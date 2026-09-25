#!/usr/bin/env python3
"""Fail closed unless GitHub repository rulesets enforce the MAD4B master policy."""

from __future__ import annotations

import argparse
import fnmatch
import json
import os
from pathlib import Path
import subprocess
import urllib.request


def gh_json(repository: str, path: str):
    endpoint = f"repos/{repository}/{path.lstrip('/')}"
    try:
        raw = subprocess.check_output(
            ["gh", "api", "-H", "Accept: application/vnd.github+json", endpoint],
            text=True,
            stderr=subprocess.PIPE,
        )
        return json.loads(raw)
    except (subprocess.CalledProcessError, FileNotFoundError) as exc:
        # Repository rulesets for public repositories are public metadata. Fall
        # back to an unauthenticated GET so a restricted GitHub App token does
        # not turn a policy read into a false negative.
        url = "https://api.github.com/" + endpoint
        req = urllib.request.Request(
            url,
            headers={
                "Accept": "application/vnd.github+json",
                "User-Agent": "mad4b-repository-governance-contract",
                "X-GitHub-Api-Version": "2022-11-28",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=20) as response:
                return json.loads(response.read().decode("utf-8"))
        except Exception as fallback:
            raise SystemExit(
                f"repository governance API unavailable: authenticated={exc}; fallback={fallback}"
            ) from fallback


def ref_matches(value: str, pattern: str, default_ref: str) -> bool:
    if pattern == "~ALL":
        return True
    if pattern == "~DEFAULT_BRANCH":
        return value == default_ref
    return value == pattern or fnmatch.fnmatch(value, pattern)


def applies_to_target(ruleset: dict, target_ref: str) -> bool:
    if ruleset.get("target") != "branch" or ruleset.get("enforcement") != "active":
        return False
    ref = ((ruleset.get("conditions") or {}).get("ref_name") or {})
    includes = ref.get("include") or ["~ALL"]
    excludes = ref.get("exclude") or []
    if not any(ref_matches(target_ref, str(p), target_ref) for p in includes):
        return False
    if any(ref_matches(target_ref, str(p), target_ref) for p in excludes):
        return False
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", required=True)
    parser.add_argument("--policy", type=Path, required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    policy = json.loads(args.policy.read_text(encoding="utf-8"))
    if policy.get("contract") != "mad4b.repository-governance-policy.v1":
        raise SystemExit("repository governance policy contract mismatch")

    target_ref = str(policy.get("target_ref") or "refs/heads/master")
    listed = gh_json(args.repository, "rulesets?includes_parents=true")
    if not isinstance(listed, list):
        raise SystemExit("GitHub rulesets response is not a list")

    details = []
    for row in listed:
        ruleset_id = row.get("id") if isinstance(row, dict) else None
        if not ruleset_id:
            continue
        details.append(gh_json(args.repository, f"rulesets/{ruleset_id}?includes_parents=true"))

    applicable = [row for row in details if isinstance(row, dict) and applies_to_target(row, target_ref)]
    if not applicable:
        raise SystemExit(f"no active repository ruleset applies to {target_ref}")

    if policy.get("require_no_bypass_actors") is True:
        missing_bypass_evidence = [
            {"id": row.get("id"), "name": row.get("name"), "source_type": row.get("source_type")}
            for row in applicable
            if "bypass_actors" not in row
        ]
        if missing_bypass_evidence:
            raise SystemExit(
                "bypass-actor evidence is unavailable for an applicable ruleset: "
                + repr(missing_bypass_evidence)
            )
        bypass = [
            {"id": row.get("id"), "name": row.get("name"), "bypass_actors": row.get("bypass_actors")}
            for row in applicable
            if row.get("bypass_actors")
        ]
        if bypass:
            raise SystemExit(f"applicable ruleset contains bypass actors: {bypass}")

    expected_ruleset_name = str(
        policy.get("required_repository_ruleset_name")
        or "MAD4B master release governance"
    )
    governed_rulesets = [
        row
        for row in applicable
        if str(row.get("name") or "") == expected_ruleset_name
        and str(row.get("source_type") or "") == "Repository"
    ]
    if len(governed_rulesets) != 1:
        raise SystemExit(
            "exactly one active repository-owned canonical MAD4B ruleset must apply: "
            + repr(
                [
                    {
                        "id": row.get("id"),
                        "name": row.get("name"),
                        "source_type": row.get("source_type"),
                        "source": row.get("source"),
                    }
                    for row in governed_rulesets
                ]
            )
        )
    governed_ruleset = governed_rulesets[0]
    if str(governed_ruleset.get("source") or "") not in {"", args.repository}:
        raise SystemExit(
            "canonical MAD4B ruleset source does not match repository: "
            + repr(governed_ruleset.get("source"))
        )

    governed_rules = [
        rule
        for rule in (governed_ruleset.get("rules") or [])
        if isinstance(rule, dict)
    ]
    required_types = set(policy.get("required_rule_types") or [])
    present_types = {str(rule.get("type") or "") for rule in governed_rules}
    if present_types != required_types:
        raise SystemExit(
            "canonical MAD4B ruleset rule types drift: "
            f"expected={sorted(required_types)!r} actual={sorted(present_types)!r}"
        )
    rule_counts = {
        rule_type: sum(
            1 for rule in governed_rules
            if str(rule.get("type") or "") == rule_type
        )
        for rule_type in required_types
    }
    duplicate_or_missing = {
        rule_type: count
        for rule_type, count in rule_counts.items()
        if count != 1
    }
    if duplicate_or_missing:
        raise SystemExit(
            "canonical MAD4B ruleset must contain exactly one rule per governed type: "
            + repr(duplicate_or_missing)
        )

    for simple_type in ("deletion", "non_fast_forward"):
        if simple_type in required_types:
            simple_rule = next(
                rule for rule in governed_rules
                if rule.get("type") == simple_type
            )
            if set(simple_rule) != {"type"}:
                raise SystemExit(
                    f"canonical {simple_type} rule contains unexpected parameters"
                )

    pr_policy = policy.get("pull_request") or {}
    pull_rule = next(
        rule for rule in governed_rules
        if rule.get("type") == "pull_request"
    )
    pull_params = pull_rule.get("parameters") or {}
    allowed_pull_fields = {
        "allowed_merge_methods",
        "dismiss_stale_reviews_on_push",
        "require_code_owner_review",
        "require_last_push_approval",
        "required_approving_review_count",
        "required_review_thread_resolution",
        "required_reviewers",
        "require_extra_approval_for_unattributed_changes",
    }
    unknown_pull_fields = sorted(set(pull_params) - allowed_pull_fields)
    if unknown_pull_fields:
        raise SystemExit(
            "canonical pull_request rule contains ungoverned parameters: "
            + repr(unknown_pull_fields)
        )
    expected_merge_methods = set(pr_policy.get("allowed_merge_methods") or [])
    expected_required_reviewers = list(pr_policy.get("required_reviewers") or [])
    expected_unattributed_approval = bool(
        pr_policy.get("require_extra_approval_for_unattributed_changes_readback", True)
    )
    pull_ready = (
        int(pull_params.get("required_approving_review_count", -1))
        == int(pr_policy.get("required_approving_review_count", 0))
        and bool(pull_params.get("dismiss_stale_reviews_on_push"))
        == bool(pr_policy.get("dismiss_stale_reviews_on_push", False))
        and bool(pull_params.get("require_code_owner_review"))
        == bool(pr_policy.get("require_code_owner_review", False))
        and bool(pull_params.get("require_last_push_approval"))
        == bool(pr_policy.get("require_last_push_approval", False))
        and bool(pull_params.get("required_review_thread_resolution"))
        == bool(pr_policy.get("required_review_thread_resolution", True))
        and list(pull_params.get("required_reviewers") or [])
        == expected_required_reviewers
        and "require_extra_approval_for_unattributed_changes" in pull_params
        and bool(
            pull_params.get("require_extra_approval_for_unattributed_changes")
        )
        == expected_unattributed_approval
        and set(pull_params.get("allowed_merge_methods") or [])
        == expected_merge_methods
    )
    if not pull_ready:
        raise SystemExit(
            "canonical pull_request rule does not satisfy exact governed review/merge policy"
        )

    status_policy = policy.get("required_status_checks") or {}
    required_rows = status_policy.get("contexts") or []
    required_checks = {
        (str(row.get("context") or ""), int(row.get("integration_id") or 0))
        for row in required_rows
        if isinstance(row, dict)
    }
    if not required_checks or any(
        not context or integration_id < 1
        for context, integration_id in required_checks
    ):
        raise SystemExit(
            "repository governance policy must pin every required check to a source integration"
        )

    status_rule = next(
        rule for rule in governed_rules
        if rule.get("type") == "required_status_checks"
    )
    status_params = status_rule.get("parameters") or {}
    allowed_status_fields = {
        "do_not_enforce_on_create",
        "required_status_checks",
        "strict_required_status_checks_policy",
    }
    unknown_status_fields = sorted(set(status_params) - allowed_status_fields)
    if unknown_status_fields:
        raise SystemExit(
            "canonical required_status_checks rule contains ungoverned parameters: "
            + repr(unknown_status_fields)
        )
    actual_checks = {
        (str(row.get("context") or ""), int(row.get("integration_id") or 0))
        for row in status_params.get("required_status_checks") or []
        if isinstance(row, dict)
    }
    if actual_checks != required_checks:
        raise SystemExit(
            "canonical required status checks drift: "
            f"expected={sorted(required_checks)!r} actual={sorted(actual_checks)!r}"
        )
    if bool(status_params.get("strict_required_status_checks_policy")) != bool(
        status_policy.get("strict_required_status_checks_policy", True)
    ):
        raise SystemExit("canonical strict required-status-check policy drift")
    if bool(status_params.get("do_not_enforce_on_create", False)):
        raise SystemExit("canonical required status checks must enforce on branch creation")

    result = {
        "contract": "mad4b.repository-governance-status.v1",
        "repository": args.repository,
        "target_ref": target_ref,
        "ready": True,
        "applicable_ruleset_ids": [row.get("id") for row in applicable],
        "applicable_ruleset_names": [row.get("name") for row in applicable],
        "governed_ruleset_id": governed_ruleset.get("id"),
        "governed_ruleset_name": governed_ruleset.get("name"),
        "governed_ruleset_source_type": governed_ruleset.get("source_type"),
        "inherited_or_additional_applicable_ruleset_ids": [
            row.get("id") for row in applicable
            if row.get("id") != governed_ruleset.get("id")
        ],
        "required_rule_types": sorted(required_types),
        "required_status_checks": [
            {"context": context, "integration_id": integration_id}
            for context, integration_id in sorted(required_checks)
        ],
        "strict_required_status_checks_policy": True,
        "bypass_actor_count": 0,
        "allowed_merge_methods": sorted(expected_merge_methods),
        "required_reviewers": expected_required_reviewers,
        "require_extra_approval_for_unattributed_changes": expected_unattributed_approval,
        "single_owner_safe": True,
    }
    encoded = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(encoded, encoding="utf-8")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
