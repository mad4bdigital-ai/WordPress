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
dependency = (PLUGIN / "includes/class-mad4b-scp-dependency-manager.php").read_text(encoding="utf-8")
continuity = (PLUGIN / "includes/class-mad4b-scp-upgrade-continuity.php").read_text(encoding="utf-8")
runtime_convergence = (PLUGIN / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")
provider_policy = (PLUGIN / "includes/class-mad4b-scp-provider-diagnostic-policy.php").read_text(encoding="utf-8")
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
assert "0 === strpos( $page, 'mad4b-control-plane' )" in schema_route
assert "return 0 === strpos" not in schema_route
assert schema_route.count("return true;") == 1  # WP_CLI only
assert "return false;" in schema_route
assert "0 === strpos( $page, 'mad4b-control-plane' )" in skill_route
assert "return 0 === strpos" not in skill_route
assert skill_route.count("return true;") == 1  # WP_CLI only
assert "return false;" in skill_route

# REST/Abilities priming is allowed only on the exact MCP Endpoints diagnostic tab.
assert "'mad4b-control-plane-connection' !== $page" in prime
assert "'endpoints' !== $tab" in prime
assert "rest_get_server()" in prime
assert "admin_connection_endpoints_prime" in prime
assert "admin_connection_prime" in prime  # legacy source-contract marker only

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
    "third-party wp-admin screens must return from MAD4B admin hooks",
    "admin.php?page=sitepress-multilingual-cms/menu/support.php",
    "no MAD4B-attributable 504 is acceptable",
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


# Heavy global notices must route before dependency/schema/audit inspection.
dependency_notice = method_body(
    dependency,
    "public static function admin_notice()",
    "public static function handle_install()",
)
assert "mad4b-control-plane" in dependency_notice
assert dependency_notice.index("mad4b-control-plane") < dependency_notice.index("self::status()")

continuity_notice = method_body(
    continuity,
    "public static function replace_ambiguous_governance_notice()",
    "public static function connection_admin_notice()",
)
assert "mad4b-control-plane" in continuity_notice
assert continuity_notice.index("mad4b-control-plane") < continuity_notice.index("self::governance_status()")


# Runtime Convergence fallback detection is also forbidden on foreign wp-admin
# screens. The route gate must run before checkpoint/provenance work.
maybe_converge = method_body(
    runtime_convergence,
    "public static function maybe_schedule_pending()",
    "private static function convergence_trigger_allowed()",
)
assert "convergence_trigger_allowed()" in maybe_converge
assert maybe_converge.index("convergence_trigger_allowed()") < maybe_converge.index("get_option( self::CHECKPOINT_OPTION")
assert "if ( 'blocked' === $state ) return;" in maybe_converge

convergence_gate = method_body(
    runtime_convergence,
    "private static function convergence_trigger_allowed()",
    "private static function detect_lightweight_runtime_drift()",
)
assert "mad4b-control-plane" in convergence_gate
assert "'plugins.php'" in convergence_gate
assert "'update.php'" in convergence_gate
assert "return true;" in convergence_gate
assert "REST_REQUEST" in convergence_gate
assert "$_GET['rest_route']" in convergence_gate
assert "$_SERVER['REQUEST_URI']" in convergence_gate
assert "return false;" in convergence_gate

print("admin hotpath isolation contract: PASS")


# Core Site Health REST and generic WP-Cron are request-serving zero-touch
# surfaces. They must not activate Control Plane init, telemetry or convergence.
for marker in (
    "current_request_is_foreign_rest",
    "current_request_is_wordpress_cron",
    "current_request_is_zero_touch_surface",
    "foreign_rest_zero_touch",
    "wordpress_cron_zero_touch",
):
    assert marker in provider_policy, marker

plugin_boot = method_body(
    plugin,
    "public static function boot()",
    "public static function boot_oauth_transport_if_effective()",
)
assert "current_request_is_zero_touch_surface()" in plugin_boot
assert plugin_boot.index("current_request_is_zero_touch_surface()") < plugin_boot.index("MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap()")

assert "current_request_is_zero_touch_surface()" in capture
