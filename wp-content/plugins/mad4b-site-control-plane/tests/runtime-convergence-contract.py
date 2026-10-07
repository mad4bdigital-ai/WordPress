#!/usr/bin/env python3
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

runtime = (PLUGIN / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
self_update = (PLUGIN / "includes/class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
main = (PLUGIN / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
plugin = (PLUGIN / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
servers = (PLUGIN / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
registry = json.loads((PLUGIN / "config/operation-registry.json").read_text(encoding="utf-8"))

required = [
    "mad4b.runtime-convergence.v1",
    "mad4b/runtime-convergence-status",
    "mad4b/runtime-convergence-plan",
    "mad4b/runtime-convergence-apply",
    "mad4b_scp_runtime_convergence_phases",
    "CONVERGE STAGING RUNTIME",
    "MAD4B_SCP_Schema::install_or_upgrade",
    "MAD4B_SCP_Skill_Provider_Discovery::reconcile",
    "candidate_binding_mutation_performed",
    "provider_write_certification_performed",
    "production_mutation_performed",
]
for token in required:
    assert token in runtime, token

assert "MAD4B_SCP_Full_Staging_Authority::apply" not in runtime
assert "MAD4B_SCP_Staging_Write_Candidate_Binding::" not in runtime
assert "MAD4B_SCP_Provider_Behavioral_Recertification::" not in runtime
assert "MAD4B_SCP_Provider_Canary_Execution::" not in runtime
assert "runtime-convergence.php" in main
assert "mark_post_update_pending" in self_update
assert "'mad4b/runtime-convergence-apply'," in servers
assert servers.index("'mad4b/runtime-convergence-apply',") < servers.index("class_exists( 'MAD4B_SCP_Remote_Operation_Parity' )")

ops = {row["id"]: row for row in registry["operations"]}
op = ops["wordpress.runtime.converge"]
assert op["planner"] == "mad4b/runtime-convergence-plan"
assert op["executor"] == "mad4b/runtime-convergence-apply"
assert op["required_runtime"] is True
assert "converge-runtime" in op["supports"]
assert registry["aliases"]["converge-runtime"] == "wordpress.runtime.converge"
assert registry["aliases"]["reconcile-runtime"] == "wordpress.runtime.converge"
assert "mad4b/runtime-convergence-plan" in registry["read_projection"]["direct"]
assert "mad4b/runtime-convergence-status" in registry["read_projection"]["catalog"]
assert registry["default_mutation_policy"] == "deny"
assert registry["dynamic_provider_autopilot"]["environments"]["production"] == "observe_propose_only"
assert registry["dynamic_provider_autopilot"]["auto_enable_mutation"] is False

assert "MAD4B_SCP_Self_Update::status()" not in runtime
assert "exact_current_runtime_identity" in runtime
assert "cached_release_target_present" in runtime

assert "MAD4B_SCP_Full_Staging_Authority::status" not in runtime

assert runtime.count("final class MAD4B_SCP_Runtime_Convergence") == 1
assert runtime.count("public static function status") == 1
assert runtime.count("public static function plan") == 1
assert runtime.count("public static function apply") == 1
assert runtime.count("MAD4B_SCP_Runtime_Convergence::boot();") == 1
assert "MAD4B_SCP_Staging_Write_Authority::candidate_binding_status" in runtime
registration = runtime.split("public static function register_abilities()", 1)[1].split("private static function register_read", 1)[0]
assert "self::$abilities_registered" in registration
assert "wp_has_ability(" not in registration
assert "remove_filter(" not in registration
assert "add_filter(" not in registration
assert "'surface' => 'enrollment'" in registration

read_registration = runtime.split("private static function register_read", 1)[1].split("public static function can_apply", 1)[0]
assert "wp_has_ability(" not in read_registration
assert "mad4b_runtime_convergence_phase_cycle" in runtime
assert "mad4b_runtime_convergence_phase_dependency_missing" in runtime

assert "mad4b/admin-query-performance-reconcile" in runtime

assert "mad4b/admin-query-performance-apply" in runtime

assert "'automatic_apply' => false" in runtime

assert "'blind_retry_allowed' => false" in runtime

assert "pending_manual_resume" in runtime

assert "wp_cron_disabled" in runtime

assert "checkpoint_persist_failed" in runtime

assert "wp_schedule_single_event" in runtime

assert "lightweight_runtime_drift_detector" in runtime
assert "detect_lightweight_runtime_drift" in runtime
assert "'option_reads_only' => true" in runtime
assert "'option_reads_only' => false" in runtime
assert "'bounded_provenance_file_read' => true" in runtime
assert "'bounded_provenance_file_read' => false" in runtime
assert "'filesystem_scan_performed' => false" in runtime
assert "'database_schema_probe_performed' => false" in runtime

detector = runtime.split("private static function detect_lightweight_runtime_drift()", 1)[1].split("private static function schedule_resume(", 1)[0]
assert "get_option(" in detector
assert "MAD4B_SCP_Schema::status" not in detector
assert "MAD4B_SCP_Schema::install_or_upgrade" not in detector
assert "hash_file(" not in detector
assert "rest_get_server(" not in detector
assert detector.index("if ( empty( $reasons ) )") < detector.index("$identity = self::current_identity();")

assert "waiting_for_exact_runtime_restart" in runtime

assert "restart_identity_now_matches" in runtime

assert "self::identity_matches( $target, $current )" in runtime

assert "convergence_trigger_allowed" in runtime

assert "MAX_TRANSIENT_RETRIES = 5" in runtime
assert "POST_UPDATE_QUIET_SECONDS = 20" in runtime
lease = (PLUGIN / "includes/class-mad4b-scp-runtime-maintenance-lease.php").read_text(encoding="utf-8")
assert "mad4b_scp_runtime_maintenance_lock_v1" in lease
assert "public static function restart_grace_status()" in runtime
assert "'resume_not_before' => time() + self::POST_UPDATE_QUIET_SECONDS" in runtime
assert "'self_update_checkpoint_preserved'" in runtime
assert "self::schedule_resume( $not_before )" in runtime
assert "MAD4B_SCP_Schema_Lifecycle::mark_current_package_applied( 'runtime_convergence' )" in runtime
assert "public static function maintenance_lease_status()" in runtime
assert "mad4b.runtime-maintenance-lease.v1" in lease
assert "MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'runtime_convergence' )" in runtime
assert "MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $token, 'runtime_convergence' )" in runtime
assert "private static function yield_safe_phases" in runtime
assert "'maintenance_sliced' => true" in runtime
assert "$checkpoint['next_safe_phase'] = sanitize_key( (string) $next_phase );" in runtime
assert "mark_activation_pending" in runtime
activation_pending = runtime.split("public static function mark_activation_pending()", 1)[1].split("private static function restore_checkpoint_snapshot", 1)[0]
assert "$previous_checkpoint = get_option( self::CHECKPOINT_OPTION, null );" in activation_pending
assert activation_pending.count("self::restore_checkpoint_snapshot( $previous_checkpoint )") >= 2
assert "'persist_phase' => 'pending_safe_phases'" in activation_pending
assert "'persist_phase' => 'pending_manual_resume'" in activation_pending
assert "'checkpoint_restore_ok' => $checkpoint_restore_ok" in activation_pending
assert "'plugin_activation' !== $stored_source" in runtime
assert "'checkpoint_persist_failed'" in runtime
assert "$manual_resume_gate" in runtime
assert "'pending_manual_resume' === $checkpoint_state" in runtime
assert "'autopilot_state' => $autopilot_state" in runtime
assert "'bootstrapping'" in runtime
assert "'converging'" in runtime
assert "'gated'" in runtime
assert "plugin_activation" in runtime
assert "automatic_bounded_retry" in runtime
assert "mad4b_runtime_convergence_busy" in runtime
assert "return false !== $next && (int) $next >= $minimum;" in runtime
assert "MAD4B_SCP_Runtime_Convergence::mark_activation_pending();" in plugin
assert "if ( 'blocked' === $state ) return;" not in runtime

assert "$checkpoint['retry_policy'] = 'explicit_resume_required';" in runtime

assert "$checkpoint['automatic_retry_allowed'] = false;" in runtime

assert "'mad4b-approval-decisions' === $page" in runtime

assert "'plugins.php'" in runtime

assert "REST_REQUEST" in runtime

assert "$_GET['rest_route']" in runtime

assert "$_SERVER['REQUEST_URI']" in runtime

assert "rest_get_url_prefix()" in runtime


auto_reconcile_registry = json.loads((PLUGIN / "config/runtime-auto-reconcile-scenarios.json").read_text(encoding="utf-8"))
assert auto_reconcile_registry["contract"] == "mad4b.runtime-auto-reconcile-scenarios.v1"
assert auto_reconcile_registry["default_decision"] == "review_required"
auto_scenarios = {row["id"]: row for row in auto_reconcile_registry["scenarios"]}
assert auto_scenarios["trusted_candidate_binding_drift"]["decision"] == "auto_if_zero_delta"
assert auto_scenarios["trusted_candidate_binding_drift"]["requires_trusted_release"] is True
assert auto_scenarios["trusted_candidate_binding_drift"]["requires_zero_delta"] is True
assert auto_scenarios["trusted_candidate_binding_drift"]["authority_expansion_allowed"] is False
assert auto_scenarios["trusted_candidate_binding_drift"]["production_allowed"] is False
assert auto_scenarios["trusted_candidate_binding_drift"]["breakglass_allowed"] is False
assert auto_scenarios["runtime_metadata_drift"]["decision"] == "auto_safe_phases"
assert auto_scenarios["unbound_candidate_requires_review"]["decision"] == "review_required"
assert auto_scenarios["unknown_runtime_drift"]["trigger_reasons"] == ["*"]

assert "AUTO_RECONCILE_REGISTRY_CONTRACT" in runtime
assert "runtime-auto-reconcile-scenarios.json" in runtime
assert "mad4b_scp_runtime_auto_reconcile_scenarios" in runtime
assert "mark_post_install_probe" in runtime
assert "upgrader_process_complete" in runtime
assert "POST_INSTALL_PROBE_OPTION" in runtime
assert "candidate_binding_drift" in runtime
assert "candidate_binding_unbound" in runtime
assert "auto_reconcile_scenario" in runtime
assert "auto_reconcile_decision" in runtime
assert "auto_if_zero_delta" in runtime
assert "requires_trusted_release" in runtime
assert "requires_zero_delta" in runtime
assert "downgraded_by_central_policy" in runtime
assert "'authority_expansion_allowed' => false" in runtime
assert "'production_allowed' => false" in runtime
assert "'breakglass_allowed' => false" in runtime

auto_registry_fn = runtime.split("private static function auto_reconcile_registry()", 1)[1].split("private static function classify_auto_reconcile_scenario", 1)[0]
assert "apply_filters( 'mad4b_scp_runtime_auto_reconcile_scenarios'" in auto_registry_fn
assert "'auto_if_zero_delta' === $decision" in auto_registry_fn
assert "! $requires_trusted_release" in auto_registry_fn
assert "! $requires_zero_delta" in auto_registry_fn
assert "$authority_expansion_allowed" in auto_registry_fn
assert "$production_allowed" in auto_registry_fn
assert "$breakglass_allowed" in auto_registry_fn
assert "$decision = 'review_required'" in auto_registry_fn

drift_detector = runtime.split("private static function detect_lightweight_runtime_drift()", 1)[1].split("private static function schedule_resume(", 1)[0]
assert "candidate_binding_probe_allowed" in drift_detector
assert "MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()" in drift_detector
assert "'candidate_binding_drift'" in drift_detector
assert "'candidate_binding_unbound'" in drift_detector
assert "post_install_probe_present" in drift_detector
assert "post_install_probe_consumable" in drift_detector

print("runtime convergence contract: PASS")
