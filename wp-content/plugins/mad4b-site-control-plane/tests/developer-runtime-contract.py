#!/usr/bin/env python3
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
inc = root / "includes"

developer = (inc / "class-mad4b-scp-developer-runtime.php").read_text(encoding="utf-8")
servers = (inc / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
policy = (inc / "class-mad4b-scp-policy.php").read_text(encoding="utf-8")
auth = (inc / "class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
fence = (inc / "class-mad4b-scp-execution-fence.php").read_text(encoding="utf-8")
impact = (inc / "class-mad4b-scp-impact-policy.php").read_text(encoding="utf-8")
governance = (inc / "class-mad4b-scp-governance-abilities.php").read_text(encoding="utf-8")
authority = (inc / "class-mad4b-scp-developer-authority.php").read_text(encoding="utf-8")
registry = (inc / "class-mad4b-scp-agent-registry.php").read_text(encoding="utf-8")
transport = (inc / "class-mad4b-scp-transport-context.php").read_text(encoding="utf-8")
connection = (inc / "class-mad4b-scp-connection-status.php").read_text(encoding="utf-8")
oauth = (inc / "class-mad4b-scp-oauth-resource-bridge.php").read_text(encoding="utf-8")
oauth_context = (inc / "class-mad4b-scp-oauth-request-context-guard.php").read_text(encoding="utf-8")
oauth_header = (inc / "class-mad4b-scp-oauth-jwt-header-guard.php").read_text(encoding="utf-8")
oauth_challenge = (inc / "class-mad4b-scp-oauth-challenge-alignment.php").read_text(encoding="utf-8")
compat = (inc / "class-mad4b-scp-mcp-client-compatibility.php").read_text(encoding="utf-8")
plugin = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime_build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")
runbook = (root / "docs/DEVELOPER-AGENT-RUNTIME.md").read_text(encoding="utf-8")

required_tools = [
    "mad4b/developer-runtime-status",
    "mad4b/developer-wp-cli",
    "mad4b/developer-php-eval",
    "mad4b/developer-shell-exec",
    "mad4b/developer-filesystem",
    "mad4b/developer-package-install",
    "mad4b/developer-breakglass-shell",
    "mad4b/developer-breakglass-wp-eval",
]
for tool in required_tools:
    assert tool in developer, tool

for server in ["mad4b-developer", "mad4b-developer-breakglass"]:
    assert server in servers, server
    assert server in auth, f"authorization missing {server}"
    assert server in fence, f"execution fence missing {server}"

assert "MAD4B_SCP_Developer_Runtime::configured_agent_public_id()" in policy
assert "MAD4B_SCP_Developer_Runtime::developer_flag_enabled()" in policy
assert "can_developer_read" in policy
assert "can_developer_runtime_status" in policy
assert "MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-developer', 'mad4b/developer-runtime-status', 'core' )" in policy
assert "can_developer_breakglass" in policy
assert "mad4b_developer_production_denied" in developer
assert "mad4b_developer_root_execution_denied" in developer
assert "expected_source_commit_sha" in developer
assert "expected_site_uuid" in developer
assert "expected_environment" in developer
assert "MAD4B_MCP_DEVELOPER_NETWORK_ENABLED" in developer
assert "MAD4B_SCP_Authorization::authorize_mutation" in developer
assert "array( 'MAD4B_SCP_Policy', 'can_developer_runtime_status' )" in developer
assert "permission_callback( $permission, $readonly, $name, $category )" in developer
assert "'_mad4b_approval_ticket_id'" in developer
assert "network_default_deny" in developer
assert "network_sandbox_binary" in developer
assert "bounded_execution_argv" in developer
assert "mad4b_developer_network_isolation_unavailable" in developer
assert "prlimit_binary" in developer
assert "DEFAULT_MEMORY_LIMIT_BYTES" in developer
assert "MAX_OPEN_FILES" in developer
assert "MAX_PROCESSES" in developer
assert "'resource_limiter_binary_present' => '' !== self::prlimit_binary()" in developer
assert "'resource_limits_enforcement' => 'execution_proves_prlimit_or_fails_closed'" in developer
assert "'network_sandbox_binary_present' => '' !== self::network_sandbox_binary()" in developer
assert "self::runtime_gate( false, $input, false )" in developer
assert "secret_redaction_enabled" in developer
assert "proc_open" in developer
assert "wp eval" not in developer.lower() or "'eval'" in developer

# No direct PHP eval()/shell_exec()/system()/passthru() execution primitive.
for pattern in [
    r"(?<![A-Za-z0-9_])eval\s*\(",
    r"(?<![A-Za-z0-9_])shell_exec\s*\(",
    r"(?<![A-Za-z0-9_])system\s*\(",
    r"(?<![A-Za-z0-9_])passthru\s*\(",
    r"(?<![A-Za-z0-9_])assert\s*\(",
    r"(?<![A-Za-z0-9_])exec\s*\(",
    r"(?<![A-Za-z0-9_])popen\s*\(",
]:
    assert re.search(pattern, developer) is None, pattern

# Runtime execution tools and authority bootstrap must not be projected
# into the normal ChatGPT catalog.
start = servers.index("public static function chatgpt_tools")
end = servers.index("private static function surface_for_server", start)
chatgpt = servers[start:end]
assert "developer-runtime-status" not in chatgpt
assert "self::core_tools( 'mad4b-developer' )" not in chatgpt
assert "self::core_tools( 'mad4b-developer-breakglass' )" not in chatgpt
assert "array_diff( $enrollment_candidates, MAD4B_SCP_Developer_Authority::enrollment_tools() )" in chatgpt

authority_tools = [
    "mad4b/developer-authority-status",
    "mad4b/developer-authority-plan",
    "mad4b/developer-authority-apply",
    "mad4b/developer-authority-disable",
    "mad4b/developer-breakglass-authority-plan",
    "mad4b/developer-breakglass-authority-apply",
]
for tool in authority_tools:
    assert tool in authority, tool
assert "class-mad4b-scp-developer-authority.php" in servers
assert "MAD4B_SCP_Developer_Authority::enrollment_tools()" in servers
assert "mad4b.developer-authority.v1" in authority
assert "self::$booted || ! function_exists( 'add_action' )" in authority
assert "PROVISION MAD4B DEVELOPER AGENT" in authority
assert "PROVISION MAD4B DEVELOPER BREAKGLASS" in authority
assert "DISABLE MAD4B DEVELOPER AGENT" in authority
assert "production_allowed' => false" in authority
assert "expected_plan_sha256" in authority
assert "expected_source_commit_sha" in authority
assert "expected_site_uuid" in authority
assert "expected_profile_revision" in authority
assert "expected_profile_digest" in authority
assert "remove_filter( 'wp_register_ability_args', $augment" in authority
assert "MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability" in authority
assert "mad4b_scp_developer_kill_switch" in authority
assert "configuration_snapshot" in authority
assert "restore_configuration" in authority
assert "force_fail_closed" in authority
assert "mad4b/developer-authority-rollback" in authority
assert "completion_audit_failed" in authority
assert "rollback_incomplete" in authority
assert "strict_grant_inventory" in authority
assert "unexpected_allow_grant:" in authority
assert "non_exact_environment_allow:" in authority
assert "duplicate_exact_allow:" in authority
assert "effective_deny:" in authority
assert "wildcard_grant:" in authority
assert "'ready' => $ready" in authority
assert "get_agent_by_slug" in registry
assert "Existing subject binding could not be re-enabled." in registry
assert "array( 'status' => 'enabled', 'label' => sanitize_text_field( $label )" in registry
assert "mad4b_scp_allow_developer_breakglass_grant_creation" in registry
assert "'mad4b-developer', 'mad4b-developer-breakglass'" in transport


# Developer transport must be first-class but isolated from the operational resource.
for marker in [
    "'mad4b-developer' => array( 'MAD4B_SCP_Servers', 'can_developer_transport' )",
    "'mad4b-developer-breakglass' => array( 'MAD4B_SCP_Servers', 'can_developer_breakglass_transport' )",
    "return 'developer';",
    "return 'developer-breakglass';",
]:
    assert marker in connection, marker

for marker in [
    "const DEVELOPER_SCOPE = 'server:mad4b-developer'",
    "const DEVELOPER_BREAKGLASS_SCOPE = 'server:mad4b-developer-breakglass'",
    "home_url( '/wp-json/mcp/mad4b-developer' )",
    "home_url( '/wp-json/mcp/mad4b-developer-breakglass' )",
    "'subject_type' => $subject_type",
    "'oauth_developer'",
]:
    assert marker in oauth, marker

for source in [oauth_context, oauth_header, oauth_challenge]:
    assert "/mcp/mad4b-developer" in source
    assert "/mcp/mad4b-developer-breakglass" in source

for marker in [
    "DEVELOPER_RESOURCE_PATH",
    "DEVELOPER_BREAKGLASS_RESOURCE_PATH",
    "authoritative_well_known_url( 'mad4b-developer' )",
    "authoritative_well_known_url( 'mad4b-developer-breakglass' )",
]:
    assert marker in compat, marker

assert "developer-breakglass-" in impact and "return 'exceptional'" in impact
assert "developer-" in impact and "return 'high'" in impact
assert "MAD4B_SCP_Authorization::claim_mutation" in auth
assert "MAD4B_SCP_Approval_Tickets::claim_exact" in auth
assert "MAD4B_SCP_Budgets::reserve" in auth
assert "finalize_execution_claim" in auth
assert "array( 'content', 'write', 'admin', 'breakglass', 'developer', 'developer-breakglass' )" in auth
assert "'server_id' => array( 'type' => 'string', 'enum' => MAD4B_SCP_Servers::expected_server_ids() )" in governance
assert "MAD4B_SCP_Impact_Policy::ticket_class_for" in governance
assert "MAD4B_SCP_Approval_Tickets::create_pending" in governance
assert "class-mad4b-scp-developer-runtime.php" in plugin
assert "0.4.0-rc.55" in plugin
assert "release=0.4.0-rc.55" in runtime_build
assert "mad4b.developer-runtime.v1" in runbook
assert "mad4b.developer-authority.v1" in runbook
assert "PROVISION MAD4B DEVELOPER AGENT" in runbook
assert "DISABLE MAD4B DEVELOPER AGENT" in runbook
assert "No SQL, WP-CLI or manual database mutation is required for this bootstrap." in runbook
assert "Production execution is denied by code." in runbook

print("mad4b.developer-runtime-contract.v9: PASS")
