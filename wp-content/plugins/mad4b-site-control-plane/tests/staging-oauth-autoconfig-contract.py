#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
auto = (ROOT / 'includes' / 'class-mad4b-scp-staging-oauth-autoconfig.php').read_text(encoding='utf-8')
plugin = (ROOT / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
loader = (ROOT / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')

required = {
    'contract v2': "mad4b.staging-oauth-autoconfig.v2",
    'staging-only fallback gate': "'staging' !== $environment",
    'production exact host': "const PRODUCTION_HOST = 'egypttourgates.com'",
    'production opt-in option': "const PRODUCTION_OPTION = 'mad4b_scp_production_readonly_oauth_v1'",
    'production never auto enabled': "'production_auto_enable' => false",
    'production read-only support': "'production_readonly_supported' => true",
    'production opt-in required': "production_readonly_opt_in_required",
    'production source': "production_readonly_opt_in",
    'production admin enable action': "admin_post_mad4b_enable_production_readonly_oauth",
    'production admin disable action': "admin_post_mad4b_disable_production_readonly_oauth",
    'production nonce': "mad4b_production_readonly_oauth",
    'production exact-origin guard': "origin_not_governed_production",
    'explicit disable respected': "explicit_local_oauth_disabled",
    'explicit local production disable respected': "explicit_local_oauth_production_disabled",
    'explicit resource production disable respected': "explicit_resource_oauth_production_disabled",
    'explicit non-local mode respected': "explicit_non_local_oauth_mode",
    'external issuer respected': "explicit_external_oauth_issuer",
    'current admin selection': "current_staging_admin",
    'single admin fallback': "single_staging_admin",
    'ambiguous admin fails closed': "staging_admin_subject_ambiguous",
    'local oauth enabled': "define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true )",
    'local production approval': "define( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED', true )",
    'resource production approval': "define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true )",
    'local authority mode': "define( 'MAD4B_MCP_OAUTH_MODE', 'local' )",
    'wp subject': "define( 'MAD4B_MCP_OAUTH_WP_USER_ID', $user_id )",
    'subject list': "define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', array( $subject ) )",
    'issuer-bound subjects': "MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS",
    'no credential persistence': "'stores_credentials' => false",
    'no private key persistence': "'stores_private_key' => false",
    'production write authority remains off': "'write_authority_enabled' => false",
    'production breakglass remains off': "'breakglass_enabled' => false",
}

missing = [name for name, marker in required.items() if marker not in auto]
if missing:
    raise SystemExit('oauth autoconfig contract missing: ' + ', '.join(missing))

for forbidden in [
    'MAD4B_MCP_MUTATION_ENABLED',
    'MAD4B_MCP_BREAKGLASS_ENABLED',
    'MAD4B_SKILLS_EDITOR_ENABLED',
    'MAD4B_OPENAI_PLUGIN_APP_ID',
    'client_secret',
    'access_token',
    'refresh_token',
]:
    if forbidden in auto:
        raise SystemExit('production read-only oauth autoconfig contains forbidden authority/secret primitive: ' + forbidden)

loader_marker = "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-oauth-autoconfig.php';"
if loader_marker not in loader:
    raise SystemExit('oauth autoconfig class is not loaded by the plugin')

boot_marker = 'MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap();'
key_marker = 'MAD4B_SCP_Local_OAuth_Key_Path_Policy::boot();'
if boot_marker not in plugin:
    raise SystemExit('oauth autoconfig is not invoked')
if plugin.find(boot_marker, plugin.find('public static function boot()')) > plugin.find(key_marker, plugin.find('public static function boot()')):
    raise SystemExit('oauth autoconfig must run before local OAuth key-path policy')

print('staging zero-touch + production explicit read-only oauth autoconfig contract ok')
