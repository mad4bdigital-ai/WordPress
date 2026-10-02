#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(rel):
    return (ROOT / rel).read_text('utf-8')

def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')

def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')

status = read('includes/class-mad4b-scp-connection-status.php')
evidence = read('includes/class-mad4b-scp-external-handshake-evidence.php')
ability = read('includes/class-mad4b-scp-connection-ability.php')
abilities = read('includes/class-mad4b-scp-abilities.php')
read_consistency = read('includes/class-mad4b-scp-read-consistency.php')
resilience = read('includes/class-mad4b-scp-connector-resilience.php')
reconnect = read('includes/class-mad4b-scp-reconnect-hardening.php')
ui = read('includes/class-mad4b-scp-connection-admin-ui.php')
admin_ui = read('includes/class-mad4b-scp-admin-ui.php')
adapter_ui = read('includes/class-mad4b-scp-adapter-coverage-admin-ui.php')
admin_experience = read('includes/class-mad4b-scp-admin-experience.php')
peer = read('includes/class-mad4b-scp-mcp-peer-governance.php')
isolation = read('includes/class-mad4b-scp-mcp-provider-isolation.php')
transport_registry = read('includes/class-mad4b-scp-provider-transport-registry.php')
transport_catalog = read('config/provider-transport-registry.json')
certified_providers = read('config/certified-providers.json')
bridge = read('includes/class-mad4b-scp-mcp-registration-bridge.php')
diagnostics = read('includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php')
transport_context = read('includes/class-mad4b-scp-transport-context.php')
authz = read('includes/class-mad4b-scp-authorization.php')
servers = read('includes/class-mad4b-scp-servers.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')
provider_contracts = read('includes/class-mad4b-scp-provider-contracts.php')

require(status, "mad4b.connection-readiness.v4", 'connection-contract')
for marker in (
    'get_server_route_namespace', 'get_server_route', 'get_transport_permission_callback',
    'MAD4B_SCP_Provider_Diagnostic_Policy::current_rest_server()', 'route_registered', 'permission_callback_match',
    'deep_route_validation_deferred', 'route_validation_deferred',
    "'local_transport_ready'", "'remote_endpoint_preflight_ready'", '$connection_certified',
    "'external_handshake_unverified'", "'external_handshake_stale'",
    "'stale_package_identity_evidence'", "'stale_runtime_surface_evidence'", "'stale_write_transport_evidence'",
    "'credential_material_exposed' => false", "'credential_creation_supported_here' => false",
    "'remote_subject_bridge_required' => true", "'write_surface'",
    "'exact_transport_grant_required' => true", "'generic_dispatcher_exposed' => false",
    "'provider_mcp_isolation'", "'oauth_resource_server'",
    'MAD4B_SCP_OAuth_Resource_Bridge::status()', 'oauth_preflight_blockers',
    'MAD4B_SCP_External_Handshake_Evidence::status()', 'bounded_handshake_status',
    'oauth_resource_bridge_not_configured', 'oauth_issuer_unconfigured',
    'oauth_wp_subject_unconfigured', 'oauth_wp_subject_invalid',
    "'preflight_ready' => empty( $blockers )",
):
    require(status, marker, 'connection-status-truth')
forbid(status, "'connection_certified' => false", 'connection-no-permanent-false')
forbid(status, 'rest_get_server()', 'connection-status-no-rest-materialization')


require(ability, "'output_schema' => array( 'type' => 'object', 'additionalProperties' => true )", 'connection-output-schema-open')
for marker in (
    "'mcp_registration_lifecycle'",
    'MAD4B_SCP_MCP_Registration_Bridge::status()',
    "'rest_init_seen_before_bridge_boot'",
    "'adapter_init_seen_before_bridge_boot'",
    "'missed_rest_recovery_scheduled'",
    "'missed_rest_recovery_attempted'",
    "'missed_rest_recovery_succeeded'",
    "'missed_rest_recovery_state'",
    "'missed_rest_recovery_blocker'",
    "'mcp_adapter_init_count'",
    "'rest_api_init_count'",
    "'first_rest_observed'",
    "'plugins_loaded_count_at_first_rest'",
    "'init_count_at_first_rest'",
    "'wp_loaded_count_at_first_rest'",
    "'doing_plugins_loaded_at_first_rest'",
    "'doing_init_at_first_rest'",
    "'jetengine_registry_class_loaded_at_first_rest'",
    "'jetengine_registry_callback_present_at_first_rest'",
    "'jetengine_rest_manager_class_loaded_at_first_rest'",
    "'jetengine_rest_manager_callback_present_at_first_rest'",
    "'mcp_adapter_callback_present_at_first_rest'",
    "'first_rest_classification'",
    "'caller_trace'",
):
    require(status, marker, 'connection-mcp-registration-lifecycle')

lifecycle_start = status.index('private static function bounded_mcp_registration_lifecycle()')
# Bound this proof to the lifecycle projection helpers themselves. The following
# admin-hotpath classifier contains a PHPCS NonceVerification annotation but no
# secret material and must not be folded into the lifecycle diagnostic contract.
lifecycle_end = status.index('private static function admin_shallow_surface()', lifecycle_start)
lifecycle = status[lifecycle_start:lifecycle_end]
for forbidden in (
    'rest_get_server(', 'rest_do_request(', 'register_routes(', 'register_rest_route(',
    "do_action( 'rest_api_init'", 'Registry::register_features_api(',
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->',
):
    forbid(lifecycle, forbidden, 'lifecycle-diagnostic-observational-only')
for secret_key in (
    'client_secret', 'access_token', 'refresh_token', 'authorization_header', 'raw_token',
    'app_id', 'oauth_subject', 'nonce', 'password',
):
    forbid(lifecycle.lower(), secret_key, 'lifecycle-diagnostic-no-secret-or-authority')


