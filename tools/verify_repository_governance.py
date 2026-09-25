#!/usr/bin/env python3
"""Fail closed unless GitHub repository rulesets enforce the MAD4B master policy."""

from __future__ import annotations

import argparse
import fnmatch
import hashlib
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


def public_json(repository: str, path: str):
    endpoint = f"repos/{repository}/{path.lstrip('/')}"
    url = "https://api.github.com/" + endpoint
    req = urllib.request.Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "User-Agent": "mad4b-repository-governance-contract",
            "X-GitHub-Api-Version": "2026-03-10",
        },
    )
    with urllib.request.urlopen(req, timeout=20) as response:
        return json.loads(response.read().decode("utf-8"))


def validate_ruleset_attestation(
    raw_value: str,
    repository: str,
    ruleset: dict,
    policy: dict,
    policy_path: Path,
    template_path: Path | None,
) -> dict:
    config = policy.get("ruleset_attestation") or {}
    expected_config = {
        "scope": "owner_issue_comment",
        "issue_title": "MAD4B Repository Governance Attestations",
        "authorized_author_login": "mad4bdigital-ai",
        "comment_marker": "MAD4B_RULESET_ATTESTATION",
        "contract": "mad4b.repository-ruleset-attestation.v1",
        "require_zero_bypass_actors": True,
        "bind_ruleset_updated_at": True,
        "bind_policy_sha256": True,
        "bind_template_sha256": True,
    }
    if config != expected_config:
        raise SystemExit("repository ruleset attestation policy is missing or drifted")
    if template_path is None or not template_path.is_file():
        raise SystemExit("ruleset attestation verification requires the canonical template")
    if not raw_value.strip():
        raise SystemExit("ruleset bypass evidence is hidden and repository attestation variable is empty")
    try:
        attestation = json.loads(raw_value)
    except json.JSONDecodeError as exc:
        raise SystemExit(f"repository ruleset attestation variable is invalid JSON: {exc}") from exc
    if not isinstance(attestation, dict):
        raise SystemExit("repository ruleset attestation must be a JSON object")
    if attestation.get("contract") != expected_config["contract"]:
        raise SystemExit("repository ruleset attestation contract mismatch")
    expected = {
        "attestation_scope": "owner_issue_comment",
        "attestation_issue_title": "MAD4B Repository Governance Attestations",
        "attestation_author_login": "mad4bdigital-ai",
        "attestation_comment_marker": "MAD4B_RULESET_ATTESTATION",
        "repository": repository,
        "ruleset_id": int(ruleset.get("id") or 0),
        "ruleset_name": str(ruleset.get("name") or ""),
        "ruleset_source_type": "Repository",
        "ruleset_source": repository,
        "ruleset_updated_at": str(ruleset.get("updated_at") or ""),
        "bypass_actor_count": 0,
        "require_extra_approval_for_unattributed_changes": True,
        "policy_sha256": hashlib.sha256(policy_path.read_bytes()).hexdigest(),
        "template_sha256": hashlib.sha256(template_path.read_bytes()).hexdigest(),
        "verified_readback": True,
    }
    for key, value in expected.items():
        if attestation.get(key) != value:
            raise SystemExit(
                f"repository ruleset attestation is stale or mismatched: {key} "
                f"expected={value!r} actual={attestation.get(key)!r}"
            )
    if not expected["ruleset_updated_at"]:
        raise SystemExit("live ruleset updated_at is unavailable for attestation freshness")
    return attestation


