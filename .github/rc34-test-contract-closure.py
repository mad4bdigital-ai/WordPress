#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('.')
BASE = 'wp-content/plugins/mad4b-site-control-plane'


def read(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, text):
    (ROOT / path).write_text(text, encoding='utf-8')


def replace_once(path, old, new):
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected exactly one match, found {count}: {old!r}')
    write(path, text.replace(old, new, 1))


# 1) External snapshot finalizer standalone fixture: model Site Profile v2 eligibility.
external = f'{BASE}/tests/external-snapshot-finalizer-runtime.php'
marker = "class MAD4B_SCP_Skill_Snapshot_Identity {"
fixture = """class MAD4B_SCP_Site_Profile {
\tpublic static function site_urls_match_enrollment() { return 'staging' === $GLOBALS['mad4b_environment']; }
\tpublic static function skills_enabled() { return 'staging' === $GLOBALS['mad4b_environment']; }
}

"""
text = read(external)
if text.count(marker) != 1 or 'class MAD4B_SCP_Site_Profile {' in text:
    raise SystemExit(f'{external}: unexpected Site Profile fixture shape')
write(external, text.replace(marker, fixture + marker, 1))

# 2) Deployment handoff contract: v2 is Site-Profile-driven and tenant-neutral.
handoff_test = f'{BASE}/tests/staging-write-authority-contract.py'
text = read(handoff_test)
start_marker = "if deployment.get('contract') != 'mad4b.wordpress-staging-deployment-handoff.v1':"
end_marker = "\nprint('mad4b.staging-write-authority.tenant-profile.v9: PASS')"
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit(f'{handoff_test}: legacy handoff assertion block not found')
new_block = r'''if deployment.get('contract') != 'mad4b.wordpress-deployment-handoff.v2':
    raise SystemExit('deployment handoff v2 contract id mismatch')
producer = deployment.get('producer', {})
expected_producer = {
    'repository': 'mad4bdigital-ai/WordPress',
    'workflow': '.github/workflows/mad4b-control-plane-package.yml',
    'artifact_name_template': 'mad4b-site-control-plane-kit-{exact_head_sha}',
    'install_manifest': 'install-manifest.json',
    'source_binding': 'exact_head_sha',
}
if producer != expected_producer:
    raise SystemExit(f'deployment producer contract drift: {producer!r}')

target = deployment.get('target', {})
if target.get('binding') != 'explicit_site_profile':
    raise SystemExit('deployment target must bind through explicit Site Profile authority')
if target.get('environment_source') != 'site_profile.environment' or target.get('origin_source') != 'site_profile.canonical_origin' or target.get('site_uuid_source') != 'site_profile.site_uuid':
    raise SystemExit('deployment target identity must derive from Site Profile v2')
if set(target.get('supported_environments', [])) != {'local', 'development', 'staging', 'production'}:
    raise SystemExit('deployment handoff supported environment set drift')
if any(key in target for key in ('environment', 'origin', 'host')):
    raise SystemExit('generic deployment handoff must not embed a tenant environment/origin/host')
if target.get('plugin_slug') != 'mad4b-site-control-plane':
    raise SystemExit('deployment handoff plugin slug mismatch')
if target.get('mcp_adapter') != {
    'slug': 'mcp-adapter',
    'required_version': '0.6.1',
    'deployment_mode': 'require_exact_preinstalled_or_verified_bundled',
}:
    raise SystemExit('deployment handoff MCP Adapter dependency contract drift')

executor = deployment.get('executor', {})
expected_executor = {
    'authority': 'deployment_connector_selected_by_site_owner',
    'operation': 'wordpress_plugin_deploy',
    'dry_run_default': True,
    'human_approval_required_for_apply': True,
    'exact_capability_envelope_required': True,
    'exact_target_allowlist_required': True,
    'caller_supplied_credentials_allowed': False,
}
if executor != expected_executor:
    raise SystemExit(f'deployment executor contract drift: {executor!r}')

preflight = deployment.get('preflight', {})
if preflight.get('must_precede_first_write') is not True:
    raise SystemExit('deployment live preflight must precede first write')
expected_preflight = {
    'target_site_profile_is_configured',
    'live_environment_matches_site_profile',
    'live_home_url_matches_site_profile_canonical_origin',
    'live_site_url_matches_site_profile_canonical_origin',
    'artifact_run_completed_successfully',
    'artifact_exact_head_sha_matches_request',
    'install_manifest_contract_is_valid',
    'install_manifest_commit_matches_exact_head_sha',
    'control_plane_archive_sha256_matches_manifest',
    'mcp_adapter_dependency_is_exact_or_verified',
}
if set(preflight.get('required', [])) != expected_preflight:
    raise SystemExit(f'deployment preflight contract drift: {preflight.get("required", [])!r}')

apply_contract = deployment.get('apply', {})
if apply_contract.get('source') != 'verified_control_plane_archive_from_exact_artifact':
    raise SystemExit('deployment apply source must be exact-artifact verified archive')
for key in (
    'backup_before_replace', 'atomic_replace_required', 'activate_after_replace',
    'same_cycle_readback_required', 'rollback_on_failed_readback',
    'rollback_restores_previous_plugin_files', 'production_requires_separate_explicit_authority',
):
    if apply_contract.get(key) is not True:
        raise SystemExit(f'deployment apply safety must remain enabled: {key}')

post_deploy = deployment.get('post_deploy', {})
expected_acceptance = {
    'exact_control_plane_version_and_build_readback',
    'site_profile_identity_exact',
    'enabled_feature_reconciliation',
    'provider_blocked_write_tools_safely_unmounted',
    'external_tool_inventory_match_when_mcp_enabled',
    'one_time_approval_replay_denial_and_undo_when_write_enabled',
}
if set(post_deploy.get('required_live_acceptance', [])) != expected_acceptance:
    raise SystemExit('deployment post-deploy acceptance contract drift')

forbidden_contract = deployment.get('forbidden', {})
expected_forbidden = {
    'unenrolled_target', 'implicit_production_write', 'breakglass',
    'raw_sql_side_channel', 'caller_supplied_credentials',
    'merge_or_ready_before_required_acceptance',
}
if set(forbidden_contract) != expected_forbidden or not all(forbidden_contract.get(key) is True for key in expected_forbidden):
    raise SystemExit(f'deployment forbidden-path contract drift: {forbidden_contract!r}')
if deployment.get('secrets_included') is not False:
    raise SystemExit('deployment handoff must never contain secrets')
'''
write(handoff_test, text[:start] + new_block + text[end:])

