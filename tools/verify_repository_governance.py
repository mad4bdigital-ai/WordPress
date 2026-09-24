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
        bypass = [
            {"id": row.get("id"), "name": row.get("name"), "bypass_actors": row.get("bypass_actors")}
            for row in applicable
            if row.get("bypass_actors")
        ]
        if bypass:
            raise SystemExit(f"applicable ruleset contains bypass actors: {bypass}")

    all_rules = []
    for ruleset in applicable:
        for rule in ruleset.get("rules") or []:
            if isinstance(rule, dict):
                all_rules.append((ruleset, rule))

    required_types = set(policy.get("required_rule_types") or [])
    present_types = {str(rule.get("type") or "") for _, rule in all_rules}
    missing_types = sorted(required_types - present_types)
    if missing_types:
        raise SystemExit(f"required repository rule types missing: {missing_types}")

    pr_policy = policy.get("pull_request") or {}
    pull_rules = [rule for _, rule in all_rules if rule.get("type") == "pull_request"]
    if not pull_rules:
        raise SystemExit("pull_request rule missing")
    if not any(
        int((rule.get("parameters") or {}).get("required_approving_review_count", -1))
        == int(pr_policy.get("required_approving_review_count", 0))
        and bool((rule.get("parameters") or {}).get("require_last_push_approval"))
        == bool(pr_policy.get("require_last_push_approval", False))
        and bool((rule.get("parameters") or {}).get("required_review_thread_resolution"))
        == bool(pr_policy.get("required_review_thread_resolution", True))
        for rule in pull_rules
    ):
        raise SystemExit("pull_request rule does not satisfy single-owner-safe review policy")

    status_policy = policy.get("required_status_checks") or {}
    required_contexts = set(status_policy.get("contexts") or [])
    status_rules = [rule for _, rule in all_rules if rule.get("type") == "required_status_checks"]
    matched_status_rule = None
    for rule in status_rules:
        params = rule.get("parameters") or {}
        contexts = {
            str(row.get("context") or "")
            for row in params.get("required_status_checks") or []
            if isinstance(row, dict)
        }
        if (
            required_contexts.issubset(contexts)
            and bool(params.get("strict_required_status_checks_policy"))
            == bool(status_policy.get("strict_required_status_checks_policy", True))
        ):
            matched_status_rule = rule
            break
    if matched_status_rule is None:
        raise SystemExit(
            f"required status-check policy missing: contexts={sorted(required_contexts)} strict=true"
        )

    result = {
        "contract": "mad4b.repository-governance-status.v1",
        "repository": args.repository,
        "target_ref": target_ref,
        "ready": True,
        "applicable_ruleset_ids": [row.get("id") for row in applicable],
        "applicable_ruleset_names": [row.get("name") for row in applicable],
        "required_rule_types": sorted(required_types),
        "required_status_checks": sorted(required_contexts),
        "strict_required_status_checks_policy": True,
        "bypass_actor_count": 0,
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
