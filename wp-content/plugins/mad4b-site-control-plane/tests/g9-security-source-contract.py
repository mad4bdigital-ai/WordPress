#!/usr/bin/env python3
"""G9 invariant smoke contract, intentionally additive to native PHP runtime CI.

A string-based source check is not a cryptographic attestation, a runtime test,
a provider acceptance certificate, or proof of a distributed lock.
"""
from pathlib import Path

# parents[0]=tests; [1]=plugin; [2]=plugins; [3]=wp-content; [4]=repository root
ROOT = Path(__file__).resolve().parents[4]
assert (ROOT / "wp-content/plugins/mad4b-site-control-plane").is_dir(), "G9_REPOSITORY_ROOT_UNRESOLVED"
assert (ROOT / ".github/workflows/feature-007-g9-resilience.yml").is_file(), "G9_REPOSITORY_WORKFLOW_UNAVAILABLE"
BASE = ROOT / "wp-content/plugins/mad4b-site-control-plane"
INCLUDES = BASE / "includes"


def source(name: str) -> str:
    file = INCLUDES / name
    assert file.is_file() and not file.is_symlink(), "MISSING_G9_FILE:" + name
    return file.read_text(encoding="utf-8")


def expect(text: str, markers: tuple[str, ...], context: str) -> None:
    for marker in markers:
        assert marker in text, "MISSING_G9_GUARD:" + context + ":" + marker


context = source("class-mad4b-scp-resilience-context.php")
release = source("class-mad4b-scp-g9-release-fence.php")
anchor = source("class-mad4b-scp-resilience-anchor.php")
gates = source("class-mad4b-scp-g9-resilience-gates.php")
local = source("class-mad4b-scp-g9-local-reader.php")
restore = source("class-mad4b-scp-g9-restore-convergence.php")
read = source("class-mad4b-scp-g9-read-surface.php")
closure = source("class-mad4b-scp-g9-operational-readiness.php")
fleet = source("class-mad4b-scp-g9-fleet-rollout.php")

expect(release, (
    "MAD4B_SCP_G9_RELEASE_FENCE_ENABLED",
    "MAD4B_SCP_G9_RELEASE_LIMITS",
    "wider_ring_not_certified",
    "current_environment() !== 'staging'",
    "grants_changed_before_cas",
    "candidate_binding_match",
    "grant_rows_fingerprint",
    "policy_decision_sha256",
    "approval_impact_binding_sha256",
    "mad4b/runtime-release-set-apply",
    "native_execution_uncertain",
    "native_link_unavailable",
    "native_evidence_changed",
    "if ( array_key_exists( 'execution_receipt_sha256', $link ) )",
    "'terminal_receipt_sha256' => $receipt['terminal_receipt_sha256']",
    "mad4b.g9.native-release-link.v1",
    "native_request_id",
    "native_target_fingerprint",
    "MAD4B_SCP_Operation_Journal::trace",
    "g9_plan_sha256",
    "site_binding_sha256",
    "'operation_journal'",
    "'evidence_type'",
    "hash( 'sha256', $operation_id )",
    "build_provenance_status",
    "runtime_manifest_match",
    "package_changed_before_cas",
    "generation_changed_before_cas",
    "restore_changed_before_cas",
    "journal_head_sha256",
    "latest_sequence",
    "native_signature_invalid",
    "replay_or_capacity",
    "external_record_sha256",
), "release")
assert "wp_register_ability" not in release, "RELEASE_MUTATION_ABILITY_EXPOSED"
expect(context, (
    "'current_grant_snapshot_ready'",
    "'candidate_binding_match'",
    "self::is_hash( $authority['grant_rows_fingerprint']",
), "passive-grant-readiness")
expect(anchor, (
    "mirror_missing",
    "mirror_anchor_mismatch",
    "'anchor_revision' => $next['revision']",
    "'anchor_sha256' => $next['anchor_sha256']",
    "directory_permissions_unsafe",
    "local_blog_mismatch",
    "local_site_mismatch",
    "mirror_lost_after_commit",
    "mirror_identity_mismatch",
    "directory_permissions_unsafe",
    "local_site_mismatch",
    "local_blog_mismatch",
    "history_truncation",
    "clearstatcache( true, $dir )",
    "clock_rollback",
    "readback_failed",
    "mirror_lost_after_commit",
), "anchor")
assert anchor.index("Could not persist external-anchor loss marker before") < anchor.index("@rename( $tmp, $path )"), "UNSAFE_EXTERNAL_MARKER_ORDER"
expect(gates, (
    "distributed_fence_unavailable", "single_host_exclusive_verified",
    "inventory_incomplete", "observation_stale", "health_stale",
    "cloned_site_uuid", "cloned_origin", "external_effect_uncertain",
    "production_ring_denied",
), "gates")
expect(local, ("single_host_exclusive_verified' => false", "provider_inventory_complete' => false"), "passive-reader")
expect(restore, (
    "'external_effects_verified' => false",
    "'requires_quarantine' => true",
    "'post_restore_acceptance_issued' => false",
), "restore")
expect(read, (
    "private static $reader_pinned = false",
    "private static $boot_result = null",
    "private static $abilities_registered = false",
    "'mad4b_g9_ability_namespace_collision'",
    "'mad4b_g9_reader_not_pinned'",
    "'mad4b_g9_ability_runtime_unavailable'",
    "$abilities = array(",
    "$provider_verified",
    "$effects_verified",
    "$health_verified",
    "if ( ! self::$reader_pinned",
    "self::$reader_pinned = true",
    "mad4b/g9-site-observation",
    "mad4b/g9-restore-status",
    "mad4b/g9-closure-status",
    "MAD4B_SCP_Policy', 'can_read",
), "read-only-abilities")
assert "mad4b/g9-release-reserve" not in read, "RESERVE_EXPOSED_AS_READ_ABILITY"
assert (BASE / "tests/g9-ability-collision-runtime.php").is_file(), "G9_NAMESPACE_COLLISION_TEST_MISSING"
expect(closure, ("native_executor_g9_reservation_binding_unimplemented",
                 "g9_reservation_host_feature_disabled",
                 "g9_host_release_threshold_policy_missing"), "native-admission-blocker")
expect(closure, ("'operationally_closed' => false", "'ready_for_production' => false"), "closure")
expect(closure, (
    "'external_fence_unavailable'",
    "'anchor_observation_valid'",
    "'anchor_error_code'",
    "'certified_provider_unready'",
    "'external_effect_unreconciled'",
    "'current_health_window_unverified'",
    "'blind_retry_allowed' => false",
), "diagnostic-denial")
expect(fleet, (
    "'cohort_promotion_allowed'=>false",
    "'automatic_rollback_allowed'=>false",
    "'rollback_risk_detected'",
    "'reported_rollback_completed_sites'",
    "'partial_rollback_observed'",
    "$last_operation_state",
    "'PREPARED'",
), "fleet")
print("G9 SOURCE INVARIANTS: PASS (non-authorizing gate markers only; runtime CI still required)")
