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

bridge = read('includes/class-mad4b-scp-staging-oauth-bridge.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')
workflow = (ROOT.parents[2] / '.github/workflows/mad4b-connection-governance.yml').read_text('utf-8')

for marker in (
    "const CONTRACT = 'mad4b.wordpress-staging-mcp-resource-server.v1'",
    "const RESOURCE = 'https://staging.egypttourgates.com/wp-json/mcp/mad4b-read'",
    "const ISSUER = 'https://dev.mad4b.com/auth/mcp/wordpress-staging'",
    "const JWKS_URI = 'https://dev.mad4b.com/auth/mcp/wordpress-staging/oauth/jwks'",
    "const METADATA_URL = 'https://staging.egypttourgates.com/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-read'",
    "const TOKEN_PURPOSE = 'wordpress_staging_mcp_access'",
    "const CLIENT_ID_PREFIX = 'mcp_stg_wp_'",
    "const CLIENT_PROFILE_PREFIX = 'wordpress_staging_mcp:'",
    "const REQUIRED_SCOPE = 'mad4b:read'",
    "const OFFLINE_SCOPE = 'offline_access'",
    "const USER_META_KEY = 'mad4b_scp_oauth_subject_fingerprint'",
    "true !== constant( self::ENABLE_FLAG )",
    "'staging' !== wp_get_environment_type()",
    "self::normalize_origin( home_url( '/' ) ) === 'https://staging.egypttourgates.com'",
):
    require(bridge, marker, 'exact-staging-boundary')

for marker in (
    "'authorization_servers' => array( self::ISSUER )",
    "'bearer_methods_supported' => array( 'header' )",
    "'scopes_supported' => array( self::REQUIRED_SCOPE )",
    "Bearer resource_metadata=\"",
    "self::METADATA_PATH !== self::request_path()",
):
    require(bridge, marker, 'rfc9728-resource-metadata')

for marker in (
    "'RS256' !== $header['alg']",
    "isset( $header['jku'] )",
    "isset( $header['x5u'] )",
    "isset( $header['x5c'] )",
    "isset( $header['crit'] )",
    "openssl_verify(",
    "OPENSSL_ALGO_SHA256",
    "self::ISSUER !== $claims['iss']",
    "self::RESOURCE !== $claims['aud']",
    "self::RESOURCE !== $claims['resource']",
    "self::TOKEN_PURPOSE !== $claims['purpose']",
    "0 !== strpos( $claims['client_id'], self::CLIENT_ID_PREFIX )",
    "0 !== strpos( $claims['client_profile_key'], self::CLIENT_PROFILE_PREFIX )",
    "$claims['client_id'] !== $claims['azp']",
    "$claims['sub'] !== $canonical_sub",
    "array_diff( $scopes, $allowed_scopes )",
):
    require(bridge, marker, 'jwt-fail-closed-verification')

require(bridge, "wp_safe_remote_get( self::JWKS_URI", 'fixed-jwks-fetch')
require(bridge, "'redirection' => 0", 'no-jwks-redirect')
require(bridge, "'sslverify' => true", 'jwks-tls-verification')
for forbidden in (
    "wp_safe_remote_get( $header",
    "wp_safe_remote_get( $claims",
    "$_GET['access_token']",
    "$_POST['access_token']",
    "access_token=",
    "client_secret",
    "private_key",
):
    forbid(bridge, forbidden, 'no-caller-key-url-or-secret-channel')

for marker in (
    "hash( 'sha256', self::SUBJECT_TYPE . \"\\0\" . (string) $subject )",
    "'meta_key' => self::USER_META_KEY",
    "1 !== count( $ids )",
    "user_can( $user_id, 'manage_options' )",
    "'subject_fingerprint' => self::$subject_fingerprint",
    "'auth_method' => 'oauth_rs256_jwks'",
    "'origin' => 'mcp'",
):
    require(bridge, marker, 'explicit-local-subject-binding')

require(bootstrap, 'class-mad4b-scp-staging-oauth-bridge.php', 'bridge-bootstrap')
require(plugin, 'MAD4B_SCP_Staging_OAuth_Bridge::boot();', 'bridge-boot')
require(workflow, 'wordpress-staging-oauth-resource-server-contract.py', 'bridge-static-ci')
require(workflow, 'runtime-wordpress-staging-oauth-resource-server-smoke.php', 'bridge-runtime-ci')

print('mad4b.wordpress-staging-mcp-resource-server-contract.v1: PASS')
