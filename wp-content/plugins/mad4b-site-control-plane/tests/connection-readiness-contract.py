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
ability = read('includes/class-mad4b-scp-connection-ability.php')
ui = read('includes/class-mad4b-scp-connection-admin-ui.php')
peer = read('includes/class-mad4b-scp-mcp-peer-governance.php')
transport_context = read('includes/class-mad4b-scp-transport-context.php')
authz = read('includes/class-mad4b-scp-authorization.php')
servers = read('includes/class-mad4b-scp-servers.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')

require(status, "mad4b.connection-readiness.v2", 'connection-contract')
for marker in (
    'get_server_route_namespace', 'get_server_route', 'get_transport_permission_callback',
    'rest_get_server()', 'route_registered', 'permission_callback_match',
    "'local_transport_ready'", "'remote_endpoint_preflight_ready'",
    "'connection_certified' => false", "'external_handshake_unverified'",
    "'credential_material_exposed' => false", "'credential_creation_supported_here' => false",
    "'remote_subject_bridge_required' => true", "'write_surface'",
    "'exact_transport_grant_required' => true", "'generic_dispatcher_exposed' => false",
):
    require(status, marker, 'connection-status-truth')

for outbound in ('wp_remote_get(', 'wp_remote_post(', 'wp_remote_request(', 'curl_exec(', 'fsockopen('):
    forbid(status + '\n' + ui, outbound, 'no-self-probe-ssrf')
for write in ('$_POST', 'admin_post_', '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete(', 'update_option(', 'add_option(', 'delete_option('):
    forbid(ui, write, 'connection-ui-read-only')
for secret in ('client_secret', 'access_token', 'refresh_token', 'authorization_header', 'raw_token', 'password_hash'):
    forbid(ui + '\n' + status, secret, 'connection-no-secret-material')

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

require(transport_context, "const CONTRACT = 'mad4b.mcp-transport-context.v1'", 'transport-context-contract')
require(transport_context, "'/mcp/' . $server_id", 'transport-exact-route')
require(transport_context, "'mad4b_transport_route_mismatch'", 'transport-route-mismatch')
require(transport_context, 'resolve_server_for_ability', 'transport-effective-server-resolver')
require(transport_context, 'MAD4B_SCP_Servers::ability_is_mounted', 'transport-mount-verification')
require(transport_context, "'mad4b_transport_ability_not_mounted'", 'transport-ability-mount-denial')
for bypass in ("apply_filters( 'mad4b_scp_transport", "$_REQUEST", "$_GET", "$_POST"):
    forbid(transport_context, bypass, 'transport-context-no-bypass-input')

require(authz, 'MAD4B_SCP_Transport_Context::resolve_server_for_ability', 'central-transport-rebind')
require(authz, '$declared_server_id', 'declared-server-evidence')
require(authz, "'transport_bound'", 'transport-binding-evidence')
if authz.index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > authz.index('MAD4B_SCP_Agent_Registry::exact_grant'):
    raise SystemExit('FAIL transport-before-grant: active MCP transport must bind before exact grant lookup')
if authz.index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > authz.index('MAD4B_SCP_Approval_Tickets::consume_exact'):
    raise SystemExit('FAIL transport-before-approval: active MCP transport must bind before approval consumption')

require(ui, 'add_submenu_page(', 'connection-admin-submenu')
require(ui, "'manage_options'", 'connection-admin-capability')
require(ui, 'Read-only transport evidence.', 'connection-admin-disclosure')
require(bootstrap, 'class-mad4b-scp-transport-context.php', 'transport-context-bootstrap')
require(bootstrap, 'class-mad4b-scp-connection-status.php', 'connection-status-bootstrap')
require(bootstrap, 'class-mad4b-scp-connection-ability.php', 'connection-ability-bootstrap')
require(bootstrap, 'class-mad4b-scp-connection-admin-ui.php', 'connection-ui-bootstrap')
require(plugin, 'MAD4B_SCP_Connection_Admin_UI::boot()', 'connection-ui-boot')
require(plugin, 'MAD4B_SCP_Connection_Ability::boot()', 'connection-ability-boot')

for marker in (
    "const CONTRACT = 'mad4b.mcp-peer-governance.v2'",
    'foreign_transport_inventory', 'rest_get_server()', "get_option( 'active_plugins'",
    "'mcp-adapter/mcp-adapter.php'", "'mad4b-site-control-plane/mad4b-site-control-plane.php'",
    'is_known_namespace_index', "'get_namespace_index'",
    '$callback[0] !== $rest_server', "'mcp_foreign_transport_unreviewed'", "'mcp_write_side_channel_detected'",
):
    require(peer, marker, 'foreign-mcp-fail-closed')
for bypass in (
    "apply_filters( 'mad4b_scp_mcp_peer",
    "apply_filters( 'mad4b_scp_ignore_mcp",
    "apply_filters( 'mad4b_scp_side_channel",
    "if ( '/mcp' === $route ) continue",
):
    forbid(peer, bypass, 'foreign-mcp-no-bypass')

print('mad4b.site-control-plane.connection-readiness-contract.v3: PASS')
