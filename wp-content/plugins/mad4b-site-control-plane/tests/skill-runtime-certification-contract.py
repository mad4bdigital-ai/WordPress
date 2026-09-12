#!/usr/bin/env python3
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'

autoconfig = (wp / 'includes' / 'class-mad4b-scp-skill-autoconfig.php').read_text(encoding='utf-8')
identity = (wp / 'includes' / 'class-mad4b-scp-skill-snapshot-identity.php').read_text(encoding='utf-8')
exporter = (wp / 'includes' / 'class-mad4b-scp-skill-exporter.php').read_text(encoding='utf-8')
cert = (wp / 'includes' / 'class-mad4b-scp-skill-runtime-certification.php').read_text(encoding='utf-8')
abilities = (wp / 'includes' / 'class-mad4b-scp-skill-abilities.php').read_text(encoding='utf-8')
adapter = (wp / 'includes' / 'adapters' / 'class-mad4b-scp-skills-adapter.php').read_text(encoding='utf-8')
provider_discovery = (wp / 'includes' / 'class-mad4b-scp-skill-provider-discovery.php').read_text(encoding='utf-8')
plugin = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
live_truth = (wp / 'includes' / 'class-mad4b-scp-live-truth.php').read_text(encoding='utf-8')
write_authority = (wp / 'includes' / 'class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
write_cert = (wp / 'includes' / 'class-mad4b-scp-write-runtime-certification.php').read_text(encoding='utf-8')

for marker in [
    "const STAGING_OPENAI_APP_ID = 'plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2'",
    "define( 'MAD4B_OPENAI_PLUGIN_APP_ID', self::STAGING_OPENAI_APP_ID )",
    "'app_mapping_source' => 'none'",
    "'app_mapping_matches_staging' => false",
    "explicit_app_mapping_invalid",
    "staging_app_id",
]:
    if marker not in autoconfig:
        raise SystemExit(f'missing zero-touch Staging App mapping guard: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.skill-snapshot-identity.v1'",
    "MAD4B_SCP_Skill_Registry::list_skills( array( 'enabled' => true ) )",
    "MAD4B_SCP_Skill_Registry::openai_app_id()",
    "public static function from_entries( array $entries, $app_id = '' )",
    "return self::from_entries( $entries, MAD4B_SCP_Skill_Registry::openai_app_id() )",
    "entry_collision",
    "resource_collision",
    "snapshot_digest",
    "identity_token",
    "sha256:",
    "resources",
    "logical_id",
    "usort",
]:
    if marker not in identity:
        raise SystemExit(f'missing deterministic snapshot identity invariant: {marker}')

for marker in [
    "MAD4B_SCP_Skill_Snapshot_Identity::build()",
    "MAD4B_SCP_Skill_Snapshot_Identity::from_entries( $observed_entries, $app_id )",
    "MAD4B-SNAPSHOT-ID.txt",
    "snapshot_identity_contract",
    "snapshot_digest",
    "identity_token",
    "$skill_sha = hash( 'sha256', $content )",
    "$resource_content = isset( $data['content'] ) ? (string) $data['content'] : ''",
    "'sha256' => hash( 'sha256', $resource_content )",
    "mad4b_skill_export_observed_identity_mismatch",
    "mad4b_skill_snapshot_changed_during_export",
    "hash_equals( (string) $identity['identity_token'], (string) $export_identity['identity_token'] )",
    "hash_equals( (string) $identity['identity_token'], (string) $identity_after['identity_token'] )",
]:
    if marker not in exporter:
        raise SystemExit(f'portable exporter is not actual-byte snapshot/race bound: {marker}')

identity_pos = exporter.find("MAD4B_SCP_Skill_Snapshot_Identity::build()")
list_pos = exporter.find("MAD4B_SCP_Skill_Registry::list_skills( array( 'enabled' => true ) )")
if identity_pos < 0 or list_pos < 0 or identity_pos > list_pos:
    raise SystemExit('exporter must establish the initial identity before taking its enabled-Skill work-list')

for marker in [
    "const CONTRACT = 'mad4b.skill-runtime-certification.v1'",
    "wp_abilities_api_init",
    "mcp_adapter_init",
    "admin_init",
    "mad4b/skill-runtime-certification",
    "public static function current_status()",
    "public static function persisted_status()",
    "return self::current_status();",
    "'persistence'] = 'read_only_live_inspection'",
    "staging_app_mapping_mismatch",
    "seed_pack_not_ready",
    "provider_reconciliation_not_ready",
    "base_skills_missing_or_disabled",
    "skills_read_abilities_incomplete",
    "skill_write_ability_leak",
    "snapshot_identity_unavailable",
    "snapshot_identity_count_mismatch",
    "snapshot_identity_token",
    "external_client_snapshot_verified",
    "local_runtime_only",
    "MAD4B_SCP_Audit::record",
    "evidence_digest",
]:
    if marker not in cert:
        raise SystemExit(f'missing runtime certification invariant: {marker}')

status_section = cert[cert.index('public static function status()'):cert.index('public static function persisted_status()')]
if 'update_option(' in status_section or 'MAD4B_SCP_Audit::record' in status_section:
    raise SystemExit('Skill current status must remain read-only and must not persist or audit')

# Provider reconciliation must see the deterministic adapter registry before it
# derives desired_enabled. This guards the live ETG DFSB failure shape where an
# early empty registry persisted adapter_required/desired_enabled=false even
# though the same request later exposed a healthy registered adapter.
adapter_prepare = plugin.find("$adapter_registry = MAD4B_SCP_Adapter_Registry::instance();")
adapter_register = plugin.find("$adapter_registry->register_defaults();", adapter_prepare)
provider_reconcile = plugin.find("MAD4B_SCP_Skill_Provider_Discovery::bootstrap();")
if adapter_prepare < 0 or adapter_register < 0 or provider_reconcile < 0:
    raise SystemExit('provider reconciliation is missing deterministic adapter preparation')
if not (adapter_prepare < adapter_register < provider_reconcile):
    raise SystemExit('adapter defaults must be registered before provider Skill reconciliation')

for marker in [
    "in_array( $owner, array( self::CONTRACT, 'mad4b.skill-seeder.v1' ), true )",
    "$current_sha = hash( 'sha256', $skill_raw )",
    "$recorded_sha = isset( $meta['sha256'] )",
    "hash_equals( $recorded_sha, $current_sha )",
    "'drifted_managed' => true",
    "$current === (bool) $desired_enabled",
    "$meta['enabled'] = (bool) $desired_enabled",
    "'provider_active_adapter_ready'",
]:
    if marker not in provider_discovery:
        raise SystemExit(f'missing managed/digest-safe provider reconciliation invariant: {marker}')

required_read = [
    'mad4b/skills-list',
    'mad4b/skill-get',
    'mad4b/skills-export-status',
    'mad4b/skills-runtime-certification',
]
for name in required_read:
    if name not in abilities or name not in adapter:
        raise SystemExit(f'missing read-only Skill ability projection: {name}')

if 'MAD4B_SCP_Skill_Snapshot_Identity::build()' not in abilities:
    raise SystemExit('skills-export-status must expose deterministic snapshot identity')

for forbidden in [
    'mad4b/skill-create',
    'mad4b/skill-update',
    'mad4b/skill-delete',
    'mad4b/skill-write',
]:
    if forbidden in abilities or forbidden in adapter:
        raise SystemExit(f'write Skill ability leaked into runtime projection: {forbidden}')

for marker in [
    'MAD4B_SCP_Skill_Runtime_Certification::boot()',
    'MAD4B_SCP_Skill_Autoconfig::bootstrap()',
]:
    if marker not in plugin:
        raise SystemExit(f'missing runtime certification/autoconfig boot wiring: {marker}')

for marker in [
    'class-mad4b-scp-skill-snapshot-identity.php',
    'class-mad4b-scp-skill-runtime-certification.php',
    'class-mad4b-scp-live-truth.php',
]:
    if marker not in main:
        raise SystemExit(f'main plugin does not load {marker}')

for marker in [
    "add_action( 'admin_init', static function () {",
    "current_user_can( 'manage_options' )",
    "'mad4b-control-plane-skills' !== $page",
    "MAD4B_SCP_Skill_Runtime_Certification::observe();",
    '}, 110 );',
]:
    if marker not in main:
        raise SystemExit(f'missing page-scoped Skill certification refresh: {marker}')

if "add_action( 'admin_init', array( __CLASS__, 'observe' )" in cert:
    raise SystemExit('global admin runtime-certification observer must remain disabled')

if "'content' => array()" not in adapter or "'admin' => array()" not in adapter:
    raise SystemExit('Skills adapter must remain read-only')

for marker in [
    "const CONTRACT = 'mad4b.live-truth.v1'",
    "const FRESHNESS_OPTION = 'mad4b_scp_write_runtime_certification_freshness_v1'",
    "add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_live_read_callbacks' ), 100, 2 )",
    "'mad4b/write-authority-status'",
    "'mad4b/write-runtime-certification'",
    "'mad4b/rest-compatibility-status'",
    "'inspection_source' => 'live_read_only'",
    "'persistence' => 'read_only_live_inspection'",
    "'state' => 'stale'",
    "'stale_persisted_certification'",
    "'control_plane_version_changed'",
    "'write_inventory_changed'",
    "'provider_blocked_projection_changed'",
    "'wpml_internal_probe_blocks_local_certification' => false",
    "'external_wpml_acceptance_required' => true",
    "'external_wpml_acceptance_verified' => false",
]:
    if marker not in live_truth:
        raise SystemExit(f'missing rc.19 live truth/freshness invariant: {marker}')

for marker in [
    "'write_inventory_fingerprint'",
    "$changed = ! is_array( $stored )",
    "if ( $changed )",
    "'persistence'] = 'unchanged'",
]:
    if marker not in write_authority:
        raise SystemExit(f'missing no-churn authority reconciliation invariant: {marker}')

for marker in [
    "$runtime = MAD4B_SCP_Staging_Write_Authority::status();",
    "$stored_stable",
    "$runtime_stable",
    "MAD4B_SCP_Staging_Write_Authority::reconcile();",
]:
    if marker not in plugin:
        raise SystemExit(f'missing current-request authority recovery invariant: {marker}')

if "'execute_callback' => array( __CLASS__, 'status' )" not in write_cert:
    raise SystemExit('write certification persistence contract unexpectedly changed registration semantics')
if "MAD4B_SCP_Live_Truth::boot_early();" not in main:
    raise SystemExit('live truth bridge must be armed before Ability materialization')

print('mad4b.skill-runtime-certification.v4: PASS')