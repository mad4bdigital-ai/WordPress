#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
server = (root / 'includes' / 'class-mad4b-scp-local-oauth-server.php').read_text(encoding='utf-8')
store = (root / 'includes' / 'class-mad4b-scp-local-oauth-store.php').read_text(encoding='utf-8')
guard = (root / 'includes' / 'class-mad4b-scp-local-oauth-loopback-guard.php').read_text(encoding='utf-8')
init_lock = (root / 'includes' / 'class-mad4b-scp-local-oauth-init-lock.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required_server = [
    'mad4b.local-oauth-server.v2',
    'MAD4B_MCP_LOCAL_OAUTH_ENABLED',
    'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED',
    'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',
    'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH',
    'const MAX_CLIENT_ID_BYTES = 191;',
    'const MAX_URI_BYTES = 2048;',
    'const MAX_TOKEN_INPUT_BYTES = 2048;',
    "authorization_response_iss_parameter_supported' => true",
    "client_id_metadata_document_supported' => false",
    "dynamic_client_registration_supported' => false",
    "client_registration_mode' => 'pre_registered'",
    "code_challenge_methods_supported' => array( 'S256' )",
    "'protected_resources' => array( self::resource_identifier() )",
    "'alg' => 'RS256'",
    "'use' => 'sig'",
    "'key_ops' => array( 'verify' )",
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
    'configured_issuer_validation',
    'mad4b_local_oauth_issuer_cross_origin',
    'issuer_same_origin_required',
    'issuer_configuration_valid',
    'consent_clickjacking_protected',
    "X-Frame-Options: DENY",
    "frame-ancestors 'none'",
    'Referrer-Policy: no-referrer',
    'X-Content-Type-Options: nosniff',
    'request_param(',
    'OAuth parameter must be scalar',
    'OAuth parameter exceeds its size limit',
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
    'strlen( $client_id ) > 512',
]:
    if forbidden in server:
        raise SystemExit(f'forbidden local OAuth server primitive: {forbidden}')

required_store = [
    'mad4b_scp_oauth_codes',
    'mad4b_scp_oauth_refresh_tokens',
    'code_hash char(64)',
    'token_hash char(64)',
    'family_id char(36)',
    'client_id varchar(191)',
    'const MAX_CLIENT_ID_BYTES = 191;',
    'valid_client_id',
    'valid_sha256_hex',
    'used_at datetime NULL',
    'revoked_at datetime NULL',
    'replacement_hash char(64)',
    'mark_code_used',
    'rotate_refresh_token',
    'revoke_family',
    'family_is_revoked',
    'Pre-insert guard catches a replay/revocation',
    'Post-insert guard closes the important race',
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

required_lock = [
    'mad4b.local-oauth-init-lock.v1',
    "add_action( 'init', array( __CLASS__, 'acquire' ), 0 )",
    "add_action( 'init', array( __CLASS__, 'release' ), 2 )",
    "flock( $handle, LOCK_EX )",
    "flock( self::$handle, LOCK_UN )",
    "'.init.lock'",
    "lock_contains_secret_material' => false",
    'is_file( $key_path )',
    "trailingslashit( wp_normalize_path( ABSPATH ) )",
    "0 === strpos( trailingslashit( dirname( $normalized ) ), $web_root )",
]
for marker in required_lock:
    if marker not in init_lock:
        raise SystemExit(f'missing local OAuth init-lock structural guard: {marker}')
for forbidden in ['file_put_contents(', 'openssl_pkey_new(', 'openssl_sign(', 'update_option(', 'add_option(']:
    if forbidden in init_lock:
        raise SystemExit(f'init lock must not own credentials or persistent authority: {forbidden}')

for loaded in [
    'class-mad4b-scp-local-oauth-store.php',
    'class-mad4b-scp-local-oauth-server.php',
    'class-mad4b-scp-local-oauth-init-lock.php',
    'class-mad4b-scp-local-oauth-loopback-guard.php',
]:
    if loaded not in main:
        raise SystemExit(f'main plugin does not load local OAuth component: {loaded}')
for boot_marker in [
    'MAD4B_SCP_Local_OAuth_Init_Lock::boot()',
    'MAD4B_SCP_Local_OAuth_Loopback_Guard::boot()',
    'MAD4B_SCP_Local_OAuth_Server::boot()',
]:
    if boot_marker not in plugin:
        raise SystemExit(f'plugin boot missing local OAuth component: {boot_marker}')

runtime = (root / 'tests' / 'runtime-local-oauth-standalone-smoke.php').read_text(encoding='utf-8')
for marker in [
    'local-oauth-standalone.runtime.v3',
    'family_is_revoked',
    'Model the dangerous interleaving explicitly',
    'too_long_client',
    'configured_issuer_validation',
    'mad4b_local_oauth_issuer_cross_origin',
]:
    if marker not in runtime:
        raise SystemExit(f'missing local OAuth runtime hardening proof: {marker}')

lock_runtime = (root / 'tests' / 'runtime-local-oauth-init-lock-smoke.php').read_text(encoding='utf-8')
for marker in [
    'local-oauth-init-lock.runtime.v1',
    "has_action( 'init', array( 'MAD4B_SCP_Local_OAuth_Init_Lock', 'acquire' ) )",
    "has_action( 'init', array( 'MAD4B_SCP_Local_OAuth_Server', 'ensure_runtime' ) )",
    "'.init.lock'",
]:
    if marker not in lock_runtime:
        raise SystemExit(f'missing local OAuth init-lock runtime proof: {marker}')

print('mad4b.site-control-plane.local-oauth-standalone.v4: PASS')
