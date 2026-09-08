#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
auto = (ROOT / 'includes' / 'class-mad4b-scp-staging-oauth-autoconfig.php').read_text(encoding='utf-8')
plugin = (ROOT / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
loader = (ROOT / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')

required = {
    'staging-only gate': "'staging' !== $environment",
    'production never auto enabled': "'production_auto_enable' => false",
    'explicit disable respected': "explicit_local_oauth_disabled",
    'explicit non-local mode respected': "explicit_non_local_oauth_mode",
    'external issuer respected': "explicit_external_oauth_issuer",
    'current admin selection': "current_staging_admin",
    'single admin fallback': "single_staging_admin",
    'ambiguous admin fails closed': "staging_admin_subject_ambiguous",
    'local oauth enabled automatically': "define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true )",
    'local authority mode automatically': "define( 'MAD4B_MCP_OAUTH_MODE', 'local' )",
    'wp subject automatically': "define( 'MAD4B_MCP_OAUTH_WP_USER_ID', $user_id )",
    'subject list automatically': "define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', array( $subject ) )",
    'issuer-bound subjects automatically': "MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS",
    'no credential persistence': "'stores_credentials' => false",
    'no private key persistence': "'stores_private_key' => false",
}

missing = [name for name, marker in required.items() if marker not in auto]
if missing:
    raise SystemExit('staging oauth autoconfig contract missing: ' + ', '.join(missing))

loader_marker = "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-oauth-autoconfig.php';"
if loader_marker not in loader:
    raise SystemExit('staging oauth autoconfig class is not loaded by the plugin')

boot_marker = 'MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap();'
key_marker = 'MAD4B_SCP_Local_OAuth_Key_Path_Policy::boot();'
if boot_marker not in plugin:
    raise SystemExit('staging oauth autoconfig is not invoked')
if plugin.find(boot_marker, plugin.find('public static function boot()')) > plugin.find(key_marker, plugin.find('public static function boot()')):
    raise SystemExit('staging oauth autoconfig must run before local OAuth key-path policy')

print('staging oauth zero-touch autoconfig contract ok')
