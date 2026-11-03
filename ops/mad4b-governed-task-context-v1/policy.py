#!/usr/bin/env python3
"""Governed, bounded, side-effect-free task-context deepening.

This module NEVER inserts, claims, cancels, merges or executes a task.
Its classifications are evidence plans, not execution permissions.
"""
from __future__ import annotations
from dataclasses import dataclass
from hashlib import sha256
import json
import re

CONTRACT = "mad4b.task-context-deepening.v1"
SENSITIVE_STAGES = frozenset({
    "PUBLISHING", "COMPANY_DISTRIBUTION", "PRICING", "RATE_GENERATION",
    "AVAILABILITY", "CHECKOUT_QA", "MEDIA_RIGHTS", "WPML_TRANSLATION",
    "JETENGINE_SYNC", "CANONICAL_RELEASE", "POST_PUBLISH",
})
ACTIVE = frozenset({"QUEUED", "WAITING_EXECUTOR", "WAITING_EXTERNAL", "RETRY",
                    "RUNNING", "CLAIMED", "LEASED", "IN_PROGRESS",
                    "NEEDS_RECONCILIATION"})
UNCERTAIN = frozenset({"RUNNING", "CLAIMED", "LEASED", "IN_PROGRESS",
                      "NEEDS_RECONCILIATION", "CANCEL_REQUESTED"})
SHA = re.compile(r"^[0-9a-f]{64}$")
MAX_EVIDENCE_READS = 16
MAX_ARTIFACTS = 6
MAX_EVENTS = 30
MAX_CONTEXT_BYTES = 524288

# Deeper inspection is conditional; absence of an essential proof is a stop,
# not permission to deduplicate.
DEPTH = {
  "L0": "work identity and lifecycle state",
  "L1": "original scope and active Content Job revision",
  "L2": "semantic inputs, output/effect and policy/provider versions",
  "L3": "origin trust, authoritative source and dependency receipts",
  "L4": "execution attempt, durable artifacts and independent readback",
}

def digest(value):
    return sha256(json.dumps(value, sort_keys=True, ensure_ascii=False,
                             separators=(",", ":")).encode()).hexdigest()

def report(code, depth, reasons, missing=(), same_work=False, **details):
    return {
        "contract": CONTRACT,
        "decision": code,
        "depth": depth,
        "same_work_candidate": bool(same_work),
        "safe_to_auto_merge": False,
        "safe_to_cancel_task": False,
        "executor_authorized": False,
        "new_grant_issued": False,
        "recommended_action": {
            "DIFFERENT_SCOPE": "KEEP_SEPARATE",
            "DIFFERENT_WORK": "KEEP_SEPARATE",
            "CROSS_JOB_REVIEW": "REVIEW_JOB_LINEAGE_AND_ARTIFACTS",
            "STALE_JOB_REVISION": "RECHECK_ORIGINAL_JOB_REVISION",
            "HISTORICAL_RELATED": "RETAIN_AUDIT_HISTORY",
            "EFFECT_RECONCILIATION": "INDEPENDENT_RECEIPT_READBACK_NO_REPLAY",
            "NEW_SEMANTIC_ITERATION": "VERSIONED_ITERATION_REVIEW",
            "NEEDS_CONTEXT": "COLLECT_BOUNDED_EVIDENCE",
            "NEEDS_ORIGIN": "VERIFY_ORIGINAL_TRIGGER_AND_SOURCE",
            "NEEDS_DEPENDENCIES": "VERIFY_DEPENDENCY_OUTPUT_RECEIPTS",
            "SINGLE_FLIGHT_CANDIDATE": "PLAN_ATOMIC_ORIGINAL_QUEUE_SINGLE_FLIGHT",
            "HIGH_IMPACT_REVIEW": "HUMAN_OR_ORIGINAL_POLICY_REVIEW",
            "VERIFIED_RESULT_REUSE_CANDIDATE": "VERIFY_RESULT_COMPATIBILITY_AND_ORIGINAL_POLICY",
        }.get(code, "MANUAL_REVIEW"),
        "reasons": list(reasons),
        "required_evidence": list(missing),
        "limits": {"max_reads": MAX_EVIDENCE_READS,
                   "max_artifacts": MAX_ARTIFACTS,
                   "max_events": MAX_EVENTS,
                   "max_context_bytes": MAX_CONTEXT_BYTES},
        **details
    }

