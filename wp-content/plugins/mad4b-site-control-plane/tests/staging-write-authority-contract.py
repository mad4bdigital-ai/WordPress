#!/usr/bin/env python3
from pathlib import Path
import json

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'

write = (wp / 'includes' / 'class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
grant_reconcile = (wp / 'includes' / 'class-mad4b-scp-staging-write-grant-reconciliation.php').read_text(encoding='utf-8')
planning = (wp / 'includes' / 'class-mad4b-scp-staging-write-planning-guard.php').read_text(encoding='utf-8')
cert = (wp / 'includes' / 'class-mad4b-scp-write-runtime-certification.php').read_text(encoding='utf-8')
rest = (wp / 'includes' / 'class-mad4b-scp-rest-compatibility.php').read_text(encoding='utf-8')
servers = (wp / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
transport = (wp / 'includes' / 'class-mad4b-scp-transport-context.php').read_text(encoding='utf-8')
auth = (wp / 'includes' / 'class-mad4b-scp-authorization.php').read_text(encoding='utf-8')
plugin = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
exporter = (wp / 'includes' / 'class-mad4b-scp-skill-exporter.php').read_text(encoding='utf-8')
portable = json.loads((repo / 'plugins' / 'mad4b-wordpress' / 'plugin.json').read_text(encoding='utf-8'))
deployment = json.loads((wp / 'config' / 'staging-deployment-handoff.json').read_text(encoding='utf-8'))

# Runtime write authority is tenant-neutral. ETG binding belongs to the reviewed
# deployment Site Profile/handoff, never to a host constant inside the authority.
for marker in [
    "const CONTRACT = 'mad4b.governed-write-authority.v2'",
    "const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id'",
    "define( 'MAD4B_MCP_MUTATION_ENABLED', true )",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::write_enabled()",
    "MAD4B_SCP_Site_Profile::agent_slug()",
    "site_profile_unconfigured",
    "site_profile_write_disabled",
    "'production_auto_enable' => false",
    "'breakglass_auto_enable' => false",
    "'all_remote_writes_require_exact_approval' => true",
    "MAD4B_SCP_Agent_Registry::grant_ability",
    "'mad4b-write'",
    "remote_scope_delegation_allowed",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()",
    "mad4b:read",
    "approval_ticket_from_input",
]:
    if marker not in write:
        raise SystemExit(f'missing tenant-bound governed write invariant: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.staging-write-grant-reconciliation.v1'",
    "const ABILITY = 'mad4b/staging-write-grant-reconcile'",
    "const CONFIRMATION = 'RECONCILE EXACT STAGING WRITE GRANTS'",
    "'jetengine/create-cct'",
    "'jetengine/create-cpt'",
    "'jetengine/create-glossary'",
    "'jetengine/create-listing'",
    "'jetengine/create-meta-box'",
    "'jetengine/create-query'",
    "'jetengine/create-taxonomy'",
    "'jetengine/manage-modules'",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()",
    "MAD4B_SCP_Identity_Context::current()",
    "MAD4B_SCP_Agent_Registry::resolve_agent",
    "MAD4B_SCP_Agent_Registry::grant_ability",
    "MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id",
    "MAD4B_SCP_Staging_Write_Authority::write_tools()",
    "MAD4B_SCP_Staging_Write_Authority::reconcile()",
    "expected_write_inventory_fingerprint",
    "expected_missing_abilities",
    "expected_agent_public_id",
    "Breakglass/raw SQL must never enter governed grant reconciliation",
    "'native-provider'",
    "'staging'",
    "'production_mutation' => false",
    "'breakglass_included' => false",
    "mad4b/staging-write-grant-reconciliation-authorized",
    "mad4b/exact-staging-write-grant-reconciled",
]:
    if marker not in grant_reconcile:
        raise SystemExit(f'missing bounded exact grant-reconciliation invariant: {marker}')

allowlist = grant_reconcile.split('public static function allowed_abilities()', 1)[1].split('public static function boot()', 1)[0]
for forbidden_grant in [
    "'jetengine/import-configuration'",
    "'jetengine/export-configuration'",
    "'mad4b/database-raw-query'",
]:
    if forbidden_grant in allowlist:
        raise SystemExit(f'forbidden ability leaked into exact grant-reconciliation allowlist: {forbidden_grant}')

if "'mad4b/staging-write-grant-reconcile'" not in servers:
    raise SystemExit('bounded grant reconciliation is missing from enrollment server catalog')
if "$bounded_bootstrap = array( 'mad4b/site-profile-feature-reenroll', 'mad4b/site-profile-write-enable', 'mad4b/staging-write-grant-reconcile' );" not in servers:
    raise SystemExit('bounded grant reconciliation is not projected into the unified ChatGPT catalog')
core_write = servers[servers.index('private static function core_write_candidates()'):servers.index('private static function registered_adapter_write_candidates()')]
if "'mad4b/staging-write-grant-reconcile'" in core_write:
    raise SystemExit('grant reconciliation must not become a normal mad4b-write candidate')

for forbidden in [
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    "const AGENT_SLUG = 'chatgpt-staging-write'",
    "staging.egypttourgates.com",
    "egypttourgates.com",
]:
    if forbidden in write:
        raise SystemExit(f'tenant-neutral write authority retained ETG/runtime host coupling: {forbidden}')

if "'mad4b/database-raw-query'" not in write or "array_diff( $tools, array( 'mad4b/database-raw-query' ) )" not in write:
    raise SystemExit('write authority must explicitly remove breakglass raw-query')

for ability in [
    'mad4b/content-update-post',
    'mad4b/plugin-activate',
    'mad4b/plugin-deactivate',
    'mad4b/filesystem-write',
    'mad4b/filesystem-patch',
    'mad4b/database-update',
    'mad4b/mutation-undo',
    'mad4b/approval-plan',
]:
    if ability not in servers:
        raise SystemExit(f'known core update/write/mutation action missing from server catalog: {ability}')

for marker in [
    "false !== $annotations['readonly']",
    "$registry->ability_names( 'content' )",
    "$registry->ability_names( 'admin' )",
    "array_merge( $tools, self::write_tools() )",
    "return self::provider_for_ability( 'mad4b-write', $ability_name )",
    "'mad4b/write-authority-status'",
    "'mad4b/write-runtime-certification'",
    "'mad4b/rest-compatibility-status'",
]:
    if marker not in servers:
        raise SystemExit(f'missing complete write inventory/same-Plugin projection invariant: {marker}')

for marker in [
    "MAD4B_SCP_Staging_Write_Authority::is_write_ability( $ability_name )",
    "return 'mad4b-write'",
    "mad4b_write_authority_mount_missing",
]:
    if marker not in transport:
        raise SystemExit(f'missing transport-to-write-authority binding: {marker}')

for marker in [
    "MAD4B_SCP_Staging_Write_Authority::authorization_input( $input )",
    "MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input )",
    "MAD4B_SCP_Staging_Write_Authority::remote_scope_delegation_allowed",
    "MAD4B_SCP_Approval_Tickets::authorize_exact( $approval_ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $authorization_input, $ticket_class )",
    "public static function claim_mutation",
    "MAD4B_SCP_Budgets::reserve(",
    "MAD4B_SCP_Approval_Tickets::claim_exact",
    "MAD4B_SCP_Approval_Tickets::finalize_claim",
    "public static function wrap_execution_boundary",
    "'approval_ticket_source'",
]:
    if marker not in auth:
        raise SystemExit(f'missing central write authorization binding: {marker}')

preflight = auth[auth.index('public static function authorize_mutation'):auth.index('public static function claim_mutation')]
for forbidden in [
    'MAD4B_SCP_Budgets::reserve',
    'MAD4B_SCP_Budgets::commit',
    'MAD4B_SCP_Approval_Tickets::claim_exact',
    'MAD4B_SCP_Approval_Tickets::consume_exact',
    'MAD4B_SCP_Approval_Tickets::finalize_claim',
]:
    if forbidden in preflight:
        raise SystemExit(f'permission preflight must remain non-consuming/read-only: {forbidden}')
claim = auth[auth.index('public static function claim_mutation'):auth.index('public static function wrap_execution_boundary')]
if claim.index('MAD4B_SCP_Budgets::reserve') > claim.index('MAD4B_SCP_Approval_Tickets::claim_exact'):
    raise SystemExit('budget reservation must precede atomic approval claim')
if claim.index('MAD4B_SCP_Approval_Tickets::claim_exact') > claim.index('MAD4B_SCP_Budgets::commit'):
    raise SystemExit('approval claim must precede budget commit')

for marker in [
    "const CONTRACT = 'mad4b.staging-write-planning-guard.v2'",
    "const ABILITY = 'mad4b/approval-plan'",
    "MAD4B_SCP_Authorization::authorize_mutation( self::ABILITY, 'mad4b-admin', 'core', $input )",
    "'ability:' . self::ABILITY",
    "planner_approval_exception",
    "validate_remote_plan_input",
    "mad4b_remote_plan_agent_mismatch",
    "'mad4b-write' !== $server_id",
    "'mad4b/database-raw-query' === $target_ability",
    "MAD4B_SCP_Staging_Write_Authority::is_write_ability( $target_ability )",
    "MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $target_ability )",
    "'mutation' !== MAD4B_SCP_Impact_Policy::ticket_class_for",
    "'remote_planner_requires_prior_ticket' => false",
    "'creates_pending_ticket_only' => true",
    "'auto_approves' => false",
]:
    if marker not in planning:
        raise SystemExit(f'missing approval planner bootstrap guard: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.rest-compatibility.v2'",
    "const WPML_ROUTE = '/wpml/v1/rest/status'",
    "apply_filters( 'rest_enabled', true )",
    "rest_do_request( $request )",
    "'test_get_parameter' => '1'",
    "'query_parameters_preserved'",
    "hook_inventory( 'rest_enabled' )",
    "hook_inventory( 'rest_authentication_errors' )",
    "'control_plane_detected'",
    "ReflectionMethod",
    "ReflectionFunction",
    "wpml_route_missing",
    "scope_mcp_recovery_to_current_http_request",
    "current_http_request_targets_mad4b_mcp",
    "'/mcp/mad4b-read'",
    "'/mcp/mad4b-chatgpt'",
    "'/mcp/mad4b-content'",
    "'/mcp/mad4b-write'",
    "'/mcp/mad4b-admin'",
    "'/mcp/mad4b-breakglass'",
    "array( 'MAD4B_SCP_MCP_Registration_Bridge', 'verify_adapter_init_after_rest' )",
    "array( 'MAD4B_SCP_MCP_Registration_Rescue', 'after_rest_init' )",
    "array( 'MAD4B_SCP_MCP_Registration_Rescue', 'before_rest_dispatch' )",
    "array( 'MAD4B_SCP_MCP_Registration_Bridge', 'recover_missed_rest_lifecycle' )",
    "mcp_recovery_callbacks_removed_for_unrelated_request",
]:
    if marker not in rest:
        raise SystemExit(f'missing WPML/general REST compatibility evidence invariant: {marker}')

for forbidden in [
    "add_filter( 'rest_enabled'",
    "add_filter( 'rest_authentication_errors'",
    "remove_all_filters( 'rest_",
]:
    if forbidden in rest:
        raise SystemExit(f'REST compatibility layer may not globally modify WordPress REST behavior: {forbidden}')

for marker in [
    "const CONTRACT = 'mad4b.write-runtime-certification.v2'",
    "add_action( 'admin_init', array( __CLASS__, 'observe' ), 110 )",
    "'execute_callback' => array( __CLASS__, 'status' )",
    "doing_action( 'rest_api_init' )",
    "MAD4B_SCP_Live_Truth::current_authority_status()",
    "MAD4B_SCP_Staging_Write_Authority::eligible()",
    "return self::ineligible_status()",
    "'persistence' => 'not_applicable'",
    "all_write_tools_exposed_on_same_plugin_transport",
    "breakglass_absent_from_write_inventory",
    "approval_planner_governed",
    "approval_planner_no_prior_ticket",
    "approval_planner_self_agent_only",
    "approval_planner_breakglass_denied",
    "control_plane_not_on_rest_enabled_hook",
    "control_plane_not_on_rest_authentication_hook",
    "control_plane_does_not_block_wpml_rest",
    "mcp_recovery_scope_evaluated",
    "mcp_recovery_scoped_to_mad4b_routes",
    "external_wpml_acceptance_not_claimed_locally",
    "external_wpml_acceptance_required",
    "external_wpml_acceptance_verified",
    "external_wpml_test_url",
    "exact_approval_required_for_remote_write",
    "approval_planner_bootstrap_exception",
    "external_client_tools_verified",
    "MAD4B_SCP_Audit::record",
]:
    if marker not in cert:
        raise SystemExit(f'missing write certification invariant: {marker}')

for forbidden in [
    "$checks['wpml_query_parameters_preserved']",
    "$checks['wpml_internal_probe_ready_or_not_active']",
]:
    if forbidden in cert:
        raise SystemExit(f'write certification re-coupled to in-process WPML route state: {forbidden}')

if "add_action( 'wp_abilities_api_init', array( __CLASS__, 'observe' )" in cert:
    raise SystemExit('write certification must not inspect REST before MCP transports are constructed')
if "add_action( 'mcp_adapter_init', array( __CLASS__, 'observe' )" in cert:
    raise SystemExit('write certification must not freeze provider runtime eligibility before REST registration completes')
if "MAD4B_SCP_Staging_Write_Authority::reconcile()" in cert:
    raise SystemExit('readonly write-runtime certification may not reconcile grants or subjects')
bootstrap_body = write.split("public static function bootstrap()", 1)[1].split("public static function boot()", 1)[0]
if "restore_persisted_ready_status( $status )" not in bootstrap_body:
    raise SystemExit('write authority bootstrap must restore the exact persisted ready authority on a new request')
if "MAD4B_SCP_Live_Truth" in bootstrap_body:
    raise SystemExit('write authority bootstrap may not rebuild Live Truth while restoring persisted readiness')
restore_body = write.split("private static function restore_persisted_ready_status", 1)[1].split("public static function boot()", 1)[0]
for marker in [
    "'site_uuid'",
    "'site_profile_revision'",
    "'site_profile_digest'",
    "'environment'",
    "'origin'",
    "'write_inventory_fingerprint'",
    "'restored_from_persisted_authority'",
]:
    if marker not in restore_body:
        raise SystemExit(f'persisted authority restore missing exact binding guard: {marker}')

if "add_action( 'wp_abilities_api_init', array( __CLASS__, 'reconcile' )" in write:
    raise SystemExit('write authority may not reconcile automatically during Abilities bootstrap')
if "add_action( 'admin_init', array( __CLASS__, 'reconcile' )" in write:
    raise SystemExit('write authority may not reconcile automatically during admin_init')
if "MAD4B_SCP_Staging_Write_Authority::reconcile();" in plugin:
    raise SystemExit('plugin lifecycle/admin read paths may not call destructive authority reconciliation')
if "MAD4B_SCP_Live_Truth::current_authority_status()" in write.split("public static function effective()", 1)[1].split("public static function status()", 1)[0]:
    raise SystemExit('authority effective() hot path may not recursively rebuild Live Truth/write inventory')
if "add_action( 'rest_api_init', array( __CLASS__, 'observe' )" in cert:
    raise SystemExit('write certification must not run authority reconciliation on the REST/tools-list critical path')
if cert.index("MAD4B_SCP_Staging_Write_Authority::eligible()") > cert.index("MAD4B_SCP_Audit::record"):
    raise SystemExit('write certification eligibility must be checked before any audit persistence')
if cert.index("MAD4B_SCP_Staging_Write_Authority::eligible()") > cert.index("update_option( self::OPTION"):
    raise SystemExit('write certification eligibility must be checked before option persistence')

for marker in [
    'class-mad4b-scp-staging-write-authority.php',
    'class-mad4b-scp-staging-write-planning-guard.php',
    'class-mad4b-scp-rest-compatibility.php',
    'class-mad4b-scp-write-runtime-certification.php',
    'MAD4B_SCP_Staging_Write_Authority::bootstrap()',
]:
    if marker not in main:
        raise SystemExit(f'main plugin missing write/REST component: {marker}')

for marker in [
    'MAD4B_SCP_Staging_Write_Authority::boot()',
    'MAD4B_SCP_Staging_Write_Planning_Guard::boot()',
    'MAD4B_SCP_REST_Compatibility::boot()',
    'MAD4B_SCP_Write_Runtime_Certification::boot()',
]:
    if marker not in plugin:
        raise SystemExit(f'plugin lifecycle missing write/REST boot: {marker}')

for marker in [
    "array( 'Read', 'Write' )",
    "'governed_write_ready'",
    "'write_certification_ready'",
]:
    if marker not in exporter:
        raise SystemExit(f'runtime portable export missing governed Write state: {marker}')

caps = portable['extensions']['com.openai']['interface'].get('capabilities', [])
if caps != ['Read', 'Write']:
    raise SystemExit(f'portable Plugin capability contract must be [Read, Write], got {caps!r}')

if deployment.get('contract') != 'mad4b.wordpress-deployment-handoff.v2':
    raise SystemExit('deployment handoff v2 contract id mismatch')
producer = deployment.get('producer', {})
expected_producer = {
    'repository': 'mad4bdigital-ai/WordPress',
    'workflow': '.github/workflows/mad4b-control-plane-package.yml',
    'artifact_name_template': 'mad4b-site-control-plane-general-distribution-kit-{exact_head_sha}',
    'install_manifest': 'install-manifest.json',
    'source_binding': 'exact_head_sha',
}
if producer != expected_producer:
    raise SystemExit(f'deployment producer contract drift: {producer!r}')

target = deployment.get('target', {})
if target.get('binding') != 'explicit_site_profile':
    raise SystemExit('deployment target must bind through explicit Site Profile authority')
if target.get('environment_source') != 'site_profile.environment' or target.get('origin_source') != 'site_profile.canonical_origin' or target.get('site_uuid_source') != 'site_profile.site_uuid':
    raise SystemExit('deployment target identity must derive from Site Profile v2')
if set(target.get('supported_environments', [])) != {'local', 'development', 'staging', 'production'}:
    raise SystemExit('deployment handoff supported environment set drift')
if any(key in target for key in ('environment', 'origin', 'host')):
    raise SystemExit('generic deployment handoff must not embed a tenant environment/origin/host')
if target.get('plugin_slug') != 'mad4b-site-control-plane':
    raise SystemExit('deployment handoff plugin slug mismatch')
if target.get('mcp_adapter') != {
    'slug': 'mcp-adapter',
    'required_version': '0.6.1',
    'deployment_mode': 'require_exact_preinstalled_or_verified_bundled',
}:
    raise SystemExit('deployment handoff MCP Adapter dependency contract drift')

executor = deployment.get('executor', {})
expected_executor = {
    'authority': 'deployment_connector_selected_by_site_owner',
    'operation': 'wordpress_plugin_deploy',
    'dry_run_default': True,
    'human_approval_required_for_apply': True,
    'exact_capability_envelope_required': True,
    'exact_target_allowlist_required': True,
    'caller_supplied_credentials_allowed': False,
}
if executor != expected_executor:
    raise SystemExit(f'deployment executor contract drift: {executor!r}')

preflight = deployment.get('preflight', {})
if preflight.get('must_precede_first_write') is not True:
    raise SystemExit('deployment live preflight must precede first write')
expected_preflight = {
    'target_site_profile_is_configured',
    'live_environment_matches_site_profile',
    'live_home_url_matches_site_profile_canonical_origin',
    'live_site_url_matches_site_profile_canonical_origin',
    'artifact_run_completed_successfully',
    'artifact_exact_head_sha_matches_request',
    'install_manifest_contract_is_valid',
    'install_manifest_commit_matches_exact_head_sha',
    'control_plane_archive_sha256_matches_manifest',
    'mcp_adapter_dependency_is_exact_or_verified',
}
if set(preflight.get('required', [])) != expected_preflight:
    raise SystemExit(f'deployment preflight contract drift: {preflight.get("required", [])!r}')

apply_contract = deployment.get('apply', {})
if apply_contract.get('source') != 'verified_control_plane_archive_from_exact_artifact':
    raise SystemExit('deployment apply source must be exact-artifact verified archive')
for key in (
    'backup_before_replace', 'atomic_replace_required', 'activate_after_replace',
    'same_cycle_readback_required', 'rollback_on_failed_readback',
    'rollback_restores_previous_plugin_files', 'production_requires_separate_explicit_authority',
):
    if apply_contract.get(key) is not True:
        raise SystemExit(f'deployment apply safety must remain enabled: {key}')

post_deploy = deployment.get('post_deploy', {})
expected_acceptance = {
    'exact_control_plane_version_and_build_readback',
    'site_profile_identity_exact',
    'enabled_feature_reconciliation',
    'provider_blocked_write_tools_safely_unmounted',
    'external_tool_inventory_match_when_mcp_enabled',
    'one_time_approval_replay_denial_and_undo_when_write_enabled',
}
if set(post_deploy.get('required_live_acceptance', [])) != expected_acceptance:
    raise SystemExit('deployment post-deploy acceptance contract drift')

forbidden_contract = deployment.get('forbidden', {})
expected_forbidden = {
    'unenrolled_target', 'implicit_production_write', 'breakglass',
    'raw_sql_side_channel', 'caller_supplied_credentials',
    'merge_or_ready_before_required_acceptance',
}
if set(forbidden_contract) != expected_forbidden or not all(forbidden_contract.get(key) is True for key in expected_forbidden):
    raise SystemExit(f'deployment forbidden-path contract drift: {forbidden_contract!r}')
if deployment.get('secrets_included') is not False:
    raise SystemExit('deployment handoff must never contain secrets')

print('mad4b.staging-write-authority.tenant-profile.v9: PASS')