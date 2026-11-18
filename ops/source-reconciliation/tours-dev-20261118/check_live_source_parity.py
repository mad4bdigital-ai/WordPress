#!/usr/bin/env python3
"""Non-authorizing, read-only Control Plane package-vs-live-source hash preflight.

A green result is source continuity evidence only, NEVER an owner approval or
runtime release certificate. A reviewed difference must be explicitly pinned by
both the old installed SHA-256 and the proposed candidate SHA-256.
"""
import argparse
import hashlib
import json
import re
from pathlib import Path, PurePosixPath

CONTRACT = "mad4b.control-plane-live-source-inventory.v1"
VALID_SHA = re.compile(r"^[0-9a-f]{64}$")


def safe_relative(path):
    if not isinstance(path, str) or not path or "\\" in path or path.startswith("/"):
        raise ValueError("Source inventory path is not an ordinary relative path")
    rel = PurePosixPath(path)
    if str(rel) != path or any(p in ("", ".", "..") for p in rel.parts):
        raise ValueError("Source inventory path is not normalized")
    return rel


def parse_inventory(payload):
    if payload.get("contract") != CONTRACT or not isinstance(payload.get("files"), list):
        raise ValueError("Invalid live-source inventory contract")
    items = payload["files"]
    if payload.get("source_count") != len(items) or not items:
        raise ValueError("Missing or truncated live-source inventory")
    seen = set()
    for item in items:
        if not isinstance(item, dict):
            raise ValueError("Malformed inventory row")
        key = str(safe_relative(item.get("path")))
        if key in seen:
            raise ValueError("Duplicate live-source path")
        seen.add(key)
        if not isinstance(item.get("sha256"), str) or not VALID_SHA.fullmatch(item["sha256"]):
            raise ValueError("Malformed source SHA-256")
        if item.get("comparison_master") not in ("live_only", "identical", "different"):
            raise ValueError("Unsupported comparison classification")
    return items


def parse_decisions(payload):
    if payload is None:
        return {}
    if payload.get("contract") != "mad4b.source-parity-review-decisions.v1":
        raise ValueError("Invalid review decisions contract")
    items = payload.get("reviewed_differences")
    if not isinstance(items, list):
        raise ValueError("Malformed reviewed differences")
    result = {}
    for item in items:
        name = str(safe_relative(item.get("path")))
        if name in result:
            raise ValueError("Duplicate reviewed path")
        if item.get("action") not in ("replace", "retire") or not VALID_SHA.fullmatch(str(item.get("source_sha256", ""))):
            raise ValueError("Invalid reviewed source identity")
        sha = item.get("candidate_sha256")
        if item["action"] == "replace" and (not isinstance(sha, str) or not VALID_SHA.fullmatch(sha)):
            raise ValueError("Replacement needs exact candidate SHA-256")
        if item["action"] == "retire" and sha is not None:
            raise ValueError("Retirement must have null candidate SHA-256")
        result[name] = item
    return result


def inspect(candidate_root, inventory, decisions=None):
    items = parse_inventory(inventory)
    approved = parse_decisions(decisions)
    root = Path(candidate_root)
    if not root.is_dir() or root.is_symlink():
        raise ValueError("Candidate plugin root must be an ordinary directory")
    rows = []
    for item in items:
        path = item["path"]
        relative = safe_relative(path)
        file = root
        for part in relative.parts:
            file = file / part
            if file.is_symlink():
                raise ValueError("Candidate source path contains a symlink: " + path)
        digest = hashlib.sha256(file.read_bytes()).hexdigest() if file.is_file() else None
        exact = digest == item["sha256"]
        decision = approved.get(path)
        reviewed = bool(decision and decision.get("source_sha256") == item["sha256"]
                        and (decision.get("candidate_sha256") == digest)
                        and ((decision.get("action") == "retire") == (digest is None)))
        state = "identical" if exact else ("reviewed_difference" if reviewed else ("missing_unreviewed" if digest is None else "changed_unreviewed"))
        rows.append({"path": path, "source_sha256": item["sha256"], "candidate_sha256": digest, "state": state})
    used_decisions = set(x["path"] for x in rows if x["state"] == "reviewed_difference")
    stale_decisions = sorted(set(approved) - used_decisions)
    counts = {name: sum(r["state"] == name for r in rows) for name in ("identical", "reviewed_difference", "missing_unreviewed", "changed_unreviewed")}
    passed = counts["missing_unreviewed"] == 0 and counts["changed_unreviewed"] == 0 and not stale_decisions
    return {"contract": "mad4b.control-plane-source-parity-preflight.v1",
            "status": "SOURCE_CONTINUITY_REVIEWED" if passed else "SOURCE_REVIEW_REQUIRED",
            "release_authorized": False, "source_count": len(rows), "counts": counts,
            "stale_decisions": stale_decisions, "issues": [x for x in rows if x["state"] not in ("identical", "reviewed_difference")],
            "reviewed_differences": [x for x in rows if x["state"] == "reviewed_difference"]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--inventory", type=Path, required=True)
    parser.add_argument("--candidate", type=Path, required=True)
    parser.add_argument("--review-decisions", type=Path)
    parser.add_argument("--out", type=Path)
    args = parser.parse_args()
    try:
        inv = json.loads(args.inventory.read_text())
        decisions = json.loads(args.review_decisions.read_text()) if args.review_decisions else None
        result = inspect(args.candidate, inv, decisions)
    except (ValueError, OSError, TypeError, json.JSONDecodeError) as exc:
        parser.error(str(exc))
    if args.out:
        args.out.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps({k:v for k,v in result.items() if k not in ("issues", "reviewed_differences")}, ensure_ascii=False))
    return 0 if result["status"] == "SOURCE_CONTINUITY_REVIEWED" else 2


if __name__ == "__main__":
    raise SystemExit(main())
