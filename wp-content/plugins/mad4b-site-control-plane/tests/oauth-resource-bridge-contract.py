#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
bridge = (root / 'includes' / 'class-mad4b-scp-oauth-resource-bridge.php').read_text(encoding='utf-8')
context_guard = (root / 'includes' / 'class-mad4b-scp-oauth-request-context-guard.php').read_text(encoding='utf-8')
alignment = (root / 'includes' / 'class-mad4b-scp-oauth-challenge-alignment.php').read_text(encoding='utf-8')
overrides = (root / 'includes' / 'class-mad4b-scp-governed-ability-overrides.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
runtime_context = (root / 'tests' / 'runtime-oauth-context-cooldown-smoke.php').read_text(encoding='utf-8')

required = [
    "mad4b.oauth-resource-bridge.v3",
    "MAD4B_MCP_OAUTH_MODE",
    "array( 'local', 'external', 'hybrid' )",
    "MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS",
    "MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER",
    "subject_allowed",
    "verified_bearer_active",
    "reset_verified_bearer_context",
    "self::reset_verified_bearer_context( true )",
    "self::reset_verified_bearer_context( false )",
    "bearer_request_resets_identity_before_verification",
    "mad4b_oauth_issuer_untrusted",
    "mad4b_oauth_resource_mismatch",
    "mad4b_oauth_subject_not_approved",
    "MIN_RSA_BITS = 2048",
    "mad4b_oauth_jwk_rsa_too_small",
    "mad4b_oauth_jwk_use_invalid",
    "mad4b_oauth_jwk_key_ops_invalid",
    "mad4b_oauth_jwk_kid_ambiguous",
    "JWKS_REFRESH_COOLDOWN = 30",
    "claim_jwks_refresh_slot",
    "jwks_refresh_cooldown_seconds",
    "jwks_cache_bound_to_issuer",
    "$issuer . \"\\0\" . (string) $jwks_uri",
    "delete_transient( $key )",
    "'redirection' => 0",
    "MAX_DISCOVERY_BYTES",
    "MAX_JWKS_BYTES",
    "MAX_JWKS_KEYS",
    "resource_metadata=",
    "authorization_server_metadata_urls",
    "/.well-known/oauth-authorization-server",
    "wp_safe_remote_get",
    "openssl_verify",
    "OPENSSL_ALGO_SHA256",
    "RS256",
    "code_challenge_methods_supported",
    "S256",
    "public_key_from_jwk",
    "jwks_rsa_ne_supported",
    "stores_bearer_tokens' => false",
    "creates_credentials' => false",
    "write_surfaces_enabled' => false",
]
for marker in required:
    if marker not in bridge:
        raise SystemExit(f"missing OAuth bridge marker: {marker}")

for marker in [
    "mad4b.oauth-request-context-guard.v1",
    "add_filter( 'rest_pre_dispatch', array( __CLASS__, 'reset_request_context' ), -1, 3 )",
    "verified_bearer_active",
    "reset_verified_bearer_context( true )",
    "reset_verified_bearer_context( false )",
    "clears_stale_oauth_service_user",
    "preserves_clean_local_admin_session",
    "creates_authority' => false",
]:
    if marker not in context_guard:
        raise SystemExit(f"missing OAuth request-context guard marker: {marker}")

for marker in [
    "mad4b.remote-oauth-read-policy.v1",
    "MAD4B_MCP_OAUTH_REMOTE_READ_ALLOWLIST",
    "mad4b/filesystem-read",
    "mad4b/database-select",
    "mad4b_remote_oauth_sensitive_read_denied",
    "verified_bearer_active",
    "mad4b_remote_oauth_default",
]:
    if marker not in overrides:
        raise SystemExit(f"missing remote OAuth read-policy marker: {marker}")

alignment_required = [
    "mad4b.oauth-challenge-alignment.v1",
    "rest_post_dispatch",
    "/mcp/mad4b-read",
    "resource_metadata=",
    "authoritative_well_known_url",
    "MAD4B_SCP_OAuth_Resource_Bridge::READ_SCOPE",
]
for marker in alignment_required:
    if marker not in alignment:
        raise SystemExit(f"missing OAuth challenge alignment marker: {marker}")

for marker in [
    'runtime-oauth-context-cooldown.v2',
    'claim_jwks_refresh_slot',
    'Second unknown-kid refresh attempt must be suppressed during cooldown.',
    'Pre-gate denial path retained stale OAuth service identity.',
    'No-bearer subrequest inherited stale OAuth service identity.',
    'Clean no-bearer local admin session was incorrectly demoted.',
]:
    if marker not in runtime_context:
        raise SystemExit(f"missing OAuth cooldown/context runtime proof: {marker}")

for forbidden in [
    "file_put_contents(",
    "error_log( $token",
    "MAD4B_MCP_MUTATION_ENABLED",
    "mad4b-write' === $route",
    "mad4b-admin' === $route",
    "mad4b-breakglass' === $route",
    "HS256",
]:
    if forbidden in bridge:
        raise SystemExit(f"forbidden OAuth bridge primitive: {forbidden}")
    if forbidden in context_guard:
        raise SystemExit(f"forbidden OAuth context-guard primitive: {forbidden}")
    if forbidden in alignment:
        raise SystemExit(f"forbidden OAuth challenge alignment primitive: {forbidden}")

for loaded in [
    "class-mad4b-scp-oauth-resource-bridge.php",
    "class-mad4b-scp-oauth-request-context-guard.php",
    "class-mad4b-scp-oauth-challenge-alignment.php",
]:
    if loaded not in main:
        raise SystemExit(f"main plugin does not load OAuth component: {loaded}")
for boot_marker in [
    "MAD4B_SCP_OAuth_Request_Context_Guard::boot()",
    "MAD4B_SCP_OAuth_Resource_Bridge::boot()",
]:
    if boot_marker not in plugin:
        raise SystemExit(f"plugin boot does not initialize OAuth component: {boot_marker}")
if "bind_local_oauth_subject_compatibility" not in plugin:
    raise SystemExit("plugin boot does not derive local subject compatibility from issuer-bound policy")

print('mad4b.site-control-plane.oauth-resource-bridge.v5: PASS')
