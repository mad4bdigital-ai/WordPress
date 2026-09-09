#!/usr/bin/env python3
import json
import re
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'
portable = repo / 'plugins' / 'mad4b-wordpress'

registry = (wp / 'includes' / 'class-mad4b-scp-skill-registry.php').read_text(encoding='utf-8')
abilities = (wp / 'includes' / 'class-mad4b-scp-skill-abilities.php').read_text(encoding='utf-8')
adapter = (wp / 'includes' / 'adapters' / 'class-mad4b-scp-skills-adapter.php').read_text(encoding='utf-8')
admin = (wp / 'includes' / 'class-mad4b-scp-skills-admin-ui.php').read_text(encoding='utf-8')
exporter = (wp / 'includes' / 'class-mad4b-scp-skill-exporter.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin_boot = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

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
]:
    if marker not in plugin_boot:
        raise SystemExit(f'missing Skill boot wiring: {marker}')

for file_marker in [
    'class-mad4b-scp-skill-registry.php',
    'class-mad4b-scp-skill-resource-reader.php',
    'class-mad4b-scp-skill-exporter.php',
    'class-mad4b-scp-skill-abilities.php',
    'class-mad4b-scp-skills-adapter.php',
    'class-mad4b-scp-skills-admin-ui.php',
]:
    if file_marker not in main:
        raise SystemExit(f'main plugin is not loading {file_marker}')

for marker in [
    'runtime registry is dynamic',
    'versioned snapshots',
    'Scan Tools',
    'Export Portable Plugin ZIP',
]:
    if marker.lower() not in admin.lower():
        raise SystemExit(f'missing admin snapshot/UX contract marker: {marker}')

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

print('mad4b.dynamic-skills.v1: PASS')
