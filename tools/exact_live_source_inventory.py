#!/usr/bin/env python3
"""Read-only file and provenance reconciliation. Never treats absent files as approved removals."""
import argparse
import hashlib
import json
import subprocess
from pathlib import Path

EXCLUDE = {"MAD4B-BUILD-PROVENANCE.json"}
ACTIVE = ("includes/", "bootstrap/", "config/", "assets/", "data/", "skill-seeds/")
SUPPORT = ("tests/", "docs/", "languages/")

def file_map(root):
    output = {}
    for path in root.rglob("*"):
        if path.is_symlink():
            raise ValueError("symlink_in_source")
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        output[rel] = (path.stat().st_size, hashlib.sha256(path.read_bytes()).hexdigest())
    return output

def status(root_a, root_b, head):
    a, b = file_map(root_a), file_map(root_b)
    all_paths = set(a) | set(b)
    records = []
    for path in sorted(all_paths):
        if path in EXCLUDE:
            continue
        left, right = a.get(path), b.get(path)
        if left == right:
            continue
        state = "live_only" if left and not right else "source_only" if right and not left else "different"
        classification = "support" if path.startswith(SUPPORT) else "active" if path.startswith(ACTIVE) or ("/" not in path and path.endswith(".php")) else "support"
        records.append({"path": path, "state": state, "class": classification,
                        "live_sha256": left[1] if left else None,
                        "source_sha256": right[1] if right else None})
    provenance = root_a / "MAD4B-BUILD-PROVENANCE.json"
    manifest = json.loads(provenance.read_text(encoding="utf-8"))
    expected = manifest.get("package_files")
    if not isinstance(expected, list):
        raise ValueError("missing_manifest_entries")
    mismatches = []
    canonical = []
    seen = set()
    for entry in expected:
        if not isinstance(entry, dict):
            raise ValueError("invalid_manifest_entry")
        name = entry.get("path")
        size, sha = entry.get("bytes"), entry.get("sha256")
        if (not isinstance(name, str) or name.startswith("/") or chr(92) in name or
                any(part in ("", ".", "..") for part in name.split("/")) or name in seen):
            raise ValueError("unsafe_manifest_path")
        if (not isinstance(size, int) or size < 0 or not isinstance(sha, str) or
                len(sha) != 64 or any(c not in "0123456789abcdef" for c in sha)):
            raise ValueError("invalid_manifest_value")
        seen.add(name)
        if a.get(name) != (size, sha):
            mismatches.append(name)
        canonical.append(name + chr(0) + str(size) + chr(0) + sha + chr(10))
    digest_valid = hashlib.sha256("".join(sorted(canonical)).encode()).hexdigest() == manifest.get("package_manifest_digest")
    return {
        "contract": "mad4b.exact-live-source-drift-inventory.v1",
        "source_head": head, "live_root": str(root_a), "source_root": str(root_b),
        "observed": {"live_files": len(a), "source_files": len(b),
                     "different": sum(r["state"]=="different" for r in records),
                     "live_only": sum(r["state"]=="live_only" for r in records),
                     "source_only": sum(r["state"]=="source_only" for r in records),
                     "active_gaps": sum(r["class"]=="active" for r in records)},
        "manifest": {"version": manifest.get("control_plane_version"),
                     "source_sha": manifest.get("source_commit_sha"),
                     "listed_files": len(expected), "mismatches": sorted(mismatches),
                     "manifest_digest_valid": digest_valid},
        "records": records, "source_authoritative": False,
        "release_ready": not records and not mismatches and digest_valid,
        "mutation_performed": False
    }

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--live-root", type=Path, required=True)
    p.add_argument("--source-root", type=Path, required=True)
    p.add_argument("--source-head", required=True)
    p.add_argument("--output", type=Path, required=True)
    args=p.parse_args()
    if not all(x.is_dir() for x in (args.live_root, args.source_root)):
        raise SystemExit("invalid_path")
    if not args.output.parent.is_dir():
        raise SystemExit("output_parent_missing")
    actual_head = subprocess.check_output(["git", "-C", str(args.source_root.resolve()), "rev-parse", "HEAD"], text=True).strip()
    if args.source_head != actual_head:
        raise SystemExit("source_head_mismatch")
    result=status(args.live_root.resolve(), args.source_root.resolve(), args.source_head)
    args.output.write_text(json.dumps(result,indent=2,sort_keys=True)+"\n",encoding="utf-8")
    print(json.dumps({"observed":result["observed"],"manifest":result["manifest"],
                      "release_ready":result["release_ready"]},sort_keys=True))

if __name__=="__main__":
    main()
