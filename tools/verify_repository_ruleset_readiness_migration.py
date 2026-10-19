#!/usr/bin/env python3
"""Fail-closed, read-only preflight for MAD4B master ruleset Issue #430.

Allow exactly the addition of the GitHub-Actions-owned Readiness check, or
a fully converged ruleset. Preserve every other mutable ruleset field.
This helper does not perform a GitHub API write or grant Production access.
"""
from __future__ import annotations

import argparse
import copy
import json
from pathlib import Path

from verify_repository_ruleset_template import mutable_ruleset, verify

NEW = {"context": "Production readiness matrix contract", "integration_id": 15368}
LEGACY = [
    {"context": "Repository release verdict", "integration_id": 15368},
    {"context": "Repository feature boundary", "integration_id": 15368},
]


def preflight(before: dict, template: dict, policy: dict, repository: str) -> dict:
    verify(template, policy)
    if not isinstance(before, dict) or "bypass_actors" not in before:
        raise ValueError("current ruleset or bypass evidence missing")
    if before["bypass_actors"] != []:
        raise ValueError("current ruleset contains bypass actors")
    if before.get("source_type") != "Repository" or before.get("source") != repository:
        raise ValueError("current ruleset is not repository-owned")
    if not isinstance(before.get("id"), int) or before["id"] < 1:
        raise ValueError("current ruleset identity is missing")
    if policy.get("required_repository_ruleset_name") != "MAD4B master release governance":
        raise ValueError("canonical ruleset name mismatch")
    target = mutable_ruleset(template)
    required = next(x for x in target["rules"] if x["type"] == "required_status_checks")
    expected = sorted(LEGACY + [NEW], key=lambda x: (x["context"], x["integration_id"]))
    if required["parameters"]["required_status_checks"] != expected:
        raise ValueError("target is not exact two-to-three readiness addition")
    observed = mutable_ruleset(before)
    old_expected = copy.deepcopy(target)
    old_required = next(x for x in old_expected["rules"] if x["type"] == "required_status_checks")
    old_required["parameters"]["required_status_checks"] = sorted(
        LEGACY, key=lambda x: (x["context"], x["integration_id"])
    )
    if observed == target:
        transition = "already_converged"
    elif observed == old_expected:
        transition = "append_readiness_only"
    else:
        raise ValueError("live ruleset differs from template beyond the one allowed check addition")
    return {
        "contract": "mad4b.ruleset-readiness-minimal-delta.v1",
        "ready": True,
        "ruleset_id": before["id"],
        "exact_repository": repository,
        "transition": transition,
        "allowed_addition": NEW,
        "preserved_checks": LEGACY,
        "other_mutable_fields_identical": True,
        "mutation_performed": False,
        "production_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--before", required=True, type=Path)
    parser.add_argument("--template", required=True, type=Path)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    try:
        evidence = preflight(
            json.loads(args.before.read_text(encoding="utf-8")),
            json.loads(args.template.read_text(encoding="utf-8")),
            json.loads(args.policy.read_text(encoding="utf-8")),
            args.repository,
        )
    except (ValueError, KeyError, TypeError, OSError, StopIteration) as exc:
        print(f"RULESET_READINESS_MINIMAL_DELTA: FAIL: {exc}")
        return 1
    if args.output:
        args.output.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print("RULESET_READINESS_MINIMAL_DELTA: PASS")
    print(json.dumps(evidence, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
