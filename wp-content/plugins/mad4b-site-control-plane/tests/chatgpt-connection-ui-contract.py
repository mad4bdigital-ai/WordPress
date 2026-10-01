#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
ui = (root / 'includes' / 'class-mad4b-scp-chatgpt-connection-admin-ui.php').read_text(encoding='utf-8')
consent_ui = (root / 'includes' / 'class-mad4b-scp-local-oauth-consent-ui.php').read_text(encoding='utf-8')
js = (root / 'assets' / 'chatgpt-connection.js').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
external_evidence = (root / 'includes' / 'class-mad4b-scp-external-handshake-evidence.php').read_text(encoding='utf-8')

required_ui = [
    'mad4b.chatgpt-connection-ui.v3',
    'Connect to ChatGPT',
    'https://chatgpt.com/oauth/client.json',
    'https://chatgpt.com/connector_platform_oauth_redirect',
    'https://chatgpt.com/plugins#settings/Connectors?create-connector=true',
    "'server_url' => $server_url",
    "'authentication' => 'OAuth'",
    "'client_registration' => 'client_id_metadata_document'",
    "'token_endpoint_auth_method' => 'none'",
    "array( 'mad4b:read', 'offline_access' )",
    "'gateway_server_id' => 'mad4b-chatgpt'",
    "MAD4B_SCP_MCP_Registration_Bridge::server_registration_identity_status( 'mad4b-chatgpt' )",
    "'gateway_expected' => $gateway_expected",
    "'gateway_registered' => $gateway_registered",
    "'gateway_registration_identity_ready' => $gateway_identity_ready",
    "'gateway_registration_state'",
    "'gateway_deep_registration_deferred'",
    "$ready = $environment_ready && ! empty( $local['effective'] ) && ! empty( $bridge['effective'] ) && $cimd_ready && $gateway_identity_ready;",
    "'generic_filesystem_exposed' => false",
    "'generic_database_exposed' => false",
    "'write_admin_breakglass_exposed' => false",
    "'creates_chatgpt_connector' => false",
    "'stores_chatgpt_credentials' => false",
    "'external_connection_certified' => false",
    "'external_connection_certification_deferred' => $external_evidence_present",
    "'external_connection_certification_state' => $external_certification_state",
    "'external_connection_evidence_present' => $external_evidence_present",
    "'external_connection_last_verified_at'",
    "'external_connection_evidence_projection' => 'persisted_identity'",
    "MAD4B_SCP_External_Handshake_Evidence::persisted_identity_status()",
    "'previously_verified_external_session'",
    "'certification_projection_state'",
    "previously verified · deep revalidation deferred",
    "Last verified external session",
    "'production_readonly_profile_supported' => true",
    "'production_readonly_write_enabled' => false",
    "'production_readonly_breakglass_enabled' => false",
    "'production_readonly_opt_in_required'",
    "'staging_only_initially' => false",
    'MAD4B_SCP_Staging_OAuth_Autoconfig::status()',
    'MAD4B_SCP_Portable_Readonly_Connection::status()',
    'MAD4B_SCP_Local_OAuth_Server::runtime_identity_status()',
    'MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()',
    "'admin_status_projection' => 'runtime_identity'",
    "'deep_oauth_status_deferred' => true",
    "'portable_readonly_ready' => (bool) $portable_ready",
    "'portable_profile_binding_state' => isset( $portable['profile_binding_state'] )",
    "'foreign_profile_quarantined' => ! empty( $portable['foreign_profile_quarantined'] )",
    "'requires_site_enrollment' => ! empty( $portable['requires_site_enrollment'] )",
    'A Site Profile from another tenant is quarantined.',
    'No write, Skills, Developer or Breakglass authority is inherited.',
    'Portable read-only auto-connect is active for this site.',
    'mad4b_enable_production_readonly_oauth',
    'mad4b_disable_production_readonly_oauth',
    'mad4b_production_readonly_oauth',
    'Enable Production read-only OAuth',
    'Disable Production read-only OAuth',
    'Portable mode is read-only.',
    "'production_write_profile_enabled' => (bool) $production_write_profile_enabled",
    "'production_write_authority_effective' => (bool) $production_write_authority_effective",
    "'production_governed_write_enabled' => (bool) $production_governed_write_enabled",
    "MAD4B_SCP_Staging_Write_Authority::effective()",
    "'production_write_policy' => 'production' === $environment ? 'exact_profile_plus_exact_one_time_approval' : 'not_applicable'",
    "'authority_step_up_available' => (bool) $step_up_available",
    'every Production write remains bound to exact grants and a one-time approval',
    'Production write is enabled in the Site Profile, but runtime write authority is not effective yet.',
    'Production governed OAuth and write authority are effective.',
    'Production write requested in Site Profile',
    'Production write authority',
    'deferred · identity ready',
    'CIMD / ChatGPT managed',
    'Open ChatGPT Plugin Builder',
    'Run OAuth Canary',
]
for marker in required_ui:
    if marker not in ui:
        raise SystemExit(f'missing ChatGPT connection UI marker: {marker}')