for marker in (
    "add_action( 'rest_api_init', array( __CLASS__, 'observe_first_rest_init' ), PHP_INT_MIN )",
    "debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 )",
    "class_exists( $registry_class, false )",
    "get_declared_classes()",
    "'rest_before_plugins_loaded'",
    "'rest_during_plugins_loaded_before_jetengine_registration'",
    "'jetengine_callbacks_present_at_first_rest'",
    "'first_rest_phase_undetermined'",
):
    require(bridge, marker, 'first-rest-trace-contract')

observer_start = bridge.index('public static function observe_first_rest_init()')
observer_end = bridge.index('public static function verify_adapter_init_after_rest()', observer_start)
observer = bridge[observer_start:observer_end]
for forbidden in (
    'rest_get_server(', 'rest_do_request(', "do_action( 'rest_api_init'", 'register_rest_route(',
    'register_features_api()', '->register_features_api(', '->register_routes(', 'new Get_Controller',
    'new MCP_Controller', 'new Run_Controller', 'update_option(', 'add_option(', 'delete_option(',
    '$wpdb->', 'HTTP_AUTHORIZATION', 'access_token', 'refresh_token', 'client_secret',
):
    forbid(observer, forbidden, 'first-rest-observer-observational-only')
require(observer, "DEBUG_BACKTRACE_IGNORE_ARGS", 'first-rest-trace-no-args')
require(observer, "relative_wordpress_path", 'first-rest-trace-relative-paths')

if status.index('$oauth_blockers = self::oauth_preflight_blockers') > status.index('$remote_preflight_blockers = array_merge'):
    raise SystemExit('FAIL oauth-before-remote-preflight: OAuth blockers must be resolved before remote readiness is claimed')
if status.index('$remote_preflight_blockers = array_merge') > status.index('$connection_certified = ! $admin_shallow && empty( $certification_blockers )'):
    raise SystemExit('FAIL preflight-before-certification: remote blockers must be assembled before final certification')

for marker in (
    "const CONTRACT = 'mad4b.external-handshake-evidence.v4'",
    "const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json'",
    "const SERVER_ID = 'mad4b-chatgpt'",
    "defined( 'REST_REQUEST' )", "defined( 'WP_CLI' ) && WP_CLI",
    "defined( 'DOING_CRON' ) && DOING_CRON", 'verified_bearer_active()',
    "'initialize'", "'tools/list'", "hash( 'sha256', $session_id )",
    "update_option( self::OPTION, $evidence, false )", "'credential_material_stored' => false",
    "'stale_package_identity_evidence'", "'stale_runtime_surface_evidence'", "'stale_tool_inventory_evidence'", "'stale_time_evidence'", 'build_fingerprint()',
    "'tool_inventory_fingerprint'", "'expected_tool_inventory_fingerprint'", "'tool_inventory_match'",
    "'write_catalog_fingerprint'", "'write_inventory_fingerprint_match'",
    "'write_transport_ready'", "'write_transport_tool_count'", "'direct_write_schema_leaks'",
    "'provider_gated_write_tools'", "'eligible_write_tool_count'", "'expected_eligible_write_tool_count'",
    'expected_tool_names()', 'expected_write_tool_names()', 'expected_eligible_write_tool_names()', 'blocked_write_tool_names()', 'breakglass_tool_names()',
):
    require(evidence, marker, 'external-handshake-evidence')
for forbidden in (
    "'access_token' =>", "'refresh_token' =>", "'authorization_header' =>", "'raw_token' =>",
    '$_SERVER[\'HTTP_AUTHORIZATION\']', 'wp_remote_get(', 'wp_remote_post(', 'curl_exec(', 'fsockopen(',
):
    forbid(evidence, forbidden, 'external-evidence-no-secret-or-outbound')

for outbound in ('wp_remote_get(', 'wp_remote_post(', 'wp_remote_request(', 'curl_exec(', 'fsockopen('):
    forbid(status + '\n' + ui + '\n' + diagnostics, outbound, 'no-self-probe-ssrf')
for write in ('$_POST', 'admin_post_', '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete(', 'update_option(', 'add_option(', 'delete_option('):
    forbid(ui + '\n' + adapter_ui + '\n' + admin_experience + '\n' + diagnostics, write, 'admin-experience-read-only')
for secret_key in ("'client_secret'", "'access_token'", "'refresh_token'", "'authorization_header'", "'raw_token'", "'password_hash'"):
    forbid(ui + '\n' + status + '\n' + diagnostics, secret_key, 'connection-no-secret-material')

require(ability, "const ABILITY = 'mad4b/connection-status'", 'connection-ability')
require(ability, "'readonly' => true", 'connection-ability-readonly')
require(ability, "'public' => false", 'connection-ability-nonpublic')
require(servers, "'mad4b/runtime-authority-status'", 'connection-runtime-authority-mounted')
require(servers, "'mad4b/connection-status'", 'connection-status-mounted')
require(servers, "'mad4b/read-snapshot-header'", 'read-snapshot-header-mounted')
require(servers, "'mad4b/read-diagnostic-bundle'", 'read-diagnostic-bundle-mounted')
require(servers, "'mad4b/read-metadata-envelope'", 'read-metadata-envelope-mounted')
if servers.count("'mad4b/read-metadata-envelope'") != 1:
    raise SystemExit("read metadata envelope must be mounted exactly once on mad4b-read and remain hidden from the compact ChatGPT direct tool list")
