#!/usr/bin/env python3
from pathlib import Path
import json

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'

write = (wp / 'includes' / 'class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
grant_reconcile = (wp / 'includes' / 'class-mad4b-scp-staging-write-grant-reconciliation.php').read_text(encoding='utf-8')
planning = (wp / 'includes' / 'class-mad4b-scp-staging-write-planning-guard.php').read_text(encoding='utf-8')
cert = (wp / 'includes' / 'class-mad4b-scp-write-runtime-certification.php').read_text(encoding='utf-8')
live_truth = (wp / 'includes' / 'class-mad4b-scp-live-truth.php').read_text(encoding='utf-8')
rest = (wp / 'includes' / 'class-mad4b-scp-rest-compatibility.php').read_text(encoding='utf-8')
servers = (wp / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
transport = (wp / 'includes' / 'class-mad4b-scp-transport-context.php').read_text(encoding='utf-8')
auth = (wp / 'includes' / 'class-mad4b-scp-authorization.php').read_text(encoding='utf-8')
plugin = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
exporter = (wp / 'includes' / 'class-mad4b-scp-skill-exporter.php').read_text(encoding='utf-8')
portable = json.loads((repo / 'plugins' / 'mad4b-wordpress' / 'plugin.json').read_text(encoding='utf-8'))
deployment = json.loads((wp / 'config' / 'staging-deployment-handoff.json').read_text(encoding='utf-8'))

# A previous stacked patch accidentally appended a second authority implementation
# after the class closing brace. Lock the file to one canonical lifecycle.
for signature in [
    'public static function augment_write_ability',
    'public static function reconcile()',
    'private static function base_status()',
]:
    if write.count(signature) != 1:
        raise SystemExit(f'write authority implementation duplicated or missing: {signature}')
plan_body = write.split("public static function reconciliation_plan", 1)[1].split("public static function reconcile", 1)[0]
if "MAD4B_SCP_Agent_Registry::exact_grant(" in plan_body:
    raise SystemExit("read-only reconciliation plan regressed to N+1 exact_grant lookups")
if plan_body.count("MAD4B_SCP_Agent_Registry::grants_for_agent") != 1:
    raise SystemExit("read-only reconciliation plan must use one bulk agent grant snapshot")
reconcile_body = write.split("public static function reconcile()", 1)[1]
for marker_text in ["$seen_current_exact_allow", "$duplicate_grants_revoked", ":duplicate_grant"]:
    if marker_text not in reconcile_body:
        raise SystemExit("explicit reconcile lost deterministic duplicate-grant cleanup: " + marker_text)

if not write.rstrip().endswith('}'):
    raise SystemExit('write authority file must end at the canonical class closing brace')

# Runtime write authority is tenant-neutral. ETG binding belongs to the reviewed
# deployment Site Profile/handoff, never to a host constant inside the authority.
for marker in [
    "const CONTRACT = 'mad4b.governed-write-authority.v2'",
    "const CANDIDATE_BINDING_CONTRACT = 'mad4b.governed-write-authority-candidate-binding.v2'",
    "const CANDIDATE_BOOTSTRAP_CONTRACT = 'mad4b.governed-write-candidate-bootstrap.v1'",
    "const CANDIDATE_BOOTSTRAP_ABILITY = 'mad4b/acceptance-target-provision'",
    "public static function candidate_bootstrap_status( $ability_name, $input = null )",
    "public static function candidate_bootstrap_allowed( $ability_name, $input = null )",
    "const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id'",
    "public static function candidate_binding_status()",
    "mad4b.governed-write-authority-reconciliation-plan.v2",
    "MAD4B_SCP_Agent_Registry::grants_for_agent( (int) $agent['id'], 'mad4b-write' )",
    "'grant_lookup_strategy' => 'bulk_agent_grant_snapshot'",
    "'duplicate_exact_allow_grants_count'",
    "'duplicate_retention_policy' => 'lowest_grant_id'",
    "'current_agent_wildcard_grants'",
    "'global_registry_wildcard_grants'",
    "'broad_environment_grants_count'",
    "'duplicate_exact_allow_grants_revoked'",
    "public static function bind_candidate_identity( $source_commit_sha, $build_fingerprint, $context = array() )",
    "private static function current_candidate_identity()",
    "define( 'MAD4B_MCP_MUTATION_ENABLED', true )",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::write_enabled()",
    "MAD4B_SCP_Site_Profile::agent_slug()",
    "site_profile_unconfigured",
    "site_profile_write_disabled",
    "'production_auto_enable' => false",
    "'breakglass_auto_enable' => false",
    "'all_remote_writes_require_exact_approval' => false",
    "'normal_remote_writes_require_exact_approval' => true",
    "exact_approval_with_bounded_standing_exceptions",
    "'remote_write_prior_approval_exceptions' => array( self::CANDIDATE_BOOTSTRAP_ABILITY, $ai_ability )",
    "public static function approval_policy_projection( $candidate_bootstrap_exception_active = null )",
    "'approval_policy_contract' => 'mad4b.remote-write-approval-policy.v2'",
    "'approval_policy_scope' => $resolved ? 'effective_runtime' : 'capability_definition'",
    "'approval_policy_effective_state_resolved' => $resolved",
    "'candidate_bootstrap_exception_defined' => true",
    "'ai_review_standing_delegation_defined' => true",
    "'ai_review_standing_delegation_contract'",
    "'ai_review_standing_delegation_configured'",
    "'normal_write_one_time_exact_approval_or_bounded_ai_review_delegation'",
    "public static function ai_review_delegation_status( $ability_name, $input = null, $identity = null )",
    "public static function ai_review_delegation_allowed( $ability_name, $input = null, $identity = null )",
    "'mad4b.context-ai-review-standing-delegation.v1'",
    "'governance_metadata_mutation_allowed' => false",
    "'production_authorized' => false",
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
    "const CONTRACT = 'mad4b.staging-write-grant-reconciliation.v2'",
    "const ABILITY = 'mad4b/staging-write-grant-reconcile'",
    "const CONFIRMATION = 'RECONCILE EXACT STAGING WRITE AUTHORITY'",
    "'jetengine/create-cct'",
    "'jetengine/create-cpt'",
    "'jetengine/create-glossary'",
    "'jetengine/create-listing'",
    "'jetengine/create-meta-box'",
    "'jetengine/create-query'",
    "'jetengine/create-taxonomy'",
    "'jetengine/manage-modules'",
    "'elementor/clone-subtree' => 'elementor'",
    "'elementor/move-element' => 'elementor'",
    "'elementor/delete-element' => 'elementor'",
    "'elementor/set-dynamic-tag' => 'elementor'",
    "'elementor/set-etg-dynamic-tag' => 'elementor'",
    "'context/update-drive-asset' => 'google_drive_context'",
    "'context/recreate-drive-asset' => 'google_drive_context'",
    "'context/brand-draft-append' => 'google_drive_context'",
    "'context/materialize-brand-draft' => 'google_drive_context'",
    "'context/reconcile-brand-materialization' => 'google_drive_context'",
    "'context/rollback-materialized-brand-draft' => 'google_drive_context'",
    "'context/source-scan-apply' => 'google_drive_context'",
    "'context/brand-draft-append' => 'google_drive_context'",
    "'context/materialize-brand-draft' => 'google_drive_context'",
    "'context/reconcile-brand-materialization' => 'google_drive_context'",
    "'context/rollback-materialized-brand-draft' => 'google_drive_context'",
    "'context/source-scan-apply' => 'google_drive_context'",
    "'mad4b/plugin-package-apply' => 'core'",
    "'mad4b/context-ai-review' => 'core'",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()",
    "MAD4B_SCP_Identity_Context::current()",
    "MAD4B_SCP_Agent_Registry::resolve_agent",
    "MAD4B_SCP_Agent_Registry::grant_ability",
    "MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id",
    "MAD4B_SCP_Staging_Write_Authority::write_tools()",
    "MAD4B_SCP_Staging_Write_Authority::finalize_exact_existing_authority()",
    "MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_fingerprint, $binding_context )",
    "candidate_rebound_without_grant_changes",
    "MAD4B_SCP_Staging_Write_Candidate_Binding::operation_context(",
    "'grant_reconciliation'",
    "self::CONTRACT",
    "expected_plan_sha256",
    "expected_write_inventory_fingerprint",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan()",
    "mad4b_grant_reconcile_plan_changed",
    "MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint()",
    "rollback_transaction",
    "mad4b/staging-write-authority-prepared",
    "candidate_binding_is_commit_point",
    "'post_commit_governance_mutation' => false",
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

allowlist = grant_reconcile.split('public static function allowed_ability_providers()', 1)[1].split('public static function boot()', 1)[0]
for required_pair in [
    "'jetengine/create-cpt' => 'native-provider'",
    "'elementor/clone-subtree' => 'elementor'",
    "'elementor/move-element' => 'elementor'",
    "'elementor/delete-element' => 'elementor'",
    "'elementor/set-dynamic-tag' => 'elementor'",
    "'elementor/set-etg-dynamic-tag' => 'elementor'",
    "'context/update-drive-asset' => 'google_drive_context'",
    "'context/recreate-drive-asset' => 'google_drive_context'",
]:
    if required_pair not in allowlist:
        raise SystemExit(f'exact grant-reconciliation provider pair missing: {required_pair}')

for forbidden_grant in [
    "'jetengine/import-configuration'",
    "'jetengine/export-configuration'",
    "'context/create-drive-asset'",
    "'mad4b/database-raw-query'",
]:
    if forbidden_grant in allowlist:
        raise SystemExit(f'forbidden ability leaked into exact grant-reconciliation allowlist: {forbidden_grant}')

if "'mad4b/staging-write-grant-reconcile'" not in servers:
    raise SystemExit('bounded grant reconciliation is missing from the internal enrollment server catalog')
chatgpt_transport = servers.split('public static function chatgpt_tools()', 1)[1].split('private static function chatgpt_internal_enrollment_mutations()', 1)[0]
if "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" not in chatgpt_transport:
    raise SystemExit('bounded Staging Write Authority plan projection is missing')
if "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()" not in chatgpt_transport:
    raise SystemExit('bounded Staging Write Authority step-up projection is missing')
if "MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" not in chatgpt_transport:
    raise SystemExit('single-app Full Staging Authority step-up projection is missing')
if "$step_up = array_merge( $narrow_step_up, $full_step_up )" not in chatgpt_transport:
    raise SystemExit('bounded and full authority step-ups must be composed explicitly')
if "$direct_mutation_transport = array_merge( array( 'mad4b/write-execute' ), $step_up, $enrollment_step_up )" not in chatgpt_transport:
    raise SystemExit('direct ChatGPT mutation transport must be limited to write-execute, the composed guarded authority step-ups, and bounded enrollment step-up')
if "MAD4B_SCP_Enrollment_Dispatch::EXECUTE_ABILITY" not in chatgpt_transport:
    raise SystemExit('bounded enrollment step-up dispatcher is missing from direct ChatGPT transport')
for bootstrap_ability in (
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-candidate-bind'",
):
    if bootstrap_ability in chatgpt_transport:
        raise SystemExit('low-level enrollment mutation leaked into minimal ChatGPT transport: ' + bootstrap_ability)
internal_enrollment = servers.split('private static function chatgpt_internal_enrollment_mutations()', 1)[1].split('private static function chatgpt_enrollment_candidates()', 1)[0]
for bootstrap_ability in (
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
):
    if bootstrap_ability not in internal_enrollment:
        raise SystemExit('internal enrollment primitive was lost: ' + bootstrap_ability)
core_write = servers[servers.index('private static function core_write_candidates()'):servers.index('private static function registered_adapter_write_candidates()')]
if "'mad4b/staging-write-grant-reconcile'" in core_write:
    raise SystemExit('grant reconciliation must not become a normal mad4b-write candidate')
if "'mad4b/staging-write-candidate-bind'" in core_write:
    raise SystemExit('candidate binding bootstrap must not become a normal mad4b-write candidate')

if "'mad4b/enrollment-execute'" in core_write:
    raise SystemExit('bounded enrollment dispatcher must not become a normal mad4b-write candidate')
if "'mad4b/reconcile-managed-skills'" in core_write:
    raise SystemExit('managed Skills reconciliation must remain outside normal mad4b-write authority')


# A package transition may expose one bootstrap write before the persisted candidate
# binding is refreshed. Lock the exception to the isolated acceptance target and
# require all normal authorization layers to remain downstream.
bootstrap_body = write.split("public static function candidate_bootstrap_status", 1)[1].split("public static function candidate_bootstrap_allowed", 1)[0]
for marker in [
    "self::CANDIDATE_BOOTSTRAP_ABILITY !== $ability_name",
    "'staging' !== MAD4B_SCP_Site_Profile::current_environment()",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "MAD4B_SCP_Site_Profile::write_enabled()",
    "MAD4B_MCP_MUTATION_ENABLED",
    "'candidate_already_bound'",
    "'wildcard_grants_detected'",
    "'breakglass_enabled'",
    "'acceptance_target_already_exists'",
    "'acceptance_target_not_safe'",
    "'acceptance_target_not_isolated'",
    "'exact_nhi_grant_required_downstream' => true",
    "'budget_required_downstream' => true",
    "'audit_required_downstream' => true",
    "'prior_approval_required' => false",
]:
    if marker not in bootstrap_body:
        raise SystemExit(f'candidate bootstrap guard missing bounded invariant: {marker}')
for forbidden in [
    "grant_ability(",
    "bind_candidate_identity(",
    "update_option(",
    "wp_insert_post(",
]:
    if forbidden in bootstrap_body:
        raise SystemExit(f'candidate bootstrap guard must remain read-only: {forbidden}')

scope_body = write.split("public static function remote_scope_delegation_allowed", 1)[1].split("public static function force_remote_write_approval", 1)[0]
if "if ( $bootstrap ) return true;" not in scope_body:
    raise SystemExit('candidate bootstrap must have one explicit no-prior-ticket scope delegation branch')
if "mad4b:read" not in scope_body or "oauth2_bearer" not in scope_body:
    raise SystemExit('candidate bootstrap scope delegation must retain verified OAuth read identity')

if "if ( self::ai_review_delegation_allowed( $ability_name, $input, $identity ) ) return true;" not in scope_body:
    raise SystemExit('AI review standing delegation must have one exact request-time scope branch')

ai_status_body = write.split("public static function ai_review_delegation_status", 1)[1].split("public static function ai_review_delegation_allowed", 1)[0]
for marker in [
    "'human_and_ai'",
    "'ai_review_staging_only'",
    "'ai_review_approval_ticket_not_allowed'",
    "'ai_review_exact_input_required'",
    "'ai_review_governance_mutation_forbidden'",
    "'ai_review_agent_not_profile_owned'",
    "MAD4B_SCP_Site_Profile::agent_slug()",
    "MAD4B_SCP_Agent_Registry::resolve_agent",
    "MAD4B_SCP_Agent_Registry::exact_grant",
    "'mad4b-write'",
    "'core'",
    "'exact_nhi_grant_required' => true",
    "'candidate_binding_required' => true",
    "'budget_required' => true",
    "'audit_required' => true",
    "'governance_metadata_mutation_allowed' => false",
    "'production_authorized' => false",
]:
    if marker not in ai_status_body:
        raise SystemExit(f'AI review standing delegation missing invariant: {marker}')
for forbidden in ["grant_ability(", "reconcile()", "bind_candidate_identity("]:
    if forbidden in ai_status_body:
        raise SystemExit(f'AI review standing delegation must remain non-provisioning: {forbidden}')

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

if "'mad4b/context-ai-review'" not in servers or "MAD4B_SCP_Context_Authority::ai_review_catalog_eligible()" not in servers:
    raise SystemExit('AI review must be stable in catalog and policy-gated in runtime write inventory')
if "array_diff( $candidates, array( 'mad4b/context-ai-review' ) )" not in servers:
    raise SystemExit('AI review runtime mount must fail closed until explicit delegation')

for marker in [
    "false !== $annotations['readonly']",
    "$registry->ability_names( 'content' )",
    "$registry->ability_names( 'admin' )",
    "public static function external_write_tools()",
    "public static function chatgpt_full_catalog_candidates()",
    "$step_up = array_merge( $narrow_step_up, $full_step_up )",
    "$direct_mutation_transport = array_merge( array( 'mad4b/write-execute' ), $step_up, $enrollment_step_up )",
    "MAD4B_SCP_Enrollment_Dispatch::EXECUTE_ABILITY",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()",
    "MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()",
    "self::provider_for_ability( 'mad4b-write', $ability_name )",
    "'mad4b/write-authority-status'",
    "'mad4b/write-runtime-certification'",
    "'mad4b/rest-compatibility-status'",
]:
    if marker not in servers:
        raise SystemExit(f'missing logical write inventory/minimal transport invariant: {marker}')

for marker in [
    "MAD4B_SCP_Staging_Write_Authority::is_write_ability( $ability_name )",
    "MAD4B_SCP_Staging_Write_Authority::candidate_bootstrap_allowed( $ability_name, $input )",
    "MAD4B_SCP_Staging_Write_Authority::candidate_bootstrap_status( $ability_name, $input )",
    "return 'mad4b-write'",
    "mad4b_write_authority_mount_missing",
]:
    if marker not in transport:
        raise SystemExit(f'missing transport-to-write-authority binding: {marker}')

for marker in [
    "MAD4B_SCP_Transport_Context::resolve_server_for_ability( $declared_server_id, $ability_name, $input )",
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
    "const CONTRACT = 'mad4b.write-runtime-certification.v3'",
    "add_action( 'admin_init', array( __CLASS__, 'observe' ), 110 )",
    "'execute_callback' => array( __CLASS__, 'status' )",
    "doing_action( 'rest_api_init' )",
    "MAD4B_SCP_Live_Truth::current_authority_status()",
    "MAD4B_SCP_Staging_Write_Authority::eligible()",
    "return self::ineligible_status()",
    "'persistence' => 'not_applicable'",
    "write_dispatch_transport_available",
    "direct_write_schemas_hidden_from_chatgpt",
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
    "normal_remote_write_exact_approval_required",
    "candidate_bootstrap_prior_approval_exception",
    "candidate_bootstrap_exception_bounded",
    "candidate_bootstrap_closure_complete",
    "candidate_bootstrap_closure",
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

expected_missing_schema = grant_reconcile.split("'expected_missing_abilities' => array(", 1)[1].split("'confirmation' => array(", 1)[0]
if "'minItems' => 0" not in expected_missing_schema:
    raise SystemExit('exact grant reconciliation must permit an empty missing set for same-inventory package candidate rebind')
if 'mad4b_grant_reconcile_nothing_to_do' in grant_reconcile:
    raise SystemExit('same-inventory package candidate rebind may not fail as nothing-to-do')
if "'state' => empty( $created_abilities ) ? 'candidate_rebound' : 'reconciled'" not in grant_reconcile:
    raise SystemExit('grant reconciliation must distinguish zero-grant candidate rebind from grant creation')
if "'source_commit_sha' => $current_sha" not in grant_reconcile or "'build_fingerprint' => $current_fingerprint" not in grant_reconcile:
    raise SystemExit('grant reconciliation completion evidence must remain exact-build bound')

effective_body = write.split("public static function effective()", 1)[1].split("public static function status()", 1)[0]
if "candidate_binding_status()" not in effective_body:
    raise SystemExit('authority effective() must fail closed on an exact packaged candidate mismatch')
if "current_authority_status()" in effective_body:
    raise SystemExit('authority effective() may not recursively rebuild Live Truth/write inventory')

for marker in [
    "runtime_authority_candidate_not_reconciled",
    "'contract' => 'mad4b.governed-write-candidate-bootstrap-closure.v1'",
    "'sequence' => array( 'mad4b/acceptance-target-provision', 'mad4b/staging-write-grant-reconcile' )",
    "'required_postconditions' => array( 'candidate_binding_match', 'runtime_reconciled', 'write_authority_ready' )",
    "'retry_provider_mutation_on_reconciliation_failure' => false",
    "'candidate_binding_required'",
    "'candidate_binding_match'",
    "'candidate_source_commit_sha'",
    "'candidate_build_fingerprint'",
]:
    if marker not in live_truth:
        raise SystemExit(f'live authority truth missing exact package candidate invariant: {marker}')

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
    'functional_gap_zero_touch_repository_evidence_exact_head',
    'functional_gap_zero_touch_runtime_fixed_point_stable',
    'functional_gap_zero_touch_promotion_remains_non_authorizing',
    'provider_blocked_write_tools_safely_unmounted',
    'external_tool_inventory_match_when_mcp_enabled',
    'one_time_approval_replay_denial_and_undo_when_write_enabled',
    'context_human_review_admin_ui_visible_for_governed_assets',
    'context_task_local_human_review_controls_absent',
    'context_review_queue_read_only_surface_available',
    'context_review_audit_read_only_surface_available',
    'context_brand_core_coverage_read_only_surface_available',
    'context_human_review_mcp_mutation_surface_absent',
    'plugin_package_plan_read_only_surface_available',
    'plugin_package_apply_requires_exact_governed_write_authority',
    'plugin_package_caller_supplied_url_and_path_absent',
    'plugin_package_apply_preserves_activation_state_and_rolls_back_on_failed_disk_readback',
    'context_review_default_mode_human_only_after_upgrade',
    'context_ai_review_admin_confirmation_required',
    'context_ai_review_stable_catalog_present_but_runtime_blocked_until_delegation',
    'context_ai_review_exact_agent_and_exact_grant_required',
    'context_ai_review_policy_change_does_not_auto_reconcile_grants',
    'context_ai_review_runtime_mount_changes_write_inventory_only_after_policy_enablement',
    'context_ai_review_exact_candidate_binding_and_write_authority_required',
    'context_ai_review_exact_bound_stale_evidence_rejected',
    'context_ai_review_governance_metadata_and_quality_immutable',
    'context_ai_review_audit_actor_attribution_exact',
    'context_human_review_remains_available_after_ai_enablement',
    'context_ai_review_production_remains_unauthorized',
    'chatgpt_refresh_initialize_and_tools_list_within_certified_budget',
    'chatgpt_compact_transport_catalog_preserves_full_governed_discovery',
    'oauth_resource_specific_scopes_match_chatgpt_enrollment_developer_surfaces',
    'developer_runtime_isolated_from_chatgpt_and_normal_write_surfaces',
    'developer_runtime_non_production_only',
    'developer_breakglass_separately_gated_and_disabled_by_default',
    'full_staging_authority_plan_read_only_before_apply',
    'full_staging_authority_apply_requires_exact_clean_plan_and_explicit_operator_authority',
    'generic_raw_sql_breakglass_remains_excluded',
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

# Candidate artifact identity must be canonical-prefix safe and exact-source bound,
# without hard-coding one producer wrapper naming scheme. This keeps historical
# general-distribution identities compatible while accepting the canonical
# versioned plugin artifact identity emitted by current build provenance.
for required in [
    "private static function artifact_identity_matches_source( $artifact_identity, $source_commit_sha )",
    "0 !== strpos( $artifact_identity, 'mad4b-site-control-plane-' )",
    "preg_match( '/^[A-Za-z0-9._-]+$/', $artifact_identity )",
    "preg_quote( $source_commit_sha, '/' )",
    "self::artifact_identity_matches_source( $stored_artifact, $stored_sha )",
    "self::artifact_identity_matches_source( $artifact, $sha )",
]:
    if required not in write:
        raise SystemExit(f'candidate artifact identity validation missing invariant: {required}')

legacy_rigid = "/^mad4b-site-control-plane-general-distribution-kit-[a-f0-9]{40}$/"
if legacy_rigid in write:
    raise SystemExit('candidate artifact identity validation remains hard-coded to the distribution wrapper name')

import re as _re
_exact_sha = '6efd5a0f266fbc84c2e49221694340209ee189af'
_safe = _re.compile(r'[A-Za-z0-9._-]+')
def _artifact_identity_valid(value, sha):
    return (
        bool(_re.fullmatch(r'[a-f0-9]{40}', sha))
        and 0 < len(value) <= 191
        and value.startswith('mad4b-site-control-plane-')
        and bool(_safe.fullmatch(value))
        and value.endswith('-' + sha)
    )

if not _artifact_identity_valid('mad4b-site-control-plane-0.4.0-rc.59-' + _exact_sha, _exact_sha):
    raise SystemExit('canonical versioned plugin artifact identity must be accepted')
if not _artifact_identity_valid('mad4b-site-control-plane-general-distribution-kit-' + _exact_sha, _exact_sha):
    raise SystemExit('historical general-distribution artifact identity must remain accepted')
if _artifact_identity_valid('mad4b-site-control-plane-0.4.0-rc.59-' + ('0' * 40), _exact_sha):
    raise SystemExit('artifact identity with a different source SHA must be rejected')
if _artifact_identity_valid('../mad4b-site-control-plane-0.4.0-rc.59-' + _exact_sha, _exact_sha):
    raise SystemExit('unsafe artifact identity characters/prefix must be rejected')

print('mad4b.staging-write-authority.tenant-profile.v14: PASS')
