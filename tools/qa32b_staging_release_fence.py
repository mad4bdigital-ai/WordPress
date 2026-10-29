#!/usr/bin/env python3
"""QA32B/QA31/DFSB read-only integration preflight; never an installer or certificate."""
import argparse
import json
from pathlib import Path

from qa31_rc118_source_bridge import audit as qa31_audit

CONTRACT = "mad4b.qa32b-staging-release-boundary.v1"
NEUTRAL = "dynamic-filter-seo-bridge"
LEGACY = "etg-dynamic-filter-seo-bridge"
SOURCE_SHA = "fbcfed264231a2553d197927617a9cdae67f5567"
SOURCE_VERSION = "0.4.0-alpha.15-rc.1"
CERTIFIED_FIELDS = (
    "source_commit_sha",
    "artifact_sha256",
    "artifact_version",
    "production_promotion_attestation",
)


def check(qa31, reviewed, deployed):
    """Assess source isolation and operational blockers; no change to any input."""
    result = {
        "contract": CONTRACT,
        "state": "BLOCKED",
        "qa31_state": qa31.get("status", "BLOCKED"),
        "source_candidate": SOURCE_SHA,
        "candidate_version": SOURCE_VERSION,
        "read_only": True,
        "provider_certified": False,
        "can_install_dfsb": False,
        "control_plane_rollout_approved": False,
        "selected_head_update_authorized": False,
        "safe_boot_override": False,
        "production_authorized": False,
        "mutation_performed": False,
        "blockers": [],
    }
    blockers = result["blockers"]
    if qa31.get("status") != "EXPLAINED_UNCERTIFIED_SOURCE_DRIFT" or qa31.get("staging_rollout_authorized") is not False:
        blockers.append("qa31_exact_rc118_reconciliation_not_accepted")
    if not isinstance(reviewed, dict) or not isinstance(deployed, dict):
        blockers.append("unreadable_source_registries")
        return result
    if reviewed.get("contract") != "mad4b.cross-plugin-sources.v1" or deployed.get("contract") != "mad4b.cross-plugin-sources.v1":
        blockers.append("source_contract_mismatch")
        return result
    if reviewed.get("default_execution") != "deny_until_exact_artifact_certified":
        blockers.append("source_default_not_fail_closed")
    try:
        target_rows = reviewed["sources"]
        live_rows = deployed["sources"]
        if not all(isinstance(row, dict) for row in target_rows + live_rows):
            raise ValueError()
        if len(target_rows) != len({r.get("id") for r in target_rows}) or len(live_rows) != len({r.get("id") for r in live_rows}):
            raise ValueError()
        target = {r["id"]: r for r in target_rows}
        live = {r["id"]: r for r in live_rows}
    except (KeyError, TypeError, ValueError):
        blockers.append("invalid_or_duplicated_source_rows")
        return result
    if set(target) != {LEGACY, NEUTRAL}:
        blockers.append("reviewed_source_set_unexpected")
    if LEGACY not in live or LEGACY not in target or live[LEGACY] != target[LEGACY]:
        blockers.append("legacy_source_identity_changed")
    candidate = target.get(NEUTRAL)
    if not candidate or candidate.get("candidate_source_commit_sha") != SOURCE_SHA or candidate.get("candidate_artifact_version") != SOURCE_VERSION:
        blockers.append("neutral_exact_review_candidate_mismatch")
    elif (candidate.get("package_certification") != "pending"
          or candidate.get("review_state") != "source_reviewed_artifact_pending"
          or candidate.get("candidate_state") != "REVIEW_ONLY_SOURCE_PINNED"
          or candidate.get("provider_id") != NEUTRAL
          or any(candidate.get(field) != "" for field in CERTIFIED_FIELDS)):
        blockers.append("neutral_source_cannot_be_treated_as_review_only")
    if NEUTRAL in live:
        if live[NEUTRAL] != candidate:
            blockers.append("deployed_neutral_source_not_exact_reviewed_candidate")
    else:
        blockers.append("neutral_source_not_deployed_to_control_plane")
    blockers.extend([
        "qa31_new_signed_control_plane_artifact_required",
        "dfsb_durable_immutable_release_unverified",
        "dfsb_independent_digest_bound_certificate_missing",
        "staging_one_use_owner_approval_required",
    ])
    if not any(x in blockers for x in (
            "qa31_exact_rc118_reconciliation_not_accepted",
            "unreadable_source_registries",
            "source_contract_mismatch", "source_default_not_fail_closed",
            "invalid_or_duplicated_source_rows",
            "reviewed_source_set_unexpected", "legacy_source_identity_changed",
            "neutral_exact_review_candidate_mismatch",
            "neutral_source_cannot_be_treated_as_review_only",
            "deployed_neutral_source_not_exact_reviewed_candidate")):
        result["state"] = "SOURCE_INTAKE_VALIDATED_WITH_SEPARATE_GATES"
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--control-plane-root", type=Path, required=True)
    p.add_argument("--reviewed-source-registry", type=Path, required=True)
    a = p.parse_args()
    root = a.control_plane_root
    observed = json.loads((root / "MAD4B-BUILD-PROVENANCE.json").read_text(encoding="utf-8"))
    current = json.loads((root / "config/cross-plugin-sources.json").read_text(encoding="utf-8"))
    reviewed = json.loads(a.reviewed_source_registry.read_text(encoding="utf-8"))
    result = check(qa31_audit(root, observed), reviewed, current)
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if result["state"] == "SOURCE_INTAKE_VALIDATED_WITH_SEPARATE_GATES" else 2


if __name__ == "__main__":
    raise SystemExit(main())
