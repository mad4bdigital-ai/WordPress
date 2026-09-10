#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CERT = ROOT / 'includes' / 'class-mad4b-scp-skill-runtime-certification.php'
ABILITIES = ROOT / 'includes' / 'class-mad4b-scp-skill-abilities.php'
BOOT = ROOT / 'includes' / 'class-mad4b-scp-plugin.php'
ADMIN = ROOT / 'includes' / 'class-mad4b-scp-skills-admin-ui.php'
MAIN = ROOT / 'mad4b-site-control-plane.php'

cert = CERT.read_text('utf-8')
abilities = ABILITIES.read_text('utf-8')
boot = BOOT.read_text('utf-8')
admin = ADMIN.read_text('utf-8')
main = MAIN.read_text('utf-8')

required_cert_markers = [
    "CONTRACT = 'mad4b.skill-runtime-certification.v1'",
    'wp_abilities_api_init',
    'admin_init',
    'MAD4B_SCP_Environment::is_staging()',
    'MAD4B_SCP_Skill_Registry::editor_enabled()',
    'MAD4B_SCP_Skill_Registry::scripts_editor_enabled()',
    'MAD4B_SCP_Staging_Autoconfig::expected_skills_app_id()',
    'MAD4B_SCP_Skill_Seeder::status()',
    'MAD4B_SCP_Skill_Provider_Discovery::status()',
    "MAD4B_SCP_Skill_Registry::get( 'site', '_site', self::BASE_SITE_SKILL )",
    "MAD4B_SCP_Skill_Registry::get( 'connection', 'mad4b-chatgpt', self::BASE_CONNECTION_SKILL )",
    "MAD4B_SCP_Skill_Registry::get( 'workflow', 'archive-audit', self::BASE_ARCHIVE_SKILL )",
    "MAD4B_SCP_Skill_Registry::get( 'workflow', 'change-safety', self::BASE_CHANGE_SKILL )",
    "'mad4b/skills-list'",
    "'mad4b/skill-get'",
    "'mad4b/skills-export-status'",
    "'mad4b/skill-create'",
    "'mad4b/skill-update'",
    "'mad4b/skill-delete'",
    'MAD4B_SCP_Skill_Export::snapshot_status()',
    "'local_evidence_only' => true",
]
for marker in required_cert_markers:
    if marker not in cert:
        raise SystemExit(f'FAIL skill-runtime-certification-contract:missing:{marker}')

required_ability_markers = [
    "name' => 'mad4b/skills-runtime-certification'",
    "MAD4B_SCP_Skill_Runtime_Certification::observe()",
    "'readonly' => true",
    "'destructive' => false",
    "'idempotent' => true",
]
for marker in required_ability_markers:
    if marker not in abilities:
        raise SystemExit(f'FAIL skill-runtime-certification-ability:{marker}')

boot_markers = [
    "require_once __DIR__ . '/class-mad4b-scp-skill-runtime-certification.php';",
    'MAD4B_SCP_Skill_Runtime_Certification::boot();',
]
for marker in boot_markers:
    if marker not in boot:
        raise SystemExit(f'FAIL skill-runtime-certification-boot:{marker}')

skills_page_refresh_markers = [
    "add_action( 'admin_init', static function () {",
    "current_user_can( 'manage_options' )",
    "'mad4b-control-plane-skills' !== $page",
    "class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' )",
    'MAD4B_SCP_Skill_Runtime_Certification::observe();',
    '}, 110 );',
]
for marker in skills_page_refresh_markers:
    if marker not in main:
        raise SystemExit(f'FAIL skill-runtime-certification-skills-page-refresh:{marker}')

if "add_action( 'admin_init', array( __CLASS__, 'observe' )" in cert:
    raise SystemExit('FAIL skill-runtime-certification-global-admin-observer-restored')

if 'Runtime certification' not in admin or 'MAD4B_SCP_Skill_Runtime_Certification::status()' not in admin:
    raise SystemExit('FAIL skill-runtime-certification-admin-status')

print('skill-runtime-certification-contract: ok')
