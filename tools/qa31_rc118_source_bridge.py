#!/usr/bin/env python3
"""Fail-closed, read-only QA31 control-plane source reconciliation preflight.

Byte parity is not source authority, a signed build, or deployment approval.
"""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath

CONTRACT = "mad4b.qa31.rc118-source-reconciliation.v1"
MANIFEST = "mad4b.build-provenance.v1"
MAIN = "mad4b-site-control-plane.php"
INCLUDES = (
    "includes/class-mad4b-scp-company-tenant-migration.php",
    "includes/class-mad4b-scp-migration-decision-catalog.php",
    "includes/class-mad4b-scp-company-migration-decisions.php",
)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_file(root, name):
    if not isinstance(name, str) or not name or "\\" in name or "\x00" in name:
        raise ValueError("invalid_path")
    parts = name.split("/")
    if name.startswith("/") or any(part in ("", ".", "..") for part in parts):
        raise ValueError("path_traversal")
    path = root.joinpath(*PurePosixPath(name).parts)
    if path.is_symlink() or any(p.is_symlink() for p in path.parents if p != root):
        raise ValueError("symlink_path")
    if not path.resolve(strict=False).is_relative_to(root.resolve()):
        raise ValueError("path_outside_root")
    return path


def audit(plugin_root, manifest, version="0.4.0-rc.118"):
    root = Path(plugin_root)
    result = {
        "contract": CONTRACT,
        "status": "BLOCKED",
        "version_expected": version,
        "manifest_entries": 0,
        "matching_files": 0,
        "unexplained_drift": [],
        "explained_main_drift": False,
        "added_require_once_lines": list(INCLUDES),
        "unmanifested_include_files": [],
        "include_file_sha256": {},
        "main_added_bytes": 0,
        "original_main_sha256": "",
        "runtime_main_sha256": "",
        "reconstructed_main_sha256": "",
        "blockers": [],
        "source_authoritative": False,
        "new_signed_package_produced": False,
        "provider_certified": False,
        "staging_rollout_authorized": False,
        "production_rollout_authorized": False,
        "mutation_performed": False,
    }
    if root.is_symlink() or not root.is_dir():
        result["blockers"].append("invalid_plugin_root")
        return result
    files = manifest.get("package_files") if isinstance(manifest, dict) and manifest.get("contract") == MANIFEST else None
    if not isinstance(files, list) or not files or len(files) > 100000:
        result["blockers"].append("invalid_build_manifest")
        return result
    result["manifest_entries"] = len(files)
    registry = {}
    for item in files:
        name = item.get("path") if isinstance(item, dict) else None
        sha = item.get("sha256") if isinstance(item, dict) else None
        if not isinstance(name, str) or name in registry or not isinstance(sha, str) or len(sha) != 64 or any(c not in "0123456789abcdef" for c in sha):
            result["blockers"].append("duplicate_or_invalid_manifest_entry")
            return result
        registry[name] = sha
    if MAIN not in registry:
        result["blockers"].append("main_file_missing_from_original_manifest")
        return result
    for relative in INCLUDES:
        try:
            path = safe_file(root, relative)
            if not path.is_file():
                result["blockers"].append("required_include_file_missing_" + relative)
                continue
            result["include_file_sha256"][relative] = digest(path.read_bytes())
            if relative not in registry:
                result["unmanifested_include_files"].append(relative)
        except (ValueError, OSError):
            result["blockers"].append("unsafe_required_include_" + relative)
    for name, sha in registry.items():
        try:
            file = safe_file(root, name)
            if not file.is_file():
                result["unexplained_drift"].append(name)
                continue
            data = file.read_bytes()
        except (ValueError, OSError):
            result["unexplained_drift"].append(name)
            continue
        current = digest(data)
        if name == MAIN:
            result["original_main_sha256"] = sha
            result["runtime_main_sha256"] = current
            if (f"Version: {version}".encode() not in data or
                    f"define( 'MAD4B_SCP_VERSION', '{version}' );".encode() not in data):
                result["blockers"].append("runtime_version_mismatch")
                continue
            if current == sha:
                result["reconstructed_main_sha256"] = current
                result["matching_files"] += 1
                continue
            guard = b"// REV121: explicitly Staging-only Company Operations modules.\nif ( 'staging' === wp_get_environment_type() ) {\n"
            guard_start = data.find(guard)
            guard_end = data.find(b"\n}\n", guard_start + len(guard)) if guard_start >= 0 else -1
            if guard_start < 0 or guard_end < 0:
                result["blockers"].append("staging_guard_unavailable")
            restored = data
            for relative in INCLUDES:
                line = b"\trequire_once MAD4B_SCP_DIR . '" + relative.encode("ascii") + b"';\n"
                if restored.count(line) != 1:
                    result["blockers"].append("unexpected_include_occurrence_" + relative)
                    break
                position = data.find(line)
                if guard_start < 0 or not (guard_start + len(guard) <= position < guard_end):
                    result["blockers"].append("include_outside_staging_guard_" + relative)
                    break
                restored = restored.replace(line, b"", 1)
            result["main_added_bytes"] = len(data) - len(restored)
            result["reconstructed_main_sha256"] = digest(restored)
            if not result["blockers"] and digest(restored) == sha and result["main_added_bytes"] == 266:
                result["explained_main_drift"] = True
            else:
                result["unexplained_drift"].append(MAIN)
        elif current == sha:
            result["matching_files"] += 1
        else:
            result["unexplained_drift"].append(name)
    if not result["blockers"] and not result["unexplained_drift"]:
        if result["explained_main_drift"] and result["matching_files"] == len(files) - 1:
            result["status"] = "EXPLAINED_UNCERTIFIED_SOURCE_DRIFT"
        elif not result["explained_main_drift"] and result["matching_files"] == len(files):
            result["status"] = "EXACT_BYTES_NOT_CERTIFIED"
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plugin-root", required=True, type=Path)
    parser.add_argument("--expected-version", default="0.4.0-rc.118")
    args = parser.parse_args()
    manifest_path = args.plugin_root / "MAD4B-BUILD-PROVENANCE.json"
    if manifest_path.is_symlink() or not manifest_path.is_file():
        parser.error("signed-build manifest unavailable")
    report = audit(args.plugin_root, json.loads(manifest_path.read_text()), args.expected_version)
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if report["status"] != "BLOCKED" else 2


if __name__ == "__main__":
    raise SystemExit(main())
