#!/usr/bin/env python3
"""Verify that an automatic repository-ruleset restore returned to the exact pre-apply state."""

from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path


HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "mad4b_ruleset_template_verifier",
    HERE / "verify_repository_ruleset_template.py",
)
module = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(module)

CONTRACT = "mad4b.repository-ruleset-restore-readback.v1"


def load(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected JSON object: {path}")
    return data


def verify(before: dict, after: dict, repository: str) -> dict:
    repository = str(repository or "").strip()
    if "/" not in repository:
        raise ValueError("repository must use owner/name form")

    for label, row in (("before", before), ("after", after)):
        if str(row.get("source_type") or "") != "Repository":
            raise ValueError(f"{label} ruleset is not repository-owned")
        if str(row.get("source") or "") != repository:
            raise ValueError(f"{label} ruleset source mismatch")
        if "bypass_actors" not in row:
            raise ValueError(f"{label} bypass-actor evidence is missing")

    before_id = int(before.get("id") or 0)
    after_id = int(after.get("id") or 0)
    if before_id < 1 or after_id != before_id:
        raise ValueError("ruleset id changed across automatic restore")

    expected = module.mutable_ruleset(before)
    observed = module.mutable_ruleset(after)
    if observed != expected:
        raise ValueError("restored ruleset does not match the exact pre-apply mutable state")

    return {
        "contract": CONTRACT,
        "ready": True,
        "repository": repository,
        "ruleset_id": before_id,
        "restored_exactly": True,
        "bypass_actor_count": len(after.get("bypass_actors") or []),
        "mutation_performed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--before", required=True, type=Path)
    parser.add_argument("--after", required=True, type=Path)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    try:
        result = verify(load(args.before), load(args.after), args.repository)
    except (ValueError, OSError, json.JSONDecodeError) as exc:
        print(f"RULESET_RESTORE: FAIL: {exc}")
        return 1

    encoded = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(encoded, encoding="utf-8")
    print("RULESET_RESTORE: PASS")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