read_server_block = servers.split("'mad4b-read' =>", 1)[1].split("'mad4b-chatgpt' =>", 1)[0]
chatgpt_server_block = servers.split("'mad4b-chatgpt' =>", 1)[1].split("'mad4b-enrollment' =>", 1)[0]
require(read_server_block, "'mad4b/read-metadata-envelope'", 'read-metadata-envelope-read-mount')
forbid(chatgpt_server_block, "'mad4b/read-metadata-envelope'", 'read-metadata-envelope-hidden-from-chatgpt-direct-tools')
for marker in (
    "mad4b_read_dispatch_recursion_denied",
    "array_key_exists( 'readonly', $annotations )",
    "true !== $annotations['readonly']",
    "MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $ability_name )",
    "'read' !== $row['lane']",
    "empty( $row['execution_eligible'] )",
    "mad4b_read_dispatch_sensitive_target_denied",
):
    require(abilities, marker, 'read-dispatch-governed-universe-gate')
require(servers, "'mad4b-write'", 'write-server-id')
require(servers, "'MAD4B Write MCP'", 'write-server-registration')
require(servers, "array( __CLASS__, 'can_write_transport' )", 'write-server-permission')
require(servers, "public static function write_tools()", 'write-tool-projection')
require(servers, "public static function external_write_tools()", 'stable-external-write-catalog')
require(servers, "public static function is_external_write_candidate", 'stable-external-write-membership')
require(servers, "array_key_exists( 'readonly', $annotations )", 'write-explicit-annotation')
require(servers, "false !== $annotations['readonly']", 'write-readonly-denial')
require(servers, "MAD4B_SCP_Adapter_Registry::instance()", 'write-adapter-projection')
require(servers, "return 'core';", 'write-core-provider-binding')
for generic in ('execute-any', 'generic-dispatch', 'call_user_func( $input', 'ability_name_from_request'):
    forbid(servers, generic, 'write-no-generic-dispatcher')

for marker in (
    "const CONTRACT = 'mad4b.read-consistency.v1'",
    "const SNAPSHOT_ABILITY = 'mad4b/read-snapshot-header'",
    "const BUNDLE_ABILITY = 'mad4b/read-diagnostic-bundle'",
    "const METADATA_ABILITY = 'mad4b/read-metadata-envelope'",
    "public static function metadata_envelope",
    "'execution_binding_digest'",
    "'dispatch_policy_digest'",
    "$payload['dispatch_identity_source'] = 'MAD4B_SCP_Enrollment_Dispatch::info';",
    "MAD4B_SCP_Enrollment_Dispatch::info",
    "'mad4b_metadata_dispatch_schema_projection_drift'",
    "'mad4b_metadata_ability_not_cataloged'",
    "MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( $target )",
    "'runtime_changed_during_metadata_read'",
    "return array( 'identity', 'runtime', 'certification', 'providers' )",
    "'runtime_generation' => $runtime_generation",
    "'read_transaction_id' => $transaction_id",
    "'discard_partial_on_generation_change' => true",
    "'valid_for_merge' => false",
    "'client_action' => 'restart_read_transaction'",
    "runtime_changed_during_bundle",
    "MAD4B_SCP_Connector_Resilience::run_checks",
):
    require(read_consistency, marker, 'read-consistency-contract')
for forbidden in ('mad4b/execute-many', 'update_option(', 'add_option(', 'delete_option(', 'wp_remote_post(', 'curl_exec(', '$wpdb->'):
    forbid(read_consistency, forbidden, 'read-consistency-readonly-fixed-bundles')
for marker in (
    "'preferred_parallelism' => 1",
    "'read_parallelism_max' => 2",
    "'reconnect_attempts' => 1",
    "'replay_read_after_reconnect' => true",
    "'replay_mutation_after_reconnect' => false",
    "'snapshot_identity_required' => true",
    "'discard_partial_on_generation_change' => true",
    "'resume_completed_reads_on_generation_match' => true",
    "'session_termination_budget' => self::SESSION_TERMINATION_BUDGET",
    "'stop_fanout_after_session_termination_budget' => true",
    "'session_breaker_scope' => 'request_local'",
    "'session_termination_category' => 'session_terminated'",
    "'metadata_micro_read_preferred' => true",
    "'metadata_envelope_ability' => 'mad4b/read-metadata-envelope'",
    "'resume_after_reconnect_requires_generation_match' => true",
    "'persistent_session_breaker_used' => false",
    "'uncertain_approval_plan_reconciliation_ability' => 'mad4b/approval-plan-reconcile'",
    "'never_replay_approval_plan_before_reconciliation' => true",
):
    require(resilience, marker, 'read-consistency-client-policy')

