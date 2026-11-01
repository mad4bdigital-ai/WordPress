#!/usr/bin/env python3
"""Fail-closed, offline diagnostic for original-source Full Enforcement gaps.

This is NOT a privilege grant or site-flag writer. Evidence originates from an
independent read-only WP-CLI runtime diagnostic; no client-provided synthetic
origin/effect SHA may qualify a lane for mutation or for enabling `enforce`.
"""
from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path

CONTRACT = "mad4b.hub.full-enforcement-shadow-evidence.v1"
ACTIVE_STATES = frozenset({
    "QUEUED", "RETRY", "WAITING_EXECUTOR", "WAITING_EXTERNAL", "RUNNING",
    "CLAIMED", "NEEDS_RECONCILIATION", "CANCEL_REQUESTED",
})
REMEDIATIONS = {
    "legacy_unlinked_origin_requires_review": "original_provider_identity_and_receipt_reconciliation",
    "origin_not_verified": "original_native_event_and_source_authority",
    "stale_job_stage": "original_content_job_revision_stage_readback",
    "context_": "original_source_policy_capability_digest_readback",
    "bound_revision_missing_or_stale": "original_job_revision_binding",
    "canonical_effect_must_be_verified_read_only": "canonical_provider_effect_readback",
    "diagnostic_source_unverified": "independent_diagnostic_source_proof",
}


def _required(condition: bool, code: str) -> None:
    if not condition:
        raise ValueError(code)


def classify(reason: str) -> str:
    if reason == "ELIGIBLE":
        return "qualified_readback_not_full_enforcement_authority"
    for marker, category in REMEDIATIONS.items():
        if marker in reason:
            return category
    return "unclassified_original_provider_review_required"


def evaluate(evidence: dict, expected_sha: str) -> dict:
    _required(isinstance(evidence, dict), "evidence_not_object")
    _required(evidence.get("contract") == CONTRACT, "wrong_contract")
    _required(evidence.get("source_master_sha") == expected_sha, "exact_source_mismatch")
    _required(evidence.get("environment") == "staging", "environment_not_staging")
    _required(evidence.get("site") == "https://tours.dev.mad4b.com", "site_mismatch")
    _required(evidence.get("database") == "etg_commerce_staging", "database_mismatch")
    _required(evidence.get("feature_mode") == "certified_only", "unexpected_effective_mode")
    _required(evidence.get("full_enforce_execution_performed") is False, "unexpected_mutation")
    _required(evidence.get("legacy_locked") is True, "legacy_not_locked")
    _required(evidence.get("original_legacy_count") == 20, "legacy_count_changed")
    _required(evidence.get("original_task_count") == 322, "original_queue_count_changed")
    digest = evidence.get("original_legacy_digest")
    _required(isinstance(digest, str) and len(digest) == 64 and
              all(c in "0123456789abcdef" for c in digest), "legacy_digest_invalid")
    records = evidence.get("classifications")
    _required(isinstance(records, list), "classification_list_missing")
    _required(type(evidence.get("active_total")) is int, "active_total_invalid")
    aggregate: Counter[str] = Counter()
    lanes: Counter[str] = Counter()
    blocked = qualified = 0
    for row in records:
        _required(isinstance(row, dict), "classification_not_object")
        reason, state, op, count = (row.get(x) for x in ("reason", "state", "operation", "count"))
        _required(isinstance(reason, str) and reason, "reason_missing")
        _required(state in ACTIVE_STATES, "not_active_state")
        _required(isinstance(op, str) and op and len(op) <= 128, "operation_invalid")
        _required(type(count) is int and count >= 1, "classification_count_invalid")
        category = classify(reason)
        aggregate[category] += count
        lanes[op] += count
        if reason == "ELIGIBLE":
            qualified += count
        else:
            blocked += count
    _required(blocked + qualified == evidence["active_total"], "active_count_mismatch")
    # Never infer high-impact approval from a zero-blocker snapshot: required
    # independent provider contracts, signatures and effect readbacks remain
    # separate from this diagnostic. Also avoid rewriting legacy histories.
    return {
        "contract": "mad4b.hub.full-enforcement-shadow-verdict.v1",
        "source_master_sha": expected_sha,
        "status": "BLOCKED_PENDING_INDEPENDENT_PROVIDER_CERTIFICATION",
        "enable_full_enforce_authorized": False,
        "staging_certified_only_preserved": True,
        "active_task_count": blocked + qualified,
        "active_requires_original_proof": blocked,
        "active_plan_eligible": qualified,
        "required_remediation": dict(sorted(aggregate.items())),
        "operation_counts": dict(sorted(lanes.items())),
        "fail_closed": True,
        "requires": [
            "provider_specific_original_receipts_and_independent_readbacks",
            "explicit_effect_specific_grants_and_rollback_receipts",
            "new_task_canaries_per_operation_type",
            "cross_process_claim_finish_receipt_and_compensation_tests",
            "fresh_exact_source_release_verdict_before_any_mode_change",
        ],
        "mutation_performed": False,
        "production_mutation": False,
    }


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--input", type=Path, required=True)
    p.add_argument("--expected-master", required=True)
    p.add_argument("--output", type=Path)
    args = p.parse_args()
    result = evaluate(json.loads(args.input.read_text()), args.expected_master)
    payload = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.write_text(payload)
    else:
        print(payload, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
