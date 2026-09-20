#!/usr/bin/env python3
import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path

CONTRACT = "mad4b.wp-import-export-provider-package-inspection.v1"
TARGETS = {
    "import": {
        "archive": "wp-all-import-pro.zip",
        "classes": ["PMXI_Plugin", "PMXI_Import_Record", "PMXI_Import_List", "PMXI_Cli"],
        "hook_prefix": "pmxi_",
        "secret_markers": ["cron_job_key", "import_key"],
    },
    "export": {
        "archive": "wp-all-export-pro.zip",
        "classes": ["PMXE_Plugin", "PMXE_Export_Record", "PMXE_Export_List"],
        "hook_prefix": "pmxe_",
        "secret_markers": ["cron_job_key", "export_key"],
    },
}

HEADER_RE = re.compile(r"(?mi)^\s*(Plugin Name|Version)\s*:\s*([^\r\n]+)")
CLASS_TEMPLATE = r"\bclass\s+%s\b"
METHOD_RE = re.compile(r"(?mi)\b(?:public|protected|private)?\s*(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(")
METHOD_SIGNATURE_RE = re.compile(r"(?mis)\b(?:public|protected|private)?\s*(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)")
HOOK_RE = re.compile(r"['\"]((?:pmxi|pmxe|wp_all_import|wp_all_export)_[A-Za-z0-9_]+)['\"]")
CLASS_RE = re.compile(r"(?mi)\bclass\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+([A-Za-z_\\][A-Za-z0-9_\\]*))?")
CLI_ADD_RE = re.compile(r"WP_CLI\s*::\s*add_command\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*([^,\)]+)", re.I)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def decode_php(raw: bytes) -> str:
    return raw.decode("utf-8", errors="replace")


def plugin_header(text: str):
    found = {}
    for key, value in HEADER_RE.findall(text[:32768]):
        found[key.lower().replace(" ", "_")] = value.strip()
    return found


def normalized_root(names):
    roots = {n.split("/", 1)[0] for n in names if "/" in n and not n.startswith("__MACOSX/")}
    if len(roots) == 1:
        root = next(iter(roots))
        if all(n == root or n.startswith(root + "/") for n in names if n and not n.startswith("__MACOSX/")):
            return root
    return ""