# 3) MCP runtime conflict/refresh static contract: remove tenant-host assumptions.
conflict = f'{BASE}/tests/mcp-runtime-conflict-guard-contract.py'
replace_once(conflict, "    \"const STAGING_HOST = 'staging.egypttourgates.com'\",\n", '')
replace_once(
    conflict,
    "    \"'staging' === $environment && self::STAGING_HOST === $host\",\n",
    "    \"MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' )\",\n    \"MAD4B_SCP_Site_Profile::origin_enrolled()\",\n    \"MAD4B_SCP_Site_Profile::managed_runtime_enabled()\",\n",
)
replace_once(
    conflict,
    "    \"'staging' === $mad4b_mcp_mu_status['environment']\",\n    \"'staging.egypttourgates.com' === $mad4b_mcp_mu_status['host']\",\n",
    "    \"get_option( 'mad4b_scp_site_profile_v2', array() )\",\n    \"'mad4b.site-profile.v2' === (string) $mad4b_mcp_mu_profile['contract']\",\n    \"! empty( $mad4b_mcp_mu_features['managed_runtime'] )\",\n    \"in_array( $mad4b_mcp_mu_status['environment'], array( 'local', 'development', 'staging' ), true )\",\n",
)
replace_once(
    conflict,
    "    \"const STAGING_HOST = 'staging.egypttourgates.com'\",\n    'mad4b.mcp-adapter-mu-bootstrap.v2',",
    "    \"MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' )\",\n    \"MAD4B_SCP_Site_Profile::origin_enrolled()\",\n    \"MAD4B_SCP_Site_Profile::managed_runtime_enabled()\",\n    'mad4b.mcp-adapter-mu-bootstrap.v2',",
)
text = read(conflict)
if 'staging.egypttourgates.com' in text or 'const STAGING_HOST' in text:
    raise SystemExit(f'{conflict}: stale tenant-host assertion remains')