for marker in (
    "const CONTRACT = 'mad4b.reconnect-hardening.v5'",
    "SESSION_SHADOW_TTL = 120",
    "add_filter( 'rest_pre_dispatch', array( __CLASS__, 'reset_session_policy_scope' ), -200, 3 )",
    "add_filter( 'rest_post_dispatch', array( __CLASS__, 'clear_session_policy_scope' ), PHP_INT_MAX, 3 )",
    "'request_scope_reset_before_reconnect_guard' => true",
    "'request_scope_reset_before_oauth_dispatch' => true",
    "'request_scope_cleared_after_dispatch' => true",
    "'request_scope_reset_clears_pending_delete_state' => true",
    "'request_scope_reset_clears_pending_shadow_retirement' => true",
    "'request_scope_bound_to_exact_request_object' => true",
    "'nested_rest_request_cannot_clear_outer_initialize_state' => true",
    "spl_object_hash( $request )",
    "self::$initialize_empty_requests",
    "'preauth_guard_delegates_oauth_effectiveness_to_resource_bridge' => true",
    "'preauth_guard_calls_full_reconnect_status' => false",
    "'preauth_response_exposes_internal_blockers' => false",
    "'local_oauth_required_for_reconnect' => false",
    "'oauth_authority_mode' =>",
    "'maintenance_fence_token_conflict' => $fence_conflict",
    "'maintenance_active_fence_count' =>",
    "'maintenance_fence_source' =>",
    "'maintenance_legacy_only_fence' =>",
    "'retryable' => ! $fence_conflict",
    "'client_action' => $fence_conflict ? 'inspect_runtime_maintenance_fence_conflict' : 'retry_after_runtime_maintenance'",
    "preauth_reconnect_blockers()",
    "'session_policy_applies_to_sibling_mcp_servers' => false",
    "'adapter_session_max_modified' => false",
    "'adapter_inactivity_timeout_modified' => false",
    "'activity_update_interval_modified' => false",
    "'repair_capacity_ceiling' => 32",
    "'repair_uses_upstream_session_capacity' => true",
    "apply_filters( 'mcp_adapter_session_max_per_user', 32 )",
    "'reinitialize_required_without_valid_repair_shadow' => true",
    "'blind_read_replay_after_transport_reinitialize' => false",
    "'original_read_request_continues_after_verified_repair' => true",
    "'blind_mutation_replay_after_transport_reinitialize' => false",
    "'authority_created' => false",
    "'production_widened' => false",
    "SESSION_META_KEY = 'mcp_adapter_sessions'",
    "add_filter( 'rest_pre_dispatch', array( __CLASS__, 'repair_or_forget_session' ), 3, 3 )",
    "add_filter( 'rest_post_dispatch', array( __CLASS__, 'capture_initialized_session' ), 900, 3 )",
    "add_filter( 'rest_post_dispatch', array( __CLASS__, 'finalize_session_shadow' ), 925, 3 )",
    "add_filter( 'rest_post_dispatch', array( __CLASS__, 'finalize_deleted_session' ), 950, 3 )",
    "'delete_cleanup_bound_to_exact_request_object' => true",
    "'shadow_key_contains_session_fingerprint_only' => true",
    "'shadow_storage_per_session' => true",
    "'shadow_cross_session_lost_update_possible' => false",
    "'shadow_hard_count_bound' => false",
    "'shadow_admission_first_empty_transition_only' => true",
    "'sequential_reconnect_shadow_growth_possible' => false",
    "session_store_is_empty_for_first_initialize",
    "$started_empty = self::session_store_is_empty_for_first_initialize( get_current_user_id() )",
    "$eligible = '' !== $key && $started_empty && self::certified_adapter_runtime_integrity_ok()",
    "self::$initialize_empty_requests[ $key ] = $eligible",
    "'initialize_not_first_empty_transition'",
    "'shadow_ttl_bounded' => true",
    "'shadow_retired_after_first_adapter_success' => true",
    "'shadow_retained_after_failed_first_read' => true",
    "'shadow_discarded_outside_first_race_shape' => true",
    "schedule_shadow_retirement",
    "finalize_session_shadow",
    "'delete_tombstone_blocks_shadow_resurrection' => true",
    "'delete_tombstone_requires_existing_session_or_shadow' => true",
    "'random_delete_tombstone_allocation_enabled' => false",
    "session_present_in_any_row",
    "'delete_tombstone_bound_to_site_user_session' => true",
    "'post_cas_delete_tombstone_rechecked' => true",
    "'raw_session_id_persisted_in_shadow' => false",
    "'initialize_params_minimized' => true",
    "'initialize_capabilities_persisted_in_shadow' => false",
    "'initialize_client_info_persisted_in_shadow' => false",
    "'initialize_shadow_fields' => array( 'protocolVersion' )",
    "minimal_initialize_params",
    "'first_session_race_repair_configured' => self::governed_nonproduction_transport() && self::session_repair_supported_runtime()",
    "'first_session_race_repair_enabled' => true === self::$runtime_integrity_ok",
    "'repair_scope_first_empty_transition_only' => true",
    "'bounded_multiway_first_empty_race_repair' => true",
    "'general_expiry_or_eviction_rehydration_enabled' => false",
    "'first_empty_race_visible_map_shape_required' => true",
    "FIRST_EMPTY_RACE_MAX_VISIBLE_SESSIONS = 8",
    "FIRST_EMPTY_RACE_CREATION_SKEW_SECONDS = 30",
    "'first_empty_race_visible_map_max_sessions' => self::FIRST_EMPTY_RACE_MAX_VISIBLE_SESSIONS",
    "'first_empty_race_creation_skew_seconds' => self::FIRST_EMPTY_RACE_CREATION_SKEW_SECONDS",
    "first_empty_race_visible_candidate",
    "'rehydration_read_only' => true",
    "'rehydration_requires_runtime_readonly_annotation' => true",
    "wp_has_ability( $ability_name )",
    "wp_get_ability( $ability_name )",
    "true === $annotations['readonly']",
    "'notifications_do_not_trigger_rehydration' => true",
    "'mutation_session_rehydration_enabled' => false",
    "'delete_forgets_shadow' => true",
    "'rehydration_respects_session_capacity' => true",
    "'repair_never_evicts_existing_adapter_session' => true",
    "'oauth_client_bound' => true",
    "CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json'",
    "CERTIFIED_STATEFUL_ADAPTER_VERSION = '0.6.1'",
    "session_repair_supported_runtime()",
    "certified_adapter_runtime_integrity_ok()",
    "MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', true )",
    "'certified_stateful_runtime' => self::session_repair_supported_runtime()",
    "'exact_adapter_version_required' => true",
    "'certified_runtime_integrity_required' => true",
    "'runtime_integrity_checked_only_on_candidate_repair_paths' => true",
    "'runtime_integrity_transport_file_count' => 4",
    "'version_only_repair_authority_allowed' => false",
    "'runtime_integrity_gate_blocked'",
    "verified_bearer_client_is( self::CHATGPT_CLIENT_ID )",
    "'canonical_adapter_session_store_used_for_repair' => true",
    "'repair_uses_custom_lock' => false",
    "'empty_only_session_meta_repair_allowed' => false",
    "'repair_matches_adapter_single_meta_visibility' => true",
    "'duplicate_nonempty_rows_union_enabled' => false",
    "'delete_removes_target_from_all_duplicate_rows' => true",
    "'delete_cleanup_runs_after_adapter_dispatch' => true",
    "'delete_cleanup_requires_exact_transport_binding' => true",
    "'delete_cleanup_requires_adapter_http_200' => true",
    "'delete_response_preserved' => true",
    "'delete_pre_dispatch_removes_canonical_session' => false",
    "self::$delete_cleanup_requests[ $key ]",
    "finalize_deleted_session",
    "'repair_readback_verified' => true",
    "'session_id_shape_pinned_to_adapter_uuid_v4' => true",
    "'existing_session_records_shape_validated' => true",
    "'session_exists_matches_adapter_visible_meta_row' => true",
    "'steady_state_user_meta_read_after_shadow_expiry' => false",
    "get_user_meta( $user_id, self::session_meta_key(), true )",
    "update_user_meta( $user_id, $key, $updated, $previous )",
    "remove_session_from_all_rows",
    "self::remove_session_record( $user_id, $session_id )",
    "'/mcp/mad4b-chatgpt'",
):
    require(reconnect, marker, 'mcp-session-continuity')
