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
]:
    assert re.search(pattern, developer) is None, pattern

# Developer tools must not be projected into the normal ChatGPT catalog.
start = servers.index("public static function chatgpt_tools")
end = servers.index("private static function surface_for_server", start)
chatgpt = servers[start:end]
assert "mad4b-developer" not in chatgpt
assert "developer-runtime-status" not in chatgpt

assert "developer-breakglass-" in impact and "return 'exceptional'" in impact
assert "developer-" in impact and "return 'high'" in impact
assert "class-mad4b-scp-developer-runtime.php" in plugin
assert "0.4.0-rc.55" in plugin
assert "release=0.4.0-rc.55" in runtime_build

print("mad4b.developer-runtime-contract.v1: PASS")
