#!/usr/bin/env python3
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'
impl = (wp / 'includes' / 'class-mad4b-scp-site-profile-write-enablement.php').read_text(encoding='utf-8')
servers = (wp / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')

required = [
    "const CONTRACT = 'mad4b.site-profile-write-enablement.v1'",
    "const ABILITY = 'mad4b/site-profile-write-enable'",
    "const CONFIRMATION = 'ENABLE GOVERNED STAGING WRITE'",
    "'additionalProperties' => false",
    "'required' => array( 'expected_revision', 'expected_profile_digest', 'expected_source_commit_sha', 'expected_build_fingerprint', 'confirmation' )",
    "self::CONFIRMATION !== (string) $input['confirmation']",
    "'staging' !== MAD4B_SCP_Site_Profile::current_environment()",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()",
    "MAD4B_SCP_Site_Profile::chatgpt_app_id()",
    "MAD4B_SCP_Site_Profile::oauth_enabled()",
    "MAD4B_SCP_Site_Profile::acceptance_enabled()",
    "MAD4B_SCP_Site_Profile::skills_enabled()",
    "MAD4B_SCP_Audit::storage_status()",
    "$next['features']['write'] = true",
    "$next['features']['production_write_confirmed'] = false",
    "$expected_after === $after",
    "mad4b/site-profile-write-enabled",
    "self::restore_profile( $before )",
    "'authority_reconciliation_deferred' => true",
    "'same_invocation_nhi_created' => false",
    "'same_invocation_grants_created' => 0",
    "'same_invocation_mutation_gate_enabled' => false",
]
for marker in required:
    if marker not in impl:
        raise SystemExit(f'missing bounded write-enablement invariant: {marker}')

props_start = impl.index("'properties' => array(")
props_end = impl.index("),\n\t\t\t\t\t'required'", props_start)
props = impl[props_start:props_end]
for name in [
    'expected_revision',
    'expected_profile_digest',
    'expected_source_commit_sha',
    'expected_build_fingerprint',
    'confirmation',
]:
    if props.count("'" + name + "'") != 1:
        raise SystemExit(f'bounded write-enablement input missing or duplicated: {name}')
for forbidden in [
    'write_enabled', 'chatgpt_app_id', 'acceptance_enabled', 'skills_enabled', 'production_write_confirmed',
    'site_uuid', 'display_name', 'oauth_user_ids', 'canonical_origin', 'provider',
    'grants', 'nhi', 'mutation_gate', '_mad4b_approval_ticket_id',
    'from_agent_public_id', 'to_agent_public_id', 'subject_fingerprint', 'agent_public_id',
]:
    if ("'" + forbidden + "'") in props:
        raise SystemExit(f'forbidden generic authority/profile input leaked into bounded schema: {forbidden}')

for forbidden in [
    'MAD4B_SCP_Staging_Write_Authority::bootstrap(',
    'MAD4B_SCP_Agent_Registry::create_agent(',
    'MAD4B_SCP_Agent_Registry::bind_subject(',
    'MAD4B_SCP_Agent_Registry::grant_ability(',
    'MAD4B_SCP_Agent_Registry::disable_agent(',
    "define( 'MAD4B_MCP_MUTATION_ENABLED'",
]:
    if forbidden in impl:
        raise SystemExit(f'generic authority mutation leaked into bounded enablement/repair: {forbidden}')

repair_required = [
    "MAD4B_SCP_Site_Profile::write_enabled() ) return self::repair_subject_handoff(",
    "MAD4B_SCP_Site_Profile::agent_slug()",
    "MAD4B_SCP_Local_OAuth_Server::issuer()",
    "hash( 'sha256', 'oauth' . \"\\0\" . $issuer . \"\\0\" . 'user:'",
    "1 !== count( $subjects )",
    "MAD4B_SCP_Staging_Write_Authority::write_tools()",
    "MAD4B_SCP_Agent_Registry::exact_grant(",
    "mad4b_site_profile_write_repair_target_grants_incomplete",
    "mad4b_site_profile_write_repair_unique_effective_authority",
    "$wpdb->query( 'START TRANSACTION' )",
    "$wpdb->query( 'ROLLBACK' )",
    "$wpdb->query( 'COMMIT' )",
    "MAD4B_SCP_Staging_Write_Authority::reconcile()",
    "mad4b/governed-write-subject-handoff",
    "'legacy_agent_disabled' => false",
    "'legacy_grants_mutated' => false",
    "'atomic_subject_move' => true",
]
for marker in repair_required:
    if marker not in impl:
        raise SystemExit(f'missing exact authority-repair invariant: {marker}')

for marker in [
    "class-mad4b-scp-site-profile-write-enablement.php",
    "MAD4B_SCP_Site_Profile_Write_Enablement::boot()",
    "'mad4b/site-profile-write-enable'",
]:
    if marker not in servers:
        raise SystemExit(f'bounded write-enable tool missing from server wiring: {marker}')

core_write = servers[servers.index('private static function core_write_candidates'):servers.index('private static function registered_adapter_write_candidates')]
if 'mad4b/site-profile-write-enable' in core_write:
    raise SystemExit('bounded Site Profile write enablement leaked into normal governed write candidates')
chatgpt_transport = servers.split('public static function chatgpt_tools()', 1)[1].split('public static function chatgpt_full_catalog_candidates()', 1)[0]
if '$bounded_bootstrap = array(' not in chatgpt_transport:
    raise SystemExit('minimal ChatGPT transport does not declare bounded bootstrap mutations')
for bootstrap_ability in (
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
):
    if bootstrap_ability not in chatgpt_transport:
        raise SystemExit('minimal ChatGPT transport missing bounded bootstrap mutation: ' + bootstrap_ability)
if 'mad4b/staging-write-grant-reconcile' in core_write:
    raise SystemExit('bounded exact grant reconciliation leaked into normal governed write candidates')
if 'mad4b/staging-write-candidate-bind' in core_write:
    raise SystemExit('binding-only candidate bootstrap leaked into normal governed write candidates')

print('mad4b.site-profile-write-enablement.contract.v2: PASS')
