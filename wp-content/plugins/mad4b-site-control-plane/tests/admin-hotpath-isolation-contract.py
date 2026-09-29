#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

plugin = (PLUGIN / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
admin = (PLUGIN / "includes/class-mad4b-scp-admin-ui.php").read_text(encoding="utf-8")
mu = (PLUGIN / "includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php").read_text(encoding="utf-8")
guard = (PLUGIN / "includes/class-mad4b-scp-mcp-runtime-conflict-guard.php").read_text(encoding="utf-8")

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

print("admin hotpath isolation contract: PASS")
