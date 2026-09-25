#!/usr/bin/env python3
"""Verify that the canonical repository ruleset template exactly implements governance policy."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

CONTRACT = "mad4b.repository-ruleset-template.v1"
EXPECTED_NAME = "MAD4B master release governance"


def load(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected JSON object: {path}")
    return data


def mutable_ruleset(value: dict) -> dict:
    conditions = json.loads(json.dumps(value.get("conditions") or {}))
    ref_name = conditions.get("ref_name") if isinstance(conditions, dict) else None
    if isinstance(ref_name, dict):
        ref_name["include"] = sorted(str(x) for x in (ref_name.get("include") or []))
        ref_name["exclude"] = sorted(str(x) for x in (ref_name.get("exclude") or []))

    rules = []
    for raw in value.get("rules") or []:
        if not isinstance(raw, dict):
            continue
        row = json.loads(json.dumps(raw))
        params = row.get("parameters")
        if isinstance(params, dict):
            if isinstance(params.get("allowed_merge_methods"), list):
                params["allowed_merge_methods"] = sorted(str(x) for x in params["allowed_merge_methods"])
            if isinstance(params.get("required_status_checks"), list):
                params["required_status_checks"] = sorted(
                    [
                        {
                            "context": str(item.get("context") or ""),
                            "integration_id": int(item.get("integration_id") or 0),
                        }
                        for item in params["required_status_checks"]
                        if isinstance(item, dict)
                    ],
                    key=lambda item: (item["context"], item["integration_id"]),
                )
        rules.append(row)
    rules.sort(key=lambda row: str(row.get("type") or ""))

    bypass = value.get("bypass_actors") or []
    bypass = sorted(
        [json.loads(json.dumps(item)) for item in bypass if isinstance(item, dict)],
        key=lambda item: (
            str(item.get("actor_type") or ""),
            int(item.get("actor_id") or 0),
            str(item.get("bypass_mode") or ""),
        ),
    )
    return {
        "name": value.get("name"),
        "target": value.get("target"),
        "enforcement": value.get("enforcement"),
        "bypass_actors": bypass,
        "conditions": conditions,
        "rules": rules,
    }


def required_checks(policy: dict) -> set[tuple[str, int]]:
    rows = ((policy.get("required_status_checks") or {}).get("contexts") or [])
    checks = {
        (str(row.get("context") or ""), int(row.get("integration_id") or 0))
        for row in rows
        if isinstance(row, dict)
    }
    if not checks or any(not name or integration_id < 1 for name, integration_id in checks):
        raise ValueError("policy must pin every required status check to a positive integration_id")
    return checks


def verify(template: dict, policy: dict, readback: dict | None = None) -> dict:
    if policy.get("contract") != "mad4b.repository-governance-policy.v1":
        raise ValueError("repository governance policy contract mismatch")
    if policy.get("target_ref") != "refs/heads/master" or policy.get("target_branch") != "master":
        raise ValueError("repository governance target must remain master")

    if template.get("name") != EXPECTED_NAME:
        raise ValueError("ruleset template name mismatch")
    if template.get("target") != "branch" or template.get("enforcement") != "active":
        raise ValueError("ruleset template must remain an active branch ruleset")
    if (template.get("bypass_actors") or []) != []:
        raise ValueError("ruleset template may not contain bypass actors")

    refs = ((template.get("conditions") or {}).get("ref_name") or {})
    if refs.get("include") != ["refs/heads/master"] or refs.get("exclude") != []:
        raise ValueError("ruleset template must target only refs/heads/master")
    if set((template.get("conditions") or {}).keys()) != {"ref_name"}:
        raise ValueError("ruleset template contains ungoverned conditions")

    rules = template.get("rules") or []
    if not isinstance(rules, list) or any(not isinstance(row, dict) for row in rules):
        raise ValueError("ruleset template rules must be objects")
    by_type: dict[str, list[dict]] = {}
    for row in rules:
        by_type.setdefault(str(row.get("type") or ""), []).append(row)

    expected_types = set(str(x) for x in (policy.get("required_rule_types") or []))
    if set(by_type) != expected_types:
        raise ValueError(
            f"ruleset template rule types drift: expected={sorted(expected_types)} actual={sorted(by_type)}"
        )
    duplicates = sorted(name for name, rows in by_type.items() if len(rows) != 1)
    if duplicates:
        raise ValueError(f"ruleset template must contain exactly one rule per governed type: {duplicates}")

    for simple in ("deletion", "non_fast_forward"):
        if simple in by_type and set(by_type[simple][0].keys()) != {"type"}:
            raise ValueError(f"{simple} rule must not carry ungoverned parameters")

    pr_policy = policy.get("pull_request") or {}
    pr_rule = by_type.get("pull_request", [{}])[0]
    pr_params = pr_rule.get("parameters") or {}
    expected_pr = {
        "allowed_merge_methods": list(pr_policy.get("allowed_merge_methods") or []),
        "dismiss_stale_reviews_on_push": bool(pr_policy.get("dismiss_stale_reviews_on_push", False)),
        "require_code_owner_review": bool(pr_policy.get("require_code_owner_review", False)),
        "require_last_push_approval": bool(pr_policy.get("require_last_push_approval", False)),
        "required_approving_review_count": int(pr_policy.get("required_approving_review_count", 0)),
        "required_review_thread_resolution": bool(pr_policy.get("required_review_thread_resolution", True)),
    }
    if pr_params != expected_pr:
        raise ValueError(f"pull_request rule differs from policy: expected={expected_pr!r} actual={pr_params!r}")

    status_policy = policy.get("required_status_checks") or {}
    status_rule = by_type.get("required_status_checks", [{}])[0]
    status_params = status_rule.get("parameters") or {}
    actual_checks = {
        (str(row.get("context") or ""), int(row.get("integration_id") or 0))
        for row in status_params.get("required_status_checks") or []
        if isinstance(row, dict)
    }
    expected_checks = required_checks(policy)
    if actual_checks != expected_checks:
        raise ValueError(
            f"required status checks differ from policy: expected={sorted(expected_checks)!r} actual={sorted(actual_checks)!r}"
        )
    if bool(status_params.get("strict_required_status_checks_policy")) != bool(
        status_policy.get("strict_required_status_checks_policy", True)
    ):
        raise ValueError("strict required-status-check policy drift")
    if bool(status_params.get("do_not_enforce_on_create", False)) is not False:
        raise ValueError("required status checks must enforce on branch creation")
    if set(status_params) != {
        "do_not_enforce_on_create",
        "required_status_checks",
        "strict_required_status_checks_policy",
    }:
        raise ValueError("required_status_checks rule contains ungoverned parameters")

    readback_verified = False
    if readback is not None:
        if mutable_ruleset(readback) != mutable_ruleset(template):
            raise ValueError("live ruleset mutable fields do not exactly match canonical template")
        readback_verified = True

    return {
        "contract": CONTRACT,
        "ready": True,
        "ruleset_name": EXPECTED_NAME,
        "target_ref": "refs/heads/master",
        "required_status_checks": [
            {"context": name, "integration_id": integration_id}
            for name, integration_id in sorted(expected_checks)
        ],
        "rule_types": sorted(expected_types),
        "readback_verified": readback_verified,
        "mutation_performed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--template", required=True, type=Path)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--readback", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    try:
        result = verify(
            load(args.template),
            load(args.policy),
            load(args.readback) if args.readback else None,
        )
    except (ValueError, OSError, json.JSONDecodeError) as exc:
        print(f"RULESET_TEMPLATE: FAIL: {exc}")
        return 1
    encoded = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(encoded, encoding="utf-8")
    print("RULESET_TEMPLATE: PASS")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
