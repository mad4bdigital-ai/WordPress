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
ui = read('includes/class-mad4b-scp-connection-admin-ui.php')
admin_ui = read('includes/class-mad4b-scp-admin-ui.php')
adapter_ui = read('includes/class-mad4b-scp-adapter-coverage-admin-ui.php')
admin_experience = read('includes/class-mad4b-scp-admin-experience.php')
peer = read('includes/class-mad4b-scp-mcp-peer-governance.php')
isolation = read('includes/class-mad4b-scp-mcp-provider-isolation.php')
bridge = read('includes/class-mad4b-scp-mcp-registration-bridge.php')
diagnostics = read('includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php')
transport_context = read('includes/class-mad4b-scp-transport-context.php')
authz = read('includes/class-mad4b-scp-authorization.php')
servers = read('includes/class-mad4b-scp-servers.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')

require(status, "mad4b.connection-readiness.v4", 'connection-contract')
for marker in (
    'get_server_route_namespace', 'get_server_route', 'get_transport_permission_callback',
    'rest_get_server()', 'route_registered', 'permission_callback_match',
    "'local_transport_ready'", "'remote_endpoint_preflight_ready'", '$connection_certified',
    "'external_handshake_unverified'", "'external_handshake_stale'",
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

if status.index('$oauth_blockers = self::oauth_preflight_blockers') > status.index('$remote_preflight_blockers = array_merge'):
    raise SystemExit('FAIL oauth-before-remote-preflight: OAuth blockers must be resolved before remote readiness is claimed')
if status.index('$remote_preflight_blockers = array_merge') > status.index('$connection_certified = empty( $certification_blockers )'):
    raise SystemExit('FAIL preflight-before-certification: remote blockers must be assembled before final certification')

for marker in (
    "const CONTRACT = 'mad4b.external-handshake-evidence.v2'",
    "const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json'",
    "const SERVER_ID = 'mad4b-chatgpt'",
    "defined( 'REST_REQUEST' )", "defined( 'WP_CLI' ) && WP_CLI",
    "defined( 'DOING_CRON' ) && DOING_CRON", 'verified_bearer_active()',
    "'initialize'", "'tools/list'", "hash( 'sha256', $session_id )",
    "update_option( self::OPTION, $evidence, false )", "'credential_material_stored' => false",
    "'stale_build_evidence'", "'stale_tool_inventory_evidence'", "'stale_time_evidence'", 'build_fingerprint()',
    "'tool_inventory_fingerprint'", "'expected_tool_inventory_fingerprint'", "'tool_inventory_match'",
    'expected_tool_names()', 'expected_write_tool_names()', 'blocked_write_tool_names()', 'breakglass_tool_names()',
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
require(servers, "'mad4b/runtime-authority-status', 'mad4b/connection-status'", 'connection-mounted-read-server')
require(servers, "'mad4b-write'", 'write-server-id')
require(servers, "'MAD4B Write MCP'", 'write-server-registration')
require(servers, "array( __CLASS__, 'can_write_transport' )", 'write-server-permission')
require(servers, "public static function write_tools()", 'write-tool-projection')
require(servers, "array_key_exists( 'readonly', $annotations )", 'write-explicit-annotation')
require(servers, "false !== $annotations['readonly']", 'write-readonly-denial')
require(servers, "MAD4B_SCP_Adapter_Registry::instance()", 'write-adapter-projection')
require(servers, "return 'core';", 'write-core-provider-binding')
for generic in ('execute-any', 'generic-dispatch', 'call_user_func( $input', 'ability_name_from_request'):
    forbid(servers, generic, 'write-no-generic-dispatcher')

require(transport_context, "const CONTRACT = 'mad4b.mcp-transport-context.v2'", 'transport-context-contract')
require(transport_context, "'/mcp/' . $server_id", 'transport-exact-route')
require(transport_context, "'mad4b_transport_route_mismatch'", 'transport-route-mismatch')
require(transport_context, 'resolve_server_for_ability', 'transport-effective-server-resolver')
require(transport_context, 'MAD4B_SCP_Servers::ability_is_mounted', 'transport-mount-verification')
require(transport_context, "'mad4b_transport_ability_not_mounted'", 'transport-ability-mount-denial')
require(transport_context, 'MAD4B_SCP_Staging_Write_Authority::is_write_ability', 'transport-chatgpt-write-delegation')
require(transport_context, "return 'mad4b-write';", 'transport-dedicated-write-authority')
require(transport_context, "'mad4b_write_authority_mount_missing'", 'transport-write-authority-mount-denial')
for bypass in ("apply_filters( 'mad4b_scp_transport", "$_REQUEST", "$_GET", "$_POST"):
    forbid(transport_context, bypass, 'transport-context-no-bypass-input')

require(authz, 'MAD4B_SCP_Transport_Context::resolve_server_for_ability', 'central-transport-rebind')
require(authz, '$declared_server_id', 'declared-server-evidence')
require(authz, "'transport_bound'", 'transport-binding-evidence')
if authz.index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > authz.index('MAD4B_SCP_Agent_Registry::exact_grant'):
    raise SystemExit('FAIL transport-before-grant: active MCP transport must bind before exact grant lookup')
if authz.index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > authz.index('MAD4B_SCP_Approval_Tickets::validate_exact'):
    raise SystemExit('FAIL transport-before-approval: active MCP transport must bind before approval validation')

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
    'class-mad4b-scp-connection-ability.php', 'class-mad4b-scp-admin-experience.php',
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
    "add_filter( 'mcp_adapter_create_default_server'", "add_action( 'rest_api_init', array( __CLASS__, 'suppress_provider_server_registrations' ), 14 )",
    "add_action( 'init', array( __CLASS__, 'suppress_provider_server_registrations' ), 19 )",
    "add_action( 'mcp_adapter_init', array( __CLASS__, 'suppress_provider_server_registrations' ), -1000000 )",
    "add_filter( 'rest_endpoints'", "'hostinger-ai-assistant-mcp-server'", "'elementskit-mcp-server'",
    "/hostinger-ai-assistant/v1/mcp/", "/hostinger-ai-assistant/v1/jwt/", "/elementskit/mcp/",
    "'unknown_routes_fail_closed' => true", "'changes_provider_settings' => false", "'creates_authority' => false",
    "'legacy_enable_flag_alone_is_non_mutating' => true", "'runtime_suppression_requires_second_gate' => true",
):
    require(isolation, marker, 'provider-isolation-contract')
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

print('mad4b.site-control-plane.connection-readiness-contract.v9: PASS')
