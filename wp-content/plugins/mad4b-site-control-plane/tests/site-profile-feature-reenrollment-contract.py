#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
servers = (ROOT / 'includes/class-mad4b-scp-servers.php').read_text('utf-8')
remote_parity = (ROOT / 'includes/class-mad4b-scp-remote-operation-parity.php').read_text('utf-8')
enrollment = (ROOT / 'includes/class-mad4b-scp-site-profile-enrollment.php').read_text('utf-8')
bridge = (ROOT / 'includes/class-mad4b-scp-oauth-resource-bridge.php').read_text('utf-8')
local = (ROOT / 'includes/class-mad4b-scp-local-oauth-server.php').read_text('utf-8')

for marker in (
    "'mad4b-enrollment'",
    "'mad4b/site-profile-status'",
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
    "'mad4b/staging-write-candidate-binding-audit'",
    "can_enrollment_transport",
    "MAD4B Enrollment MCP",
):
    if marker not in servers:
        raise SystemExit('missing enrollment server contract: ' + marker)

remote_enrollment = (
    'mad4b/reconcile-managed-skills',
    'mad4b/frontend-performance-sample-run',
    'mad4b/admin-query-performance-apply',
    'mad4b/remote-operation-work-claim',
    'mad4b/remote-operation-work-complete',
)
for marker in (
    'public static function enrollment_abilities()',
    'self::SKILLS_ABILITY',
    'self::FRONTEND_SAMPLE_ABILITY',
    'self::PERFORMANCE_INDEX_ABILITY',
    'self::WORK_CLAIM_ABILITY',
    'self::WORK_COMPLETE_ABILITY',
):
    if marker not in remote_parity:
        raise SystemExit('remote parity enrollment source-of-truth missing: ' + marker)

enrollment_start = servers.index("'mad4b-enrollment' =>")
enrollment_end = servers.index("'mad4b-content' =>", enrollment_start)
segment = servers[enrollment_start:enrollment_end]
for marker in (
    "'mad4b/site-info'",
    "'mad4b/site-profile-status'",
    "'mad4b/build-provenance-status'",
    "'mad4b/multi-authority-registry-status'",
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
    "'mad4b/staging-write-candidate-binding-audit'",
    "MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities()",
):
    if marker not in segment:
        raise SystemExit('base enrollment inventory is not exact/bounded: ' + marker)
for ability in remote_enrollment:
    if ability in segment:
        raise SystemExit('remote parity ability must be delegated, not duplicated in Servers: ' + ability)
if "MAD4B_SCP_Developer_Authority::enrollment_tools()" not in segment:
    raise SystemExit('Developer authority bootstrap must be projected only through its bounded enrollment inventory')
if "class_exists( 'MAD4B_SCP_Developer_Authority' )" not in segment:
    raise SystemExit('Developer authority enrollment projection must fail closed when the authority class is unavailable')
for forbidden in ('mad4b/database-update', 'mad4b/database-raw-query', 'mad4b/filesystem-write', 'mad4b/plugin-activate', 'mad4b/approval-plan'):
    if forbidden in segment:
        raise SystemExit('dangerous ability leaked into enrollment inventory: ' + forbidden)
write_candidates = servers[servers.index('private static function core_write_candidates'):servers.index('private static function registered_adapter_write_candidates')]
bounded = (
    'mad4b/site-profile-feature-reenroll',
    'mad4b/site-profile-write-enable',
    'mad4b/staging-write-grant-reconcile',
    'mad4b/staging-write-candidate-bind',
) + remote_enrollment
for ability in bounded:
    if ability in write_candidates:
        raise SystemExit('bounded enrollment ability leaked into normal write candidates: ' + ability)

for marker in (
    "expected_revision",
    "expected_profile_digest",
    "expected_source_commit_sha",
    "expected_build_fingerprint",
    "chatgpt_app_id",
    "acceptance_enabled",
    "skills_enabled",
    "site_urls_match_enrollment()",
    "current_environment()",
    "chatgpt_app_id()",
    "build_provenance_status()",
    "manifest_present",
    "runtime_manifest_match",
    "provenance_mismatch",
    "mad4b.site-profile-app-mapping.v1",
    "mad4b/site-profile-app-mapping-bound",
    "mad4b_site_profile_feature_reenroll_mixed_mode_denied",
    "mad4b_site_profile_app_mapping_already_configured",
    "mad4b/site-profile-feature-reenrolled",
    "restore_profile",
):
    if marker not in enrollment:
        raise SystemExit('missing bounded reenrollment/app-mapping guard: ' + marker)

registration = enrollment[enrollment.index('public static function register_ability'):enrollment.index('public static function can_access_transport')]
if "'write_enabled'" in registration:
    raise SystemExit('write_enabled must not be accepted by Phase A enrollment input schema')
if "'chatgpt_app_id'" not in registration:
    raise SystemExit('bounded App ID bootstrap input is not registered')
if "remove_filter( 'wp_register_ability_args', $augment" not in registration or "add_filter( 'wp_register_ability_args', $augment" not in registration:
    raise SystemExit('enrollment registration must stay outside normal write augmentation')
for forbidden in ('MAD4B_SCP_Agent_Registry', 'MAD4B_SCP_Approval_Tickets', 'MAD4B_SCP_Staging_Write_Authority::reconcile', 'MAD4B_SCP_Provider_Canary', 'MAD4B_SCP_Provider_Behavioral'):
    if forbidden in enrollment:
        raise SystemExit('Enrollment bootstrap must not create write/provider authority: ' + forbidden)
if 'egypttourgates.com' in enrollment.lower():
    raise SystemExit('tenant-neutral enrollment runtime hardcodes ETG')

app_mapping = enrollment[enrollment.index('private static function bind_app_mapping'):enrollment.index('private static function restore_profile')]
for forbidden in ("features']['acceptance'] = true", "features']['skills'] = true", "features']['write'] = true", 'MAD4B_SCP_Agent_Registry', 'MAD4B_SCP_Approval_Tickets'):
    if forbidden in app_mapping:
        raise SystemExit('App mapping bootstrap widens authority: ' + forbidden)
for marker in ("$next['chatgpt_app_id'] = $app_id", "previous_profile_digest", "chatgpt_app_id_configured", "acceptance_enabled' => false", "skills_enabled' => false", "write_enabled' => false"):
    if marker not in app_mapping:
        raise SystemExit('App mapping bootstrap preservation/evidence guard missing: ' + marker)

for marker in ("resource_identifier( 'mad4b-enrollment' )", "resource_for_route", "resource_identifiers"):
    if marker not in bridge:
        raise SystemExit('OAuth enrollment resource binding missing: ' + marker)
if "'protected_resources' => self::resource_identifiers()" not in local:
    raise SystemExit('Local OAuth does not advertise both exact protected resources')

print('mad4b.site-profile-feature-reenrollment.contract.v1: PASS')
