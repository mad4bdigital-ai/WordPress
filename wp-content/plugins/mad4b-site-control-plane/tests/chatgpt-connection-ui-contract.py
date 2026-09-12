#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
ui = (root / 'includes' / 'class-mad4b-scp-chatgpt-connection-admin-ui.php').read_text(encoding='utf-8')
consent_ui = (root / 'includes' / 'class-mad4b-scp-local-oauth-consent-ui.php').read_text(encoding='utf-8')
js = (root / 'assets' / 'chatgpt-connection.js').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required_ui = [
    'mad4b.chatgpt-connection-ui.v2',
    'Connect to ChatGPT',
    'https://chatgpt.com/oauth/client.json',
    'https://chatgpt.com/connector_platform_oauth_redirect',
    'https://chatgpt.com/plugins#settings/Connectors?create-connector=true',
    "'server_url' => $server_url",
    "'authentication' => 'OAuth'",
    "'client_registration' => 'client_id_metadata_document'",
    "'token_endpoint_auth_method' => 'none'",
    "array( 'mad4b:read', 'offline_access' )",
    "'gateway_server_id' => 'mad4b-chatgpt'",
    "'generic_filesystem_exposed' => false",
    "'generic_database_exposed' => false",
    "'write_admin_breakglass_exposed' => false",
    "'creates_chatgpt_connector' => false",
    "'stores_chatgpt_credentials' => false",
    "'external_connection_certified' => false",
    'CIMD / ChatGPT managed',
    'Open ChatGPT Plugin Builder',
    'Run OAuth Canary',
]
for marker in required_ui:
    if marker not in ui:
        raise SystemExit(f'missing ChatGPT connection UI marker: {marker}')

for forbidden in [
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->',
    'client_secret', 'access_token', 'refresh_token', 'file_put_contents(',
    'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',
    "'client_registration' => 'user_defined_oauth_client'",
    'MAD4B_MCP_MUTATION_ENABLED', 'MAD4B_MCP_BREAKGLASS_ENABLED',
]:
    if forbidden in ui:
        raise SystemExit(f'forbidden ChatGPT connection UI primitive: {forbidden}')

required_consent_semantics = [
    'mad4b.local-oauth-consent-ui.v2',
    "add_action( 'admin_init', array( __CLASS__, 'start_buffer_for_connection_admin' ), -20 )",
    'This consent authenticates the client and grants the narrow read resource scope shown below; it does not grant write authority.',
    'Governed write actions, when available, require separate Staging write authority and a one-time approval.',
    'OAuth identity/read scope · write authority separate · PKCE S256',
    'Connection and certification workspace.',
    'This page is inspection-only:',
    'Governed write capability, when available, is established separately by Write Authority, Write Runtime Certification and one-time approvals.',
    'This certification does not grant write authority; governed mutation availability is evaluated separately by Write Authority and Write Runtime Certification.',
]
for marker in required_consent_semantics:
    if marker not in consent_ui:
        raise SystemExit(f'missing OAuth/write-authority semantic marker: {marker}')

for forbidden in [
    'Read-only access · OAuth 2.1 · PKCE S256',
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->',
    'MAD4B_MCP_MUTATION_ENABLED', 'MAD4B_MCP_BREAKGLASS_ENABLED',
    'grant_ability(', 'approve(', 'wp_remote_get(', 'wp_remote_post(',
]:
    if forbidden in consent_ui:
        raise SystemExit(f'forbidden consent/connection semantic primitive: {forbidden}')

for marker in ['mad4b-chatgpt-copy', 'navigator.clipboard.writeText', 'data-copy-target']:
    if marker not in (ui + js):
        raise SystemExit(f'missing ChatGPT copy-helper marker: {marker}')

if 'class-mad4b-scp-chatgpt-connection-admin-ui.php' not in main:
    raise SystemExit('main plugin does not load ChatGPT connection UI')
if 'class-mad4b-scp-local-oauth-consent-ui.php' not in main:
    raise SystemExit('main plugin does not load OAuth consent semantic UI')
if 'MAD4B_SCP_ChatGPT_Connection_Admin_UI::boot()' not in plugin:
    raise SystemExit('plugin boot does not initialize ChatGPT connection UI')
if 'MAD4B_SCP_Local_OAuth_Consent_UI::boot()' not in main:
    raise SystemExit('main plugin does not initialize OAuth consent semantic UI')

print('mad4b.site-control-plane.chatgpt-connection-ui.v3: PASS')
