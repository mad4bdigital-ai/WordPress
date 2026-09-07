#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
bridge = (root / 'includes' / 'class-mad4b-scp-oauth-resource-bridge.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required = [
    "mad4b.oauth-resource-bridge.v1",
    "MAD4B_MCP_OAUTH_ENABLED",
    "MAD4B_MCP_OAUTH_ISSUER",
    "MAD4B_MCP_OAUTH_WP_USER_ID",
    "MAD4B_MCP_OAUTH_PRODUCTION_APPROVED",
    "mad4b:read",
    "/mcp/mad4b-read",
    "oauth-protected-resource",
    "WWW-Authenticate",
    "resource_metadata=",
    "wp_safe_remote_get",
    "openssl_verify",
    "OPENSSL_ALGO_SHA256",
    "RS256",
    "code_challenge_methods_supported",
    "S256",
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
]:
    if forbidden in bridge:
        raise SystemExit(f"forbidden OAuth bridge primitive: {forbidden}")

if "class-mad4b-scp-oauth-resource-bridge.php" not in main:
    raise SystemExit("main plugin does not load OAuth resource bridge")
if "MAD4B_SCP_OAuth_Resource_Bridge::boot()" not in plugin:
    raise SystemExit("plugin boot does not initialize OAuth resource bridge")

print('mad4b.site-control-plane.oauth-resource-bridge.v1: PASS')