write(conflict, text)

# 4) Pre-init lifecycle: a write-disabled enrolled profile must hide stale persisted
# certification behind live fail-closed ineligible truth and must not mutate it.
preinit = f'{BASE}/tests/runtime-pre-init-abilities-lifecycle.php'
old_readback = """$legacy_readback = MAD4B_SCP_Write_Runtime_Certification::status();
if ( ! is_array( $legacy_readback ) || empty( $legacy_readback['stale'] ) || ! isset( $legacy_readback['state'] ) || 'stale' !== $legacy_readback['state'] || ! empty( $legacy_readback['ready'] ) ) {
\tfwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: stale persisted certification was exposed as current: ' . json_encode( $legacy_readback, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
\texit( 1 );
}
"""
new_readback = """$legacy_readback = MAD4B_SCP_Write_Runtime_Certification::status();
$legacy_blockers = isset( $legacy_readback['blockers'] ) && is_array( $legacy_readback['blockers'] ) ? $legacy_readback['blockers'] : array();
if ( ! is_array( $legacy_readback ) || ! empty( $legacy_readback['ready'] ) || 'ineligible' !== ( isset( $legacy_readback['state'] ) ? $legacy_readback['state'] : '' ) || 'not_applicable' !== ( isset( $legacy_readback['persistence'] ) ? $legacy_readback['persistence'] : '' ) || ! in_array( 'site_profile_write_disabled', $legacy_blockers, true ) ) {
\tfwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: stale persisted certification was not hidden by fail-closed ineligible truth: ' . json_encode( $legacy_readback, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
\texit( 1 );
}
"""
replace_once(preinit, old_readback, new_readback)
old_observe = """// The explicit observer may persist current evidence; freshness metadata must
// then make the normal status() readback current again rather than stale.
MAD4B_SCP_Write_Runtime_Certification::observe();
$fresh_readback = MAD4B_SCP_Write_Runtime_Certification::status();
if ( ! is_array( $fresh_readback ) || ! empty( $fresh_readback['stale'] ) || ! isset( $fresh_readback['write_tool_count'] ) || (int) $fresh_readback['write_tool_count'] !== $live_write_count ) {
\tfwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: persisted certification did not refresh to current inventory: ' . json_encode( $fresh_readback, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
\texit( 1 );
}
"""
new_observe = """// A write-disabled Site Profile is intentionally ineligible. Observing certification
// must stay non-mutating and continue to hide any historical ready certificate.
$storage_before_observe = get_option( MAD4B_SCP_Write_Runtime_Certification::OPTION, array() );
MAD4B_SCP_Write_Runtime_Certification::observe();
$ineligible_after_observe = MAD4B_SCP_Write_Runtime_Certification::status();
$storage_after_observe = get_option( MAD4B_SCP_Write_Runtime_Certification::OPTION, array() );
$after_blockers = isset( $ineligible_after_observe['blockers'] ) && is_array( $ineligible_after_observe['blockers'] ) ? $ineligible_after_observe['blockers'] : array();
if ( ! is_array( $ineligible_after_observe ) || ! empty( $ineligible_after_observe['ready'] ) || 'ineligible' !== ( isset( $ineligible_after_observe['state'] ) ? $ineligible_after_observe['state'] : '' ) || 'not_applicable' !== ( isset( $ineligible_after_observe['persistence'] ) ? $ineligible_after_observe['persistence'] : '' ) || ! in_array( 'site_profile_write_disabled', $after_blockers, true ) ) {
\tfwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: write-disabled observer escaped fail-closed state: ' . json_encode( $ineligible_after_observe, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
\texit( 1 );
}
if ( wp_json_encode( $storage_before_observe ) !== wp_json_encode( $storage_after_observe ) ) {
\tfwrite( STDERR, "FAIL pre-init-abilities-lifecycle: ineligible observer mutated persisted certification state\n" );
\texit( 1 );
}
"""
replace_once(preinit, old_observe, new_observe)

print('RC34 test contract closure applied')