for forbidden in (
    "return PHP_INT_MAX",
    "'blind_mutation_replay_after_transport_reinitialize' => true",
    "'mutation_session_rehydration_enabled' => true",
    "SESSION_REPAIR_LOCK_META_KEY",
    "SESSION_REPAIR_LOCK_TTL",
    "acquire_session_repair_lock",
    "safe_initialize_params",
    "'initialize_params_secret_keys_rejected' => true",
    "'existing_empty_session_meta_supported' => true",
    "merge_session_rows",
    "canonical_session_rows",
    "'duplicate_nonempty_rows_converged_by_cas' => true",
    "'session_exists_scans_all_meta_rows' => true",
    "self::oauth_blocker( $local )",
    "SESSION_SHADOW_MAX_PER_USER",
    "shadow_store_single_bounded_map_per_user",
    "self::$chatgpt_request_active",
    "self::$initialize_started_with_empty_store",
    "'general_expiry_or_eviction_rehydration_enabled' => true",
    "CHATGPT_SESSION_MAX_PER_USER",
    "CHATGPT_SESSION_INACTIVITY_TIMEOUT",
    "add_filter( 'mcp_adapter_session_max_per_user'",
    "add_filter( 'mcp_adapter_session_inactivity_timeout'",
    "CHATGPT_SESSION_ACTIVITY_UPDATE_INTERVAL",
    "add_filter( 'mcp_adapter_session_activity_update_interval'",
):
    forbid(reconnect, forbidden, 'mcp-session-continuity-bounded')

for marker in (
    '"includes/Transport/Infrastructure/HttpRequestHandler.php": "27efb5353e78a234faec3246b9603c050c87f46e2497ffe4d4c1757f7a11f375"',
    '"includes/Transport/Infrastructure/HttpSessionValidator.php": "2f1a06b7b39fb8619d43cf41a6efdb4dc187309bf834c0696801aa754becba88"',
    '"includes/Transport/Infrastructure/RequestRouter.php": "e91c1dd049a525183e8fbf31edd70967308e2dd7fa8131d4898b246362ea710d"',
    '"includes/Transport/Infrastructure/SessionManager.php": "70cc27038911811810a0a7186e2b24a968266b7cd63f94ef0ccfc13a71147b7c"',
):
    require(certified_providers, marker, 'mcp-adapter-session-runtime-integrity-manifest')

require(transport_context, "const CONTRACT = 'mad4b.mcp-transport-context.v3'", 'transport-context-contract')
require(transport_context, "'/mcp/' . $server_id", 'transport-exact-route')
require(transport_context, "'mad4b_transport_route_mismatch'", 'transport-route-mismatch')
require(transport_context, 'resolve_server_for_ability', 'transport-effective-server-resolver')
require(transport_context, 'MAD4B_SCP_Servers::ability_is_mounted', 'transport-mount-verification')
require(transport_context, "'mad4b_transport_ability_not_mounted'", 'transport-ability-mount-denial')
require(transport_context, 'MAD4B_SCP_Servers::is_external_write_candidate', 'transport-stable-write-candidate-check')
require(transport_context, 'MAD4B_SCP_Staging_Write_Authority::is_write_ability', 'transport-chatgpt-write-delegation')
require(transport_context, "'mad4b_write_capability_not_eligible'", 'transport-provider-write-gate')
require(transport_context, "return 'mad4b-write';", 'transport-dedicated-write-authority')
require(transport_context, "'mad4b_write_authority_mount_missing'", 'transport-write-authority-mount-denial')
require(transport_context, "'stable_unified_catalog_fail_closed_execution'", 'transport-stable-discovery-model')
for bypass in ("apply_filters( 'mad4b_scp_transport", "$_REQUEST", "$_GET", "$_POST"):
    forbid(transport_context, bypass, 'transport-context-no-bypass-input')

require(authz, 'MAD4B_SCP_Transport_Context::resolve_server_for_ability', 'central-transport-rebind')
require(authz, '$declared_server_id', 'declared-server-evidence')
require(authz, "'transport_bound'", 'transport-binding-evidence')
if authz.index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > authz.index('MAD4B_SCP_Agent_Registry::exact_grant'):
    raise SystemExit('FAIL transport-before-grant: active MCP transport must bind before exact grant lookup')
