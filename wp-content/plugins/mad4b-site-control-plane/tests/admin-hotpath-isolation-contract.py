#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

plugin = (PLUGIN / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
admin = (PLUGIN / "includes/class-mad4b-scp-admin-ui.php").read_text(encoding="utf-8")
mu = (PLUGIN / "includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php").read_text(encoding="utf-8")
guard = (PLUGIN / "includes/class-mad4b-scp-mcp-runtime-conflict-guard.php").read_text(encoding="utf-8")
query_monitor = (PLUGIN / "includes/class-mad4b-scp-query-monitor-evidence-bridge.php").read_text(encoding="utf-8")
live_truth = (PLUGIN / "includes/class-mad4b-scp-live-truth.php").read_text(encoding="utf-8")
slo = (ROOT / "specs/008-dynamic-content-runtime-hardening/performance-slo.md").read_text(encoding="utf-8")

def method_body(source: str, signature: str, next_signature: str) -> str:
    start = source.index(signature)
    end = source.index(next_signature, start)
    return source[start:end]

schema_route = method_body(
    plugin,
    "private static function request_requires_schema_reconciliation()",
    "private static function request_requires_skill_reconciliation()",
)
skill_route = method_body(
    plugin,
    "private static function request_requires_skill_reconciliation()",
    "private static function bind_local_oauth_subject_compatibility()",
)
prime = method_body(
    plugin,
    "public static function prime_admin_mcp_runtime()",
    "public static function governance_bootstrap_error_code()",
)

# Ordinary MAD4B admin rendering must never be treated as lifecycle repair.
assert "mad4b-control-plane" not in schema_route
assert "return false;" in schema_route
assert "mad4b-control-plane" not in skill_route
assert "return false;" in skill_route

# REST/Abilities priming is allowed only on the exact MCP Endpoints diagnostic tab.
assert "'mad4b-control-plane-connection' !== $page" in prime
assert "'endpoints' !== $tab" in prime
assert "rest_get_server()" in prime
assert "admin_connection_endpoints_prime" in prime

# Main governance UI is tab scoped and no longer runs deep provider/peer scans
# simply to render Overview.
assert "snapshot( $agent_public_id = '', $section = 'overview' )" in admin
assert "self::snapshot( $agent_public_id, $tab )" in admin
assert "runtime_self_test()" not in admin
assert "MAD4B_SCP_MCP_Peer_Governance::status()" not in admin
assert "'approvals' === $section" in admin
assert "'mutations' === $section" in admin
assert "'audit' === $section" not in admin or "MAD4B_SCP_Audit::tail" in admin

# Filesystem/runtime repair cannot execute on ordinary request-serving hotpaths.
for source in (mu, guard):
    assert "repair_lifecycle_allowed()" in source
    assert "next_lifecycle_required" in source
    assert "wp_doing_cron()" in source
    assert "'update.php'" in source
    assert "'plugin-install.php'" in source
    assert "'mad4b-control-plane-connection' === $page" in source
    assert "'endpoints' === $tab" in source

assert "deferred_request_hotpath" in mu
assert "repair_deferred_request_hotpath" in guard

for token in (
    "WordPress Admin read-hotpath SLO",
    "server elapsed: <= 1500 ms target, <= 2000 ms hard ceiling",
    "database queries: <= 100",
    "peak memory: <= 128 MiB",
    "3 consecutive uncached/warm mixed samples",
):
    assert token in slo, token


# Foreign wp-admin pages must exit before Query Monitor filesystem/provenance work.
boot = method_body(
    query_monitor,
    "public static function boot_early()",
    "public static function db_attribution_status()",
)
assert "pin_request_build_fingerprint();" not in boot

maybe_qm = method_body(
    query_monitor,
    "public static function maybe_enable_db_attribution()",
    "private static function bounded_loader_contents()",
)
assert "deferred_foreign_admin_surface" in maybe_qm
assert maybe_qm.index("deferred_foreign_admin_surface") < maybe_qm.index("db_attribution_status()")

capture = method_body(
    query_monitor,
    "public static function capture_and_flush()",
    "private static function request_sample_id()",
)
assert "current_request_is_mad4b_admin_surface()" in capture
assert capture.index("current_request_is_mad4b_admin_surface()") < capture.index("request_build_fingerprint()")
assert capture.index("current_request_is_mad4b_admin_surface()") < capture.index("load_telemetry(")

# If another plugin materializes Abilities on its own admin page, MAD4B must not
# turn that into an authority/provider inventory scan.
recover = method_body(
    live_truth,
    "private static function recover_runtime_authority()",
    "public static function current_authority_status()",
)
assert "is_admin()" in recover
assert "mad4b-control-plane" in recover
assert "mad4b-approval-decisions" in recover
assert "current_authority_status();" in recover

print("admin hotpath isolation contract: PASS")