def inspect_archive(base: Path, kind: str, config: dict):
    archive = base / config["archive"]
    if not archive.is_file():
        raise SystemExit(f"missing provider archive: {archive}")
    result = {
        "archive": config["archive"],
        "archive_sha256": sha256_file(archive),
        "archive_bytes": archive.stat().st_size,
        "entry_count": 0,
        "root_prefix": "",
        "plugin": {},
        "classes": {},
        "hooks": [],
        "lifecycle_markers": {},
        "wp_cli": {
            "wp_cli_symbol_present": False,
            "all_import_command_marker": False,
            "all_export_command_marker": False,
            "add_command_marker": False,
        },
        "critical_files": {},
        "structural_api_map": {
            "class_inheritance": {},
            "record_model_candidates": [],
            "wp_cli_registrations": [],
            "execution_method_signatures": [],
        },
        "source_exposed": False,
        "secret_values_exposed": False,
    }
    with zipfile.ZipFile(archive) as zf:
        names = [i.filename for i in zf.infolist() if not i.is_dir()]
        result["entry_count"] = len(names)
        result["root_prefix"] = normalized_root(names)

        php = {}
        for name in names:
            if not name.lower().endswith(".php"):
                continue
            try:
                raw = zf.read(name)
            except KeyError:
                continue
            php[name] = (raw, decode_php(raw))

        # Discover the canonical plugin header rather than assuming archive layout.
        candidates = []
        for name, (raw, text) in php.items():
            hdr = plugin_header(text)
            pname = hdr.get("plugin_name", "")
            if pname and (
                ("import" in pname.lower() and kind == "import")
                or ("export" in pname.lower() and kind == "export")
            ):
                candidates.append((len(name.split("/")), name, hdr, raw))
        if not candidates:
            raise SystemExit(f"{kind}: canonical plugin header not found")
        candidates.sort(key=lambda x: (x[0], len(x[1]), x[1]))
        _, main_name, hdr, main_raw = candidates[0]
        result["plugin"] = {
            "plugin_name": hdr.get("plugin_name", ""),
            "version": hdr.get("version", ""),
            "plugin_file": main_name,
        }
        result["critical_files"][main_name] = sha256_bytes(main_raw)

        hooks = set()
        all_text = "\n".join(text for _, text in php.values())
        for hook in HOOK_RE.findall(all_text):
            if hook.startswith(config["hook_prefix"]) or hook.startswith("wp_all_import_") or hook.startswith("wp_all_export_"):
                hooks.add(hook)
        result["hooks"] = sorted(hooks)

        result["wp_cli"] = {
            "wp_cli_symbol_present": "WP_CLI" in all_text,
            "all_import_command_marker": bool(re.search(r"all[-_ ]import", all_text, re.I)),
            "all_export_command_marker": bool(re.search(r"all[-_ ]export", all_text, re.I)),
            "add_command_marker": "add_command" in all_text and "WP_CLI" in all_text,
        }

        class_inheritance = {}
        record_candidates = []
        cli_registrations = []
        for name, (raw, text) in php.items():
            declared = CLASS_RE.findall(text)
            methods = sorted(set(METHOD_RE.findall(text)))
            for class_name, parent_name in declared:
                class_inheritance[class_name] = parent_name or ""
                if {"set", "save", "getById"}.issubset(set(methods)):
                    record_candidates.append({
                        "class": class_name,
                        "parent": parent_name or "",
                        "file": name,
                        "file_sha256": sha256_bytes(raw),
                        "methods": [m for m in methods if m in {
                            "set", "save", "getById", "getBy", "isEmpty", "execute",
                            "process", "delete", "deletePosts", "delete_missing_records"
                        }],
                    })
            for command, callback in CLI_ADD_RE.findall(text):
                cli_registrations.append({
                    "command": command.strip(),
                    "callback_identifier": re.sub(r"\s+", " ", callback.strip())[:160],
                    "file": name,
                    "file_sha256": sha256_bytes(raw),
                })
        execution_signatures = []
        execution_targets = {"execute", "process", "generate_bundle", "run"}
        for name, (raw, text) in php.items():
            for method_name, args in METHOD_SIGNATURE_RE.findall(text):
                if method_name not in execution_targets:
                    continue
                if not (
                    "models/import/record.php" in name
                    or "models/export/record.php" in name
                    or "cli" in name.lower()
                    or "command" in name.lower()
                ):
                    continue
                execution_signatures.append({
                    "file": name,
                    "file_sha256": sha256_bytes(raw),
                    "method": method_name,
                    "arguments": " ".join(args.split())[:500],
                })
        result["structural_api_map"] = {
            "class_inheritance": dict(sorted(class_inheritance.items())),
            "record_model_candidates": sorted(record_candidates, key=lambda item: (item["class"], item["file"])),
            "wp_cli_registrations": sorted(cli_registrations, key=lambda item: (item["command"], item["file"])),
            "execution_method_signatures": sorted(execution_signatures, key=lambda item: (item["file"], item["method"], item["arguments"])),
        }

        for cls in config["classes"]:
            rx = re.compile(CLASS_TEMPLATE % re.escape(cls))
            matches = []
            for name, (raw, text) in php.items():
                if rx.search(text):
                    methods = sorted(set(METHOD_RE.findall(text)))
                    matches.append({
                        "file": name,
                        "file_sha256": sha256_bytes(raw),
                        "methods": methods,
                    })
                    result["critical_files"][name] = sha256_bytes(raw)
            result["classes"][cls] = matches

        for marker in config["secret_markers"]:
            result["lifecycle_markers"][marker] = marker in all_text
        for marker in [
            "action=trigger", "action=processing", "action=cancel",
            "trigger", "processing", "canceled", "executing",
            "pmxi_before_xml_import", "pmxi_saved_post", "pmxi_after_xml_import",
            "wp_all_import_is_post_to_delete", "wp_all_import_is_post_to_change_missing",
            "pmxi_missing_post", "pmxe_before_export", "pmxe_exported_post", "pmxe_after_export",
        ]:
            result["lifecycle_markers"][marker] = marker in all_text

    for cls, matches in result["classes"].items():
        if not matches:
            raise SystemExit(f"{kind}: required provider class not found: {cls}")
    if not result["plugin"].get("version"):
        raise SystemExit(f"{kind}: provider version missing from plugin header")
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo-root", default=None)
    parser.add_argument("--output", default=None)
    args = parser.parse_args()
    here = Path(__file__).resolve()
    repo = Path(args.repo_root).resolve() if args.repo_root else here.parents[4]
    plugin_dir = repo / "wp-content" / "plugins"

    report = {
        "contract": CONTRACT,
        "provider_family": "wp-import-export",
        "inspection_mode": "exact_repository_archives_static_no_execution",
        "archives": {},
        "execution_authorized": False,
        "mutation_mounted": False,
        "source_exposed": False,
        "secret_values_exposed": False,
    }
    for kind, cfg in TARGETS.items():
        report["archives"][kind] = inspect_archive(plugin_dir, kind, cfg)

    certified_path = plugin_dir / "mad4b-site-control-plane" / "config" / "certified-providers.json"
    certified = json.loads(certified_path.read_text(encoding="utf-8"))
    provider_contract = certified.get("providers", {}).get("wp-import-export", {})
    components = provider_contract.get("components", {})
    for kind in ("import", "export"):
        observed = report["archives"][kind]
        expected = components.get(kind, {})
        if not expected:
            raise SystemExit(f"{kind}: exact composite provider component is not cataloged")
        if expected.get("version") != observed["plugin"].get("version"):
            raise SystemExit(f"{kind}: certified version does not match exact repository archive")
        if expected.get("archive") != observed.get("archive"):
            raise SystemExit(f"{kind}: certified archive name does not match exact repository archive")
        if expected.get("archive_sha256") != observed.get("archive_sha256"):
            raise SystemExit(f"{kind}: certified archive SHA-256 drifted")
        expected_plugin_file = expected.get("plugin_file", "")
        observed_plugin_file = observed["plugin"].get("plugin_file", "")
        if expected_plugin_file != observed_plugin_file:
            raise SystemExit(f"{kind}: certified plugin_file drifted")
        prefix = observed_plugin_file.rsplit("/", 1)[0] + "/"
        normalized = {}
        for member, digest in observed.get("critical_files", {}).items():
            rel = member[len(prefix):] if member.startswith(prefix) else member
            normalized[rel] = digest
        if expected.get("critical_files", {}) != normalized:
            raise SystemExit(f"{kind}: certified critical-file manifest drifted")
    report["composite_provider_contract_verified"] = True
    report["composite_contract_mode"] = provider_contract.get("contract_mode", "")

    # Guard against accidentally treating package introspection as behavioral certification.
    report["behavioral_certified"] = False
    report["rollback_certified"] = False
    report["artifact_registry_ingest_certified"] = False
    report["dry_run_diff_certified"] = False

    encoded = json.dumps(report, sort_keys=True, indent=2)
    if args.output:
        Path(args.output).write_text(encoded + "\n", encoding="utf-8")
    print(encoded)


if __name__ == "__main__":
    main()