if authz.index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > authz.index('MAD4B_SCP_Approval_Tickets::authorize_exact'):
    raise SystemExit('FAIL transport-before-approval: active MCP transport must bind before read-only approval preflight')

require(ui, 'add_submenu_page(', 'connection-admin-submenu')
require(ui, "'manage_options'", 'connection-admin-capability')
for marker in (
    "'readiness' =>", "'oauth' =>", "'endpoints' =>", "'isolation' =>", "'certification' =>",
    'MAD4B_SCP_Admin_Experience::stages', 'MAD4B_SCP_Admin_Experience::tabs', 'MAD4B_SCP_Admin_Experience::next_step',
    'WordPress local OAuth authority', 'External / federated OAuth resource bridge', 'MAD4B_SCP_Local_OAuth_Server::status()',
    'OAuth & Identity', 'Isolation & Safety', 'Required evidence', 'real external OAuth browser round-trip',
    'Bridge configured', 'Bridge effective', 'Issuer configured', 'RFC 9728 metadata',
    'Authorization-server metadata candidates', 'OAuth blockers', 'Outbound discovery on this screen',
    'Provider MCP isolation', 'Production separately approved', 'Default MCP server suppressed',
    'Unknown routes fail closed', 'Changes provider settings', 'Creates authority', 'Provider MCP routes removed',
):
    require(ui, marker, 'connection-staged-admin-experience')

for marker in (
    "'overview' =>", "'agents' =>", "'approvals' =>", "'mutations' =>", "'audit' =>",
    'Agents & Access', 'Approval tickets', 'Mutation / undo evidence', 'Append-only audit integrity',
):
    require(admin_ui, marker, 'main-governance-tabs')

for marker in (
    "'overview' =>", "'installed' =>", "'priority' =>", "'requests' =>",
    'MAD4B_SCP_Admin_Experience::stages', 'MAD4B_SCP_Admin_Experience::tabs', 'MAD4B_SCP_Admin_Experience::next_step',
    'Discover', 'Match adapter', 'Certify provider', 'Clear runtime blockers',
    'Installed Plugins', 'Priority Coverage', 'Support Requests', 'How to read coverage',
    'adapter_present_side_channel_blocked', 'Runtime blocker',
):
    require(adapter_ui, marker, 'adapter-staged-admin-experience')

for marker in (
    'mad4b-scp-stage-rail', 'mad4b-scp-card-grid', 'mad4b-scp-table-wrap',
    'aria-current', 'public static function tabs', 'public static function stages',
    'public static function cards', 'public static function next_step', 'public static function tab_url',
):
    require(admin_experience, marker, 'shared-admin-experience')

for marker in (
    'class-mad4b-scp-mcp-provider-isolation.php', 'class-mad4b-scp-external-handshake-evidence.php',
    'class-mad4b-scp-transport-context.php', 'class-mad4b-scp-connection-status.php',
    'class-mad4b-scp-connection-ability.php', 'class-mad4b-scp-read-consistency.php', 'class-mad4b-scp-admin-experience.php',
    'class-mad4b-scp-connection-admin-ui.php', 'class-mad4b-scp-mcp-registration-bridge.php',
    'class-mad4b-scp-mcp-registration-diagnostics-admin.php',
):
    require(bootstrap, marker, 'bootstrap-load')
require(bootstrap, 'MAD4B_SCP_MCP_Registration_Bridge::boot_early();', 'mcp-registration-early-boot')
require(bootstrap, 'MAD4B_SCP_MCP_Registration_Diagnostics_Admin::boot();', 'mcp-registration-diagnostics-boot')
require(bootstrap, 'MAD4B_SCP_MCP_Provider_Isolation::boot_early();', 'provider-early-boot')
require(bootstrap, 'MAD4B_SCP_External_Handshake_Evidence::boot();', 'external-evidence-boot')
require(plugin, 'MAD4B_SCP_Connection_Admin_UI::boot()', 'connection-ui-boot')
require(plugin, 'MAD4B_SCP_Connection_Ability::boot()', 'connection-ability-boot')
require(bootstrap, 'MAD4B_SCP_Read_Consistency::boot();', 'read-consistency-boot')
require(bootstrap, 'MAD4B_SCP_Authorization::boot();', 'authorization-execution-boundary-boot')
authorization_boot_pos = bootstrap.find('MAD4B_SCP_Authorization::boot();')
ability_bootstrap_pos = bootstrap.find('MAD4B_SCP_Site_Profile::bootstrap();')
if authorization_boot_pos < 0 or ability_bootstrap_pos < 0 or authorization_boot_pos > ability_bootstrap_pos:
    raise SystemExit('central authorization execution boundary must boot before runtime ability/bootstrap registration begins')
require(plugin, 'MAD4B_SCP_MCP_Provider_Isolation::boot();', 'isolation-boot')
require(plugin, 'MAD4B_SCP_MCP_Registration_Bridge::boot_early();', 'registration-bridge-idempotent-boot')
forbid(plugin, "add_action( 'mcp_adapter_init', array( $servers, 'register_servers' )", 'no-late-mcp-server-binding')

for marker in (
    "const CONTRACT = 'mad4b.mcp-registration-bridge.v2'",
    "add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_core_categories' ), 10 )",
    "add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_registry_categories' ), 20 )",
    "add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_core_abilities' ), 10 )",
    "add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_registry_abilities' ), 20 )",
    "add_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers' ), 10, 1 )",
    "'ability_hook_bound' => $core_ability_hook_bound && $registry_ability_hook_bound",
    "'adapter_init_seen_before_bridge_boot'", "'adapter_runtime_from_official_plugin'", "'registration_errors'",
    "'rest_init_seen_before_bridge_boot'", "'missed_rest_recovery_succeeded'", "'missed_rest_recovery_blocker'",
):
    require(bridge, marker, 'mcp-registration-bridge')
