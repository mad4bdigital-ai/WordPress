#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
component = (root / 'includes' / 'class-mad4b-scp-chatgpt-oauth-lifecycle.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')

required = [
    'mad4b.chatgpt-oauth-lifecycle.v1',
    "CHATGPT_CIMD_CLIENT_ID",
    "MAD4B_MCP_LOCAL_OAUTH_ENABLED",
    "add_action( 'parse_request', array( __CLASS__, 'augment_authorization_scope' ), -30 )",
    "MAD4B_SCP_Local_OAuth_Server::authorize_url()",
    "MAD4B_SCP_Local_OAuth_Server::resource_identifier()",
    "'code' !== $response_type",
    "'S256' !== strtoupper( $pkce_method )",
    "'mad4b:read'",
    "'offline_access'",
    "$params['scope'] = implode( ' ', $scopes )",
]
for marker in required:
    if marker not in component:
        raise SystemExit(f'missing ChatGPT OAuth lifecycle marker: {marker}')

for forbidden in [
    'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED',
    'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED',
    'MAD4B_MCP_MUTATION_ENABLED',
    'mad4b-write',
    'mad4b-admin',
    'mad4b-breakglass',
    'client_secret',
    'wp_remote_get(',
    'wp_safe_remote_get(',
    'curl_exec(',
]:
    if forbidden in component:
        raise SystemExit(f'forbidden ChatGPT OAuth lifecycle primitive: {forbidden}')

for marker in [
    "class-mad4b-scp-chatgpt-oauth-lifecycle.php",
    "MAD4B_SCP_ChatGPT_OAuth_Lifecycle::boot()",
]:
    if marker not in main:
        raise SystemExit(f'plugin bootstrap missing ChatGPT OAuth lifecycle component: {marker}')

print('mad4b.site-control-plane.chatgpt-oauth-lifecycle.v1: PASS')
