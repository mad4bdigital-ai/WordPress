#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
bridge = (root / 'includes' / 'class-mad4b-scp-oauth-resource-bridge.php').read_text(encoding='utf-8')
alignment = (root / 'includes' / 'class-mad4b-scp-oauth-challenge-alignment.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required = [
    "mad4b.oauth-resource-bridge.v2",
    "MAD4B_MCP_OAUTH_ENABLED",
    "MAD4B_MCP_OAUTH_ISSUER",
    "MAD4B_MCP_OAUTH_WP_USER_ID",
    "MAD4B_MCP_OAUTH_PRODUCTION_APPROVED",
    "mad4b:read",
    "/mcp/mad4b-read",
    "oauth-protected-resource",
    "WWW-Authenticate",
    "resource_metadata=",
    "authoritative_well_known_url",
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
    "RSA JWKS signing key must provide x5c or n/e public-key material.",
    "mad4b_oauth_issuer_mismatch",
    "mad4b_oauth_audience_mismatch",
    "mad4b_oauth_token_expired",
    "mad4b_oauth_token_not_yet_valid",
    "mad4b_oauth_scope_missing",
    "subject_fingerprint",
    "oauth2_bearer",
    "stores_bearer_tokens' => false",
    "creates_credentials' => false",
    "write_surfaces_enabled' => false",
]
for marker in required:
    if marker not in bridge:
        raise SystemExit(f"missing OAuth bridge marker: {marker}")

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
    "update_option(",
    "add_option(",
    "delete_option(",
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
if "MAD4B_SCP_OAuth_Challenge_Alignment::boot()" not in plugin:
    raise SystemExit("plugin boot does not initialize OAuth challenge alignment")

print('mad4b.site-control-plane.oauth-resource-bridge.v2: PASS')
