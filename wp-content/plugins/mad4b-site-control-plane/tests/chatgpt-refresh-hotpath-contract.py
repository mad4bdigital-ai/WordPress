#!/usr/bin/env python3
from pathlib import Path
from zipfile import ZipFile

root = Path(__file__).resolve().parents[1]
servers = (root / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
plugin = (root / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
entry = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime_build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")
adapter_zip = root.parent / "mcp-adapter.zip"

# Upstream 0.6.1 eagerly materializes Ability -> Tool DTOs while each server is
# constructed during mcp_adapter_init. This is the structural reason shrinking
# only the mad4b-chatgpt response payload is insufficient.
with ZipFile(adapter_zip) as zf:
    adapter = zf.read("mcp-adapter/includes/Core/McpAdapter.php").decode("utf-8")
    server = zf.read("mcp-adapter/includes/Core/McpServer.php").decode("utf-8")
    registry = zf.read("mcp-adapter/includes/Core/McpComponentRegistry.php").decode("utf-8")
    ability_tool = zf.read("mcp-adapter/includes/Domain/Tools/RegisterAbilityAsMcpTool.php").decode("utf-8")

for marker in [
    "add_action( 'rest_api_init', array( self::$instance, 'init' ), 15 )",
    "do_action( 'mcp_adapter_init', $this )",
]:
    assert marker in adapter, marker
assert "$this->setup_components( $tools, $resources, $prompts, $mcp_transports );" in server
assert "$this->component_registry->register_tools( $tools );" in server
assert "$mcp_tool = McpTool::fromAbility( $ability );" in registry
assert "SchemaTransformer::transform_to_object_schema" in ability_tool

# rc.58 registers every route but only materializes the addressed server's tool
# DTOs on an HTTP MCP request.
for marker in [
    "private static function current_request_server_id()",
    "private static function should_materialize_server_tools( $server_id, $target_server_id )",
    "$target_server_id = self::current_request_server_id();",
    "$materialize = static function ( $server_id, $factory ) use ( $target_server_id )",
    "if ( ! self::should_materialize_server_tools( $server_id, $target_server_id ) ) return array();",
    "'materialized' => (bool) $materialized",
    "'tool_count' => count( $tools )",
]:
    assert marker in servers, marker
assert servers.count("$this->create( $adapter,") == 7
for server_id in [
    "mad4b-read",
    "mad4b-chatgpt",
    "mad4b-enrollment",
    "mad4b-content",
    "mad4b-write",
    "mad4b-admin",
    "mad4b-breakglass",
]:
    assert f"$materialize( '{server_id}'" in servers, server_id

# Request-local memoization must be effective while rest_api_init is still
# constructing the MCP servers; requiring rest_api_init completion would miss
# the hot path.
for marker in [
    "private static $registered_adapter_write_candidates_cache = null;",
    "private static $external_write_tools_cache = null;",
    "private static $chatgpt_tools_cache = null;",
    "private static $provider_for_ability_cache = array();",
    "private static function catalog_cacheable()",
    "did_action( 'wp_abilities_api_init' ) > 0",
]:
    assert marker in servers, marker
cache_body = servers.split("private static function catalog_cacheable()", 1)[1].split("private static function registered_adapter_write_candidates()", 1)[0]
assert "did_action( 'rest_api_init' )" not in cache_body
for forbidden in [
    "set_transient( 'mad4b_scp_chatgpt_catalog",
    "update_option( 'mad4b_scp_chatgpt_catalog",
]:
    assert forbidden not in servers

# Generic MCP transport must not run seed/provider Skill filesystem/plugin
# reconciliation before initialize/tools-list.
reconcile = plugin.split("private static function request_requires_skill_reconciliation()", 1)[1].split("private static function bind_local_oauth_subject_compatibility()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_requires_mcp_runtime() ) return false;" in reconcile
assert "defined( 'WP_CLI' )" in reconcile
assert "0 === strpos( $page, 'mad4b-control-plane' )" in reconcile

assert "Version: 0.4.0-rc.58" in entry
assert "define( 'MAD4B_SCP_VERSION', '0.4.0-rc.58' );" in entry
assert "release=0.4.0-rc.58" in runtime_build

print("mad4b.chatgpt-refresh-hotpath.v1: PASS")
