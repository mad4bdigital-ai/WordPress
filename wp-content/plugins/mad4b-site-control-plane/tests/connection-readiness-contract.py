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
servers = read('includes/class-mad4b-scp-servers.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')

require(status, "mad4b.connection-readiness.v1", 'connection-contract')
for marker in (
    'get_server_route_namespace', 'get_server_route', 'get_transport_permission_callback',
    'rest_get_server()', 'route_registered', 'permission_callback_match',
    "'local_transport_ready'", "'remote_endpoint_preflight_ready'",
    "'connection_certified' => false", "'external_handshake_unverified'",
    "'credential_material_exposed' => false", "'credential_creation_supported_here' => false",
    "'remote_subject_bridge_required' => true",
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
require(ui, 'add_submenu_page(', 'connection-admin-submenu')
require(ui, "'manage_options'", 'connection-admin-capability')
require(ui, 'Read-only transport evidence.', 'connection-admin-disclosure')
require(bootstrap, 'class-mad4b-scp-connection-status.php', 'connection-status-bootstrap')
require(bootstrap, 'class-mad4b-scp-connection-ability.php', 'connection-ability-bootstrap')
require(bootstrap, 'class-mad4b-scp-connection-admin-ui.php', 'connection-ui-bootstrap')
require(plugin, 'MAD4B_SCP_Connection_Admin_UI::boot()', 'connection-ui-boot')
require(plugin, 'MAD4B_SCP_Connection_Ability::boot()', 'connection-ability-boot')

for marker in (
    "const CONTRACT = 'mad4b.mcp-peer-governance.v2'",
    'foreign_transport_inventory', 'rest_get_server()', "get_option( 'active_plugins'",
    "'mcp-adapter/mcp-adapter.php'", "'mad4b-site-control-plane/mad4b-site-control-plane.php'",
    "'mcp_foreign_transport_unreviewed'", "'mcp_write_side_channel_detected'",
):
    require(peer, marker, 'foreign-mcp-fail-closed')
for bypass in ("apply_filters( 'mad4b_scp_mcp_peer", "apply_filters( 'mad4b_scp_ignore_mcp", "apply_filters( 'mad4b_scp_side_channel"):
    forbid(peer, bypass, 'foreign-mcp-no-bypass')

print('mad4b.site-control-plane.connection-readiness-contract.v1: PASS')