def load_owner_ruleset_attestation(
    repository: str,
    ruleset: dict,
    policy: dict,
    policy_path: Path,
    template_path: Path | None,
) -> dict:
    config = policy.get("ruleset_attestation") or {}
    issue_title = str(config.get("issue_title") or "")
    owner_login = str(config.get("authorized_author_login") or "").lower()
    marker = str(config.get("comment_marker") or "")
    if issue_title != "MAD4B Repository Governance Attestations":
        raise SystemExit("ruleset attestation issue title drifted")
    if owner_login != "mad4bdigital-ai":
        raise SystemExit("ruleset attestation authorized owner drifted")
    if marker != "MAD4B_RULESET_ATTESTATION":
        raise SystemExit("ruleset attestation comment marker drifted")

    owner_issues = []
    page = 1
    while True:
        rows = gh_json(repository, f"issues?state=all&per_page=100&page={page}")
        if not isinstance(rows, list):
            raise SystemExit("GitHub issues response is not a list")
        for row in rows:
            if not isinstance(row, dict) or "pull_request" in row:
                continue
            if str(row.get("title") or "") != issue_title:
                continue
            if str(((row.get("user") or {}).get("login") or "")).lower() != owner_login:
                continue
            issue_number = int(row.get("number") or 0)
            if issue_number > 0:
                owner_issues.append(issue_number)
        if len(rows) < 100:
            break
        page += 1
        if page > 50:
            raise SystemExit("ruleset attestation issue pagination exceeded safety bound")

    if not owner_issues:
        raise SystemExit("owner-authored repository governance attestation issue is missing")

    matches = []
    stale = 0
    prefix = marker + "\n"
    for issue_number in sorted(set(owner_issues)):
        page = 1
        while True:
            comments = gh_json(
                repository,
                f"issues/{issue_number}/comments?per_page=100&page={page}",
            )
            if not isinstance(comments, list):
                raise SystemExit("GitHub attestation comments response is not a list")
            for row in comments:
                if not isinstance(row, dict):
                    continue
                if str(((row.get("user") or {}).get("login") or "")).lower() != owner_login:
                    continue
                body = str(row.get("body") or "").strip()
                if not body.startswith(prefix):
                    continue
                raw_value = body[len(prefix):].strip()
                try:
                    attestation = validate_ruleset_attestation(
                        raw_value,
                        repository,
                        ruleset,
                        policy,
                        policy_path,
                        template_path,
                    )
                except SystemExit:
                    stale += 1
                    continue
                matches.append(
                    {
                        "comment_id": int(row.get("id") or 0),
                        "issue_number": issue_number,
                        "created_at": str(row.get("created_at") or ""),
                        "updated_at": str(row.get("updated_at") or ""),
                        "attestation": attestation,
                    }
                )
            if len(comments) < 100:
                break
            page += 1
            if page > 50:
                raise SystemExit("ruleset attestation comment pagination exceeded safety bound")

    if not matches:
        raise SystemExit(
            "no owner-authored ruleset attestation comment matches the current governed ruleset"
        )
    matches.sort(key=lambda item: (item["comment_id"], item["updated_at"]))
    selected = matches[-1]
    selected["stale_owner_attestation_count"] = stale
    return selected


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
    parser.add_argument("--template", type=Path)
    parser.add_argument("--allow-bootstrap-hidden-bypass-evidence", action="store_true")
    parser.add_argument("--require-ruleset-attestation", action="store_true")
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
        detail_path = f"rulesets/{ruleset_id}?includes_parents=true"
        detail = gh_json(args.repository, detail_path)
        if not isinstance(detail, dict):
            raise SystemExit(f"ruleset detail is not an object: id={ruleset_id}")
        if isinstance(row, dict):
            for metadata_key in (
                "id",
                "name",
                "target",
                "source_type",
                "source",
                "enforcement",
                "created_at",
                "updated_at",
            ):
                if metadata_key not in detail and metadata_key in row:
                    detail[metadata_key] = row[metadata_key]
        if (
            isinstance(detail, dict)
            and "bypass_actors" not in detail
            and str(detail.get("source_type") or row.get("source_type") or "") == "Repository"
        ):
            try:
                public_detail = public_json(args.repository, detail_path)
            except Exception:
                public_detail = None
            if isinstance(public_detail, dict):
                for key, value in public_detail.items():
                    if key not in detail:
                        detail[key] = value
        details.append(detail)

    applicable = [row for row in details if isinstance(row, dict) and applies_to_target(row, target_ref)]
    if not applicable:
        raise SystemExit(f"no active repository ruleset applies to {target_ref}")

    expected_ruleset_name = str(
        policy.get("required_repository_ruleset_name")
        or "MAD4B master release governance"
    )
    bypass_evidence_sources = {}
    ruleset_attestation_verified = False
    ruleset_attestation = None
    if args.allow_bootstrap_hidden_bypass_evidence and (
        "ruleset_attestation" in policy or "required_repository_ruleset_name" in policy
    ):
        raise SystemExit(
            "bootstrap hidden-bypass exception is valid only against the legacy PR-base policy"
        )

    if policy.get("require_no_bypass_actors") is True:
        for row in applicable:
            row_id = int(row.get("id") or 0)
            if "bypass_actors" in row:
                if row.get("bypass_actors"):
                    raise SystemExit(
                        "applicable ruleset contains bypass actors: "
                        + repr({
                            "id": row.get("id"),
                            "name": row.get("name"),
                            "bypass_actors": row.get("bypass_actors"),
                        })
                    )
                bypass_evidence_sources[str(row_id)] = "direct_ruleset_detail"
                continue

            canonical_local = (
                str(row.get("name") or "") == expected_ruleset_name
                and str(row.get("source_type") or "") == "Repository"
                and str(row.get("source") or "") in {"", args.repository}
            )
            if not canonical_local:
                raise SystemExit(
                    "bypass-actor evidence is unavailable for an applicable non-canonical ruleset: "
                    + repr({
                        "id": row.get("id"),
                        "name": row.get("name"),
                        "source_type": row.get("source_type"),
                    })
                )

            if args.allow_bootstrap_hidden_bypass_evidence:
                bypass_evidence_sources[str(row_id)] = (
                    "one_time_pr66_bootstrap_owner_attestation_required"
                )
                continue

            selected_owner_attestation = load_owner_ruleset_attestation(
                args.repository,
                row,
                policy,
                args.policy,
                args.template,
            )
            ruleset_attestation = selected_owner_attestation["attestation"]
            ruleset_attestation_verified = True
            bypass_evidence_sources[str(row_id)] = "owner_issue_comment"

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

    if args.require_ruleset_attestation:
        if args.allow_bootstrap_hidden_bypass_evidence:
            raise SystemExit(
                "ruleset attestation cannot be required while the bootstrap hidden-bypass exception is active"
            )
        selected_owner_attestation = load_owner_ruleset_attestation(
            args.repository,
            governed_ruleset,
            policy,
            args.policy,
            args.template,
        )
        ruleset_attestation = selected_owner_attestation["attestation"]
        ruleset_attestation_verified = True
        bypass_evidence_sources.setdefault(
            str(int(governed_ruleset.get("id") or 0)),
            "direct_ruleset_detail+owner_issue_comment",
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
    response_only_approval_evidence_source = "direct_ruleset_detail"
    if "require_extra_approval_for_unattributed_changes" in pull_params:
        response_only_approval_ready = (
            bool(pull_params.get("require_extra_approval_for_unattributed_changes"))
            == expected_unattributed_approval
        )
    elif args.allow_bootstrap_hidden_bypass_evidence:
        response_only_approval_ready = expected_unattributed_approval is True
        response_only_approval_evidence_source = (
            "one_time_pr66_bootstrap_owner_attestation_required"
        )
    else:
        if ruleset_attestation is None:
            selected_owner_attestation = load_owner_ruleset_attestation(
                args.repository,
                governed_ruleset,
                policy,
                args.policy,
                args.template,
            )
            ruleset_attestation = selected_owner_attestation["attestation"]
            ruleset_attestation_verified = True
        response_only_approval_ready = (
            ruleset_attestation.get(
                "require_extra_approval_for_unattributed_changes"
            )
            is expected_unattributed_approval
        )
        response_only_approval_evidence_source = "owner_issue_comment"

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
        and response_only_approval_ready
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
        "bypass_evidence_sources": bypass_evidence_sources,
        "ruleset_attestation_verified": ruleset_attestation_verified,
        "ruleset_attestation_required": bool(args.require_ruleset_attestation),
        "ruleset_attestation_scope": "owner_issue_comment",
        "ruleset_attestation_issue_title": "MAD4B Repository Governance Attestations",
        "ruleset_attestation_author_login": "mad4bdigital-ai",
        "response_only_approval_evidence_source": response_only_approval_evidence_source,
        "bootstrap_hidden_bypass_exception": bool(
            args.allow_bootstrap_hidden_bypass_evidence
        ),
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
