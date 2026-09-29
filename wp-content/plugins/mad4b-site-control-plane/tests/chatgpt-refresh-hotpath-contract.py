#!/usr/bin/env python3
from pathlib import Path
from zipfile import ZipFile
import re

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
write_authority = (root / "includes/class-mad4b-scp-staging-write-authority.php").read_text(encoding="utf-8")
provider_contracts = (root / "includes/class-mad4b-scp-provider-contracts.php").read_text(encoding="utf-8")
oauth_autoconfig = (root / "includes/class-mad4b-scp-staging-oauth-autoconfig.php").read_text(encoding="utf-8")
audit = (root / "includes/class-mad4b-scp-audit.php").read_text(encoding="utf-8")
authorization = (root / "includes/class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
request_scope = (root / "includes/class-mad4b-scp-mcp-request-scope.php").read_text(encoding="utf-8")
read_consistency = (root / "includes/class-mad4b-scp-read-consistency.php").read_text(encoding="utf-8")
query_monitor = (root / "includes/class-mad4b-scp-query-monitor-evidence-bridge.php").read_text(encoding="utf-8")
mu_refresh = (root / "includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php").read_text(encoding="utf-8")
runtime_conflict = (root / "includes/class-mad4b-scp-mcp-runtime-conflict-guard.php").read_text(encoding="utf-8")
upgrade_continuity = (root / "includes/class-mad4b-scp-upgrade-continuity.php").read_text(encoding="utf-8")
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

# The current runtime registers all governed routes but only materializes the addressed server's tool
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

runtime_release_match = re.search(r"^release=(0\.4\.0-rc\.\d+)$", runtime_build, re.M)
assert runtime_release_match, "runtime release marker missing"
runtime_release = runtime_release_match.group(1)
assert f"Version: {runtime_release}" in entry
assert f"define( 'MAD4B_SCP_VERSION', '{runtime_release}' );" in entry

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
assert "MAD4B-BUILD-PROVENANCE.json" not in fingerprint_body
assert fingerprint_body.index("self::$request_build_fingerprint") < fingerprint_body.index("$files = array(")
assert "self::$request_build_fingerprint = hash_final( $ctx );" in fingerprint_body

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
skill_observe = skill_runtime.split("public static function observe( $force_explicit = false )", 1)[1].split("public static function current_status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in skill_observe
assert "if ( ! $force_explicit" in skill_observe
assert "return self::persisted_status();" in skill_observe
assert skill_observe.index("current_request_is_protocol_hotpath()") < skill_observe.index("self::evaluate()")

# Automatic Live Truth recovery at wp_abilities_api_init can perform DB/grant/
# provider inventory work. MCP discovery must not invoke it implicitly; explicit
# status tools remain fresh because their execute callbacks still call current truth.
live_truth_recovery = live_truth.split("private static function recover_runtime_authority()", 1)[1].split("public static function current_authority_status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in live_truth_recovery
assert live_truth_recovery.index("current_request_is_protocol_hotpath()") < live_truth_recovery.index("MAD4B_SCP_Staging_Write_Authority::eligible()")

# OAuth autoconfig runs on every plugin boot. Its durable diagnostic record must
# be write-on-change; a timestamp alone must never force a DB write per MCP request.
oauth_nonprod = oauth_autoconfig.split("private static function bootstrap_enrolled_nonproduction()", 1)[1].split("private static function bootstrap_production_readonly()", 1)[0]
assert "$existing = get_option( self::OPTION, array() );" in oauth_nonprod
assert "unset( $existing_semantic['updated_at'] );" in oauth_nonprod
assert "if ( $existing_semantic !== $record )" in oauth_nonprod
assert oauth_nonprod.index("$existing_semantic !== $record") < oauth_nonprod.index("$record['updated_at'] = gmdate( 'c' );")
assert oauth_nonprod.index("$existing_semantic !== $record") < oauth_nonprod.index("update_option( self::OPTION, $record, false );")

# Central plugin boot must classify protocol requests before any governance
# physical-schema readiness, dbDelta migration, or legacy audit option creation.
central_boot = plugin.split("public static function boot()", 1)[1].split("public static function boot_oauth_transport_if_effective()", 1)[0]
assert "$protocol_hotpath = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )" in central_boot
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in central_boot
schema_guard_pos = central_boot.index("$protocol_hotpath =")
assert schema_guard_pos < central_boot.index("MAD4B_SCP_Schema::is_ready()")
assert schema_guard_pos < central_boot.index("MAD4B_SCP_Schema::install_or_upgrade()")
assert schema_guard_pos < central_boot.index("add_option( MAD4B_SCP_Audit::LEGACY_OPTION")
assert "$schema_reconciliation = self::request_requires_schema_reconciliation();" in central_boot
schema_block = central_boot.split("MAD4B_SCP_Schema::is_ready()", 1)[0]
assert "! $protocol_hotpath" in schema_block
assert "$schema_reconciliation" in schema_block
assert "private static function request_requires_schema_reconciliation()" in plugin
schema_reconcile = plugin.split("private static function request_requires_schema_reconciliation()", 1)[1].split("private static function request_requires_skill_reconciliation()", 1)[0]
assert "defined( 'WP_CLI' )" in schema_reconcile
assert "0 === strpos( $page, 'mad4b-control-plane' )" in schema_reconcile
assert "if ( ! is_admin() ) return false;" in schema_reconcile

# Mutations independently re-prove physical schema readiness, so removing
# request-global schema scans does not weaken fail-closed write safety.
assert "MAD4B_SCP_Schema::is_ready()" in authorization
assert "MAD4B_SCP_Schema::critical_ready()" in authorization
authorize_mutation = authorization.split("public static function authorize_mutation(", 1)[1]
assert authorize_mutation.index("MAD4B_SCP_Schema::is_ready()") < authorize_mutation.index("MAD4B_SCP_Agent_Registry::exact_grant")
assert authorize_mutation.index("MAD4B_SCP_Schema::critical_ready()") < authorize_mutation.index("MAD4B_SCP_Agent_Registry::exact_grant")
assert "MAD4B_SCP_Schema::critical_ready()" in central_boot

# Audit chain integrity must remain fail-closed for mutations without forcing
# physical table/engine/legacy-chain/head inspection during MCP discovery.
plugin_boot = plugin.split("public static function boot()", 1)[1].split("public static function boot_oauth_transport_if_effective()", 1)[0]
assert "MAD4B_SCP_Audit::ensure_head_initialized()" in plugin_boot
audit_boot_prefix = plugin_boot.split("MAD4B_SCP_Audit::ensure_head_initialized()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in audit_boot_prefix
audit_record = audit.split("public static function record(", 1)[1].split("public static function ensure_head_initialized()", 1)[0]
assert "self::ensure_head_initialized();" in audit_record

# Binding OAuth transport hooks on init must use identity-only readiness.
oauth_boot = plugin.split("public static function boot_oauth_transport_if_effective()", 1)[1].split("private static function oauth_transport_enabled()", 1)[0]
assert "MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()" in oauth_boot
assert oauth_boot.index("runtime_identity_status()") < oauth_boot.index("MAD4B_SCP_OAuth_Request_Context_Guard::boot()")

# Runtime scope and protocol hotpath are distinct contracts. Developer planes
# are real MCP routes; OAuth metadata/protocol paths are latency-sensitive but
# must not keep the MCP Adapter alive.
mcp_routes = request_scope.split("private static function is_mad4b_mcp_route", 1)[1]
for route in [
    "/mcp/mad4b-chatgpt",
    "/mcp/mad4b-developer",
    "/mcp/mad4b-developer-breakglass",
]:
    assert route in mcp_routes, route
http_transport = request_scope.split("public static function current_request_is_http_mcp_transport()", 1)[1].split("public static function current_request_is_protocol_hotpath()", 1)[0]
assert "defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return false;" in http_transport
assert "self::is_mad4b_mcp_route" in http_transport

runtime_scope = request_scope.split("public static function current_request_requires_mcp_runtime()", 1)[1].split("public static function current_request_is_http_mcp_transport()", 1)[0]
assert "defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;" in runtime_scope

protocol_hotpath = request_scope.split("public static function current_request_is_protocol_hotpath()", 1)[1].split("private static function is_mad4b_mcp_route", 1)[0]
assert "self::current_request_is_http_mcp_transport()" in protocol_hotpath
assert "self::current_request_requires_mcp_runtime()" not in protocol_hotpath
for route in [
    "/.well-known/oauth-protected-resource",
    "/.well-known/oauth-authorization-server",
    "/oauth/mcp/jwks",
    "/oauth/mcp/token",
    "/mad4b/v1/oauth-protected-resource",
]:
    assert route in protocol_hotpath, route

print("mad4b.chatgpt-refresh-hotpath.v14: PASS")

# Post-update MU reconciliation must never execute filesystem mutation/audit work
# before an MCP/OAuth protocol request can be served.
mu_bootstrap = mu_refresh.split("public static function bootstrap()", 1)[1].split("public static function status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in mu_bootstrap
assert "'deferred_protocol_hotpath'" in mu_bootstrap
assert "$status['refresh_deferred'] = true;" in mu_bootstrap
assert mu_bootstrap.index("current_request_is_protocol_hotpath()") < mu_bootstrap.index("hash_file(")
assert mu_bootstrap.index("current_request_is_protocol_hotpath()") < mu_bootstrap.index("@copy(")
assert mu_bootstrap.index("current_request_is_protocol_hotpath()") < mu_bootstrap.index("MAD4B_SCP_Audit::record(")

scope_boot_pos = entry.index("MAD4B_SCP_MCP_Request_Scope::bootstrap();")
mu_boot_pos = entry.index("MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();")
assert scope_boot_pos < mu_boot_pos, "request scope must be classified before MU refresh bootstrap"

# Runtime conflict repair is also a mutation/recovery transaction and must be
# deferred on protocol hotpaths before active_plugins or MU/audit repair work.
conflict_bootstrap = runtime_conflict.split("public static function bootstrap()", 1)[1].split("public static function status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in conflict_bootstrap
assert "'repair_deferred_protocol_hotpath'" in conflict_bootstrap
assert "$status['repair_deferred'] = true;" in conflict_bootstrap
assert conflict_bootstrap.index("current_request_is_protocol_hotpath()") < conflict_bootstrap.index("get_option( 'active_plugins'")
assert conflict_bootstrap.index("current_request_is_protocol_hotpath()") < conflict_bootstrap.index("update_option( 'active_plugins'")
assert conflict_bootstrap.index("current_request_is_protocol_hotpath()") < conflict_bootstrap.index("MAD4B_SCP_Audit::record(")

# Query Monitor telemetry bootstrap needs only packaged identity. Full runtime
# hashing remains explicit/final acceptance and must not execute on every request.
qm_pin = query_monitor.split("private static function pin_request_build_fingerprint()", 1)[1].split("private static function request_build_fingerprint()", 1)[0]
assert "build_provenance_identity_status()" in qm_pin
assert "build_provenance_status()" not in qm_pin
assert "hash_file(" not in qm_pin

qm_flush = query_monitor.split("public static function capture_and_flush()", 1)[1].split("/** @internal Pure seam", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in qm_flush
protocol_branch = qm_flush.split("if ( $protocol_hotpath )", 1)[1].split("$telemetry['observed_request_count']", 2)[0]
assert "query_monitor_events()" not in protocol_branch
assert "performance_sample(" not in protocol_branch
assert "(int) $telemetry['request_coverage']['mcp'] > 0" in qm_flush
assert qm_flush.index("if ( $protocol_hotpath )") < qm_flush.index("$sample = self::performance_sample( $class )")
assert qm_flush.index("if ( $protocol_hotpath )") < qm_flush.index("foreach ( self::query_monitor_events()")

qm_db_bootstrap = query_monitor.split("public static function maybe_enable_db_attribution()", 1)[1].split("private static function bounded_loader_contents()", 1)[0]
assert "0 !== strpos( $page, 'mad4b-control-plane' )" in qm_db_bootstrap
assert qm_db_bootstrap.index("0 !== strpos( $page, 'mad4b-control-plane' )") < qm_db_bootstrap.index("@symlink(")
assert qm_db_bootstrap.index("0 !== strpos( $page, 'mad4b-control-plane' )") < qm_db_bootstrap.index("@fopen(")

# Ordinary wp-admin pages (including plugins.php) get at most one lightweight
# coverage marker per build and must not execute full Query Monitor profiling.
assert "private static function current_request_is_mad4b_admin_surface()" in query_monitor
assert "if ( 'wp_admin' === $class && ! self::current_request_is_mad4b_admin_surface() )" in qm_flush
admin_budget = qm_flush.split("if ( 'wp_admin' === $class && ! self::current_request_is_mad4b_admin_surface() )", 1)[1].split("$telemetry['observed_request_count']", 2)[0]
assert "query_monitor_events()" not in admin_budget
assert "performance_sample(" not in admin_budget
assert "(int) $telemetry['request_coverage']['wp_admin'] > 0" in qm_flush
assert qm_flush.index("if ( 'wp_admin' === $class && ! self::current_request_is_mad4b_admin_surface() )") < qm_flush.index("$sample = self::performance_sample( $class )")

# Upgrade continuity may persist recovered profile/OAuth state, so migration
# recovery must be deferred during MCP/OAuth protocol requests.
upgrade_preboot = upgrade_continuity.split("public static function pre_boot()", 1)[1].split("public static function boot()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in upgrade_preboot
assert "'deferred_protocol_hotpath'" in upgrade_preboot
assert upgrade_preboot.index("current_request_is_protocol_hotpath()") < upgrade_preboot.index("recover_verified_read_continuity()")

# The direct ChatGPT "session-safe" report must remain genuinely bounded:
# no full package hashing, deep authority scan, live Skill reconciliation,
# network-capable self-update status, or runtime write-catalog rebuild.
session_safe = read_consistency.split("public static function session_safe_diagnostics", 1)[1].split("private static function compact_bundle_result", 1)[0]
assert "self::session_safe_bundle_checks( $bundle )" in session_safe
assert "self::request_metrics()" in session_safe
for marker in (
    "full_runtime_provenance_hash",
    "deep_write_authority_scan",
    "live_skill_filesystem_reconciliation",
    "live_update_manifest_network_fetch",
    "write_catalog_runtime_rebuild",
):
    assert marker in session_safe, marker

safe_checks = read_consistency.split("private static function session_safe_bundle_checks", 1)[1].split("private static function build_projection", 1)[0]
for forbidden in (
    "MAD4B_SCP_Staging_Certification::status",
    "workflow_provider_projection()",
    "deep_build_projection()",
    "deep_write_authority_projection()",
    "deep_skills_projection()",
    "deep_update_projection()",
    "deep_catalog_projection()",
):
    assert forbidden not in safe_checks, forbidden

snapshot_build = read_consistency.split("private static function build_projection()", 1)[1].split("private static function deep_build_projection()", 1)[0]
assert "build_provenance_identity_status()" in snapshot_build
assert "build_provenance_status()" not in snapshot_build
assert "full_runtime_hash_validation_deferred" in snapshot_build

snapshot_catalog = read_consistency.split("private static function catalog_projection()", 1)[1].split("private static function deep_catalog_projection()", 1)[0]
assert "MAD4B_SCP_Servers::registration_status()" in snapshot_catalog
assert "MAD4B_SCP_Staging_Write_Authority::persisted_status()" in snapshot_catalog
assert "MAD4B_SCP_Servers::write_tools()" not in snapshot_catalog
assert "MAD4B_SCP_Servers::chatgpt_tools()" not in snapshot_catalog
assert "runtime_catalog_rebuild_deferred" in snapshot_catalog

safe_authority = read_consistency.split("private static function write_authority_projection()", 1)[1].split("private static function deep_write_authority_projection()", 1)[0]
assert "MAD4B_SCP_Staging_Write_Authority::persisted_status()" in safe_authority
assert "candidate_binding_status()" in safe_authority
assert "MAD4B_SCP_Live_Truth::current_authority_status()" not in safe_authority
assert "deep_authority_scan_deferred" in safe_authority

safe_skills = read_consistency.split("private static function skills_projection()", 1)[1].split("private static function deep_skills_projection()", 1)[0]
assert "persisted_status()" in safe_skills
assert "current_status()" not in safe_skills

safe_update = read_consistency.split("private static function update_projection()", 1)[1].split("private static function deep_update_projection()", 1)[0]
assert "MAD4B_SCP_Self_Update::cached_status" in safe_update
assert "MAD4B_SCP_Self_Update::status(" not in safe_update

# Deep explicit bundles retain full integrity/certification semantics.
deep_bundle = read_consistency.split("private static function bundle_checks", 1)[1].split("private static function session_safe_bundle_checks", 1)[0]
for marker in (
    "deep_build_projection()",
    "deep_write_authority_projection()",
    "deep_skills_projection()",
    "deep_update_projection()",
    "MAD4B_SCP_Staging_Certification::status",
    "deep_catalog_projection()",
):
    assert marker in deep_bundle, marker

metrics = read_consistency.split("private static function request_metrics()", 1)[1].split("private static function transaction_id", 1)[0]
for marker in (
    "memory_get_usage",
    "memory_get_peak_usage",
    "get_included_files",
    "get_num_queries",
    "external_network_calls_started_by_report",
    "deep_integrity_hashes_started_by_report",
):
    assert marker in metrics, marker

deep_bundle = read_consistency.split("private static function bundle_checks", 1)[1].split("private static function session_safe_bundle_checks", 1)[0]
safe_bundle = read_consistency.split("private static function session_safe_bundle_checks", 1)[1].split("private static function build_projection", 1)[0]
assert "self::connection_projection()" in deep_bundle
assert "self::reconnect_projection()" in deep_bundle
assert "self::session_safe_connection_projection()" not in deep_bundle
assert "self::session_safe_reconnect_projection()" not in deep_bundle
assert "self::session_safe_connection_projection()" in safe_bundle
assert "self::session_safe_reconnect_projection()" in safe_bundle
assert "self::connection_projection()" not in safe_bundle
assert "self::reconnect_projection()" not in safe_bundle

# Session-safe connection identity consumes persisted handshake evidence only.
# Live status may rebuild catalogs and hash the bounded runtime file set.
assert "public static function persisted_identity_status()" in handshake
persisted_handshake = handshake.split("public static function persisted_identity_status()", 1)[1].split("public static function status()", 1)[0]
assert "get_option( self::OPTION" in persisted_handshake
for forbidden in (
    "expected_tool_names()",
    "expected_write_tool_names()",
    "expected_eligible_write_tool_names()",
    "build_fingerprint()",
    "hash_file(",
):
    assert forbidden not in persisted_handshake, forbidden
assert "'live_verification_deferred' => true" in persisted_handshake
safe_connection = read_consistency.split("private static function session_safe_connection_projection()", 1)[1].split("private static function session_safe_reconnect_projection()", 1)[0]
assert "persisted_identity_status()" in safe_connection
assert "MAD4B_SCP_External_Handshake_Evidence::status()" not in safe_connection

# Lightweight authority projections consume persisted runtime evidence only and
# must not rebuild approval-policy/catalog eligibility.
assert "public static function persisted_status()" in write_authority
persisted_authority = write_authority.split("public static function persisted_status()", 1)[1].split("public static function status()", 1)[0]
assert "self::raw_status()" in persisted_authority
assert "approval_policy_projection()" not in persisted_authority
assert "approval_policy_projection_deferred" in persisted_authority
safe_authority = read_consistency.split("private static function write_authority_projection()", 1)[1].split("private static function deep_write_authority_projection()", 1)[0]
assert "persisted_status()" in safe_authority
assert "MAD4B_SCP_Staging_Write_Authority::status()" not in safe_authority

# Provider header/version inspection is request-local memoized so repeated
# connection/session-safe projections do not re-read plugin headers.
assert "private static $installed_version_cache = array();" in provider_contracts
version_reader = provider_contracts.split("private static function installed_version_for_contract", 1)[1].split("private static function composite_version_string", 1)[0]
assert "array_key_exists( $file, self::$installed_version_cache )" in version_reader
assert version_reader.index("array_key_exists( $file, self::$installed_version_cache )") < version_reader.index("get_file_data(")
assert "self::$installed_version_cache[ $file ] = trim" in version_reader
