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
plugin = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')

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
    "MAD4B-SNAPSHOT-ID.txt",
    "snapshot_identity_contract",
    "snapshot_digest",
    "identity_token",
    "mad4b_skill_snapshot_changed_during_export",
    "hash_equals( (string) $identity['identity_token'], (string) $identity_after['identity_token'] )",
]:
    if marker not in exporter:
        raise SystemExit(f'portable exporter is not snapshot-identity/race bound: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.skill-runtime-certification.v1'",
    "wp_abilities_api_init",
    "mcp_adapter_init",
    "admin_init",
    "mad4b/skill-runtime-certification",
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
]:
    if marker not in main:
        raise SystemExit(f'main plugin does not load {marker}')

if "'content' => array()" not in adapter or "'admin' => array()" not in adapter:
    raise SystemExit('Skills adapter must remain read-only')

print('mad4b.skill-runtime-certification.v1: PASS')
