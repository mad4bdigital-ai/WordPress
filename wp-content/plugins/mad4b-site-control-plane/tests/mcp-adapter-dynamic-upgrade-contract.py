#!/usr/bin/env python3
from pathlib import Path
base=Path(__file__).resolve().parents[1]
bootstrap=(base/'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php').read_text()
scope=(base/'includes/class-mad4b-scp-mcp-request-scope.php').read_text()
server=(base/'includes/class-mad4b-scp-servers.php').read_text()
for marker in (
    "'installed_compatible_layout'",
    "'adapter_profile_dynamic'",
    "runtime_symbols",
    "runtime_class",
    "hash_file( 'sha256'",
    "git_blob_sha1",
    "WP\\\\MCP\\\\Cli\\\\StdioServerBridge",
    "WP\\\\MCP\\\\Cli\\\\McpCommand",
    "mad4b_mcp_mu_command_server",
    "mad4b_mcp_mu_cli_opt_in",
):
    assert marker in bootstrap, f"MU bootstrap is missing {marker}"
assert "cli_mcp_serve_requested()" in scope
assert "current_request_requires_mcp_runtime()" in server
assert "__mad4b_non_mcp_cli__" in server
assert "official_adapter_file_unreadable" in bootstrap
print("MCP_DYNAMIC_UPGRADE_AND_CLI_SCOPE_CONTRACT: PASS")
