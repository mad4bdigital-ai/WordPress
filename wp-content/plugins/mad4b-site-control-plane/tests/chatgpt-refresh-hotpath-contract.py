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
registration_bridge = (root / "includes/class-mad4b-scp-mcp-registration-bridge.php").read_text(encoding="utf-8")
self_update = (root / "includes/class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
repo_root = root.parents[2]
external_diagnostic_workflow = (repo_root / ".github/workflows/mad4b-wpml-external-diagnostic.yml").read_text(encoding="utf-8")
read_consistency = (root / "includes/class-mad4b-scp-read-consistency.php").read_text(encoding="utf-8")
query_monitor = (root / "includes/class-mad4b-scp-query-monitor-evidence-bridge.php").read_text(encoding="utf-8")
mu_refresh = (root / "includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php").read_text(encoding="utf-8")
runtime_conflict = (root / "includes/class-mad4b-scp-mcp-runtime-conflict-guard.php").read_text(encoding="utf-8")
upgrade_continuity = (root / "includes/class-mad4b-scp-upgrade-continuity.php").read_text(encoding="utf-8")
reconnect = (root / "includes/class-mad4b-scp-reconnect-hardening.php").read_text(encoding="utf-8")
provider_policy = (root / "includes/class-mad4b-scp-provider-diagnostic-policy.php").read_text(encoding="utf-8")
skills_ui = (root / "includes/class-mad4b-scp-skills-admin-ui.php").read_text(encoding="utf-8")
oauth_canary = (root / "includes/class-mad4b-scp-local-oauth-browser-canary.php").read_text(encoding="utf-8")
plugin_discovery = (root / "includes/class-mad4b-scp-plugin-discovery.php").read_text(encoding="utf-8")
runtime_components = (root / "includes/adapters/class-mad4b-scp-runtime-component-adapters.php").read_text(encoding="utf-8")
google_drive = (root / "includes/class-mad4b-scp-google-drive-context.php").read_text(encoding="utf-8")
context_ui = (root / "includes/class-mad4b-scp-context-admin-ui.php").read_text(encoding="utf-8")
entry = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime_build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")
adapter_zip = root.parent / "mcp-adapter.zip"

# Unrelated REST/AJAX must exit before the full 5+ MiB Control Plane include
# surface is parsed. Keep only Site Profile + request scope so the official MCP
# Adapter can still be disarmed on the exact governed site.
for marker in (
    "MAD4B_SCP_EARLY_ZERO_TOUCH_REASON",
    "'foreign_rest'",
    "'foreign_admin_ajax'",
    "0 === strpos( $mad4b_scp_early_rest_path, '/mcp/mad4b-' )",
    "0 === strpos( $mad4b_scp_early_rest_path, '/mad4b/' )",
    "class-mad4b-scp-site-profile.php",
    "class-mad4b-scp-mcp-request-scope.php",
    "MAD4B_SCP_MCP_Request_Scope::bootstrap();",
):
    assert marker in entry, marker
early_gate = entry.index("$mad4b_scp_early_zero_touch_reason = '';")
first_full_runtime_require = entry.index("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-environment.php';")
assert early_gate < first_full_runtime_require
early_return_block = entry.split("if ( '' !== $mad4b_scp_early_zero_touch_reason ) {", 1)[1].split("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile.php';", 2)[1]
assert "return;" in early_return_block


# Every ordinary Control Plane GET/HEAD screen is request-serving by default.
# Only Connection > Endpoints remains an explicit browser deep-diagnostic route;
# POST/admin-post actions never enter the read-only classifier.
passive_router = request_scope.split("private static function passive_admin_route(", 1)[1].split("/** @internal Pure regression seam", 1)[0]
for page in (
    "mad4b-control-plane",
    "mad4b-control-plane-connection",
    "mad4b-control-plane-chatgpt",
    "mad4b-control-plane-context",
    "mad4b-control-plane-site-profile",
    "mad4b-control-plane-skills",
    "mad4b-control-plane-performance",
    "mad4b-control-plane-content-pipeline",
    "mad4b-adapter-coverage",
    "mad4b-runtime-components",
    "mad4b-approval-decisions",
):
    assert page in passive_router or "0 === strpos( $page, 'mad4b-control-plane-' )" in passive_router, page
assert "if ( 'mad4b-control-plane-connection' === $page ) return true;" in passive_router
assert "current_admin_request_method_is_read_only()" in request_scope
assert "array( 'GET', 'HEAD' )" in request_scope

# Deep endpoint work is signed AJAX; GET and obsolete HTML POST stay passive.
assert "public static function explicit_rest_materialization_allowed()" in provider_policy
assert "MAD4B_SCP_Endpoint_Diagnostic::is_authorized_request()" in provider_policy
endpoint_worker = (root / "includes/class-mad4b-scp-endpoint-diagnostic.php").read_text(encoding="utf-8")
assert endpoint_worker.index("wp_verify_nonce") < endpoint_worker.index("begin_endpoint_diagnostic") < endpoint_worker.index("rest_get_server()")
connection_ui = (root / "includes/class-mad4b-scp-connection-admin-ui.php").read_text(encoding="utf-8")
endpoint_client = (root / "assets/connection-endpoint-diagnostics.js").read_text(encoding="utf-8")
assert "wp_create_nonce( 'mad4b_connection_deep_endpoints' )" in connection_ui
assert "Run Deep Endpoint Diagnostic" in connection_ui
assert "self::snapshot( false )" in connection_ui
assert "$asset_hash = is_readable( $asset_path ) ? @hash_file( 'sha256', $asset_path ) : false;" in connection_ui
assert "substr( $asset_hash, 0, 16 )" in connection_ui
assert "'assetVersion' => $asset_version" in connection_ui
assert "mu_proof: config.muProof || ''" in endpoint_client
assert "mad4b_endpoint_diagnostic_mu_proof_invalid" in endpoint_worker
assert "mad4b_endpoint_diagnostic_mu_bootstrap_not_ready" in endpoint_worker
assert "'runtime_bootstrap' => $runtime_bootstrap" in endpoint_worker
assert "$_POST" not in connection_ui
assert "rest_get_server(" not in connection_ui
prime = plugin.split("public static function prime_admin_mcp_runtime()", 1)[1].split("public static function governance_bootstrap_error_code()", 1)[0]
assert "rest_get_server(" not in prime

# Passive MAD4B admin is part of zero-touch policy, so Plugin::boot() returns
# after navigation registration and before authority/OAuth/provider lifecycle.
assert "public static function current_request_is_passive_mad4b_admin()" in provider_policy
zero_touch = provider_policy.split("public static function current_request_is_zero_touch_surface()", 1)[1].split("public static function zero_touch_reason()", 1)[0]
assert "self::current_request_is_passive_mad4b_admin()" in zero_touch

# The entrypoint must also avoid registering heavy global lifecycle observers
# before Plugin::boot() gets a chance to return. Navigation/render methods stay
# available; lifecycle work remains for explicit action/protocol/deep surfaces.
assert "$mad4b_passive_admin_read = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )" in entry
assert "if ( ! $mad4b_passive_admin_read ) {" in entry
passive_gate_pos = entry.index("$mad4b_passive_admin_read =")
for heavy in (
    "MAD4B_SCP_Live_Acceptance_Observer::boot_early();",
    "MAD4B_SCP_Query_Monitor_Evidence_Bridge::boot_early();",
    "MAD4B_SCP_Staging_Certification::boot();",
    "MAD4B_SCP_Context_Authority::boot();",
    "MAD4B_SCP_Provider_Autopilot::boot();",
    "MAD4B_SCP_Plugin_Lifecycle::boot();",
    "MAD4B_SCP_Self_Update::boot();",
    "MAD4B_SCP_Workflow_Providers::boot();",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::boot();",
    "MAD4B_SCP_Skill_Autoconfig::bootstrap();",
):
    assert heavy in entry, heavy
    assert passive_gate_pos < entry.index(heavy), heavy
assert "MAD4B_SCP_Skill_Runtime_Certification::observe();" not in entry
assert "Registration/transport hooks are cheap and request-local" in entry

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
assert "$passive_admin_hotpath = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )" in central_boot
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in central_boot
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath()" in central_boot
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
assert "if ( is_admin() ) {" in schema_reconcile
assert schema_reconcile.count("return true;") == 1  # WP_CLI is the only automatic repair owner
assert schema_reconcile.count("return false;") >= 2
assert "physical schema probes or dbDelta" in schema_reconcile

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
assert "defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return self::cli_mcp_opt_in();" in runtime_scope
assert "private static function cli_mcp_opt_in()" in request_scope
assert "MAD4B_SCP_MCP_CLI_REQUEST" in request_scope

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

# Protocol REST isolation has two bounded layers: remove the two expensive
# WordPress Core materializers, then remove only callbacks whose reflected file
# provenance proves they belong to an unrelated plugin. Core, MAD4B and the
# official MCP Adapter remain available and unknown provenance fails open.
scope_bootstrap = request_scope.split("public static function bootstrap()", 1)[1].split("public static function enforce()", 1)[0]
assert "self::current_request_is_protocol_hotpath()" in scope_bootstrap
assert "isolate_protocol_core_rest_bootstrap" in scope_bootstrap
assert "isolate_protocol_external_rest_bootstrap" in scope_bootstrap
protocol_core_isolation = request_scope.split("public static function isolate_protocol_core_rest_bootstrap()", 1)[1].split("public static function isolate_protocol_external_rest_bootstrap()", 1)[0]
for callback in (
    "register_initial_settings",
    "create_initial_rest_routes",
):
    assert callback in protocol_core_isolation, callback
assert "rest_api_default_filters" not in protocol_core_isolation
for forbidden in (
    "Jet_Engine",
    "WP\\MCP\\Core\\McpAdapter",
    "wp_filter['rest_api_init']->callbacks",
    "ReflectionFunction",
    "ReflectionMethod",
):
    assert forbidden not in protocol_core_isolation, forbidden

external_isolation = request_scope.split("public static function isolate_protocol_external_rest_bootstrap()", 1)[1].split("public static function current_request_mcp_server_id()", 1)[0]
for marker in (
    "MAX_PROTOCOL_REST_CALLBACK_SCAN",
    "MAX_PROTOCOL_REST_CALLBACK_EVIDENCE",
    "protocol_external_rest_callback_descriptor",
    "class_exists( $class, false )",
    "WP_PLUGIN_DIR",
    "mad4b-site-control-plane",
    "mcp-adapter",
    "ReflectionFunction",
    "ReflectionMethod",
    "remove_action( 'rest_api_init'",
    "source_plugin",
    "protocol_external_rest_scan_truncated",
):
    assert marker in external_isolation or marker in request_scope, marker
assert "return null;" in external_isolation  # unresolved provenance fails open
assert "'source' => $relative" not in external_isolation
assert "method_exists( $class, $method )" not in external_isolation
for marker in (
    "protocol_external_rest_isolation_request_local_only",
    "protocol_external_rest_unknown_callbacks_preserved",
    "protocol_external_rest_mu_plugin_callbacks_preserved",
    "protocol_external_rest_scan_limit",
):
    assert marker in request_scope, marker

# Compact protocol planes must not instantiate every provider adapter merely to
# create the addressed server. Provider-backed read/content/write/admin retain
# the full registry.
for marker in (
    "private static function request_needs_adapter_registry()",
    "MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath()",
    "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()",
    "MAD4B_SCP_MCP_Request_Scope::current_request_mcp_server_id()",
    "if ( $protocol_hotpath && '' === $server_id ) return false;",
    "'mad4b-read', 'mad4b-content', 'mad4b-write', 'mad4b-admin'",
    "if ( ! self::request_needs_adapter_registry() ) return;",
    "if ( self::request_needs_adapter_registry() ) self::prepare_registry();",
):
    assert marker in registration_bridge, marker
registry_gate = registration_bridge.split("private static function request_needs_adapter_registry()", 1)[1].split("private static function prepare_registry()", 1)[0]
assert registry_gate.index("current_request_is_passive_admin_hotpath()") < registry_gate.index("if ( '' === $server_id ) return true;")
assert registry_gate.index("if ( $protocol_hotpath && '' === $server_id ) return false;") < registry_gate.index("if ( '' === $server_id ) return true;")

# The central init boot must terminate at the protocol-kernel boundary before
# admin/resource/lifecycle-only work while retaining the three lightweight
# ability annotations needed for tools/list.
protocol_fast_return = central_boot.split("if ( $protocol_hotpath || $passive_admin_hotpath ) {", 1)[1].split("// Governance schema repair", 1)[0]
for marker in (
    "MAD4B_SCP_Staging_Write_Authority::boot();",
    "MAD4B_SCP_Write_Runtime_Certification::boot();",
    "MAD4B_SCP_Skill_Runtime_Certification::boot();",
    "MAD4B_SCP_MCP_Registration_Bridge::boot_early();",
    "return;",
):
    assert marker in protocol_fast_return, marker
for forbidden in (
    "MAD4B_SCP_Skill_Resource_Writer::boot();",
    "MAD4B_SCP_Local_OAuth_Browser_Canary::boot();",
    "MAD4B_SCP_Schema::install_or_upgrade()",
):
    assert forbidden not in protocol_fast_return, forbidden

# Staging managed-runtime recovery must have an execution path independent from
# MCP and interactive wp-admin. It remains exact-origin, signed-manifest and
# non-Production only, and never schedules from a protocol request.
for marker in (
    "const RECOVERY_CRON_HOOK",
    "ensure_recovery_update_schedule",
    "run_recovery_update",
    "recovery_update_eligible",
    "MAD4B_SCP_Site_Profile::managed_runtime_enabled()",
    "'staging' === sanitize_key",
    "download_governed_release_to_protected_storage",
    "verify_archive",
    "apply_verified_archive",
    "'governed_staging_recovery_cron'",
    "production_mutation_performed",
):
    assert marker in self_update, marker
recovery_schedule = self_update.split("public static function ensure_recovery_update_schedule()", 1)[1].split("public static function run_recovery_update()", 1)[0]
assert "current_request_is_protocol_hotpath()" in recovery_schedule
assert "current_request_is_passive_admin_hotpath()" in recovery_schedule
recovery_run = self_update.split("public static function run_recovery_update()", 1)[1].split("private static function recovery_update_eligible()", 1)[0]
recovery_ineligible = recovery_run.split("$status['eligible'] = true;", 1)[0]
assert "self::persist_recovery_status( $status );" not in recovery_ineligible
assert "return $status;" in recovery_ineligible
recovery_eligible = self_update.split("private static function recovery_update_eligible()", 1)[1].split("private static function persist_recovery_status", 1)[0]
for marker in (
    "self::environment_allowed( true )",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::managed_runtime_enabled()",
    "'staging' === sanitize_key",
):
    assert marker in recovery_eligible, marker

# PRs may report deployment drift as inconclusive, but post-merge/manual live
# acceptance must fail closed until the exact published runtime is loaded.
for marker in (
    "push:",
    "branches:",
    "master",
    "MAD4B_REQUIRE_RUNTIME_IDENTITY",
    "deployment_drift",
):
    assert marker in external_diagnostic_workflow, marker

print("mad4b.chatgpt-refresh-hotpath.v17: PASS")

# Post-update MU reconciliation must never execute filesystem mutation/audit work
# before an MCP/OAuth protocol request can be served.
mu_bootstrap = mu_refresh.split("public static function bootstrap()", 1)[1].split("public static function status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in mu_bootstrap
assert "'deferred_protocol_hotpath'" in mu_bootstrap
assert "$status['refresh_deferred'] = true;" in mu_bootstrap
assert mu_bootstrap.index("current_request_is_protocol_hotpath()") < mu_bootstrap.index("hash_file(")
mu_refresh_write = "@file_put_contents( $temp, $source_bytes, LOCK_EX )"
assert mu_refresh_write in mu_bootstrap, "MU refresh must use the bounded same-directory temp writer"
assert mu_bootstrap.index("current_request_is_protocol_hotpath()") < mu_bootstrap.index(mu_refresh_write)
assert mu_bootstrap.index("current_request_is_protocol_hotpath()") < mu_bootstrap.index("MAD4B_SCP_Audit::record(")

scope_boot_pos = entry.index("MAD4B_SCP_MCP_Request_Scope::bootstrap();")
mu_hook = "add_action( 'init', array( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh', 'bootstrap' ), 20 );"
assert mu_hook in entry, "MU refresh must be deferred to lifecycle init"
mu_boot_pos = entry.index(mu_hook)
assert scope_boot_pos < mu_boot_pos, "request scope must be classified before MU refresh scheduling"
assert "MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();" not in entry, "MU refresh must not mutate during plugin include"

# Runtime conflict repair is also a mutation/recovery transaction and must be
# deferred on protocol hotpaths before active_plugins or MU/audit repair work.
conflict_bootstrap = runtime_conflict.split("public static function bootstrap(", 1)[1].split("public static function status()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in conflict_bootstrap
assert "'repair_deferred_protocol_hotpath'" in conflict_bootstrap
assert "$status['repair_deferred'] = true;" in conflict_bootstrap
assert conflict_bootstrap.index("current_request_is_protocol_hotpath()") < conflict_bootstrap.index("get_option( 'active_plugins'")
assert conflict_bootstrap.index("current_request_is_protocol_hotpath()") < conflict_bootstrap.index("update_option( 'active_plugins'")
assert conflict_bootstrap.index("current_request_is_protocol_hotpath()") < conflict_bootstrap.index("MAD4B_SCP_Audit::record(")

# Query Monitor telemetry bootstrap pins build + package identity from one
# lightweight provenance snapshot. The build helper must delegate to that
# atomic pin instead of performing a second provenance read or full hashing.
qm_identity_pin = query_monitor.split("private static function pin_request_identity()", 1)[1].split("private static function pin_request_build_fingerprint()", 1)[0]
assert "build_provenance_identity_status()" in qm_identity_pin
assert "build_provenance_status()" not in qm_identity_pin
assert "hash_file(" not in qm_identity_pin
qm_build_pin = query_monitor.split("private static function pin_request_build_fingerprint()", 1)[1].split("private static function request_build_fingerprint()", 1)[0]
assert "self::pin_request_identity()" in qm_build_pin
assert "build_provenance_status()" not in qm_build_pin
assert "hash_file(" not in qm_build_pin

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

# Third-party wp-admin pages (WPML, plugins.php, Elementor, etc.) remain
# zero-touch by default. The only opt-in is the exact short-lived governed
# acceptance canary. Both the generic zero-touch guard and the wp-admin-specific
# guard must run before provenance, telemetry reads/writes, Query Monitor
# collectors, or performance sampling.
assert "private static function current_request_is_mad4b_admin_surface()" in query_monitor
assert "private static function request_acceptance_canary_kind()" in query_monitor
assert "$acceptance_canary = self::request_acceptance_canary_kind();" in qm_flush
zero_touch_guard = "MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface()"
foreign_admin_guard = "if ( 'wp_admin' === $class && ! self::current_request_is_mad4b_admin_surface() && '' === $acceptance_canary ) return;"
assert zero_touch_guard in qm_flush
assert "&& '' === $acceptance_canary ) return;" in qm_flush
assert foreign_admin_guard in qm_flush
guard_pos = qm_flush.index(foreign_admin_guard)
assert qm_flush.index("$acceptance_canary = self::request_acceptance_canary_kind();") < qm_flush.index(zero_touch_guard)
assert qm_flush.index(zero_touch_guard) < guard_pos
assert guard_pos < qm_flush.index("MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed()")
assert guard_pos < qm_flush.index("self::request_build_fingerprint()")
assert guard_pos < qm_flush.index("self::load_telemetry( $build, $package_identity_token )")
assert guard_pos < qm_flush.index("$sample = self::performance_sample( $class )")
assert guard_pos < qm_flush.index("foreach ( self::query_monitor_events()")
foreign_prefix = qm_flush[:guard_pos]
assert "update_option(" not in foreign_prefix
assert "query_monitor_events()" not in foreign_prefix
assert "performance_sample(" not in foreign_prefix

# Upgrade continuity may persist recovered profile/OAuth state, so migration
# recovery must be deferred during MCP/OAuth protocol requests.
upgrade_preboot = upgrade_continuity.split("public static function pre_boot()", 1)[1].split("public static function boot()", 1)[0]
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in upgrade_preboot
assert "'deferred_protocol_hotpath'" in upgrade_preboot
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath()" in upgrade_preboot
assert "'deferred_passive_admin_hotpath'" in upgrade_preboot
assert upgrade_preboot.index("current_request_is_protocol_hotpath()") < upgrade_preboot.index("recover_verified_read_continuity()")
assert upgrade_preboot.index("current_request_is_passive_admin_hotpath()") < upgrade_preboot.index("recover_verified_read_continuity()")


# OAuth discovery must remain reachable even while authenticated MCP execution is
# fenced by restart grace/maintenance. The unauthenticated remote resource probe
# flows through to OAuth Resource Bridge so it can emit the standards 401
# WWW-Authenticate challenge; bearer/local-admin requests remain fenced.
guard = reconnect.split("public static function guard_mcp_rest_dispatch(", 1)[1].split("public static function connection_admin_notice()", 1)[0]
assert "$remote_preauth_probe" in guard
assert "get_header( 'authorization' )" in guard
assert "is_user_logged_in()" in guard
assert guard.index("if ( $remote_preauth_probe ) return $result;") < guard.index("$restart_grace = self::restart_grace_status();")
assert guard.index("if ( $remote_preauth_probe ) return $result;") < guard.index("$maintenance_lease = self::maintenance_lease_status();")

# Passive admin readiness must not report a false not_registered merely because
# the MCP Adapter registry is intentionally not materialized on that PHP request.
# Registration facts are owned by the bridge; effective readiness is projected
# canonically and consumed by reconnect_status without reviving deep materialization.
registration_projection = registration_bridge.split("public static function server_registration_identity_status( $server_id )", 1)[1].split("public static function status()", 1)[0]
for marker in (
    "'mad4b.mcp-registration-fact.v1'",
    "MAD4B_SCP_Servers::expected_server_ids()",
    "MAD4B_SCP_Servers::registration_status()",
    "MAD4B_SCP_Truth_Projection::mcp_registration_identity( $fact )",
    "'actual_registered'",
    "'observed_registration_error'",
):
    assert marker in registration_projection, marker
for forbidden in ("rest_get_server(", "wp_get_abilities(", "register_servers( $adapter"):
    assert forbidden not in registration_projection, forbidden

reconnect_status = reconnect.split("public static function reconnect_status()", 1)[1].split("private static function preauth_reconnect_blockers()", 1)[0]
assert "MAD4B_SCP_MCP_Registration_Bridge::server_registration_identity_status( 'mad4b-chatgpt' )" in reconnect_status
assert "'chatgpt_registration_observed'" in reconnect_status
assert "'chatgpt_registration_identity_ready'" in reconnect_status
assert "'chatgpt_registration_projection'" in reconnect_status
assert "'chatgpt_registration_deep_check_deferred'" in reconnect_status
assert "self::chatgpt_registration_projection()" not in reconnect_status

# Skills and OAuth Canary render from persisted/runtime-identity evidence. Fresh
# certification or deep OAuth inspection happens only after an explicit action.
skills_render = skills_ui.split("private static function render_status(", 1)[1].split("private static function render_snapshot_note()", 1)[0]
assert "MAD4B_SCP_Skill_Runtime_Certification::persisted_status()" in skills_render
assert "MAD4B_SCP_Skill_Runtime_Certification::status()" not in skills_render
assert "MAD4B_SCP_Skill_Runtime_Certification::observe( true )" in skills_ui

canary_status = oauth_canary.split("public static function status()", 1)[1].split("public static function enqueue_assets()", 1)[0]
assert "MAD4B_SCP_Local_OAuth_Server::runtime_identity_status()" in canary_status
assert "MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()" in canary_status
assert "MAD4B_SCP_Local_OAuth_Server::status()" not in canary_status
assert "MAD4B_SCP_OAuth_Resource_Bridge::status()" not in canary_status

# Local inventory pages may still do bounded filesystem/plugin enumeration, but
# repeated projections inside one page request must reuse the same snapshot.
assert "private static $coverage = null;" in plugin_discovery
coverage_body = plugin_discovery.split("public static function coverage()", 1)[1].split("private static function functional_state_counts", 1)[0]
assert "$cacheable = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )" in coverage_body
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath()" in coverage_body
assert "if ( $cacheable && null !== self::$coverage ) return self::$coverage;" in coverage_body
assert "return self::$coverage;" in coverage_body
assert "private static $inventory = null;" in runtime_components
inventory_body = runtime_components.split("public static function inventory()", 1)[1].split("public function inventory()", 1)[0]
assert "$cacheable = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )" in inventory_body
assert "MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath()" in inventory_body
assert "if ( $cacheable && null !== self::$inventory ) return self::$inventory;" in inventory_body
assert "return self::$inventory;" in inventory_body

# Context > Google Drive folder navigation is an explicit provider read, but the
# wp-admin GET path must not refresh/persist OAuth tokens or inherit the 20-second
# provider timeout. The preview is at most two 3-second read calls and fails
# closed with refresh_required when the cached access token is stale.
assert "public static function admin_folder_preview(" in google_drive
assert "public static function admin_list_folders_preview(" in google_drive
preview = google_drive.split("private static function passive_admin_api_get(", 1)[1].split("public static function get_folder(", 1)[0]
for token in (
    "token_refresh_suppressed",
    "network_request_suppressed",
    "self::provider_timeout( 3 )",
    "'timeout' => min( 3.0",
    "'redirection' => 0",
):
    assert token in preview, token
for forbidden in (
    "self::access_token()",
    "wp_remote_post(",
    "persist_tokens(",
    "record_refresh_failure(",
):
    assert forbidden not in preview, forbidden
folder_render = context_ui.split("private static function render_folder_browser()", 1)[1].split("private static function render_sources()", 1)[0]
assert "MAD4B_SCP_Google_Drive_Context::admin_folder_preview( $folder_id )" in folder_render
assert "MAD4B_SCP_Google_Drive_Context::admin_list_folders_preview( $folder_id )" in folder_render
assert "MAD4B_SCP_Google_Drive_Context::get_folder( $folder_id )" not in folder_render
assert "MAD4B_SCP_Google_Drive_Context::list_folders( $folder_id )" not in folder_render

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