status_method = ui.split('public static function status()', 1)[1].split('public static function enqueue_assets()', 1)[0]
for forbidden_status_call in [
    'MAD4B_SCP_Local_OAuth_Server::status()',
    'MAD4B_SCP_OAuth_Resource_Bridge::status()',
    'MAD4B_SCP_External_Handshake_Evidence::status()',
]:
    if forbidden_status_call in status_method:
        raise SystemExit(f'deep OAuth status call leaked into ChatGPT admin render: {forbidden_status_call}')
if "private static $status_cache = null;" not in ui or "if ( is_array( self::$status_cache ) ) return self::$status_cache;" not in status_method:
    raise SystemExit('ChatGPT admin status must be request-local memoized')
if "$gateway_registered = class_exists( 'MAD4B_SCP_Servers' ) && in_array( 'mad4b-chatgpt', MAD4B_SCP_Servers::expected_server_ids(), true );" in status_method:
    raise SystemExit('ChatGPT admin must not equate expected gateway catalog membership with actual registration')
if "$production_governed_write_enabled = 'production' === $environment && $profile_oauth_ready && MAD4B_SCP_Site_Profile::write_enabled();" in status_method:
    raise SystemExit('ChatGPT admin must not equate Site Profile write preference with effective Production authority')
if "<tr><th>External ChatGPT connection certified</th><td>no</td></tr>" in ui:
    raise SystemExit('ChatGPT admin UI still hard-codes external certification to no')
for marker in (
    "'previously_verified_external_session' => false",
    "'certification_projection_state' => 'unverified'",
    "'previously_verified_external_session' => $previously_verified",
    "'certification_projection_state' => $previously_verified ? 'previously_verified_revalidation_deferred' : 'persisted_evidence_invalid'",
    "'live_verification_deferred' => true",
):
    if marker not in external_evidence:
        raise SystemExit(f'canonical persisted external evidence projection missing: {marker}')

for forbidden in [
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->',
    'client_secret', 'access_token', 'refresh_token', 'file_put_contents(',
    'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',
    "'client_registration' => 'user_defined_oauth_client'",
    'MAD4B_MCP_MUTATION_ENABLED', 'MAD4B_MCP_BREAKGLASS_ENABLED',
]:
    if forbidden in ui:
        raise SystemExit(f'forbidden ChatGPT connection UI primitive: {forbidden}')

required_consent_semantics = [
    'mad4b.local-oauth-consent-ui.v4',
    "add_action( 'admin_init', array( __CLASS__, 'start_buffer_for_connection_admin' ), -20 )",
    'This consent authenticates the client and grants only the read resource scope shown below. Write, Developer and Developer Breakglass are separate governed authorities and are not created by this OAuth approval.',
    "Read identity + ' . $environment_label . ' authority step-up",
    'mad4b:authority:step-up',
    'Approve governed access',
    'Write, Developer and Developer Breakglass are separate governed authorities',
    'OAuth identity · Step-up is request permission only · Write/Developer authorities separate · PKCE S256',
    'Connection and certification workspace.',
    'This page is inspection-only:',
    'Governed write capability, when available, is established separately by Write Authority, Write Runtime Certification and one-time approvals.',
    'This certification does not grant write authority; governed mutation availability is evaluated separately by Write Authority and Write Runtime Certification.',
    'What you are approving now',
    'Generic raw-SQL Breakglass',
    'Current governed authority',
    'Full Staging Authority (Staging only)',
    'Production writes still require exact Site Profile confirmation, database-bound Production OAuth opt-in, explicit runtime-gate confirmation, and one-time approval',
]
for marker in required_consent_semantics:
    if marker not in consent_ui:
        raise SystemExit(f'missing OAuth/write-authority semantic marker: {marker}')

for forbidden in [
    'Read-only access · OAuth 2.1 · PKCE S256',
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->',
    'MAD4B_MCP_MUTATION_ENABLED', 'MAD4B_MCP_BREAKGLASS_ENABLED',
    'grant_ability(', 'approve(', 'wp_remote_get(', 'wp_remote_post(',
]:
    if forbidden in consent_ui:
        raise SystemExit(f'forbidden consent/connection semantic primitive: {forbidden}')

for marker in ['mad4b-chatgpt-copy', 'navigator.clipboard.writeText', 'data-copy-target']:
    if marker not in (ui + js):
        raise SystemExit(f'missing ChatGPT copy-helper marker: {marker}')

if 'class-mad4b-scp-chatgpt-connection-admin-ui.php' not in main:
    raise SystemExit('main plugin does not load ChatGPT connection UI')
if 'class-mad4b-scp-local-oauth-consent-ui.php' not in main:
    raise SystemExit('main plugin does not load OAuth consent semantic UI')
if 'MAD4B_SCP_ChatGPT_Connection_Admin_UI::boot()' not in plugin:
    raise SystemExit('plugin boot does not initialize ChatGPT connection UI')
if 'MAD4B_SCP_Local_OAuth_Consent_UI::boot()' not in main:
    raise SystemExit('main plugin does not initialize OAuth consent semantic UI')

print('mad4b.site-control-plane.chatgpt-connection-ui.v4: PASS')
