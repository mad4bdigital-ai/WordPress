#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
auto = (ROOT / 'includes' / 'class-mad4b-scp-staging-oauth-autoconfig.php').read_text(encoding='utf-8')
plugin = (ROOT / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
loader = (ROOT / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')

required = {
    'contract v3': "mad4b.oauth-autoconfig.v3",
    'site profile configured gate': "MAD4B_SCP_Site_Profile::configured()",
    'exact enrolled origin gate': "MAD4B_SCP_Site_Profile::origin_enrolled()",
    'profile oauth feature gate': "MAD4B_SCP_Site_Profile::oauth_enabled()",
    'profile users': "MAD4B_SCP_Site_Profile::oauth_user_ids()",
    'profile site uuid': "MAD4B_SCP_Site_Profile::site_uuid()",
    'profile revision': "MAD4B_SCP_Site_Profile::revision()",
    'profile digest': "MAD4B_SCP_Site_Profile::profile_digest()",
    'supported environments': "array( 'staging', 'production', 'development', 'local' )",
    'production opt-in option': "const PRODUCTION_OPTION = 'mad4b_scp_production_readonly_oauth_v1'",
    'production never auto enabled': "'production_auto_enable' => false",
    'production read-only support': "'production_readonly_supported' => true",
    'production opt-in required': "production_readonly_opt_in_required",
    'production profile stale denial': "production_readonly_profile_stale",
    'production source': "site_profile_production_readonly_opt_in",
    'production admin enable action': "admin_post_mad4b_enable_production_readonly_oauth",
    'production admin disable action': "admin_post_mad4b_disable_production_readonly_oauth",
    'production nonce': "mad4b_production_readonly_oauth",
    'production exact-origin guard': "Production read-only OAuth can only be changed on the exact enrolled Production origin.",
    'profile admin owner required': "site_profile_admin_owner_required",
    'primary owner selector': "primary_owner_user_id",
    'explicit disable respected': "explicit_local_oauth_disabled",
    'explicit resource bridge disable respected': "explicit_resource_oauth_disabled",
    'resource oauth enabled': "define( 'MAD4B_MCP_OAUTH_ENABLED', true )",
    'explicit local production disable respected': "explicit_local_oauth_production_disabled",
    'explicit resource production disable respected': "explicit_resource_oauth_production_disabled",
    'explicit non-local mode respected': "explicit_non_local_oauth_mode",
    'external issuer respected': "explicit_external_oauth_issuer",
    'explicit subject conflict': "explicit_subject_policy_conflict",
    'issuer binding conflict': "explicit_subject_binding_conflict",
    'local oauth enabled': "define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true )",
    'local production approval': "define( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED', true )",
    'resource production approval': "define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true )",
    'local authority mode': "define( 'MAD4B_MCP_OAUTH_MODE', 'local' )",
    'wp trust owner': "define( 'MAD4B_MCP_OAUTH_WP_USER_ID', $primary_user_id )",
    'subject list': "define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', $subjects )",
    'issuer-bound subjects': "MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS",
    'no credential persistence': "'stores_credentials' => false",
    'no private key persistence': "'stores_private_key' => false",
    'production write authority remains off': "'write_authority_enabled' => false",
    'production breakglass remains off': "'breakglass_enabled' => false",
    'semantic persistence comparison': "if ( $existing_semantic !== $record )",
    'timestamp excluded from semantic identity': "unset( $existing_semantic['updated_at'] )",
}

missing = [name for name, marker in required.items() if marker not in auto]
if missing:
    raise SystemExit('tenant oauth autoconfig contract missing: ' + ', '.join(missing))

# Host identity comes from the Site Profile. The OAuth autoconfig runtime must not
# restore the old Egypt Tour Gates production/staging constants or implicit-admin
# discovery path.
for forbidden in [
    "const PRODUCTION_HOST = 'egypttourgates.com'",
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    'current_staging_admin',
    'single_staging_admin',
    'staging_admin_subject_ambiguous',
    'MAD4B_MCP_MUTATION_ENABLED',
    'MAD4B_MCP_BREAKGLASS_ENABLED',
    'MAD4B_SKILLS_EDITOR_ENABLED',
    'MAD4B_OPENAI_PLUGIN_APP_ID',
    'client_secret',
    'access_token',
    'refresh_token',
]:
    if forbidden in auto:
        raise SystemExit('tenant oauth autoconfig contains forbidden legacy authority/secret primitive: ' + forbidden)

loader_marker = "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-oauth-autoconfig.php';"
if loader_marker not in loader:
    raise SystemExit('oauth autoconfig class is not loaded by the plugin')

boot_marker = 'MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap();'
key_marker = 'MAD4B_SCP_Local_OAuth_Key_Path_Policy::boot();'
if boot_marker not in plugin:
    raise SystemExit('oauth autoconfig is not invoked')
if plugin.find(boot_marker, plugin.find('public static function boot()')) > plugin.find(key_marker, plugin.find('public static function boot()')):
    raise SystemExit('oauth autoconfig must run before local OAuth key-path policy')

print('tenant-profile local oauth + explicit production read-only contract v3: PASS')

# rc.46 local HTTP issuer must be loopback-bounded.
for marker in ["127.0.0.1", "::1", "localhost", "$local_loopback"]:
    if marker not in auto:
        raise SystemExit(f'missing local loopback OAuth autoconfig boundary: {marker}')
