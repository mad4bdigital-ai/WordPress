#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
php = (root / 'includes' / 'class-mad4b-scp-local-oauth-browser-canary.php').read_text(encoding='utf-8')
js = (root / 'assets' / 'local-oauth-canary.js').read_text(encoding='utf-8')
ps1 = (root / 'tools' / 'Invoke-MAD4BLocalOAuthStagingCanary.ps1').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

for marker in [
    'mad4b.local-oauth-browser-canary.v1',
    'mad4b-control-plane-oauth-canary',
    'mad4b-staging-browser-canary',
    'mad4b-staging-canary',
    'http://127.0.0.1:8765/callback',
    "'staging_only' => true",
    "'persists_pkce_material' => false",
    "'persists_bearer_tokens' => false",
    "'creates_credentials' => false",
    "'creates_clients' => false",
    "'changes_configuration' => false",
    "'external_connection_certified' => false",
    "current_user_can( 'manage_options' )",
    "MAD4B_MCP_LOCAL_OAUTH_CLIENTS",
    "MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS",
    "MAD4B_MCP_OAUTH_MODE', 'local'",
    "application_type' => 'web'",
    "application_type' => 'native'",
    'Canary configuration is intentionally unavailable outside Staging.',
    "if ( 'staging' !== $status['environment'] ) return;",
]:
    if marker not in php:
        raise SystemExit(f'missing browser canary PHP marker: {marker}')

for forbidden in [
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->', 'file_put_contents(',
    'wp_remote_post(', 'wp_remote_request(', 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED',
    'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED',
]:
    if forbidden in php:
        raise SystemExit(f'forbidden browser canary PHP primitive: {forbidden}')

for marker in [
    'window.sessionStorage',
    'window.crypto.getRandomValues',
    "window.crypto.subtle.digest('SHA-256'",
    "code_challenge_method', 'S256'",
    "grant_type', 'authorization_code'",
    "credentials: 'omit'",
    "anonymousProbe.status !== 401",
    "'Authorization': 'Bearer ' + accessToken",
    "delete tokenPayload.refresh_token",
    "delete tokenPayload.access_token",
    "window.history.replaceState",
    "window.sessionStorage.removeItem(storageKey)",
    "Anonymous ingress was denied with HTTP 401",
    "External-client certification is still required",
]:
    if marker not in js:
        raise SystemExit(f'missing browser canary JS marker: {marker}')

for forbidden in [
    'localStorage', 'document.cookie', 'console.log(accessToken)', 'console.log(tokenPayload)',
    'window.name', 'IndexedDB', 'indexedDB', "credentials: 'same-origin'",
]:
    if forbidden in js:
        raise SystemExit(f'forbidden browser canary JS persistence/auth primitive: {forbidden}')

for marker in [
    "[string]$ClientId = 'mad4b-staging-canary'",
    "[string]$RedirectUri = 'http://127.0.0.1:8765/callback'",
    'PKCE RFC 7636 self-test failed',
    'The access token was intentionally not printed or persisted.',
]:
    if marker not in ps1:
        raise SystemExit(f'missing bundled PowerShell canary marker: {marker}')
for forbidden in ['Write-Host $accessToken', 'Write-Output $accessToken', 'Set-Content', 'Out-File']:
    if forbidden in ps1:
        raise SystemExit(f'forbidden PowerShell token persistence/output primitive: {forbidden}')

if 'class-mad4b-scp-local-oauth-browser-canary.php' not in main:
    raise SystemExit('main plugin does not load browser canary class')
if 'MAD4B_SCP_Local_OAuth_Browser_Canary::boot()' not in plugin:
    raise SystemExit('plugin boot does not initialize browser canary')

print('mad4b.site-control-plane.local-oauth-browser-canary.v3: PASS')
