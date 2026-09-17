#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapters = root / "includes" / "adapters"
diag = adapters / "class-mad4b-scp-jetengine-diagnostics-adapter.php"
base = adapters / "class-mad4b-scp-adapter-base.php"

for path in (diag, base):
    assert path.is_file(), f"missing required source: {path}"

src = diag.read_text(encoding="utf-8")
base_src = base.read_text(encoding="utf-8")

for token in (
    "mad4b.jetengine-native-diagnostics.v1",
    "jetengine/native-diagnostics",
    "JetEngine Native Diagnostics",
    "source_evidence",
    "resolve_settings_from_source",
    "route_inventory",
    "capability_evidence",
    "classify_root_causes",
    "mcp_server_setting_discoverable",
    "mcp_server_enabled",
    "features_api_setting_discoverable",
    "features_api_enabled",
    "matched_setting_paths",
    "values_redacted",
    "jsonrpc_mcp_route_found",
    "mcp_tools_route_found",
    "mcp_tool_run_route_found",
    "current_user_can( 'manage_options' )",
    "no_credentials_inspected",
    "no_secrets_exposed",
    "settings_mutation' => false",
    "tools_call_executed' => false",
):
    assert token in src, f"JetEngine diagnostics contract missing: {token}"

# Source introspection must be bounded and return only derived identifiers/state.
for token in (
    "MAX_SOURCE_FILES",
    "MAX_SOURCE_BYTES",
    "MAX_SINGLE_FILE_BYTES",
    "safe_option_key",
    "raw_value_redacted",
    "matched_source_files",
    "setting_identifiers",
    "option_keys",
    "route_candidates",
):
    assert token in src, f"bounded/redacted source diagnostics missing: {token}"

# Provider discovery must reuse the already-created REST server and must never
# consume rest_api_init early.
assert "global $wp_rest_server" in src
assert "rest_get_server" not in src

# This surface is strictly diagnostic. No direct data/write/breakglass path and
# no provider-native mutation call is permitted.
for forbidden in (
    "$wpdb",
    "update_option(",
    "add_option(",
    "delete_option(",
    "database-raw-query",
    "BREAKGLASS",
    "call_tool(",
    "tools/call",
):
    assert forbidden not in src, f"JetEngine diagnostics must not contain: {forbidden}"

# The canonical bootstrap must load and boot the adapter.
assert "class-mad4b-scp-jetengine-diagnostics-adapter.php" in base_src
assert "MAD4B_SCP_JetEngine_Diagnostics_Bootstrap::boot();" in base_src

print("MAD4B JetEngine native diagnostics contract: PASS")
