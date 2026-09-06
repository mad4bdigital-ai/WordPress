#!/usr/bin/env python3
"""Inspect the exact plugin ZIPs committed to this repository.

This scanner is intentionally read-only. It extracts packaged providers into a
temporary directory, derives their plugin header/version, inventories native
MCP/Abilities implementation evidence, and records only contract/symbol data
needed by the MAD4B Site Control Plane. It never executes provider PHP.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
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
        "require_plugin_version": True,
    },
    "elementor": {
        "archive": "elementor.zip",
        "patterns": {
            "native_manage_elements_ability": r"elementor/manage-elements",
            "document_mutator": r"Document_Mutator",
            "mcp_module": r"Modules\\Mcp|modules/mcp",
            "elementor_version_constant": r"ELEMENTOR_VERSION",
        },
        "required": ["elementor_version_constant", "mcp_module"],
        "require_plugin_version": True,
    },
    "jetengine": {
        "archive": "jet-engine.zip",
        "patterns": {
            "jet_engine_function": r"function\s+jet_engine\s*\(",
            "legacy_version_constant": r"JET_ENGINE_VERSION",
            "mcp_tools": r"mcp[-_ ]?tools|MCP_Tools|mcp tools",
            "meta_boxes": r"meta[-_ ]?boxes|meta_boxes",
            "custom_post_types": r"custom[-_ ]?post[-_ ]?types|post-types",
            "custom_content_types": r"custom[-_ ]?content[-_ ]?types|custom-content-types|cct",
            "relations": r"relations",
            "query_builder": r"query[-_ ]?builder|query_builder",
            "meta_fields_api": r"get_meta_fields|meta_fields",
        },
        "required": ["jet_engine_function", "mcp_tools"],
        "require_plugin_version": True,
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
        "required": ["version_constant", "plugin_accessor", "providers", "query_var"],
        "require_plugin_version": True,
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
        "require_plugin_version": True,
    },
}

TEXT_SUFFIXES = {
    ".php", ".json", ".js", ".mjs", ".cjs", ".md", ".txt", ".xml", ".yml", ".yaml"
}
MAX_FILE_BYTES = 2 * 1024 * 1024
MAX_HITS_PER_PATTERN = 12
MAX_MCP_FILES = 120
MAX_CAPABILITY_IDS = 120


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
            parts = Path(name).parts
            if name.startswith("/") or ".." in parts:
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


def line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


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


def is_mcp_path(relative_path: str) -> bool:
    value = "/" + relative_path.lower().replace("\\", "/")
    return (
        "/mcp/" in value
        or "/mcp-tools/" in value
        or "/mcp_tools/" in value
        or value.endswith("/mcp.php")
        or "/modules/mcp" in value
    )


def native_mcp_inventory(files: list[Path], root: Path) -> dict:
    mcp_files = []
    candidates: dict[str, list[dict]] = {}
    registrations = {
        "wp_register_ability": [],
        "ability_id_method": [],
        "register_tool": [],
    }

    slash_id = re.compile(r"['\"]([a-z][a-z0-9_.-]{1,50}/[a-z0-9][a-z0-9_.-]{1,80})['\"]", re.I)
    ability_method = re.compile(
        r"(?:get_ability_id|get_ability_name)\s*\([^)]*\)\s*(?::[^\{]+)?\{.{0,800}?return\s+['\"]([^'\"]+)['\"]",
        re.I | re.S,
    )
    wp_register = re.compile(r"wp_register_ability\s*\(\s*['\"]([^'\"]+)['\"]", re.I)
    register_tool = re.compile(r"(?:register_tool|add_tool|register_feature)\s*\(\s*['\"]([^'\"]+)['\"]", re.I)

    for path in files:
        rel = path.relative_to(root).as_posix()
        if not is_mcp_path(rel):
            continue
        if len(mcp_files) < MAX_MCP_FILES:
            mcp_files.append(rel)
        text = read_text(path)

        for match in slash_id.finditer(text):
            value = match.group(1)
            candidates.setdefault(value, []).append({"file": rel, "line": line_number(text, match.start())})
        for key, regex in (
            ("wp_register_ability", wp_register),
            ("ability_id_method", ability_method),
            ("register_tool", register_tool),
        ):
            for match in regex.finditer(text):
                item = {"id": match.group(1), "file": rel, "line": line_number(text, match.start())}
                registrations[key].append(item)

    unique_candidates = []
    for ability_id in sorted(candidates)[:MAX_CAPABILITY_IDS]:
        unique_candidates.append({"id": ability_id, "hits": candidates[ability_id][:5]})

    for key in registrations:
        seen = set()
        deduped = []
        for item in registrations[key]:
            identity = (item["id"], item["file"])
            if identity in seen:
                continue
            seen.add(identity)
            deduped.append(item)
        registrations[key] = deduped[:MAX_CAPABILITY_IDS]

    return {
        "present": bool(mcp_files),
        "files": sorted(mcp_files),
        "slash_id_candidates": unique_candidates,
        "registrations": registrations,
    }


def scan_provider(provider: str, cfg: dict, plugins_dir: Path, temp_root: Path) -> dict:
    archive = plugins_dir / cfg["archive"]
    if not archive.is_file():
        return {
            "provider": provider,
            "archive": cfg["archive"],
            "present": False,
            "required_contracts_pass": False,
            "missing_required": list(cfg.get("required", [])) + ["plugin_archive"],
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
            hits.append(
                {
                    "file": path.relative_to(extract_dir).as_posix(),
                    "line": line_number(text, match.start()),
                }
            )
            if len(hits) >= MAX_HITS_PER_PATTERN:
                break
        evidence[key] = {"present": bool(hits), "hits": hits}

    missing = [key for key in cfg.get("required", []) if not evidence.get(key, {}).get("present")]
    if cfg.get("require_plugin_version"):
        primary_version = headers[0].get("version", "") if headers else ""
        if not primary_version:
            missing.append("plugin_header_version")

    return {
        "provider": provider,
        "archive": cfg["archive"],
        "present": True,
        "archive_sha256": sha256(archive),
        "archive_bytes": archive.stat().st_size,
        "plugin_headers": headers,
        "text_file_count": len(files),
        "contracts": evidence,
        "native_mcp": native_mcp_inventory(files, extract_dir),
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
