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
        "classes": ["PMXI_Plugin", "PMXI_Import_Record", "PMXI_Import_List"],
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
HOOK_RE = re.compile(r"['\"]((?:pmxi|pmxe)_[A-Za-z0-9_]+)['\"]")


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
        "critical_files": {},
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
            if hook.startswith(config["hook_prefix"]):
                hooks.add(hook)
        result["hooks"] = sorted(hooks)

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
        for marker in ["action=trigger", "action=processing", "trigger", "processing", "canceled", "executing"]:
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
