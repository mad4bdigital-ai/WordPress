#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime = (ROOT / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
schema = (ROOT / "includes/class-mad4b-scp-schema-lifecycle.php").read_text(encoding="utf-8")
self_update = (ROOT / "includes/class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
reconnect = (ROOT / "includes/class-mad4b-scp-reconnect-hardening.php").read_text(encoding="utf-8")
resilience = (ROOT / "includes/class-mad4b-scp-connector-resilience.php").read_text(encoding="utf-8")

SHARED_LOCK = "mad4b_scp_runtime_maintenance_lock_v1"

# One maintenance lane: schema and post-update convergence must never run
# independently against the same freshly replaced runtime.
assert SHARED_LOCK in runtime
assert SHARED_LOCK in schema
assert "public static function managed_apply_in_progress()" in self_update
assert "MAD4B_SCP_Self_Update::managed_apply_in_progress()" in schema
assert "mad4b.self-update-runtime-cache-invalidation.v1" in self_update
assert "wp_opcache_invalidate" in self_update
assert "opcache_invalidate" in self_update
assert "'global_opcache_reset_used' => false" in self_update
assert "'bounded_file_limit' => 1000" in self_update
after_upgrade = schema.split("public static function after_upgrade", 1)[1].split("public static function reconcile", 1)[0]
assert after_upgrade.index("managed_apply_in_progress") < after_upgrade.index("wp_schedule_single_event")
assert "MAD4B_SCP_Schema_Lifecycle::mark_current_package_applied( 'runtime_convergence' )" in runtime

# A governed package replacement creates a bounded quiet period instead of
# letting Cron, schema repair and the first reconnect compete for PHP/DB workers.
assert "POST_UPDATE_QUIET_SECONDS = 20" in runtime
assert "'resume_not_before' => time() + self::POST_UPDATE_QUIET_SECONDS" in runtime
assert "public static function restart_grace_status()" in runtime
assert "self::schedule_resume( $not_before )" in runtime
assert "max( time() + 5, absint( $not_before ), self::maintenance_not_before() )" in runtime
assert "'self_update_checkpoint_preserved'" in runtime

# Protocol hotpaths are classified before runtime/recovery bootstrap.
scope = "MAD4B_SCP_MCP_Request_Scope::bootstrap();"
assert main.count(scope) == 1
assert main.index(scope) < main.index("MAD4B_SCP_Reconnect_Hardening::boot();")
assert main.index(scope) < main.index("MAD4B_SCP_MCP_Adapter_Metadata_Bridge::bootstrap();")

# During restart grace, MCP fails fast with structured retry guidance instead
# of waiting until the upstream gateway times out.
assert "mad4b_mcp_runtime_restart_grace" in reconnect
assert "'status' => 503" in reconnect
assert "'automatic_retry_allowed' => false" in reconnect
assert "'client_action' => 'retry_after_restart_grace'" in reconnect
assert "$response->header( 'Retry-After'" in reconnect
assert "$response->header( 'Cache-Control', 'no-store' )" in reconnect

# Shared connector policy understands this as a bounded runtime transition,
# not a generic timeout that should be retried immediately.
assert "mad4b.connector-resilience.v2" in resilience
assert "'category' => 'runtime_restart'" in resilience
assert "'auto_retry' => false" in resilience
assert "'runtime_restart_honors_retry_after' => true" in resilience
assert "'runtime_restart_immediate_auto_retry_allowed' => false" in resilience

# The hardening does not widen mutation authority.
for text in (runtime, schema, reconnect, resilience):
    assert "production_mutation_allowed' => true" not in text
assert "blind_mutation_replay_after_transport_reinitialize' => false" in reconnect

print("mad4b.post-update-bottleneck-hardening.v1: PASS")
