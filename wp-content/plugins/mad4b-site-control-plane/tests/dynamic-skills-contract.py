#!/usr/bin/env python3
import json
import re
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'
portable = repo / 'plugins' / 'mad4b-wordpress'

autoconfig = (wp / 'includes' / 'class-mad4b-scp-skill-autoconfig.php').read_text(encoding='utf-8')
registry = (wp / 'includes' / 'class-mad4b-scp-skill-registry.php').read_text(encoding='utf-8')
seeder = (wp / 'includes' / 'class-mad4b-scp-skill-seeder.php').read_text(encoding='utf-8')
provider_discovery = (wp / 'includes' / 'class-mad4b-scp-skill-provider-discovery.php').read_text(encoding='utf-8')
provider_catalog = json.loads((wp / 'config' / 'skill-provider-catalog.json').read_text(encoding='utf-8'))
abilities = (wp / 'includes' / 'class-mad4b-scp-skill-abilities.php').read_text(encoding='utf-8')
adapter = (wp / 'includes' / 'adapters' / 'class-mad4b-scp-skills-adapter.php').read_text(encoding='utf-8')
admin = (wp / 'includes' / 'class-mad4b-scp-skills-admin-ui.php').read_text(encoding='utf-8')
exporter = (wp / 'includes' / 'class-mad4b-scp-skill-exporter.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin_boot = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
readme = (portable / 'README.md').read_text(encoding='utf-8')

for marker in [
    "const CONTRACT = 'mad4b.skill-autoconfig.v1'",
    "'staging' !== $environment",
    "defined( 'MAD4B_SKILLS_EDITOR_ENABLED' )",
    "true !== constant( 'MAD4B_SKILLS_EDITOR_ENABLED' )",
    "define( 'MAD4B_SKILLS_EDITOR_ENABLED', true )",
    "'configuration_source' => 'none'",
    "'production_auto_enable' => false",
    "'scripts_auto_enable' => false",
    "'explicit_editor_disabled'",
    "'staging_auto'",
]:
    if marker not in autoconfig:
        raise SystemExit(f'missing zero-touch Staging Skill autoconfig guard: {marker}')

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
    "'staging' !== $environment",
    "MAD4B_SCP_Skill_Registry::editor_enabled()",
    "MAD4B_SCP_Audit::storage_status()",
    "MAD4B_SCP_Audit::record",
    "'overwrites_existing' => false",
    "'production_auto_seed' => false",
    "is_file( $file )",
    "realpath( $root )",
    "atomic_write",
    "wordpress-site-diagnostics",
    "wordpress-connection-diagnostics",
    "elementor-dynamic-content",
    "jetengine-content-modeling",
    "wordpress-archive-audit",
    "wordpress-change-safety",
    "'enabled' => false",
    "class-mad4b-scp-skill-provider-discovery.php",
    "MAD4B_SCP_Skill_Provider_Discovery",
    "plugins_loaded",
    "30",
]:
    if marker not in seeder:
        raise SystemExit(f'missing automatic seed/provider handoff guard: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.skill-provider-discovery.v1'",
    "const CATALOG_CONTRACT = 'mad4b.skill-provider-catalog.v1'",
    "'staging' !== $environment",
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
    'elementor',
    'jetengine',
    'jetsmartfilters',
    'woocommerce',
    'polylang',
    'rank-math',
    'litespeed',
    'media-optimization',
    'etg-dfsb',
    'bitflows',
    'fluentforms',
    'wpml',
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
]
for name in expected_read_abilities:
    if name not in abilities or name not in adapter:
        raise SystemExit(f'missing governed skill read ability ownership: {name}')

for forbidden in ['mad4b/skill-create', 'mad4b/skill-update', 'mad4b/skill-delete', 'mad4b/skill-write']:
    if forbidden in abilities or forbidden in adapter:
        raise SystemExit(f'ChatGPT/MCP skill mutation surface must not be exposed: {forbidden}')

