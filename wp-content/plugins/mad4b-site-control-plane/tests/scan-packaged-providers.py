#!/usr/bin/env python3
"""Inspect the exact plugin ZIPs committed to this repository.

This scanner is intentionally read-only. It extracts packaged providers into a
temporary directory, derives their plugin header/version, and records only
contract/symbol evidence needed by the MAD4B Site Control Plane. It does not
execute provider PHP.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

PROVIDERS = {
    "mcp_adapter": {
        "archive": "mcp-adapter.zip",
        "patterns": {
            "create_server": r"\bcreate_server\s*\(",
            "default_server_config_filter": r"mcp_adapter_default_server_config",
            "default_server_factory": r"DefaultServerFactory",
            "mcp_public_exposure": r"mcp.*public|public.*mcp",
        },
        "required": ["create_server", "default_server_config_filter"],
    },
    "elementor": {
        "archive": "elementor.zip",
        "patterns": {
            "native_manage_elements_ability": r"elementor/manage-elements",
            "document_mutator": r"Document_Mutator",
            "mcp_module": r"Modules\\Mcp|modules/mcp",
            "elementor_version_constant": r"ELEMENTOR_VERSION",
        },
        "required": ["elementor_version_constant"],
    },
    "jetengine": {
        "archive": "jet-engine.zip",
        "patterns": {
            "jet_engine_function": r"function\s+jet_engine\s*\(",
            "version_constant": r"JET_ENGINE_VERSION",
            "meta_boxes": r"meta[-_ ]?boxes|meta_boxes",
            "custom_post_types": r"custom[-_ ]?post[-_ ]?types|post-types",
            "custom_content_types": r"custom[-_ ]?content[-_ ]?types|custom-content-types|cct",
            "relations": r"relations",
            "query_builder": r"query[-_ ]?builder|query_builder",
            "meta_fields_api": r"get_meta_fields|meta_fields",
        },
        "required": ["version_constant"],
    },
    "jetsmartfilters": {
        "archive": "jet-smart-filters.zip",
        "patterns": {
            "version_constant": r"JET_SMART_FILTERS_VERSION|JET_SMARTFILTERS_VERSION",
            "plugin_accessor": r"jet_smart_filters\s*\(",
            "filter_post_type": r"post_type.*filter|filter.*post_type|jet-smart-filters",
            "query_var": r"query_var",
            "providers": r"providers?",
            "indexer": r"indexer",
        },
        "required": [],
    },
    "bit_pi": {
        "archive": "bit-pi.zip",
        "patterns": {
            "flow_model": r"class\s+Flow\b",
            "flow_node_model": r"class\s+FlowNode\b",
            "flow_history_model": r"class\s+FlowHistory\b",
            "flow_executor": r"class\s+FlowExecutor\b",
            "flow_executor_execute": r"FlowExecutor.*execute|function\s+execute\s*\(",
            "bitpi_version_constant": r"BITPI_VERSION|BIT_PI_VERSION",
        },
        "required": ["flow_model", "flow_node_model", "flow_executor"],
    },
}

TEXT_SUFFIXES = {
    ".php", ".json", ".js", ".mjs", ".cjs", ".md", ".txt", ".xml", ".yml", ".yaml"
}
MAX_FILE_BYTES = 2 * 1024 * 1024
MAX_HITS_PER_PATTERN = 12


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def safe_extract(archive: Path, dest: Path) -> None:
    with zipfile.ZipFile(archive) as zf:
        for member in zf.infolist():
            name = member.filename.replace("\\", "/")
            if name.startswith("/") or ".." in Path(name).parts:
                raise RuntimeError(f"Unsafe ZIP entry in {archive.name}: {member.filename}")
        zf.extractall(dest)


def iter_text_files(root: Path):
    for path in root.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue
        try:
            if path.stat().st_size > MAX_FILE_BYTES:
                continue
        except OSError:
            continue
        yield path


def read_text(path: Path) -> str:
    try:
        return path.read_text("utf-8", errors="ignore")
    except OSError:
        return ""


def plugin_headers(files: list[Path], root: Path) -> list[dict]:
    headers = []
    for path in files:
        if path.suffix.lower() != ".php":
            continue
        text = read_text(path)[:65536]
        if "Plugin Name:" not in text:
            continue
        name = re.search(r"^[ \t/*#@]*Plugin Name:\s*(.+?)\s*$", text, re.M | re.I)
        version = re.search(r"^[ \t/*#@]*Version:\s*(.+?)\s*$", text, re.M | re.I)
        headers.append(
            {
                "file": path.relative_to(root).as_posix(),
                "name": name.group(1).strip(" */\t") if name else "",
                "version": version.group(1).strip(" */\t") if version else "",
            }
        )
    headers.sort(key=lambda x: (x["file"].count("/"), len(x["file"]), x["file"]))
    return headers[:20]


def scan_provider(provider: str, cfg: dict, plugins_dir: Path, temp_root: Path) -> dict:
    archive = plugins_dir / cfg["archive"]
    if not archive.is_file():
        return {
            "provider": provider,
            "archive": cfg["archive"],
            "present": False,
            "required_contracts_pass": False,
            "missing_required": list(cfg.get("required", [])),
        }

    extract_dir = temp_root / provider
    extract_dir.mkdir(parents=True, exist_ok=True)
    safe_extract(archive, extract_dir)
    files = list(iter_text_files(extract_dir))
    headers = plugin_headers(files, extract_dir)

    evidence = {}
    for key, pattern in cfg["patterns"].items():
        regex = re.compile(pattern, re.I | re.M)
        hits = []
        for path in files:
            text = read_text(path)
            match = regex.search(text)
            if not match:
                continue
            line = text.count("\n", 0, match.start()) + 1
            hits.append({"file": path.relative_to(extract_dir).as_posix(), "line": line})
            if len(hits) >= MAX_HITS_PER_PATTERN:
                break
        evidence[key] = {"present": bool(hits), "hits": hits}

    missing = [key for key in cfg.get("required", []) if not evidence.get(key, {}).get("present")]
    return {
        "provider": provider,
        "archive": cfg["archive"],
        "present": True,
        "archive_sha256": sha256(archive),
        "archive_bytes": archive.stat().st_size,
        "plugin_headers": headers,
        "text_file_count": len(files),
        "contracts": evidence,
        "required_contracts_pass": not missing,
        "missing_required": missing,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugins-dir", default="wp-content/plugins")
    parser.add_argument("--output", default="/tmp/mad4b-provider-contracts.json")
    args = parser.parse_args()

    plugins_dir = Path(args.plugins_dir).resolve()
    output = Path(args.output).resolve()
    failures = []
    report = {
        "contract": "mad4b.site-control-plane.packaged-provider-contracts.v1",
        "mode": "static_zip_inspection_only",
        "providers": {},
    }

    with tempfile.TemporaryDirectory(prefix="mad4b-provider-scan-") as tmp:
        temp_root = Path(tmp)
        for provider, cfg in PROVIDERS.items():
            result = scan_provider(provider, cfg, plugins_dir, temp_root)
            report["providers"][provider] = result
            if not result.get("present") or not result.get("required_contracts_pass"):
                failures.append(provider)

    report["status"] = "passed" if not failures else "failed"
    report["failed_providers"] = failures
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if not failures else 1


if __name__ == "__main__":
    sys.exit(main())
