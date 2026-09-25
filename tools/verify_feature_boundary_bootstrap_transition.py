#!/usr/bin/env python3
"""Verify the one-time repository-governance transition that bootstraps Feature Boundary root trust."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

BOOTSTRAP_BRANCH = "chore/bootstrap-feature-boundary-policy-20260925"
BOOTSTRAP_PR = "66"
EXPECTED_ADDED_CHECK = ("Repository feature boundary", 15368)


def load(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise SystemExit(f"expected JSON object: {path}")
    return data


def checks(policy: dict) -> set[tuple[str, int]]:
    rows = ((policy.get("required_status_checks") or {}).get("contexts") or [])
    result = {
        (str(row.get("context") or ""), int(row.get("integration_id") or 0))
        for row in rows
        if isinstance(row, dict)
    }
    if not result or any(not name or integration_id < 1 for name, integration_id in result):
        raise SystemExit("every required status check must be pinned to a positive integration_id")
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-policy", type=Path, required=True)
    parser.add_argument("--target-policy", type=Path, required=True)
    parser.add_argument("--target-template", type=Path, required=True)
    parser.add_argument("--head-branch", required=True)
    parser.add_argument("--pr-number", required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    if args.head_branch != BOOTSTRAP_BRANCH or str(args.pr_number) != BOOTSTRAP_PR:
        raise SystemExit("feature-boundary bootstrap transition is restricted to the one-time PR #66 branch")

    base = load(args.base_policy)
    target = load(args.target_policy)
    template = load(args.target_template)

    for label, policy in (("base", base), ("target", target)):
        if policy.get("contract") != "mad4b.repository-governance-policy.v1":
            raise SystemExit(f"{label} repository governance policy contract mismatch")

    base_allowed_keys = {
        "contract",
        "target_branch",
        "target_ref",
        "required_ruleset_enforcement",
        "require_no_bypass_actors",
        "required_rule_types",
        "pull_request",
        "required_status_checks",
        "single_owner_safety",
    }
    target_allowed_keys = set(base_allowed_keys) | {"required_repository_ruleset_name"}
    if set(base) != base_allowed_keys:
        raise SystemExit(
            "bootstrap base governance policy shape drift: "
            + repr(sorted(set(base) ^ base_allowed_keys))
        )
    if set(target) != target_allowed_keys:
        raise SystemExit(
            "bootstrap target governance policy shape drift: "
            + repr(sorted(set(target) ^ target_allowed_keys))
        )
    if target.get("required_repository_ruleset_name") != "MAD4B master release governance":
        raise SystemExit("bootstrap target canonical repository ruleset name drifted")

    immutable_keys = (
        "target_branch",
        "target_ref",
        "required_ruleset_enforcement",
        "require_no_bypass_actors",
        "required_rule_types",
    )
    for key in immutable_keys:
        if base.get(key) != target.get(key):
            raise SystemExit(f"bootstrap transition may not change repository governance field: {key}")

    base_pr = base.get("pull_request") or {}
    target_pr = target.get("pull_request") or {}
    for key, value in base_pr.items():
        if target_pr.get(key) != value:
            raise SystemExit(f"bootstrap transition changed existing pull_request policy field: {key}")
    expected_target_pr = dict(base_pr)
    expected_target_pr.update(
        {
            "dismiss_stale_reviews_on_push": False,
            "require_code_owner_review": False,
            "required_reviewers": [],
            "require_extra_approval_for_unattributed_changes_readback": True,
        }
    )
    if target_pr != expected_target_pr:
        raise SystemExit(
            "bootstrap transition pull_request enrichment must exactly codify current live defaults"
        )

    base_status = base.get("required_status_checks") or {}
    target_status = target.get("required_status_checks") or {}
    if bool(base_status.get("strict_required_status_checks_policy", True)) != bool(
        target_status.get("strict_required_status_checks_policy", True)
    ):
        raise SystemExit("bootstrap transition may not weaken strict required status checks")

    base_checks = checks(base)
    target_checks = checks(target)
    if not base_checks.issubset(target_checks):
        raise SystemExit("bootstrap transition removed an existing required status check")
    added = target_checks - base_checks
    if added != {EXPECTED_ADDED_CHECK}:
        raise SystemExit(f"bootstrap transition must add only {EXPECTED_ADDED_CHECK!r}; observed={sorted(added)!r}")

    base_owner = base.get("single_owner_safety") or {}
    target_owner = target.get("single_owner_safety") or {}
    expected_target_owner = dict(base_owner)
    expected_target_owner.update(
        {
            "authorized_owner_logins": ["mad4bdigital-ai"],
            "attestation_command": "OWNER_ATTEST_SINGLE_OWNER",
            "attestation_stale_on_descendant": True,
            "required_by_repository_release_verdict": True,
        }
    )
    if target_owner != expected_target_owner:
        raise SystemExit(
            "bootstrap target single-owner safety must be an exact monotonic enrichment"
        )

    if template.get("name") != "MAD4B master release governance":
        raise SystemExit("target ruleset template name mismatch")
    if template.get("target") != "branch" or template.get("enforcement") != "active":
        raise SystemExit("target ruleset template must remain an active branch ruleset")
    if template.get("bypass_actors") != []:
        raise SystemExit("target ruleset template may not contain bypass actors")
    refs = ((template.get("conditions") or {}).get("ref_name") or {})
    if refs.get("include") != ["refs/heads/master"] or refs.get("exclude") != []:
        raise SystemExit("target ruleset template must target only refs/heads/master")

    rules = [row for row in template.get("rules") or [] if isinstance(row, dict)]
    rule_types = {str(row.get("type") or "") for row in rules}
    expected_rule_types = set(target.get("required_rule_types") or [])
    if rule_types != expected_rule_types:
        raise SystemExit("target ruleset template rule types drift from target governance policy")
    counts = {
        rule_type: sum(1 for row in rules if row.get("type") == rule_type)
        for rule_type in expected_rule_types
    }
    if any(count != 1 for count in counts.values()):
        raise SystemExit("target ruleset template must contain exactly one rule per governed type")
    for simple in ("deletion", "non_fast_forward"):
        if simple in expected_rule_types:
            row = next(item for item in rules if item.get("type") == simple)
            if set(row) != {"type"}:
                raise SystemExit(f"target {simple} rule contains ungoverned parameters")

    pull_rules = [row for row in rules if row.get("type") == "pull_request"]
    if len(pull_rules) != 1:
        raise SystemExit("target ruleset template must contain exactly one pull_request rule")
    template_pr = (pull_rules[0].get("parameters") or {})
    expected_template_pr = {
        key: value
        for key, value in target_pr.items()
        if key != "require_extra_approval_for_unattributed_changes_readback"
    }
    if template_pr != expected_template_pr:
        raise SystemExit("target ruleset template pull_request parameters drift from target policy")

    status_rules = [row for row in rules if row.get("type") == "required_status_checks"]
    if len(status_rules) != 1:
        raise SystemExit("target ruleset template must contain exactly one required_status_checks rule")
    status_params = status_rules[0].get("parameters") or {}
    template_checks = {
        (str(row.get("context") or ""), int(row.get("integration_id") or 0))
        for row in status_params.get("required_status_checks") or []
        if isinstance(row, dict)
    }
    if template_checks != target_checks:
        raise SystemExit("target ruleset template checks do not exactly match target governance policy")
    if bool(status_params.get("strict_required_status_checks_policy")) is not True:
        raise SystemExit("target ruleset template must keep strict required status checks")
    if bool(status_params.get("do_not_enforce_on_create", False)):
        raise SystemExit("target ruleset template must enforce required checks on branch creation")
    if set(status_params) != {
        "do_not_enforce_on_create",
        "required_status_checks",
        "strict_required_status_checks_policy",
    }:
        raise SystemExit("target required_status_checks rule contains ungoverned parameters")

    result = {
        "contract": "mad4b.feature-boundary-governance-bootstrap-transition.v1",
        "ready": True,
        "state": "bootstrap_transition_ready",
        "bootstrap_branch": BOOTSTRAP_BRANCH,
        "bootstrap_pr_number": int(BOOTSTRAP_PR),
        "live_policy_source": "pull_request_base",
        "target_policy_source": "pull_request_head",
        "base_required_status_checks": [
            {"context": name, "integration_id": integration_id}
            for name, integration_id in sorted(base_checks)
        ],
        "target_required_status_checks": [
            {"context": name, "integration_id": integration_id}
            for name, integration_id in sorted(target_checks)
        ],
        "added_required_status_checks": [
            {"context": name, "integration_id": integration_id}
            for name, integration_id in sorted(added)
        ],
        "post_merge_ruleset_apply_required": True,
        "pull_request_live_semantics_preserved": True,
        "response_only_unattributed_approval_required": True,
        "target_policy_sha256": hashlib.sha256(args.target_policy.read_bytes()).hexdigest(),
        "target_template_sha256": hashlib.sha256(args.target_template.read_bytes()).hexdigest(),
    }

    encoded = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(encoded, encoding="utf-8")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