for marker in [
    'MAD4B_SCP_Skills_Admin_UI::boot()',
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
    'class-mad4b-scp-skill-exporter.php',
    'class-mad4b-scp-skill-abilities.php',
    'class-mad4b-scp-skills-adapter.php',
    'class-mad4b-scp-skills-admin-ui.php',
]:
    if file_marker not in main:
        raise SystemExit(f'main plugin is not loading {file_marker}')
if 'MAD4B_SCP_Skill_Autoconfig::bootstrap()' not in main:
    raise SystemExit('main plugin must bootstrap Staging Skills automatically')

for marker in [
    'registry is dynamic',
    'versioned snapshots',
    'Scan Tools',
    'Export Portable Plugin ZIP',
]:
    if marker.lower() not in admin.lower():
        raise SystemExit(f'missing admin snapshot/UX contract marker: {marker}')

for marker in [
    'automatically enables the local Skill editor',
    'No `wp-config.php` edit is required',
    'Production is never auto-enabled',
]:
    if marker not in readme:
        raise SystemExit(f'missing zero-touch Staging documentation marker: {marker}')

if "'capabilities' => array( 'Read' )" not in exporter:
    raise SystemExit('runtime exporter must advertise Read capability only')
if "'publication_semantics' => 'snapshot'" not in exporter:
    raise SystemExit('runtime exporter must identify snapshot publication semantics')

manifest = json.loads((portable / 'plugin.json').read_text(encoding='utf-8'))
compat = json.loads((portable / '.codex-plugin' / 'plugin.json').read_text(encoding='utf-8'))
app = json.loads((portable / '.app.json').read_text(encoding='utf-8'))
marketplace = json.loads((repo / '.agents' / 'plugins' / 'marketplace.json').read_text(encoding='utf-8'))

if manifest.get('name') != 'mad4b-wordpress':
    raise SystemExit('unexpected portable plugin name')
openai = manifest.get('extensions', {}).get('com.openai', {})
if openai.get('apps') != './.app.json':
    raise SystemExit('portable plugin must reference the existing MCP app mapping')
if openai.get('interface', {}).get('capabilities') != ['Read']:
    raise SystemExit('portable OpenAI interface must stay Read-only')
if compat.get('apps') != './.app.json' or compat.get('skills') != './skills/':
    raise SystemExit('compatibility manifest must point to app mapping and root skills directory')

app_id = app.get('apps', {}).get('mad4b-wordpress', {}).get('id', '')
if not re.fullmatch(r'plugin_asdk_app_[A-Za-z0-9]+', app_id):
    raise SystemExit('portable app mapping must use a real plugin_asdk_app technical ID')

expected_skills = {
    'wordpress-site-diagnostics',
    'wordpress-connection-diagnostics',
    'elementor-dynamic-content',
    'jetengine-content-modeling',
    'wordpress-archive-audit',
    'wordpress-change-safety',
}
found = set()
for skill_dir in (portable / 'skills').iterdir():
    if not skill_dir.is_dir():
        continue
    skill_file = skill_dir / 'SKILL.md'
    if not skill_file.is_file():
        raise SystemExit(f'missing SKILL.md in {skill_dir.name}')
    text = skill_file.read_text(encoding='utf-8')
    if not text.startswith('---\n'):
        raise SystemExit(f'{skill_dir.name} missing YAML frontmatter')
    if f'name: {skill_dir.name}' not in text:
        raise SystemExit(f'{skill_dir.name} frontmatter name must match folder')
    if 'description:' not in text.split('---', 2)[1]:
        raise SystemExit(f'{skill_dir.name} missing frontmatter description')
    found.add(skill_dir.name)
if not expected_skills.issubset(found):
    raise SystemExit(f'missing seed skills: {sorted(expected_skills - found)}')

entry = next((x for x in marketplace.get('plugins', []) if x.get('name') == 'mad4b-wordpress'), None)
if not entry or entry.get('source', {}).get('path') != './plugins/mad4b-wordpress':
    raise SystemExit('local marketplace is missing the MAD4B WordPress portable plugin')
if entry.get('policy', {}).get('authentication') != 'ON_INSTALL':
    raise SystemExit('MAD4B WordPress marketplace entry must authenticate on install')

print('mad4b.dynamic-skills.v2: PASS')
