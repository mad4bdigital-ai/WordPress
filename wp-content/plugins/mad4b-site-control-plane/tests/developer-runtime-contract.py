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
connection = (inc / "class-mad4b-scp-connection-status.php").read_text(encoding="utf-8")
oauth = (inc / "class-mad4b-scp-oauth-resource-bridge.php").read_text(encoding="utf-8")
oauth_context = (inc / "class-mad4b-scp-oauth-request-context-guard.php").read_text(encoding="utf-8")
oauth_header = (inc / "class-mad4b-scp-oauth-jwt-header-guard.php").read_text(encoding="utf-8")
oauth_challenge = (inc / "class-mad4b-scp-oauth-challenge-alignment.php").read_text(encoding="utf-8")
compat = (inc / "class-mad4b-scp-mcp-client-compatibility.php").read_text(encoding="utf-8")
plugin = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime_build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")

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

assert "MAD4B_MCP_DEVELOPER_AGENT_PUBLIC_ID" in policy
assert "can_developer_read" in policy
assert "can_developer_breakglass" in policy
assert "mad4b_developer_production_denied" in developer
assert "mad4b_developer_root_execution_denied" in developer
assert "expected_source_commit_sha" in developer
assert "expected_site_uuid" in developer
assert "expected_environment" in developer
assert "MAD4B_MCP_DEVELOPER_NETWORK_ENABLED" in developer
assert "network_default_deny" in developer
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

# Developer tools must not be projected into the normal ChatGPT catalog.
start = servers.index("public static function chatgpt_tools")
end = servers.index("private static function surface_for_server", start)
chatgpt = servers[start:end]
assert "mad4b-developer" not in chatgpt
assert "developer-runtime-status" not in chatgpt


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
assert "class-mad4b-scp-developer-runtime.php" in plugin
assert "0.4.0-rc.55" in plugin
assert "release=0.4.0-rc.55" in runtime_build

print("mad4b.developer-runtime-contract.v2: PASS")