for marker in (
    'MAD4B MCP registration diagnostics', 'Adapter runtime from official plugin',
    'Adapter init happened before bridge boot', 'Registration error:',
):
    require(diagnostics, marker, 'mcp-registration-diagnostics')

for marker in (
    "const CONTRACT = 'mad4b.mcp-provider-isolation.v3'",
    "const ENABLE_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED'",
    "const RUNTIME_SUPPRESSION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_RUNTIME_SUPPRESSION_APPROVED'",
    'public static function runtime_suppression_approved()',
    'if ( ! self::configured() || ! self::runtime_suppression_approved() ) return false;',
    "add_filter( 'wpmedia_mcp_oauth_server_enabled'", 'filter_wpmedia_oauth_server_enabled',
    "add_filter( 'mcp_adapter_create_default_server'",
    "add_action( 'rest_api_init', array( __CLASS__, 'suppress_provider_server_registrations' ), 14 )",
    "add_action( 'init', array( __CLASS__, 'suppress_provider_server_registrations' ), 19 )",
    "add_action( 'mcp_adapter_init', array( __CLASS__, 'suppress_provider_server_registrations' ), -1000000 )",
    "add_filter( 'rest_endpoints'",
    "MAD4B_SCP_Provider_Transport_Registry::route_descriptors()",
    "MAD4B_SCP_Provider_Transport_Registry::server_callback_descriptors()",
    "'unknown_routes_fail_closed' => true", "'changes_provider_settings' => false", "'creates_authority' => false",
    "'legacy_enable_flag_alone_is_non_mutating' => true", "'runtime_suppression_requires_second_gate' => true",
):
    require(isolation, marker, 'provider-isolation-contract')

for marker in (
    "const CONTRACT = 'mad4b.provider-transport-registry.v1'",
    "'unknown_transport_auto_allowed' => false",
    "suppress_when_isolation_effective",
    "catalog_version_invalid", "external_visibility_invalid", "route_pattern_invalid",
):
    require(transport_registry, marker, 'provider-transport-registry-contract')

for marker in (
    '"server_id": "hostinger-ai-assistant-mcp-server"',
    '"server_id": "elementskit-mcp-server"',
    '/hostinger-ai-assistant/v1/mcp/', '/hostinger-ai-assistant/v1/jwt/', '/elementskit/mcp/',
):
    require(transport_catalog, marker, 'provider-transport-catalog')

for forbidden in ('update_option(', 'add_option(', 'delete_option(', 'wp_remote_get(', 'wp_remote_post(', 'deactivate_plugins(', 'activate_plugin(', 'ReflectionClass', 'setAccessible('):
    forbid(isolation, forbidden, 'provider-isolation-deny-only')

for marker in (
    "const CONTRACT = 'mad4b.mcp-peer-governance.v2'", 'foreign_transport_inventory', 'rest_get_server()',
    "get_option( 'active_plugins'", "'mcp-adapter/mcp-adapter.php'", "'mad4b-site-control-plane/mad4b-site-control-plane.php'",
    'is_known_namespace_index', "'get_namespace_index'", '$callback[0] !== $rest_server',
    'HOSTINGER_BANNER_CONTROL_ROUTE', 'is_reviewed_non_transport_route', 'reviewed_non_transport_routes',
    "'mcp_foreign_transport_unreviewed'", "'mcp_write_side_channel_detected'",
):
    require(peer, marker, 'foreign-mcp-fail-closed')
for bypass in ("apply_filters( 'mad4b_scp_mcp_peer", "apply_filters( 'mad4b_scp_ignore_mcp", "apply_filters( 'mad4b_scp_side_channel", "if ( '/mcp' === $route ) continue"):
    forbid(peer, bypass, 'foreign-mcp-no-bypass')

print('mad4b.site-control-plane.connection-readiness-contract.v10: PASS')

# Connection status is callable over MCP, so deep peer inventory must fail-soft
# on protocol hotpaths instead of scanning all servers/tools/routes inline.
for marker in (
    "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()",
    "'mcp_peer_inventory_deferred_protocol_hotpath'",
    "'deep_inventory_performed' => false",
):
    require(status, marker, 'connection-protocol-hotpath-peer-deferral')
peer_call_pos = status.index("MAD4B_SCP_MCP_Peer_Governance::status()")
hotpath_pos = status.index("current_request_is_protocol_hotpath()")
if hotpath_pos > peer_call_pos:
    raise SystemExit('FAIL connection-peer-deferral-order: hotpath decision must precede deep peer inventory')

# Deferred deep verification on an already-running MCP request is informational,
# not evidence that local transport is broken.
local_blocker_section = status.split("$local_blockers = array();", 1)[1].split("$remote_preflight_blockers", 1)[0]
require(local_blocker_section, "if ( ! $lightweight && empty( $peer['inventory_ready'] ) )", 'deferred-peer-neutral-readiness')
require(local_blocker_section, "if ( ! $lightweight && ! empty( $peer['blockers'] )", 'deferred-peer-blockers-not-promoted')
require(status, "'deferred_checks' => $lightweight ? array( 'route_permission_validation', 'mcp_peer_inventory', 'write_catalog_inventory', 'live_handshake_revalidation', 'provider_runtime_integrity' ) : array()", 'connection-deferred-checks-explicit')
require(status, "'route_registered' => $protocol_hotpath ? null", 'write-surface-deferred-route-tristate')
require(status, "'permission_callback_match' => $protocol_hotpath ? null", 'write-surface-deferred-permission-tristate')

