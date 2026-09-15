#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
servers = (ROOT / 'includes/class-mad4b-scp-servers.php').read_text('utf-8')
enrollment = (ROOT / 'includes/class-mad4b-scp-site-profile-enrollment.php').read_text('utf-8')
bridge = (ROOT / 'includes/class-mad4b-scp-oauth-resource-bridge.php').read_text('utf-8')
local = (ROOT / 'includes/class-mad4b-scp-local-oauth-server.php').read_text('utf-8')

for marker in (
    "'mad4b-enrollment'",
    "'mad4b/site-profile-status'",
    "'mad4b/site-profile-feature-reenroll'",
    "can_enrollment_transport",
    "MAD4B Enrollment MCP",
):
    if marker not in servers:
        raise SystemExit('missing enrollment server contract: ' + marker)

entry = "'mad4b-enrollment' => array( 'mad4b/site-info', 'mad4b/site-profile-status', 'mad4b/build-provenance-status', 'mad4b/site-profile-feature-reenroll' )"
if entry not in servers:
    raise SystemExit('enrollment inventory is not exact/bounded')
for forbidden in ('mad4b/database-update', 'mad4b/database-raw-query', 'mad4b/filesystem-write', 'mad4b/plugin-activate', 'mad4b/approval-plan'):
    segment = servers[servers.index(entry):servers.index(entry)+len(entry)]
    if forbidden in segment:
        raise SystemExit('dangerous ability leaked into enrollment inventory: ' + forbidden)
if "'mad4b/site-profile-feature-reenroll'" in servers[servers.index('private static function core_write_candidates'):servers.index('private static function registered_adapter_write_candidates')]:
    raise SystemExit('enrollment ability leaked into normal write candidates')

for marker in (
    "expected_revision",
    "expected_profile_digest",
    "expected_source_commit_sha",
    "expected_build_fingerprint",
    "acceptance_enabled",
    "skills_enabled",
    "site_urls_match_enrollment()",
    "current_environment()",
    "chatgpt_app_id()",
    "build_provenance_status()",
    "manifest_present",
    "runtime_manifest_match",
    "provenance_mismatch",
    "mad4b/site-profile-feature-reenrolled",
    "restore_profile",
):
    if marker not in enrollment:
        raise SystemExit('missing bounded reenrollment guard: ' + marker)

registration = enrollment[enrollment.index('public static function register_ability'):enrollment.index('public static function can_access_transport')]
if "'write_enabled'" in registration:
    raise SystemExit('write_enabled must not be accepted by Phase A input schema')
if "remove_filter( 'wp_register_ability_args', $augment" not in registration or "add_filter( 'wp_register_ability_args', $augment" not in registration:
    raise SystemExit('enrollment registration must stay outside normal write augmentation')
for forbidden in ('MAD4B_SCP_Agent_Registry', 'MAD4B_SCP_Approval_Tickets', 'MAD4B_SCP_Staging_Write_Authority::reconcile', 'MAD4B_SCP_Provider_Canary', 'MAD4B_SCP_Provider_Behavioral'):
    if forbidden in enrollment:
        raise SystemExit('Phase A must not bootstrap write/provider authority: ' + forbidden)
if 'egypttourgates.com' in enrollment.lower():
    raise SystemExit('tenant-neutral enrollment runtime hardcodes ETG')

for marker in ("resource_identifier( 'mad4b-enrollment' )", "resource_for_route", "resource_identifiers"):
    if marker not in bridge:
        raise SystemExit('OAuth enrollment resource binding missing: ' + marker)
if "'protected_resources' => self::resource_identifiers()" not in local:
    raise SystemExit('Local OAuth does not advertise both exact protected resources')

print('mad4b.site-profile-feature-reenrollment.contract.v1: PASS')