def required(a, b, paths):
    missing = []
    for prefix, path in (("A", a), ("B", b)):
        for p in paths:
            d = path
            for token in p.split("."):
                d = d.get(token) if isinstance(d, dict) else None
            if d is None or d == "":
                missing.append(prefix + "." + p)
    return missing

def at(record, dotted, default=None):
    d = record
    for token in dotted.split("."):
        d = d.get(token) if isinstance(d, dict) else None
    return default if d is None else d

def compare(a, b):
    """Compare two authoritative read-models. No string-based owner inference."""
    if not isinstance(a, dict) or not isinstance(b, dict):
        return report("NEEDS_CONTEXT", "L0", ["invalid_read_model"], ["two_record_objects"])

    base = ["task.stage", "task.operation", "task.state", "task.batch_state",
            "job.job_id", "job.site_uuid", "job.tenant_id",
            "job.brand_id", "job.language", "job.market", "job.content_type"]
    missing = required(a, b, base)
    if missing:
        return report("NEEDS_CONTEXT", "L0", ["basic_identity_unverified"], missing)

    scope = ["job.site_uuid", "job.tenant_id", "job.brand_id",
             "job.language", "job.market", "job.content_type"]
    if any(at(a, p) != at(b, p) for p in scope):
        return report("DIFFERENT_SCOPE", "L1", ["original_scope_differs"])

    if at(a, "job.job_id") != at(b, "job.job_id"):
        return report("CROSS_JOB_REVIEW", "L1",
            ["two_original_content_job_aggregates",
             "matching_subject_does_not_prove_same_job",
             "artifact_lineage_and_original_owner_must_be_checked"],
            ["artifacts.content_sha256", "job.subject_sha256",
             "job.original_intent_id"], same_work=True)

    if any(at(a, p) != at(b, p) for p in ("task.stage", "task.operation",
                                           "task.target_ref")):
        return report("DIFFERENT_WORK", "L1", ["different_stage_operation_or_target"])
    revision = required(a, b, ["job.revision", "job.stage", "job.state",
                               "task.bound_revision", "task.attempts"])
    if revision:
        return report("NEEDS_CONTEXT", "L1", ["original_job_revision_missing"], revision)
    if at(a, "job.revision") != at(b, "job.revision") or any(
      at(r, "task.bound_revision") != at(r, "job.revision") for r in (a, b)):
        return report("STALE_JOB_REVISION", "L1",
                      ["different_or_stale_original_revision"])
    if any(at(r, "job.stage") != at(r, "task.stage") for r in (a, b)):
        return report("STALE_JOB_REVISION", "L1", ["job_stage_changed"])
    if any(at(r, "job.state") in ("CANCELLED", "COMPLETED", "FAILED") for r in (a, b)):
        return report("HISTORICAL_RELATED", "L1",
                      ["terminal_original_job_requires_history_not_execution"])
    if any(at(r, "task.state") not in ACTIVE or
           at(r, "task.batch_state") != "ACTIVE" for r in (a, b)):
        return report("HISTORICAL_RELATED", "L1",
                      ["inactive_or_historical_task_must_not_be_rescheduled"])

    # Costly deeper reads only for a live collision sharing exact original scope.
    semantic = ["input.semantic_sha256", "input.source_snapshot_sha256",
                "input.capability_sha256", "input.policy_snapshot_sha256",
                "input.output_contract", "input.effect_type"]
    missing = required(a, b, semantic)
    if missing:
        return report("NEEDS_CONTEXT", "L2", ["semantic_identity_incomplete"], missing,
                      same_work=True)
    if any(not SHA.fullmatch(at(r,p)) for r in (a,b) for p in semantic[:4]):
        return report("NEEDS_CONTEXT", "L2", ["input_fingerprint_invalid"],
                      semantic[:4], same_work=True)
    if any(at(a, p) != at(b, p) for p in semantic):
        return report("NEW_SEMANTIC_ITERATION", "L2", [
            "source_content_capability_policy_output_or_effect_differs",
            "new_evidence_must_not_be_deduplicated"], same_work=True)

    # Trust is required independently of SHA equality. Legacy queues without
    # WorkflowOrigin are inspected but are never auto-merged.
    origin_required = ["provenance.authoritative_scope_verified",
                       "provenance.origin_verified",
                       "provenance.source_identity_verified"]
    missing = required(a, b, origin_required)
    if missing or any(at(r, p) is not True for r in (a, b)
                      for p in origin_required):
        return report("NEEDS_ORIGIN", "L3",
           ["original_origin_or_source_proof_not_independently_verified",
            "trigger_event_id_is_a_receipt_not_a_semantic_work_key"],
           missing or origin_required, same_work=True)
    source_proofs = ["provenance.source_version_sha256", "provenance.source_reference_sha256"]
    missing = required(a, b, source_proofs)
    if missing:
        return report("NEEDS_ORIGIN", "L3", ["source_fingerprint_unverified"],
                      missing, same_work=True)
    if any(not SHA.fullmatch(at(r,p)) for r in (a,b) for p in source_proofs):
        return report("NEEDS_ORIGIN", "L3", ["source_fingerprint_invalid"],
                      source_proofs, same_work=True)
    if any(at(a, p) != at(b, p) for p in source_proofs):
        return report("NEW_SEMANTIC_ITERATION", "L3",
           ["different_authoritative_source_version"], same_work=True)
    dep = ["dependencies.receipts_sha256",
           "dependencies.all_predecessors_completed",
           "dependencies.expected_artifact_contract"]
    missing = required(a, b, dep)
    if missing or any(at(r, "dependencies.all_predecessors_completed") is not True
                      for r in (a, b)):
        return report("NEEDS_DEPENDENCIES", "L3",
           ["task_key_matches_but_predecessor_receipt_not_verified"],
           missing or ["dependencies.all_predecessors_completed"], same_work=True)
    if any(not SHA.fullmatch(at(r, "dependencies.receipts_sha256")) for r in (a,b)):
        return report("NEEDS_DEPENDENCIES", "L3", ["receipt_fingerprint_invalid"],
                      ["dependencies.receipts_sha256"], same_work=True)
    if (at(a, "dependencies.receipts_sha256") !=
        at(b, "dependencies.receipts_sha256") or
        at(a, "dependencies.expected_artifact_contract") !=
        at(b, "dependencies.expected_artifact_contract")):
        return report("NEW_SEMANTIC_ITERATION", "L3",
           ["dependency_evidence_or_expected_artifact_differs"], same_work=True)

    # An attempted task is EFFECT_UNCERTAIN until the original independent
    # receipt says otherwise. Never cancel/replay because inputs look similar.
    if any((int(at(r, "task.attempts", 0) or 0) > 0 or
            at(r, "task.state") in UNCERTAIN) for r in (a, b)):
        return report("EFFECT_RECONCILIATION", "L4",
                      ["possible_side_effect_or_unverified_attempt"],
                      ["receipt.independently_verified_effect"], same_work=True)
    if any(at(r, "task.state") == "COMPLETED" for r in (a, b)):
        return report("HISTORICAL_RELATED", "L4",
                      ["completed_task_requires_existing_result_readback"])
    stage = at(a, "task.stage")
    effect = at(a, "input.effect_type")
    if stage in SENSITIVE_STAGES or effect not in ("read_only", "research_artifact"):
        return report("HIGH_IMPACT_REVIEW", "L4",
                      ["high_impact_or_unknown_effect",
                       "original_permission_and_target_snapshot_required"],
                      ["original_authority.approved_effect_scope"], same_work=True)
    return report("SINGLE_FLIGHT_CANDIDATE", "L4",
                  ["same_original_job_revision_scope_and_semantic_effect",
                   "origin_dependency_and_attempt_checks_pass",
                   "atomic_database_single_flight_not_performed"],
                  same_work=True)

if __name__ == "__main__":
    import argparse, sys
    parser=argparse.ArgumentParser()
    parser.add_argument("input", help="two-record, redacted JSON array")
    args=parser.parse_args()
    data=json.load(open(args.input, encoding="utf-8"))
    if not isinstance(data,list) or len(data)!=2:sys.exit("TWO_RECORDS_REQUIRED")
    print(json.dumps(compare(data[0],data[1]),indent=2,sort_keys=True))
