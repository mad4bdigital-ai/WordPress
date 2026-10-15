#!/usr/bin/env python3
"""Check or explicitly refresh repository-only delivery fingerprints; never acceptance."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[3]
EXTENSION = HERE.relative_to(REPO)
GROUPS = ("code_paths", "test_paths", "spec_paths", "workflow_paths", "evidence_integrity")
PREFIXES = (
    "wp-content/plugins/mad4b-site-control-plane/",
    "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/",
    ".github/workflows/",
)


def source_bytes(repo: Path, path: str, manifest_path: str) -> bytes:
    if not isinstance(path, str) or not path:
        raise ValueError("delivery_binding_path_invalid")
    relative = PurePosixPath(path)
    if (relative.is_absolute() or ".." in relative.parts
            or str(relative) != path or not path.startswith(PREFIXES)
            or path == manifest_path or path.endswith("-delivery.json")):
        raise ValueError("delivery_binding_path_outside_scope_or_cyclic:" + path)
    source = repo / path
    if (source.is_symlink() or not source.is_file()
            or not source.resolve().is_relative_to(repo.resolve())):
        raise ValueError("delivery_binding_source_unavailable:" + path)
    return source.read_bytes()


def refresh(repo: Path = REPO, write: bool = False) -> dict:
    documents = sorted((repo / EXTENSION).glob("*-delivery.json"))
    if not documents:
        raise ValueError("delivery_manifests_missing")
    results, pending = [], []
    for manifest in documents:
        if manifest.is_symlink():
            raise ValueError("delivery_manifest_symlink_forbidden")
        relative = manifest.relative_to(repo).as_posix()
        payload = json.loads(manifest.read_text(encoding="utf-8"))
        seen, drift, count = set(), [], 0
        for group in GROUPS:
            for entry in payload.get(group, []):
                if isinstance(entry, str):  # Legacy inventories bind through evidence_integrity.
                    continue
                if not isinstance(entry, dict) or "sha256" not in entry:
                    raise ValueError("delivery_fingerprint_record_invalid:" + relative)
                path = entry.get("path")
                raw = source_bytes(repo, path, relative)
                if path in seen:
                    raise ValueError("delivery_fingerprint_duplicate:" + path)
                seen.add(path)
                count += 1
                digest = hashlib.sha256(raw).hexdigest()
                if entry["sha256"] != digest or ("bytes" in entry and entry["bytes"] != len(raw)):
                    drift.append(path)
                    entry["sha256"] = digest
                    if "bytes" in entry:
                        entry["bytes"] = len(raw)
        if not count:
            raise ValueError("delivery_fingerprint_inventory_missing:" + relative)
        if write and drift:
            pending.append((manifest, json.dumps(payload, indent=2) + "\n"))
        results.append({"manifest": relative, "bindings": count, "drift_paths": drift})
    # Complete admission for every document before any source file is changed.
    # A malformed later inventory must not partially rebind earlier manifests.
    for manifest, content in pending:
        manifest.write_text(content, encoding="utf-8")
    return {"contract": "mad4b.competitive-delivery-fingerprints.v1",
            "mode": "explicit_write" if write else "read_only_check",
            "authorizing": False, "runtime_certification_inferred": False,
            "manifest_count": len(results), "binding_count": sum(r["bindings"] for r in results),
            "drift_count": sum(len(r["drift_paths"]) for r in results), "results": results}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write", action="store_true", help="Refresh hashes only; never task or acceptance state.")
    args = parser.parse_args()
    try:
        report = refresh(write=args.write)
    except (ValueError, OSError, KeyError, TypeError) as error:
        print("COMPETITIVE_DELIVERY_FINGERPRINTS: FAIL: " + str(error))
        return 1
    print(json.dumps(report, sort_keys=True))
    return 0 if args.write or report["drift_count"] == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