# Protocol connection readiness uses a hash-free provider identity projection.
# Deep critical-file hashing remains mandatory for runtime_status/mutation_guard.
require(status, "MAD4B_SCP_Provider_Contracts::runtime_identity_status( 'mcp_adapter', $adapter_available )", 'connection-provider-identity-fastpath')
provider_branch = status.split("$provider = class_exists( 'MAD4B_SCP_Provider_Contracts' )", 1)[1].split("$provider_ok =", 1)[0]
require(provider_branch, "$lightweight", 'provider-identity-lightweight-branch')
require(provider_contracts, "public static function runtime_identity_status( $provider, $available = null )", 'provider-identity-projection')
identity_projection = provider_contracts.split("public static function runtime_identity_status( $provider, $available = null )", 1)[1].split("public static function runtime_status( $provider, $available = null )", 1)[0]
forbid(identity_projection, "hash_file(", 'provider-identity-no-byte-hashing')
require(identity_projection, "'runtime_integrity_verification_deferred' => true", 'provider-identity-deferred-integrity-explicit')
require(identity_projection, "'mutation_certified' => false", 'provider-identity-never-mutation-certifies')
mutation_guard = provider_contracts.split("public static function mutation_guard(", 1)[1]
require(mutation_guard, "self::runtime_status( $provider, $available )", 'mutation-still-deep-provider-certification')

# Deep transport verification is opt-in. MCP request-serving status remains
# lightweight, while acceptance fixtures may explicitly force physical checks.
require(status, "public static function status( $force_deep = false )", 'connection-explicit-deep-signature')
require(status, "$protocol_hotpath = ! $force_deep", 'connection-deep-bypasses-hotpath-projection')
require(status, "'explicit_deep_validation' => (bool) $force_deep", 'connection-deep-mode-observable')

# Ordinary Connection/ChatGPT wp-admin rendering is a first-class shallow mode.
# It must reuse the same hash-free identity projection as protocol hotpaths.
# Deep Endpoints diagnostics are POST + nonce only; opening a GET link never
# materializes the deep runtime.
for marker in (
    "self::admin_shallow_surface()",
    "$lightweight = $protocol_hotpath || $admin_shallow",
    "'mcp_peer_inventory_deferred_admin_hotpath'",
    "'state' => $protocol_hotpath ? 'deferred_protocol_hotpath' : 'deferred_admin_hotpath'",
    "'certification_deferred_checks'",
    "'deep_connection_diagnostics'",
    "'status_mode' => $force_deep ? 'deep_explicit'",
    "MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()",
    "'deep_status_deferred' =>",
):
    require(status, marker, 'connection-admin-shallow-mode')

admin_surface = status.split("private static function admin_shallow_surface()", 1)[1].split("private static function oauth_preflight_blockers", 1)[0]
require(admin_surface, "'mad4b-control-plane-connection'", 'connection-admin-shallow-route')
require(admin_surface, "'mad4b-control-plane-chatgpt'", 'chatgpt-admin-shallow-route')

require(ui, "public static function snapshot( $force_deep = false )", 'connection-ui-shallow-snapshot')
require(ui, "$deep_endpoints = false", 'connection-ui-deep-default-off')
require(ui, "'POST' === strtoupper", 'connection-ui-deep-post-only')
require(ui, "wp_verify_nonce( $nonce, 'mad4b_connection_deep_endpoints' )", 'connection-ui-deep-nonce')
require(ui, "self::snapshot( $deep_endpoints )", 'connection-ui-explicit-deep-snapshot')
require(ui, 'form method="post"', 'connection-ui-deep-post-form')
require(ui, "MAD4B_SCP_Local_OAuth_Server::runtime_identity_status()", 'connection-ui-oauth-identity-projection')
require(ui, "'endpoints' === $tab", 'connection-ui-deep-tab-gate')
require(ui, "MAD4B_SCP_Local_OAuth_Server::status()", 'connection-ui-explicit-deep-oauth-remains')
require(status, "MAD4B_SCP_MCP_Registration_Bridge::server_registration_identity_status( $id )", 'connection-shallow-central-registration-identity')
require(status, "'registration_identity_ready'", 'connection-shallow-registration-identity-ready')
require(status, "'identity_ready_deep_validation_deferred'", 'connection-shallow-transport-tristate')
require(status, "'remote_endpoint_preflight_state'", 'connection-shallow-remote-preflight-tristate')
require(status, "'remote_endpoint_deep_preflight_ready'", 'connection-deep-remote-preflight-fact')
require(ui, "Remote endpoint preflight state", 'connection-ui-remote-preflight-state')
require(status, "'persisted_external_evidence_deep_revalidation_deferred'", 'connection-shallow-certification-tristate')
require(status, "'evidence_present' => $evidence_present", 'connection-shallow-persisted-evidence')
require(status, "count( $fallback_server_ids )", 'connection-fallback-server-count-no-drift')
require(ui, "Identity ready · deep validation deferred", 'connection-ui-transport-deferred-state')
require(ui, "Deep revalidation deferred", 'connection-ui-certification-deferred-state')
require(ui, "Deep connection validation result", 'connection-ui-deep-result')

# Protocol connection status consumes persisted handshake evidence only. Live
# build/tool revalidation remains available through explicit deep diagnostics.
status_method = status.split("public static function status( $force_deep = false )", 1)[1].split("private static function bounded_mcp_registration_lifecycle()", 1)[0]
require(status_method, "MAD4B_SCP_External_Handshake_Evidence::persisted_identity_status()", 'connection-persisted-handshake-hotpath')
require(status_method, "MAD4B_SCP_External_Handshake_Evidence::status()", 'connection-live-handshake-deep-path')
require(status_method, "'live_handshake_revalidation'", 'connection-deferred-live-handshake-marker')
require(status_method, "'provider_runtime_integrity'", 'connection-deferred-provider-integrity-marker')
