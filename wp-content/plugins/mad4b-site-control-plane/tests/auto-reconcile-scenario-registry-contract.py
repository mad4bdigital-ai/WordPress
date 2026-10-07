#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
adaptive = (ROOT / "includes/class-mad4b-scp-adaptive-runtime-convergence.php").read_text(encoding="utf-8")
registry = (ROOT / "includes/class-mad4b-scp-auto-reconcile-scenarios.php").read_text(encoding="utf-8")
runtime = (ROOT / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")

assert "class-mad4b-scp-auto-reconcile-scenarios.php" in main
assert "mad4b.auto-reconcile-scenarios.v1" in registry
assert "mad4b_scp_auto_reconcile_scenarios" in registry
for decision in ("NO_OP", "SCHEDULE_PROBE", "DEFER", "REVIEW_REQUIRED", "HARD_BLOCK"):
    assert decision in registry
for scenario in (
    "same_version_package_replacement",
    "forward_package_update",
    "rollback_or_reinstall",
    "manual_or_same_version_package_drift",
    "native_or_release_set_continuation",
    "trusted_reinstall_or_rollback_probe",
    "version_or_schema_drift",
    "untrusted_package_never_auto",
    "authority_evidence_drift",
    "concurrent_reconciliation",
    "production_never_auto",
    "breakglass_never_auto",
    "skills_dependency_pending",
    "continuation_owner_gate",
    "unclassified_reconcile_need",
):
    assert scenario in registry

# Central safety invariants cannot be relaxed by extension descriptors.
assert "'mutation_allowed' => false" in registry
assert "'authority_expansion_allowed' => false" in registry
assert "'zero_delta_required_for_rebind' => true" in registry
assert "MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind" in registry
assert "DECISION_SCHEDULE_PROBE" in registry
assert "DECISION_HARD_BLOCK" in registry

# Lifecycle integration must preserve one bounded observer lane.
assert "upgrader_process_complete" in adaptive
assert "'source' => 'wordpress_upgrader'" in adaptive
assert "'source' => 'build_stamp_drift'" in adaptive
assert "FALLBACK_PROBE_INTERVAL = 300" in adaptive
assert "candidate_binding_fallback_drift" in adaptive
assert "'source' => 'candidate_binding_probe'" in adaptive
assert "core_enqueued_generation" in adaptive
assert "Persist the generation-bound core decision before provider slicing" in adaptive
assert "plugins.php" in adaptive and "update.php" in adaptive and "plugin-install.php" in adaptive
assert "$admin_lifecycle" in adaptive
assert "( is_admin() && ! $admin_lifecycle )" in adaptive
assert "MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate" in adaptive
assert "MAD4B_SCP_Auto_Reconcile_Scenarios::classify_worker_error" in adaptive
assert "failure_decision" in adaptive and "failure_policy_id" in adaptive and "failure_policy_source" in adaptive
assert "MAD4B_SCP_Runtime_Convergence::mark_activation_pending()" in adaptive
assert "This only queues the existing convergence worker" in adaptive
assert "'SCHEDULE_PROBE' === $decision" in adaptive
assert "'DEFER' === $decision" in adaptive
assert "'NO_OP' === $decision" in adaptive


assert "'current_version' =>" in adaptive
assert "'stored_version' =>" in adaptive
assert "same_version_identity_drift" in registry
assert "version_forward" in registry
assert "version_rollback" in registry
for signal in (
    "grant_inventory_drift",
    "write_contract_drift",
    "site_profile_drift",
    "actor_identity_drift",
    "transport_contract_drift",
    "baseline_expired",
    "untrusted_package",
    "continuation_conflict",
    "concurrent_permit",
    "source_candidate_binding_probe",
):
    assert signal in registry

# Dynamic extension points are additive and fail closed.
assert "mad4b_scp_auto_reconcile_signals" in registry
assert "mad4b_scp_auto_reconcile_worker_error_policies" in registry
assert "MAX_EXTENSION_SIGNALS" in registry
assert "MAX_EXTENSION_ERROR_POLICIES" in registry
assert "bounded_extension_context" in registry
assert "core_worker_error_decision" in registry
assert "extension_worker_error_decision" in registry

# Runtime Convergence consumes the central registry; a second scenario universe
# or a second extension filter would reintroduce policy drift.
assert "MAD4B_SCP_Auto_Reconcile_Scenarios::registry" in runtime
assert "MAD4B_SCP_Auto_Reconcile_Scenarios::evaluate" in runtime
assert "central_reconciliation_scenario" in runtime
assert "reconciliation_context" in runtime
assert "mad4b_scp_auto_reconciliation_scenarios" not in runtime
assert "'registry_decision' =>" in runtime
assert "'registry_reason' =>" in runtime
assert "private static function select_reconciliation_scenario" not in runtime
assert "private static function reconciliation_signals" not in runtime

# Runtime decision combination must make registry safety decisions binding.
for marker in [
    "private static function combine_reconciliation_disposition",
    "'HARD_BLOCK' === $registry_decision || 'HARD_BLOCK' === $preflight_disposition",
    "'REVIEW_REQUIRED' === $registry_decision || 'REVIEW_REQUIRED' === $preflight_disposition",
    "'DEFER' === $registry_decision || 'DEFER' === $preflight_disposition",
    "'SCHEDULE_PROBE' === $registry_decision",
    "'decision_combination_policy' => 'registry_safety_ceiling_then_exact_zero_delta_preflight'",
    "'preflight_disposition' => $preflight_disposition",
]:
    assert marker in runtime, f"central decision ceiling missing: {marker}"

# Runtime context must not hard-code Breakglass false; persisted governed authority
# is bounded read-only evidence and any enabled exceptional gate blocks auto-reconcile.
for marker in [
    "MAD4B_SCP_Staging_Write_Authority::persisted_status()",
    "$persisted_authority['breakglass_included']",
    "$persisted_authority['breakglass_auto_enable']",
    "$persisted_authority['raw_sql_breakglass_enabled']",
    "'breakglass_enabled' => (bool) $breakglass_enabled",
]:
    assert marker in runtime, f"runtime breakglass evidence missing: {marker}"

# Authority-affecting external signals are core-reserved and cannot be removed by
# extension filters.
for marker in [
    "core_external_signal_keys",
    "'grant_inventory_drift'",
    "'write_contract_drift'",
    "'site_profile_drift'",
    "'actor_identity_drift'",
    "'transport_contract_drift'",
    "'baseline_expired'",
    "'untrusted_package'",
    "'continuation_conflict'",
    "'concurrent_permit'",
]:
    assert marker in registry, f"reserved authority signal missing: {marker}"

# Adaptive and Runtime fallback behavior must fail closed when the central
# classifier is unavailable; no legacy blind-retry path may become authority.
for marker in [
    "private static function breakglass_enabled",
    "MAD4B_SCP_Staging_Write_Authority::persisted_status()",
    "&& ! self::breakglass_enabled()",
    "'breakglass_enabled' => self::breakglass_enabled()",
    "'decision' => 'REVIEW_REQUIRED', 'policy_id' => 'registry_unavailable'",
]:
    assert marker in adaptive, f"adaptive fail-closed reconcile guard missing: {marker}"
assert "adaptive_legacy_retry" not in adaptive

assert "'decision' => 'REVIEW_REQUIRED'" in runtime
assert "'policy_id' => 'registry_unavailable'" in runtime
assert "legacy_transient_fallback" not in runtime
assert "legacy_review_fallback" not in runtime
