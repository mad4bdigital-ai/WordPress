#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
server = (root / 'includes' / 'class-mad4b-scp-local-oauth-server.php').read_text(encoding='utf-8')
store = (root / 'includes' / 'class-mad4b-scp-local-oauth-store.php').read_text(encoding='utf-8')
guard = (root / 'includes' / 'class-mad4b-scp-local-oauth-loopback-guard.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required_server = [
    'mad4b.local-oauth-server.v1',
    'MAD4B_MCP_LOCAL_OAUTH_ENABLED',
    'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED',
    'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',
    'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH',
    "authorization_response_iss_parameter_supported' => true",
    "client_id_metadata_document_supported' => false",
    "dynamic_client_registration_supported' => false",
    "client_registration_mode' => 'pre_registered'",
    "code_challenge_methods_supported' => array( 'S256' )",
    "'protected_resources' => array( self::resource_identifier() )",
    "'alg' => 'RS256'",
    "'resource' => $resource",
    "'iss' => self::issuer()",
    "'aud' => $resource",
    "'sub' => 'user:' . (int) $wp_user_id",
    'random_bytes(',
    "hash( 'sha256', $code )",
    "hash( 'sha256', $token )",
    'openssl_sign(',
    'OPENSSL_ALGO_SHA256',
    'pre_http_request',
    'intercept_local_discovery',
    'private_key_exposed',
    'private_key_stored_in_database',
    'outside the WordPress web root',
    'Refresh token replay detected; token family revoked.',
]
for marker in required_server:
    if marker not in server:
        raise SystemExit(f'missing local OAuth server marker: {marker}')

for forbidden in [
    'registration_endpoint',
    'client_secret',
    'wp_remote_get(',
    'wp_safe_remote_get(',
    "update_option( 'mad4b_mcp_local_oauth_private_key'",
    "add_option( 'mad4b_mcp_local_oauth_private_key'",
]:
    if forbidden in server:
        raise SystemExit(f'forbidden local OAuth server primitive: {forbidden}')

required_store = [
    'mad4b_scp_oauth_codes',
    'mad4b_scp_oauth_refresh_tokens',
    'code_hash char(64)',
    'token_hash char(64)',
    'family_id char(36)',
    'used_at datetime NULL',
    'revoked_at datetime NULL',
    'replacement_hash char(64)',
    'mark_code_used',
    'rotate_refresh_token',
    'revoke_family',
    "plaintext_authorization_codes_stored' => false",
    "plaintext_refresh_tokens_stored' => false",
]
for marker in required_store:
    if marker not in store:
        raise SystemExit(f'missing local OAuth store marker: {marker}')

required_guard = [
    'pre_http_request',
    '/.well-known/openid-configuration',
    '/.well-known/oauth-authorization-server',
    'MAD4B_SCP_Local_OAuth_Server::metadata()',
    'MAD4B_SCP_Local_OAuth_Server::jwks_document()',
]
for marker in required_guard:
    if marker not in guard:
        raise SystemExit(f'missing local OAuth loopback guard marker: {marker}')
for forbidden in ['wp_remote_get(', 'wp_safe_remote_get(', 'curl_exec(']:
    if forbidden in guard:
        raise SystemExit(f'forbidden loopback guard network primitive: {forbidden}')

if 'class-mad4b-scp-local-oauth-store.php' not in main:
    raise SystemExit('main plugin does not load local OAuth store')
if 'class-mad4b-scp-local-oauth-server.php' not in main:
    raise SystemExit('main plugin does not load local OAuth server')
if 'class-mad4b-scp-local-oauth-loopback-guard.php' not in main:
    raise SystemExit('main plugin does not load local OAuth loopback guard')
if 'MAD4B_SCP_Local_OAuth_Loopback_Guard::boot()' not in plugin:
    raise SystemExit('plugin boot does not initialize local OAuth loopback guard')
if 'MAD4B_SCP_Local_OAuth_Server::boot()' not in plugin:
    raise SystemExit('plugin boot does not initialize local OAuth server')

print('mad4b.site-control-plane.local-oauth-standalone.v1: PASS')
