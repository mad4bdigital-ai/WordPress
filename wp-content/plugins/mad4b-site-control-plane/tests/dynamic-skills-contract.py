#!/usr/bin/env python3
import json
import re
import subprocess
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'
portable = repo / 'plugins' / 'mad4b-wordpress'

autoconfig = (wp / 'includes' / 'class-mad4b-scp-skill-autoconfig.php').read_text(encoding='utf-8')
registry = (wp / 'includes' / 'class-mad4b-scp-skill-registry.php').read_text(encoding='utf-8')
seeder = (wp / 'includes' / 'class-mad4b-scp-skill-seeder.php').read_text(encoding='utf-8')

for label, source in [('skill-registry', registry), ('skill-seeder', seeder)]:
    if "function_exists( 'wp_is_valid_utf8' )" not in source or 'wp_is_valid_utf8( (string) $value )' not in source:
        raise SystemExit(f'{label} must use the WordPress 6.9+ UTF-8 validator')
    if re.search(r'\bseems_utf8\s*\(', source):
        raise SystemExit(f'{label} must not invoke deprecated seems_utf8()')

provider_discovery = (wp / 'includes' / 'class-mad4b-scp-skill-provider-discovery.php').read_text(encoding='utf-8')
provider_catalog = json.loads((wp / 'config' / 'skill-provider-catalog.json').read_text(encoding='utf-8'))
seed_manifest = json.loads((wp / 'config' / 'skill-seed-manifest.json').read_text(encoding='utf-8'))
abilities = (wp / 'includes' / 'class-mad4b-scp-skill-abilities.php').read_text(encoding='utf-8')
adapter = (wp / 'includes' / 'adapters' / 'class-mad4b-scp-skills-adapter.php').read_text(encoding='utf-8')
admin = (wp / 'includes' / 'class-mad4b-scp-skills-admin-ui.php').read_text(encoding='utf-8')
resource_writer = (wp / 'includes' / 'class-mad4b-scp-skill-resource-writer.php').read_text(encoding='utf-8')
exporter = (wp / 'includes' / 'class-mad4b-scp-skill-exporter.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin_boot = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
enrollment_dispatch = (wp / 'includes' / 'class-mad4b-scp-enrollment-dispatch.php').read_text(encoding='utf-8')
core_abilities = (wp / 'includes' / 'class-mad4b-scp-abilities.php').read_text(encoding='utf-8')

for marker in [
    "private static function request_is_wordpress_plugin_lifecycle()",
    "array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' )",
    "array( 'upload-plugin', 'install-plugin', 'update-plugin', 'activate', 'deactivate', 'delete-selected' )",
    "$plugin_lifecycle = self::request_is_wordpress_plugin_lifecycle();",
    "if ( ! $plugin_lifecycle && ( ! MAD4B_SCP_Schema::is_ready()",
    "if ( ! $plugin_lifecycle && false === get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false ) )",
    "if ( ! $plugin_lifecycle && ! is_wp_error( self::$schema_error ) && self::request_requires_skill_reconciliation() )",
]:
    if marker not in plugin_boot:
        raise SystemExit(f'missing plugin upload lifecycle protection invariant: {marker}')

readme = (portable / 'README.md').read_text(encoding='utf-8')

for marker in [
    "const EXECUTE_ABILITY = 'mad4b/enrollment-execute'",
    "'operator' !==",
    "MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-enrollment'",
    "'mad4b_enrollment_dispatch_target_surface_mismatch'",
    "'mad4b_enrollment_dispatch_generic_admin_denied'",
    "'mad4b_enrollment_dispatch_target_production_denied'",
    "'mutation_evidence_source'",
]:
    if marker not in enrollment_dispatch:
        raise SystemExit(f'missing bounded enrollment dispatcher invariant: {marker}')

runtime_test = wp / 'tests' / 'enrollment-dispatch-runtime.php'
if not runtime_test.is_file():
    raise SystemExit('bounded enrollment dispatcher runtime test is missing')
subprocess.run(['php', str(runtime_test)], check=True)

registration_runtime_test = wp / 'tests' / 'enrollment-dispatch-registration-runtime.php'
if not registration_runtime_test.is_file():
    raise SystemExit('bounded enrollment dispatcher registration-boundary runtime test is missing')
subprocess.run(['php', str(registration_runtime_test)], check=True)

for marker in [
    "'enrollment_dispatch' === $permission",
    "array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' )",
    "has_filter( 'wp_register_ability_args', $write_augment )",
    "remove_filter( 'wp_register_ability_args', $write_augment",
    "finally",
    "add_filter( 'wp_register_ability_args', $write_augment",
]:
    if marker not in core_abilities:
        raise SystemExit(f'missing enrollment dispatcher write-augmentation isolation invariant: {marker}')

for marker in [
    "public static function reconcile()",
    "self::$ran = false;",
    "self::$runtime_status = null;",
    "return self::bootstrap();",
]:
    if marker not in provider_discovery:
        raise SystemExit(f'missing explicit provider Skill reconciliation invariant: {marker}')

for marker in [
    "'reconcile_managed' === $action",
    "private static function render_reconcile_managed",
    "wp_nonce_field( 'mad4b_skill_reconcile_managed', 'mad4b_skill_reconcile_nonce' )",
    "private static function handle_reconcile_managed",
    "check_admin_referer( 'mad4b_skill_reconcile_managed', 'mad4b_skill_reconcile_nonce' )",
    "current_user_can( 'manage_options' )",
    "MAD4B_SCP_Skill_Seeder::bootstrap()",
    "MAD4B_SCP_Skill_Provider_Discovery::reconcile()",
    "MAD4B_SCP_Skill_Runtime_Certification::observe()",
]:
    if marker not in admin:
        raise SystemExit(f'missing explicit managed Skill admin reconciliation invariant: {marker}')

if admin.index("MAD4B_SCP_Skill_Seeder::bootstrap()") > admin.index("MAD4B_SCP_Skill_Provider_Discovery::reconcile()"):
    raise SystemExit('managed Skill reconciliation must seed canonical Skills before provider reconciliation')

for source in (seeder, main, plugin_boot):
    if "array( 'MAD4B_SCP_Skill_Seeder', 'bootstrap' )" in source:
        raise SystemExit('canonical Skill seeding must remain explicit and must not become an automatic request lifecycle mutation')

for source in (admin, provider_discovery):
    if "wp_register_ability(" in source:
        raise SystemExit('managed Skill reconciliation must not add a remote MCP mutation ability')


for marker in [
    "const CONTRACT = 'mad4b.skill-autoconfig.v2'",
    "! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true )",
    "MAD4B_SCP_Site_Profile::configured()",
    "MAD4B_SCP_Site_Profile::chatgpt_app_id()",
    "MAD4B_SCP_Site_Profile::site_host()",
    "'site_profile_unconfigured'",
    "'site_profile_skills_disabled'",
    "'site_profile_app_mapping_missing'",
    "defined( 'MAD4B_SKILLS_EDITOR_ENABLED' )",
    "true !== constant( 'MAD4B_SKILLS_EDITOR_ENABLED' )",
    "define( 'MAD4B_SKILLS_EDITOR_ENABLED', true )",
    "'configuration_source' => 'none'",
    "'production_auto_enable' => false",
    "'scripts_auto_enable' => false",
    "'explicit_editor_disabled'",
    "'site_profile_staging_auto'",
    "'app_mapping_matches_profile'",
    "'app_mapping_source'] = 'site_profile'",
]:
    if marker not in autoconfig:
        raise SystemExit(f'missing tenant-bound Staging Skill autoconfig guard: {marker}')

for forbidden in [
    'staging.egypttourgates.com',
    'plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2',
    'STAGING_OPENAI_APP_ID',
    'STAGING_OPENAI_APP_HOST',
]:
    if forbidden in autoconfig:
        raise SystemExit(f'deployment-specific Skill autoconfig literal leaked into generic runtime: {forbidden}')

for marker in [
    "const ROOT_DIRNAME = 'mad4b-skills'",
    "private static $levels = array( 'site', 'connection', 'provider', 'adapter', 'workflow' )",
    "MAD4B_SKILLS_EDITOR_ENABLED",
    "MAD4B_SKILLS_PRODUCTION_EDITOR_ENABLED",
    "MAD4B_SKILLS_SCRIPTS_EDITOR_ENABLED",
    "MAD4B_OPENAI_PLUGIN_APP_ID",
    "MAD4B_SCP_Audit::record",
    "wp_mkdir_p",
    "realpath",
    "is_link",
    "atomic_write",
]:
    if marker not in registry:
        raise SystemExit(f'missing dynamic skill registry guard: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.skill-seeder.v1'",
    "const SEED_DIR = 'skill-seeds'",
    "const SEED_MANIFEST_CONTRACT = 'mad4b.skill-seed-manifest.v1'",
    "const SEED_MANIFEST_FILE = 'config/skill-seed-manifest.json'",
    "private static function seeds()",
    "self::SEED_MANIFEST_CONTRACT",
    "self::SEED_VERSION !== (int) $data['seed_version']",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Skill_Registry::editor_enabled()",
    "MAD4B_SCP_Audit::storage_status()",
    "MAD4B_SCP_Audit::record",
    "mad4b/skill-seed-refresh",
    "refresh_existing_if_managed",
    "canonical_document",
    "'overwrites_user_owned' => false",
    "'refreshes_only_digest_clean_managed' => true",
    "'production_auto_seed' => false",
    "recorded_sha",
    "hash_equals( $recorded_sha, $current_sha )",
    "mad4b_skill_seed_refresh_rollback_failed",
    "context_policy_sha256",
    "allowed_mutation_abilities",
    "class-mad4b-scp-skill-provider-discovery.php",
    "MAD4B_SCP_Skill_Provider_Discovery",
    "plugins_loaded",
    "30",
]:
    if marker not in seeder:
        raise SystemExit(f'missing canonical automatic seed/provider handoff guard: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.skill-provider-discovery.v1'",
    "const CATALOG_CONTRACT = 'mad4b.skill-provider-catalog.v1'",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Plugin_Discovery::coverage()",
    "adapter_registered",
    "adapter_runtime_available",
    "provider_active_adapter_ready",
    "provider_adapter_unavailable",
    "provider_inactive",
    "mad4b/skill-provider-autoprovision",
    "mad4b/skill-provider-activation",
    "'production_auto_provision' => false",
    "'provider_plugin_mutation' => false",
    "'deletes_skills' => false",
    "'mad4b.skill-seeder.v1'",
    "user_owned",
    "is_file( $file )",
    "realpath( $root )",
    "atomic_write",
]:
    if marker not in provider_discovery:
        raise SystemExit(f'missing dynamic provider Skill guard: {marker}')

if provider_catalog.get('contract') != 'mad4b.skill-provider-catalog.v1':
    raise SystemExit('provider Skill catalog contract is invalid')
expected_provider_families = {
    'elementor', 'jetengine', 'jetsmartfilters', 'woocommerce', 'polylang',
    'rank-math', 'litespeed', 'media-optimization', 'etg-dfsb', 'bitflows',
    'fluentforms', 'wpml',
}
provider_packs = provider_catalog.get('packs', {})
missing_families = expected_provider_families - set(provider_packs)
if missing_families:
    raise SystemExit(f'missing provider Skill packs: {sorted(missing_families)}')
for family, definitions in provider_packs.items():
    if not isinstance(definitions, list) or not definitions:
        raise SystemExit(f'provider Skill family {family} must have at least one definition')
    for definition in definitions:
        if definition.get('level') not in {'site', 'connection', 'provider', 'adapter', 'workflow'}:
            raise SystemExit(f'provider Skill {family} has invalid level')
        if not re.fullmatch(r'[a-z0-9_-]+', definition.get('target', '')):
            raise SystemExit(f'provider Skill {family} has invalid target')
        if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*', definition.get('name', '')):
            raise SystemExit(f'provider Skill {family} has invalid name')
        if not definition.get('description') or not definition.get('body'):
            raise SystemExit(f'provider Skill {family} must contain description and body')

if "MAD4B_SCP_DIR . 'skills" in registry:
    raise SystemExit('runtime-authored skills must not be persisted inside the upgradeable plugin directory')

expected_read_abilities = [
    'mad4b/skills-list',
    'mad4b/skill-get',
    'mad4b/skills-export-status',
    'mad4b/skills-runtime-certification',
]
for name in expected_read_abilities:
    if name not in abilities or name not in adapter:
        raise SystemExit(f'missing governed skill read ability ownership: {name}')

for forbidden in ['mad4b/skill-create', 'mad4b/skill-update', 'mad4b/skill-delete', 'mad4b/skill-write']:
    if forbidden in abilities or forbidden in adapter:
        raise SystemExit(f'ChatGPT/MCP skill mutation surface must not be exposed: {forbidden}')

for marker in [
    'MAD4B_SCP_Skills_Admin_UI::boot()',
    'MAD4B_SCP_Skill_Resource_Writer::boot()',
    'MAD4B_SCP_Skill_Abilities::boot()',
    'MAD4B_SCP_Skills_Adapter::boot()',
    'MAD4B_SCP_Skill_Seeder::bootstrap()',
]:
    if marker not in plugin_boot:
        raise SystemExit(f'missing Skill boot wiring: {marker}')

for file_marker in [
    'class-mad4b-scp-skill-autoconfig.php',
    'class-mad4b-scp-skill-registry.php',
    'class-mad4b-scp-skill-seeder.php',
    'class-mad4b-scp-skill-resource-reader.php',
    'class-mad4b-scp-skill-resource-writer.php',
    'class-mad4b-scp-skill-exporter.php',
    'class-mad4b-scp-skill-abilities.php',
    'class-mad4b-scp-skills-adapter.php',
    'class-mad4b-scp-skills-admin-ui.php',
]:
    if file_marker not in main:
        raise SystemExit(f'main plugin is not loading {file_marker}')
if 'MAD4B_SCP_Skill_Autoconfig::bootstrap()' not in main:
    raise SystemExit('main plugin must bootstrap enrolled Staging Skills automatically')

for marker in [
    'admin_init',
    'intercept_export',
    'Portable Plugin export failed',
    'Skill identity is immutable while editing',
    'MAD4B_SCP_Skill_Resource_Writer::save',
    'enables the Skill editor automatically',
    'Snapshot identity',
    'MAD4B-SNAPSHOT-ID.txt',
]:
    if marker not in admin:
        raise SystemExit(f'missing hardened admin UX contract marker: {marker}')
if 'define MAD4B_SKILLS_EDITOR_ENABLED=true' in admin:
    raise SystemExit('admin UI must not instruct manual Staging editor activation')

for marker in [
    'mad4b_skill_resource_rollback_failed',
    'Restored resource does not match its pre-change digest',
    'MAD4B_SCP_Skill_Resource_Reader::read',
    'hash_equals',
]:
    if marker not in resource_writer:
        raise SystemExit(f'missing verified resource rollback guard: {marker}')

for marker in [
    "const MAX_EXPORT_SKILLS = 250",
    "const MAX_EXPORT_RESOURCES = 2000",
    "const MAX_EXPORT_UNCOMPRESSED_BYTES = 67108864",
    'mad4b_skill_export_skill_limit_exceeded',
    'mad4b_skill_export_resource_limit_exceeded',
    'mad4b_skill_export_size_limit_exceeded',
    'uncompressed_payload_bytes',
    'resource_count',
    "MAD4B_SCP_Live_Truth::current_authority_status()",
    "MAD4B_SCP_Live_Truth::current_write_certification()",
    "$write_ready = ! empty( $write_authority['ready'] ) && ! empty( $write_certification['ready'] )",
    "$capabilities = $write_ready ? array( 'Read', 'Write' ) : array( 'Read' )",
    "'capabilities' => $capabilities",
    "'write_certification_ready'",
    "'publication_semantics' => 'snapshot'",
]:
    if marker not in exporter:
        raise SystemExit(f'missing bounded certification-gated exporter guard: {marker}')
if "MAD4B_SCP_Staging_Write_Authority::reconcile()" in exporter or "MAD4B_SCP_Write_Runtime_Certification::observe()" in exporter:
    raise SystemExit('Skill exporter may not mutate or reconcile governed write authority while exporting')

for marker in [
    'Fresh installation is zero-authority',
    'explicit Site Profile v2 enrollment',
    'Production never receives write authority by installation',
    'Portable Plugin capability: `Read + Write`',
    'mad4b-write',
    'approval-plan',
    'rest-compatibility-status',
    'WPML',
]:
    if marker not in readme:
        raise SystemExit(f'missing tenant-neutral governed documentation marker: {marker}')

manifest = json.loads((portable / 'plugin.json').read_text(encoding='utf-8'))
compat = json.loads((portable / '.codex-plugin' / 'plugin.json').read_text(encoding='utf-8'))
app = json.loads((portable / '.app.json').read_text(encoding='utf-8'))
marketplace = json.loads((repo / '.agents' / 'plugins' / 'marketplace.json').read_text(encoding='utf-8'))

if manifest.get('name') != 'mad4b-wordpress':
    raise SystemExit('unexpected portable plugin name')
openai = manifest.get('extensions', {}).get('com.openai', {})
if openai.get('apps') != './.app.json':
    raise SystemExit('portable plugin must reference the existing MCP app mapping')
if openai.get('interface', {}).get('capabilities') != ['Read', 'Write']:
    raise SystemExit('portable OpenAI interface must declare governed Read + Write')
if compat.get('apps') != './.app.json' or compat.get('skills') != './skills/':
    raise SystemExit('compatibility manifest must point to app mapping and root skills directory')
if compat.get('interface', {}).get('capabilities') != ['Read', 'Write']:
    raise SystemExit('Codex compatibility manifest must match governed Read + Write capability')
if compat.get('version') != manifest.get('version'):
    raise SystemExit('portable and Codex compatibility manifest versions must match')

for label, payload in [('portable README', readme), ('portable manifest', json.dumps(manifest)), ('Codex manifest', json.dumps(compat))]:
    for forbidden in ['staging.egypttourgates.com', 'Egypt Tour Gates']:
        if forbidden in payload:
            raise SystemExit(f'{label} retained deployment-specific identity: {forbidden}')

app_id = app.get('apps', {}).get('mad4b-wordpress', {}).get('id', '')
if not re.fullmatch(r'plugin_asdk_app_[A-Za-z0-9]+', app_id):
    raise SystemExit('portable app mapping must use a real plugin_asdk_app technical ID')

if seed_manifest.get('contract') != 'mad4b.skill-seed-manifest.v1':
    raise SystemExit('canonical Skill seed manifest contract is invalid')
seed_version = seed_manifest.get('seed_version')
if not isinstance(seed_version, int) or seed_version < 1:
    raise SystemExit('canonical Skill seed manifest version is invalid')
match = re.search(r"const SEED_VERSION = ([0-9]+);", seeder)
if not match or int(match.group(1)) != seed_version:
    raise SystemExit('canonical Skill seed manifest/runtime version mismatch')
manifest_rows = seed_manifest.get('skills', [])
if not isinstance(manifest_rows, list) or not manifest_rows:
    raise SystemExit('canonical Skill seed manifest must contain Skills')
expected_skills = {row.get('name') for row in manifest_rows if isinstance(row, dict)}
if None in expected_skills or len(expected_skills) != len(manifest_rows):
    raise SystemExit('canonical Skill seed manifest contains duplicate or invalid names')
provider_seed_rows = [row for row in manifest_rows if isinstance(row, dict) and row.get('level') == 'provider']
if not provider_seed_rows:
    raise SystemExit('canonical Skill seed manifest must retain provider seed coverage')
if any(row.get('enabled') is not False for row in provider_seed_rows):
    raise SystemExit('provider-level canonical Skill seeds must fail closed disabled by default')

found = set()
seed_root = wp / 'skill-seeds'
for skill_dir in (portable / 'skills').iterdir():
    if not skill_dir.is_dir():
        continue
    skill_file = skill_dir / 'SKILL.md'
    if not skill_file.is_file():
        raise SystemExit(f'missing SKILL.md in {skill_dir.name}')
    raw = skill_file.read_bytes()
    text = raw.decode('utf-8')
    if not text.startswith('---\n'):
        raise SystemExit(f'{skill_dir.name} missing YAML frontmatter')
    if f'name: {skill_dir.name}' not in text:
        raise SystemExit(f'{skill_dir.name} frontmatter name must match folder')
    if 'description:' not in text.split('---', 2)[1]:
        raise SystemExit(f'{skill_dir.name} missing frontmatter description')
    if skill_dir.name == 'wordpress-brand-context-builder':
        for marker in ('context/brand-gap-plan', 'context/brand-draft-append', 'context/materialize-brand-draft', 'context/source-scan-plan', 'context/source-scan-apply', 'Generation is not approval'):
            if marker not in text:
                raise SystemExit(f'wordpress-brand-context-builder missing governed workflow instruction: {marker}')
    if skill_dir.name == 'wordpress-release-orchestration':
        for marker in ('plan_sha256', 'expected_plan_sha256', 'expected_state_sha256', 're-planning and re-approval'):
            if marker not in text:
                raise SystemExit(f'wordpress-release-orchestration missing plan-bound authority instruction: {marker}')
    found.add(skill_dir.name)

    canonical = seed_root / skill_dir.name / 'SKILL.md'
    if skill_dir.name in expected_skills:
        if not canonical.is_file():
            raise SystemExit(f'missing canonical Control Plane seed for {skill_dir.name}')
        if canonical.read_bytes() != raw:
            raise SystemExit(f'canonical/runtime seed drift detected for {skill_dir.name}')

if not expected_skills.issubset(found):
    raise SystemExit(f'missing portable seed skills: {sorted(expected_skills - found)}')
if {p.parent.name for p in seed_root.glob('*/SKILL.md')} != expected_skills:
    raise SystemExit('canonical Control Plane seed set must exactly match the canonical Skill seed manifest')

entry = next((x for x in marketplace.get('plugins', []) if x.get('name') == 'mad4b-wordpress'), None)
if not entry or entry.get('source', {}).get('path') != './plugins/mad4b-wordpress':
    raise SystemExit('local marketplace is missing the MAD4B WordPress portable plugin')
if entry.get('policy', {}).get('authentication') != 'ON_INSTALL':
    raise SystemExit('MAD4B WordPress marketplace entry must authenticate on install')

print('mad4b.dynamic-skills.v8: PASS')
