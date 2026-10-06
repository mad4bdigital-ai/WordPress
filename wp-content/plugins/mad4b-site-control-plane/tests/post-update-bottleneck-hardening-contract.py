#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime = (ROOT / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
schema = (ROOT / "includes/class-mad4b-scp-schema-lifecycle.php").read_text(encoding="utf-8")
lease = (ROOT / "includes/class-mad4b-scp-runtime-maintenance-lease.php").read_text(encoding="utf-8")
self_update = (ROOT / "includes/class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
reconnect = (ROOT / "includes/class-mad4b-scp-reconnect-hardening.php").read_text(encoding="utf-8")
resilience = (ROOT / "includes/class-mad4b-scp-connector-resilience.php").read_text(encoding="utf-8")

SHARED_LOCK = "mad4b_scp_runtime_maintenance_lock_v1"

# One maintenance lane with cross-version fencing and renewal.
assert "class-mad4b-scp-runtime-maintenance-lease.php" in main
assert SHARED_LOCK in lease
assert "mad4b.runtime-maintenance-lease.v1" in lease
assert "LEASE_TTL = 300" in lease
assert "HARD_TTL = 1200" in lease
assert "LEGACY_EXPIRY_GRACE = 300" in lease
assert "legacy_expiry_grace_applied" in lease
assert "'legacy_only_fence' =>" in lease
assert "'fence_source' =>" in lease
assert "'fence_token_conflict' =>" in lease
assert "'active_fence_count' => count( $active_fences )" in lease
assert "public static function refresh(" in lease
assert "'fence_token' => $token" in lease
assert "mad4b_scp_runtime_convergence_lock_v1" in lease
assert "mad4b_scp_schema_lifecycle_lock_v1" in lease
assert "MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'runtime_convergence' )" in runtime
assert "MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $token, 'runtime_convergence' )" in runtime
assert "MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' )" in schema
assert "MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $token, 'schema_lifecycle' )" in schema
assert "public static function maintenance_lease_status()" in runtime

# Governed self-update owns Plugin_Upgrader and schema lifecycle does not create
# a competing maintenance lane at that boundary.
assert "public static function managed_apply_in_progress()" in self_update
assert "MAD4B_SCP_Self_Update::managed_apply_in_progress()" in schema
after_upgrade = schema.split("public static function after_upgrade", 1)[1].split("public static function reconcile", 1)[0]
assert after_upgrade.index("managed_apply_in_progress") < after_upgrade.index("wp_schedule_single_event")
assert "MAD4B_SCP_Schema_Lifecycle::mark_current_package_applied( 'runtime_convergence' )" in runtime

# Restart quiet period is a minimum delay, not the authority to reopen transport.
assert "POST_UPDATE_QUIET_SECONDS = 20" in runtime
assert "'resume_not_before' => time() + self::POST_UPDATE_QUIET_SECONDS" in runtime
assert "mad4b.runtime-restart-grace.v2" in runtime
assert "$active = $convergence_pending;" in runtime
assert "'waiting_for_exact_runtime_restart'" in runtime
assert "'pending_manual_resume'" in runtime
assert "'blocked'" in runtime
assert "'operator_repair_runtime_convergence'" in runtime
assert "'self_update_blocked_checkpoint_preserved'" in runtime
assert "$operator_action_required" in runtime
assert "'exact_runtime_identity_match' => $identity_match" in runtime
assert "'operator_resume_runtime_convergence'" in runtime
assert "'retry_after_runtime_convergence'" in runtime
assert "$not_before = self::maintenance_not_before();" in runtime
assert "self::schedule_resume( $not_before )" in runtime
assert "private static function restore_checkpoint_snapshot(" in runtime
assert "private static function post_update_checkpoint_matches(" in runtime
assert "'checkpoint_restore_ok' => $checkpoint_restore_ok" in runtime
for field in ("'contract'", "'state'", "'source'", "'channel'", "'update_plan_sha256'", "'resume_not_before'", "'quiet_period_seconds'"):
    assert field in runtime
assert "'persist_phase' => 'pending_restart'" in runtime
assert "'persist_phase' => 'pending_manual_resume'" in runtime
# Sliced post-update work must retain lifecycle provenance so the transport
# barrier cannot reopen between schema and managed-skills slices.
assert "$prior_source = isset( $checkpoint['source'] )" in runtime
assert "$checkpoint['source'] = 'self_update' === $prior_source ? 'self_update' : $execution_source;" in runtime
assert "$checkpoint['last_execution_source'] = $execution_source;" in runtime

# Protocol/request scope is deliberately available in two mutually exclusive
# entry paths: the early zero-touch kernel and the full Control Plane bootstrap.
# Foreign REST/admin-AJAX returns immediately after the first call; MAD4B-owned
# requests reach the second call. Both must precede runtime/recovery work.
scope = "MAD4B_SCP_MCP_Request_Scope::bootstrap();"
assert main.count(scope) == 2
first_scope = main.index(scope)
second_scope = main.index(scope, first_scope + len(scope))
early_return = main.index("return;", first_scope)
assert first_scope < early_return < second_scope
assert second_scope < main.index("MAD4B_SCP_Reconnect_Hardening::boot();")
assert second_scope < main.index("MAD4B_SCP_MCP_Adapter_Metadata_Bridge::bootstrap();")

# Every MAD4B MCP transport fails fast while convergence/maintenance owns the lane.
assert "mad4b_mcp_runtime_restart_grace" in reconnect
assert "'status' => 503" in reconnect
assert "'automatic_retry_allowed' => false" in reconnect
assert "retry_after_runtime_convergence" in reconnect
assert "$response->header( 'Retry-After'" in reconnect
assert "$response->header( 'Cache-Control', 'no-store' )" in reconnect
assert "current_request_is_http_mcp_transport()" in reconnect
assert "mad4b_mcp_runtime_maintenance_busy" in reconnect
assert "retry_after_runtime_maintenance" in reconnect

# OPcache invalidation is indexed from the already-verified ZIP; it never walks
# the installed plugin tree after replacement.
assert "mad4b.self-update-runtime-cache-invalidation.v2" in self_update
assert "'source' => 'verified_archive_index'" in self_update
assert "'filesystem_tree_scan_used' => false" in self_update
assert "'bounded_file_limit' => $limit" in self_update
assert "$runtime_php_limit = 2000" in self_update
assert "$zip->numFiles > 10000" in self_update
assert "'runtime_php_files'" in self_update
assert "RecursiveDirectoryIterator" not in self_update
assert "wp_opcache_invalidate" in self_update
assert "opcache_invalidate" in self_update
assert "'global_opcache_reset_used' => false" in self_update
assert "mad4b_self_update_install_exception" in self_update
assert "catch ( Throwable $throwable )" in self_update
assert "finally {" in self_update
assert "self::$managed_apply = false;" in self_update
assert "self::rollback( $backup, $before, $runtime_php_files )" in self_update

# The filesystem replacement itself is continuously fenced. The shared lease
# starts before backup/mutation, and WordPress core maintenance covers the
# clear-destination interval when the plugin directory may be temporarily absent.
managed_apply = self_update.split("private static function apply_verified_archive(", 1)[1].split("private static function download_governed_release_to_protected_storage", 1)[0]
assert "$lease_owner = 'self_update_replacement';" in managed_apply
assert "MAD4B_SCP_Runtime_Maintenance_Lease::acquire( $lease_owner )" in managed_apply
assert "MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, $lease_owner )" in managed_apply
assert "$upgrader->maintenance_mode( true );" in managed_apply
assert "$upgrader->maintenance_mode( false );" in managed_apply
assert "MAD4B_SCP_Runtime_Maintenance_Lease::release( $lease_token, $lease_owner );" in managed_apply
assert "'core_maintenance_window_used' => true" in managed_apply
assert "'pre_replacement_runtime_lease' => true" in managed_apply
assert managed_apply.index("MAD4B_SCP_Runtime_Maintenance_Lease::acquire( $lease_owner )") < managed_apply.index("self::backup_current()")
assert managed_apply.index("$upgrader->maintenance_mode( true );") < managed_apply.index("$upgrader->install(")
assert managed_apply.index("$upgrader->install(") < managed_apply.index("MAD4B_SCP_Runtime_Convergence::mark_post_update_pending")
rollback_body = self_update.split("private static function rollback(", 1)[1].split("private static function activation_state()", 1)[0]
assert "self::invalidate_runtime_caches( $runtime_php_files );" in rollback_body
assert rollback_body.index("copy_dir( $backup['backup_path'], $root )") < rollback_body.index("self::invalidate_runtime_caches( $runtime_php_files );") < rollback_body.index("self::restore_activation_state( $before )")

# Shared connector policy classifies transition/maintenance without blind replay.
assert "mad4b.connector-resilience.v2" in resilience
assert "'category' => 'runtime_restart'" in resilience
assert "'auto_retry' => false" in resilience
assert "'runtime_restart_honors_retry_after' => true" in resilience
assert "'category' => 'runtime_maintenance'" in resilience
assert "'runtime_maintenance_honors_retry_after' => true" in resilience

# No hardening change widens Production authority.
for text in (runtime, schema, lease, reconnect, resilience):
    assert "production_mutation_allowed' => true" not in text
assert "blind_mutation_replay_after_transport_reinitialize' => false" in reconnect

print("mad4b.post-update-bottleneck-hardening.v2: PASS")
