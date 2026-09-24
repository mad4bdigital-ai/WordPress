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
seeder = (wp / 'includes' / 'class-mad4b-scp-skill-seeder.php').read_text(encoding='utf-8')
plugin = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
live_truth = (wp / 'includes' / 'class-mad4b-scp-live-truth.php').read_text(encoding='utf-8')
write_authority = (wp / 'includes' / 'class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
write_cert = (wp / 'includes' / 'class-mad4b-scp-write-runtime-certification.php').read_text(encoding='utf-8')
request_scope = (wp / 'includes' / 'class-mad4b-scp-mcp-request-scope.php').read_text(encoding='utf-8')

for marker in [
    "const CONTRACT = 'mad4b.skill-autoconfig.v2'",
    "MAD4B_SCP_Site_Profile::configured()",
    "MAD4B_SCP_Site_Profile::site_host()",
    "MAD4B_SCP_Site_Profile::chatgpt_app_id()",
    "'site_profile_unconfigured'",
    "'site_profile_skills_disabled'",
    "'site_profile_app_mapping_missing'",
    "'app_mapping_source' => 'none'",
    "'app_mapping_matches_profile' => false",
    "'profile_digest'",
    "define( 'MAD4B_OPENAI_PLUGIN_APP_ID', $profile_app_id )",
    "explicit_app_mapping_profile_mismatch",
]:
    if marker not in autoconfig:
        raise SystemExit(f'missing tenant-bound Staging App mapping guard: {marker}')

for forbidden in [
    "plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2",
    "staging.egypttourgates.com",
    "const STAGING_OPENAI_APP_ID",
    "const STAGING_OPENAI_APP_HOST",
]:
    if forbidden in autoconfig:
        raise SystemExit(f'generic Skill autoconfig must not embed deployment-specific identity: {forbidden}')

for marker in [
    "const CONTRACT = 'mad4b.skill-snapshot-identity.v1'",
    "MAD4B_SCP_Skill_Registry::list_skills( array( 'enabled' => true ) )",
    "MAD4B_SCP_Skill_Registry::openai_app_id()",
    "public static function from_entries( array $entries, $app_id = '' )",
    "$result = self::from_entries( $entries, MAD4B_SCP_Skill_Registry::openai_app_id() )",
    "$result['runtime_context'] = self::runtime_context();",
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

payload_start = identity.find("$payload = array(")
payload_end = identity.find("$json = wp_json_encode( $payload", payload_start)
if payload_start < 0 or payload_end < 0 or payload_start >= payload_end:
    raise SystemExit('canonical snapshot payload boundary is missing')
if 'runtime_context' in identity[payload_start:payload_end]:
    raise SystemExit('diagnostic runtime_context must remain outside the canonical snapshot payload/digest')

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
    "const CONTRACT = 'mad4b.skill-runtime-certification.v2'",
    "MAD4B_SCP_Skill_Seeder::inspect()",
    "MAD4B_SCP_Skill_Provider_Discovery::inspect()",
    "'seed_inspection' => $seed",
    "'provider_inspection' => $provider",
    "wp_abilities_api_init",
    "mcp_adapter_init",
    "admin_init",
    "mad4b/skill-runtime-certification",
    "public static function current_status()",
    "public static function persisted_status()",
    "return self::current_status();",
    "'persistence'] = 'read_only_live_inspection'",
    "profile_app_mapping_mismatch",
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

observe_section = cert[cert.index('public static function observe()'):cert.index('public static function current_status()')]
if "current_request_is_protocol_hotpath()" not in observe_section:
    raise SystemExit('Skill observe must skip expensive recomputation only on the protocol hotpath')
if "current_request_requires_mcp_runtime()" in observe_section:
    raise SystemExit('Skill observe must not equate WP-CLI/admin runtime ownership with the HTTP protocol hotpath')

http_scope = request_scope[request_scope.index('public static function current_request_is_http_mcp_transport()'):request_scope.index('public static function current_request_is_protocol_hotpath()')]
runtime_scope = request_scope[request_scope.index('public static function current_request_requires_mcp_runtime()'):request_scope.index('public static function current_request_is_http_mcp_transport()')]
if "defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return false;" not in http_scope:
    raise SystemExit('HTTP MCP transport classifier must exclude WP-CLI')
if "defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;" not in runtime_scope:
    raise SystemExit('MCP runtime ownership must remain available under WP-CLI')

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

for text_value, start_marker, end_marker, label in [
    (seeder, "public static function inspect()", "private static function set_status", "seed inspection"),
    (provider_discovery, "public static function inspect()", "private static function set_status", "provider inspection"),
]:
    section = text_value[text_value.index(start_marker):text_value.index(end_marker)]
    for forbidden in [
        "update_option(",
        "add_option(",
        "delete_option(",
        "wp_mkdir_p(",
        "file_put_contents(",
        "atomic_write(",
        "MAD4B_SCP_Audit::record",
    ]:
        if forbidden in section:
            raise SystemExit(f'{label} must remain read-only: {forbidden}')

for marker in [
    "const INSPECTION_CONTRACT = 'mad4b.skill-seed-inspection.v1'",
    "'expected_sha256'",
    "'current_sha256'",
    "'would_create'",
    "'would_refresh'",
    "'user_owned'",
    "'mutation_performed' => false",
]:
    if marker not in seeder:
        raise SystemExit(f'missing seed inspection invariant: {marker}')

for marker in [
    "const INSPECTION_CONTRACT = 'mad4b.skill-provider-reconciliation-inspection.v1'",
    "'provider_family'",
    "'desired_enabled'",
    "'current_mapping'",
    "'would_enable'",
    "'would_disable'",
    "'would_refresh'",
    "'content_drift'",
    "'expected_sha256'",
    "'current_sha256'",
    "'content_current'",
    "'refresh_policy' => 'digest_clean_provider_managed_only'",
    "'catalog_truncated'",
    "'definition_limit_exceeded'",
    "'user_owned'",
    "'provider_plugin_mutation' => false",
]:
    if marker not in provider_discovery:
        raise SystemExit(f'missing provider inspection invariant: {marker}')

for marker in [
    "const DISCOVERY_VERSION = 3;",
    "const MAX_PACKS = 100;",
    "const MAX_DEFINITIONS_PER_FAMILY = 20;",
    "private static function catalog_limits( array $packs )",
    "if ( ! empty( $limits['exceeded'] ) ) return self::set_status( 'catalog_limits_exceeded' );",
    "private static function canonical_document( array $definition )",
    "'mad4b/skill-provider-refresh'",
    "'provider_content_refreshed' => true",
    "$provider_managed && (bool) $desired_enabled",
]:
    if marker not in provider_discovery:
        raise SystemExit(f'missing provider drift/limit hardening invariant: {marker}')

if "const CONTRACT = 'mad4b.write-runtime-certification.v3'" not in write_cert:
    raise SystemExit('write runtime certification must expose v3 current-truth semantics')
for marker in [
    "public static function current_status()",
    "public static function persisted_status()",
    "MAD4B_SCP_Live_Truth::current_write_certification()",
    "'historical_evidence_only'",
]:
    if marker not in write_cert:
        raise SystemExit(f'missing write current/historical split invariant: {marker}')


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
    "const CONTRACT = 'mad4b.live-truth.v2'",
    "const FRESHNESS_OPTION = 'mad4b_scp_write_runtime_certification_freshness_v1'",
    "'evidence_schema_revision' => 3",
    "'candidate_binding_fingerprint'",
    "'package_manifest_digest'",
    "'artifact_identity'",
    "'normal_remote_write_exact_approval_required'",
    "'candidate_bootstrap_prior_approval_exception'",
    "'candidate_bootstrap_closure'",
    "'exact_approval_required_for_remote_write' => empty( $exceptions )",
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
    "public static function reconcile_authority_if_needed()",
    "MAD4B_SCP_Live_Truth::current_authority_status()",
    "Compatibility entry point retained for older callers. Inspection only.",
]:
    if marker not in plugin:
        raise SystemExit(f'missing read-only authority inspection invariant: {marker}')

for forbidden in [
    "add_action( 'wp_abilities_api_init', array( __CLASS__, 'reconcile_authority_if_needed' )",
    "add_action( 'admin_init', array( __CLASS__, 'reconcile_authority_on_mad4b_admin' )",
    "MAD4B_SCP_Staging_Write_Authority::reconcile();",
]:
    if forbidden in plugin:
        raise SystemExit(f'plugin lifecycle retained destructive authority reconciliation: {forbidden}')

if "'execute_callback' => array( __CLASS__, 'status' )" not in write_cert:
    raise SystemExit('write certification persistence contract unexpectedly changed registration semantics')
if "hash_equals( (string) $inventory['write_inventory_fingerprint'], (string) $runtime['write_inventory_fingerprint'] )" not in live_truth:
    raise SystemExit('runtime reconciliation must bind the exact live write inventory fingerprint')
if "MAD4B_SCP_Live_Truth::boot_early();" not in main:
    raise SystemExit('live truth bridge must be armed before Ability materialization')
if "$missing_remote_mounts = array();" not in live_truth:
    raise SystemExit('legacy missing_remote_mounts response field must be initialized deterministically')
if "current_request_is_protocol_hotpath()" not in live_truth:
    raise SystemExit('Live Truth automatic recovery must skip only the protocol hotpath')

print('mad4b.skill-runtime-certification.v6: PASS')
