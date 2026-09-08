#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
bridge = (root / 'includes' / 'class-mad4b-scp-oauth-resource-bridge.php').read_text(encoding='utf-8')
alignment = (root / 'includes' / 'class-mad4b-scp-oauth-challenge-alignment.php').read_text(encoding='utf-8')
overrides = (root / 'includes' / 'class-mad4b-scp-governed-ability-overrides.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required = [
    "mad4b.oauth-resource-bridge.v3",
    "MAD4B_MCP_OAUTH_MODE",
    "array( 'local', 'external', 'hybrid' )",
    "MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS",
    "MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER",
    "subject_allowed",
    "verified_bearer_active",
    "mad4b_oauth_issuer_untrusted",
    "mad4b_oauth_resource_mismatch",
    "mad4b_oauth_subject_not_approved",
    "MIN_RSA_BITS = 2048",
    "mad4b_oauth_jwk_rsa_too_small",
    "mad4b_oauth_jwk_use_invalid",
    "mad4b_oauth_jwk_key_ops_invalid",
    "mad4b_oauth_jwk_kid_ambiguous",
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
    if forbidden in alignment:
        raise SystemExit(f"forbidden OAuth challenge alignment primitive: {forbidden}")

if "class-mad4b-scp-oauth-resource-bridge.php" not in main:
    raise SystemExit("main plugin does not load OAuth resource bridge")
if "class-mad4b-scp-oauth-challenge-alignment.php" not in main:
    raise SystemExit("main plugin does not load OAuth challenge alignment")
if "MAD4B_SCP_OAuth_Resource_Bridge::boot()" not in plugin:
    raise SystemExit("plugin boot does not initialize OAuth resource bridge")
if "bind_local_oauth_subject_compatibility" not in plugin:
    raise SystemExit("plugin boot does not derive local subject compatibility from issuer-bound policy")

print('mad4b.site-control-plane.oauth-resource-bridge.v3: PASS')
