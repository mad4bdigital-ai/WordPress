#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapters = root / "includes" / "adapters"
base = adapters / "class-mad4b-scp-adapter-base.php"
semantics = adapters / "class-mad4b-scp-mutation-semantics-adapter.php"
translation = adapters / "class-mad4b-scp-translation-bridge-adapter.php"
jetengine_client = adapters / "class-mad4b-scp-jetengine-mcp-client.php"

for path in (base, semantics, translation, jetengine_client):
    assert path.is_file(), f"missing required source: {path}"

base_src = base.read_text(encoding="utf-8")
semantics_src = semantics.read_text(encoding="utf-8")
translation_src = translation.read_text(encoding="utf-8")
jetengine_client_src = jetengine_client.read_text(encoding="utf-8")

# Mutation semantics must be loaded and booted from the canonical adapter bootstrap.
assert "class-mad4b-scp-mutation-semantics-adapter.php" in base_src
assert "MAD4B_SCP_Mutation_Semantics_Adapter::boot();" in base_src

# Hard deletes and provider-native development/import operations must declare
# irreversible semantics instead of being mistaken for missing rollback support.
for token in (
    "mad4b.mutation-semantics.v2",
    "mad4b/mutation-semantics",
    "mad4b/taxonomy-delete-term",
    "wordpress_hard_delete_cannot_restore_same_term_identity",
    "'reversible' => false",
    "'impact' => 'high'",
    "jetengine/create-cpt",
    "jetengine/create-taxonomy",
    "jetengine/import-configuration",
    "mad4b/provider-import-content",
):
    assert token in semantics_src, f"mutation semantics contract missing: {token}"

# Approval planning must validate nested target input before the canonical
# approval-plan callback can create a Pending Ticket.
for token in (
    "wp_register_ability_args",
    "mad4b/approval-plan",
    "validate_planned_target",
    "->validate_input( $operation_input )",
    "mad4b_approval_plan_recursive_target_denied",
    "mad4b_approval_target_input_invalid",
    "mad4b_approval_target_validation_unavailable",
):
    assert token in semantics_src, f"approval planning validation missing: {token}"

# When JetEngine's native transport is present, approval planning must also
# bind the nested provider payload to the exact live native tool contract.
for token in (
    "MAD4B_SCP_JetEngine_MCP_Client",
    "MAD4B_SCP_JetEngine_MCP_Client::available()",
    "MAD4B_SCP_JetEngine_MCP_Client::validate_tool_input",
    "expected_native_ability",
    "expected_schema_sha256",
    "mad4b_approval_target_native_input_invalid",
):
    assert token in semantics_src, f"JetEngine approval planning binding missing: {token}"

for token in (
    "mad4b.jetengine-mcp-client.v4",
    "validate_tool_input",
    "rest_validate_value_from_schema",
    "mad4b_jetengine_mcp_input_invalid",
    "mad4b_jetengine_mcp_input_schema_unavailable",
    "mad4b_jetengine_mcp_input_validation_unavailable",
    "mad4b_jetengine_mcp_schema_drift",
):
    assert token in jetengine_client_src, f"JetEngine native input validation missing: {token}"

# Provider discovery must never instantiate the REST server. Doing so before the
# MCP Adapter has attached its rest_api_init callbacks consumes the lifecycle and
# leaves governed MCP server objects without their REST routes. Negative endpoint
# and failed tool discovery results must also remain retryable later in the request.
for token in (
    "initialized_rest_server",
    "did_action( 'rest_api_init' )",
    "global $wp_rest_server",
    "self::$tools = $tools",
):
    assert token in jetengine_client_src, f"JetEngine REST lifecycle hardening missing: {token}"
assert "rest_get_server()->" not in jetengine_client_src, "JetEngine discovery must not instantiate the WordPress REST server"
assert "self::$tools = array();" not in jetengine_client_src, "JetEngine tools must not cache a failed/early empty discovery"

# JetEngine discovery must support both first-party transports without enabling
# provider settings or creating credentials: JSON-RPC MCP when enabled and the
# native mcp-tools registry/run endpoints as a bounded fallback.
for token in (
    "registry_endpoint",
    "/jet-engine/v1/mcp-tools",
    "/mcp-tools/run/",
    "native-rest-tools",
    "mcp-jsonrpc",
    "transport_status",
    "rest_registry_tools",
    "call_rest_tool",
    "provider_native_channel",
):
    assert token in jetengine_client_src, f"JetEngine dual native transport contract missing: {token}"

# Both transports must preserve exact native schema binding before execution.
assert "expected_schema_sha256" in semantics_src
assert "schema_sha256" in jetengine_client_src
assert "validate_tool_input( $tool_name, $arguments, $expected_schema_sha256 )" in jetengine_client_src
assert "call_rest_tool( $tool_name, $arguments )" in jetengine_client_src

# Translation reads may keep provider=auto, but write schemas must be rewritten
# to require an exact provider and runtime validation must deny missing/auto.
assert "array( 'auto', 'wpml', 'polylang' )" in translation_src
for token in (
    "mad4b/translation-set-post-language",
    "mad4b/translation-link-posts",
    "bind_translation_provider_schema",
    "'enum' => array( 'wpml', 'polylang' )",
    "in_array( 'provider', $required, true )",
    "mad4b_translation_provider_binding_required",
    "wp_ability_validate_input",
):
    assert token in semantics_src, f"translation provider binding missing: {token}"

# These governance/validation layers must not gain direct data or breakglass side channels.
for src, label in (
    (semantics_src, "mutation-semantics"),
    (jetengine_client_src, "jetengine-native-client"),
):
    assert "$wpdb" not in src, f"{label} must not use direct SQL"
    assert "database-raw-query" not in src, f"{label} must not expose raw SQL"
    assert "BREAKGLASS" not in src.upper(), f"{label} must not expose breakglass"

print("MAD4B mutation planning semantics contract: PASS")
