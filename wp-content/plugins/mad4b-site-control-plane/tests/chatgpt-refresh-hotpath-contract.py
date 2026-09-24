#!/usr/bin/env python3
from pathlib import Path
from zipfile import ZipFile

root = Path(__file__).resolve().parents[1]
servers = (root / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
plugin = (root / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")
handshake = (root / "includes/class-mad4b-scp-external-handshake-evidence.php").read_text(encoding="utf-8")
observer = (root / "includes/class-mad4b-scp-live-acceptance-observer.php").read_text(encoding="utf-8")
skill_abilities = (root / "includes/class-mad4b-scp-skill-abilities.php").read_text(encoding="utf-8")
skill_snapshot = (root / "includes/class-mad4b-scp-skill-snapshot-identity.php").read_text(encoding="utf-8")
skill_runtime = (root / "includes/class-mad4b-scp-skill-runtime-certification.php").read_text(encoding="utf-8")
finalizer = (root / "includes/class-mad4b-scp-live-acceptance-finalizer.php").read_text(encoding="utf-8")
live_truth = (root / "includes/class-mad4b-scp-live-truth.php").read_text(encoding="utf-8")
oauth_autoconfig = (root / "includes/class-mad4b-scp-staging-oauth-autoconfig.php").read_text(encoding="utf-8")
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

# rc.59 registers all governed routes but only materializes the addressed server's tool
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
assert servers.count("$this->create( $adapter,") == 9
assert "$registry = null;" in servers
assert "$registry_for_surface = static function () use ( &$registry )" in servers
register_body = servers.split("public function register_servers( $adapter )", 1)[1].split("private function create( $adapter", 1)[0]
pre_target = register_body.split("$target_server_id = self::current_request_server_id();", 1)[0]
assert "MAD4B_SCP_Adapter_Registry::instance()" not in pre_target

provider_body = servers.split("public static function provider_for_ability( $server_id, $ability_name )", 1)[1].split("public static function registration_status()", 1)[0]
assert "( 'mad4b-chatgpt' !== $server_id && self::is_external_write_candidate( $ability_name ) )" in provider_body
chatgpt_provider = provider_body.split("if ( 'mad4b-chatgpt' === $server_id )", 1)[1]
core_shortcut = chatgpt_provider.split("if ( self::is_external_write_candidate( $ability_name ) )", 1)[0]
assert "self::core_tools( 'mad4b-chatgpt' )" in core_shortcut
assert "self::core_tools( $core_server )" in core_shortcut
for server_id in [
    "mad4b-read",
    "mad4b-chatgpt",
    "mad4b-enrollment",
    "mad4b-content",
    "mad4b-write",
    "mad4b-admin",
    "mad4b-developer",
    "mad4b-developer-breakglass",
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

assert "Version: 0.4.0-rc.59" in entry
assert "define( 'MAD4B_SCP_VERSION', '0.4.0-rc.59' );" in entry
assert "release=0.4.0-rc.59" in runtime_build

# External evidence must not put dynamic provider certification back onto the
# initialize/tools-list response path. Stable logical catalog identity is
# captured synchronously; live eligibility is projected only when status is read.
handshake_capture = handshake.split("private static function capture_tools_list", 1)[1].split("private static function normalize_tool_names", 1)[0]
assert "expected_write_tool_names()" in handshake_capture
assert "expected_eligible_write_tool_names()" not in handshake_capture
assert "blocked_write_tool_names()" not in handshake_capture
assert "'runtime_projection_deferred' => true" in handshake_capture

observer_init = observer.split("private static function capture_external_initialize", 1)[1].split("private static function capture_external_tools_list", 1)[0]
assert "expected_write_tool_names()" not in observer_init
observer_inventory = observer.split("public static function inventory_attestation_from_names", 1)[1].split("public static function external_handshake_attestation_status()", 1)[0]
assert "eligible_write_tool_names()" not in observer_inventory
assert "blocked_write_tool_names()" not in observer_inventory
assert "'runtime_projection_deferred' => true" in observer_inventory

# Real ChatGPT Refresh must not repeat filesystem Skill scans or perform full
# package byte hashing during the tools/list response/shutdown lifecycle.
for marker in [
    "private static $request_cache = null;",
    "public static function build_request_cached()",
]:
    assert marker in skill_snapshot, marker
assert "build_request_cached" in skill_abilities
snapshot_anchor_body = skill_abilities.split("public static function snapshot_anchor()", 1)[1].split("private static function ability_description", 1)[0]
assert "build_request_cached" in snapshot_anchor_body

snapshot_finalize = skill_abilities.split("public static function finalize_external_snapshot_observation()", 1)[1].split("/** @internal Pure parser", 1)[0]
assert "build_provenance_identity_status" in snapshot_finalize
assert "build_provenance_status()" not in snapshot_finalize
assert "runtime_manifest_match" not in snapshot_finalize

identity_body = observer.split("public static function build_provenance_identity_status()", 1)[1].split("public static function build_provenance_status()", 1)[0]
assert "full_runtime_hash_validation_deferred" in identity_body
assert "hash_file(" not in identity_body
assert "filesize(" not in identity_body

# Full byte-for-byte provenance remains mandatory on the explicit/final
# acceptance path; the optimization moves it off Refresh rather than weakening it.
full_provenance_body = observer.split("public static function build_provenance_status()", 1)[1].split("private static function provenance_manifest()", 1)[0]
assert "hash_file(" in full_provenance_body
assert "build_provenance_status()" in finalizer

# External handshake build identity is allowed to hash its bounded file set only
# once per PHP request; tools/list calls it repeatedly during evidence capture.
for marker in [
    "private static $request_build_fingerprint = null;",
    "is_string( self::$request_build_fingerprint )",
    "self::$request_build_fingerprint = hash_final( $ctx );",
]:
    assert marker in handshake, marker
fingerprint_body = handshake.split("public static function build_fingerprint()", 1)[1].split("private static function capture_runtime_allowed", 1)[0]
assert fingerprint_body.count("hash_file(") == 1
assert "MAD4B-BUILD-PROVENANCE.json" in fingerprint_body
assert "'mad4b.build-provenance.v1'" in fingerprint_body
assert fingerprint_body.index("MAD4B-BUILD-PROVENANCE.json") < fingerprint_body.index("$files = array(")
assert fingerprint_body.index("self::$request_build_fingerprint") < fingerprint_body.index("$files = array(")

# tools/list observers only need tool names/identity anchors. They must not
# serialize+deserialize the full schema payload merely to inspect names.
handshake_tools_body = handshake.split("private static function capture_tools_list", 1)[1].split("private static function normalize_tool_names", 1)[0]
assert "normalize_value( $response->get_data() )" not in handshake_tools_body
observer_tools_body = observer.split("private static function capture_external_tools_list", 1)[1].split("public static function inventory_attestation_from_names", 1)[0]
assert "normalize_value( $response->get_data() )" not in observer_tools_body
skill_tools_body = skill_abilities.split("public static function observe_external_tools_list", 1)[1].split("public static function finalize_external_snapshot_observation", 1)[0]
assert "normalize_value( $rest->get_data() )" not in skill_tools_body
for body in [handshake_tools_body, observer_tools_body, skill_tools_body]:
    assert "get_object_vars" in body
    assert "['tools']" in body

# Repeated MCP discovery requests must not persist generic telemetry counters
# once request coverage for the current build is already established. Warnings
# still mark telemetry dirty independently through record_warning().
mark_request_body = observer.split("private static function mark_current_request()", 1)[1].split("private static function request_class()", 1)[0]
assert "'mcp' === $class" in mark_request_body
assert "(int) $telemetry['request_coverage'][ $class ] > 0" in mark_request_body
assert "return;" in mark_request_body

# The canonical Abilities hook fires during MCP transport construction. Skill
# runtime certification is expensive (provider/registry/filesystem evaluation)
# and must return persisted evidence before evaluate() on every MCP request.
skill_observe = skill_runtime.split("public static function observe()", 1)[1].split("public static function current_status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_requires_mcp_runtime()" in skill_observe
assert "return self::persisted_status();" in skill_observe
assert skill_observe.index("current_request_requires_mcp_runtime()") < skill_observe.index("self::evaluate()")

# Automatic Live Truth recovery at wp_abilities_api_init can perform DB/grant/
# provider inventory work. MCP discovery must not invoke it implicitly; explicit
# status tools remain fresh because their execute callbacks still call current truth.
live_truth_recovery = live_truth.split("private static function recover_runtime_authority()", 1)[1].split("public static function current_authority_status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_requires_mcp_runtime()" in live_truth_recovery
assert live_truth_recovery.index("current_request_requires_mcp_runtime()") < live_truth_recovery.index("MAD4B_SCP_Staging_Write_Authority::eligible()")

# OAuth autoconfig runs on every plugin boot. Its durable diagnostic record must
# be write-on-change; a timestamp alone must never force a DB write per MCP request.
oauth_nonprod = oauth_autoconfig.split("private static function bootstrap_enrolled_nonproduction()", 1)[1].split("private static function bootstrap_production_readonly()", 1)[0]
assert "$existing = get_option( self::OPTION, array() );" in oauth_nonprod
assert "unset( $existing_semantic['updated_at'] );" in oauth_nonprod
assert "if ( $existing_semantic !== $record )" in oauth_nonprod
assert oauth_nonprod.index("$existing_semantic !== $record") < oauth_nonprod.index("$record['updated_at'] = gmdate( 'c' );")
assert oauth_nonprod.index("$existing_semantic !== $record") < oauth_nonprod.index("update_option( self::OPTION, $record, false );")

print("mad4b.chatgpt-refresh-hotpath.v10: PASS")
