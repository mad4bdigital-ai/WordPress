#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
ui = (root / 'includes' / 'class-mad4b-scp-chatgpt-connection-admin-ui.php').read_text(encoding='utf-8')
js = (root / 'assets' / 'chatgpt-connection.js').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required_ui = [
    'mad4b.chatgpt-connection-ui.v1',
    'Connect to ChatGPT',
    'https://chatgpt.com/oauth/client.json',
    'https://chatgpt.com/connector_platform_oauth_redirect',
    'https://chatgpt.com/plugins#settings/Connectors?create-connector=true',
    "'server_url' => $server_url",
    "'authentication' => 'OAuth'",
    "'client_registration' => 'user_defined_oauth_client'",
    "'token_endpoint_auth_method' => 'none'",
    "array( 'mad4b:read', 'offline_access' )",
    "'creates_chatgpt_connector' => false",
    "'stores_chatgpt_credentials' => false",
    "'external_connection_certified' => false",
    'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',
    'Open ChatGPT Plugin Builder',
    'Run OAuth Canary',
]
for marker in required_ui:
    if marker not in ui:
        raise SystemExit(f'missing ChatGPT connection UI marker: {marker}')

for forbidden in [
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->',
    'client_secret', 'access_token', 'refresh_token', 'file_put_contents(',
    'MAD4B_MCP_MUTATION_ENABLED', 'MAD4B_MCP_BREAKGLASS_ENABLED',
]:
    if forbidden in ui:
        raise SystemExit(f'forbidden ChatGPT connection UI primitive: {forbidden}')

for marker in ['mad4b-chatgpt-copy', 'navigator.clipboard.writeText', 'data-copy-target']:
    if marker not in (ui + js):
        raise SystemExit(f'missing ChatGPT copy-helper marker: {marker}')

if 'class-mad4b-scp-chatgpt-connection-admin-ui.php' not in main:
    raise SystemExit('main plugin does not load ChatGPT connection UI')
if 'MAD4B_SCP_ChatGPT_Connection_Admin_UI::boot()' not in plugin:
    raise SystemExit('plugin boot does not initialize ChatGPT connection UI')

print('mad4b.site-control-plane.chatgpt-connection-ui.v1: PASS')
