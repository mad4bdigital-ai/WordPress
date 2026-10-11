#!/usr/bin/env python3
from pathlib import Path
import json

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'

write = (wp / 'includes' / 'class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
abilities = (wp / 'includes' / 'class-mad4b-scp-abilities.php').read_text(encoding='utf-8')
grant_reconcile = (wp / 'includes' / 'class-mad4b-scp-staging-write-grant-reconciliation.php').read_text(encoding='utf-8')
grant_plan = (wp / 'includes' / 'class-mad4b-scp-staging-write-grant-reconciliation-plan.php').read_text(encoding='utf-8')
planning = (wp / 'includes' / 'class-mad4b-scp-staging-write-planning-guard.php').read_text(encoding='utf-8')
ai_approval = (wp / 'includes' / 'class-mad4b-scp-ai-approval.php').read_text(encoding='utf-8')
impact_policy = (wp / 'includes' / 'class-mad4b-scp-impact-policy.php').read_text(encoding='utf-8')
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
runtime_release_policy = json.loads((wp / 'config' / 'runtime-release-policy.json').read_text(encoding='utf-8'))

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
for marker_text in [
    "MAD4B_SCP_Truth_Projection::governed_write_grant_snapshot",
    "'current_readiness_blockers'",
    "'persisted_ready' => $persisted_ready",
]:
    if marker_text not in plan_body:
        raise SystemExit("write reconciliation current/persisted readiness split missing: " + marker_text)
if "'current_ready' => $persisted_ready" in plan_body:
    raise SystemExit("write reconciliation current_ready must not alias the historical persisted checkpoint")

for marker_text in [
    "MAD4B_SCP_Agent_Registry::subject_binding( 'oauth', $fingerprint )",
    "'subject_preflight_ready' => empty( $subject_preflight_blockers )",
    "'subject_preflight_blockers' => $subject_preflight_blockers",
    "'oauth_subject_bound_to_other_agent'",
    "'oauth_subject_not_enrolled'",
    "'oauth_issuer_unavailable'",
]:
    if marker_text not in plan_body:
        raise SystemExit("write reconciliation subject preflight missing: " + marker_text)
if "MAD4B_SCP_Agent_Registry::bind_subject(" in plan_body:
    raise SystemExit("read-only reconciliation plan may not mutate subject bindings")
for marker_text in [
    "$current_readiness_blockers = isset( $grant_truth['blockers'] )",
    "array_merge( $current_readiness_blockers, $subject_preflight_blockers )",
    "'current_readiness_blockers' => $current_readiness_blockers",
]:
    if marker_text not in plan_body:
        raise SystemExit("write current readiness must explain subject preflight blockers: " + marker_text)

# Exact current-environment authority and a simultaneous broad environment=all
# allow can coexist because environment participates in the DB uniqueness key.
# The read-only plan must expose that broad row even when the exact row wins
# execution lookup, otherwise current readiness and explicit reconcile disagree.
if "} elseif ( ! empty( $allow_current[ $key ] ) ) {" not in plan_body or "} elseif ( ! empty( $allow_all[ $key ] ) ) {" not in plan_body:
    raise SystemExit("write reconciliation allow-current/allow-all branches are unavailable")
exact_allow_branch = plan_body.split("} elseif ( ! empty( $allow_current[ $key ] ) ) {", 1)[1].split("} elseif ( ! empty( $allow_all[ $key ] ) ) {", 1)[0]
for marker_text in (
    "if ( ! empty( $allow_all[ $key ] ) )",
    "foreach ( $allow_all[ $key ] as $broad_grant )",
    "$broad_environment[] = array(",
    "'environment' => 'all'",
):
    if marker_text not in exact_allow_branch:
        raise SystemExit("exact+broad coexistence drift is not projected into current write truth: " + marker_text)

current_status_body = write.split("public static function current_status()", 1)[1].split("public static function effective()", 1)[0]
for marker_text in [
    "self::current_execution_readiness()",
    "'persisted_ready'",
    "'checkpoint_ready'",
    "'current_grant_snapshot_ready'",
    "'current_readiness_blockers'",
    "$checkpoint['current_truth'] = true;",
    "'deep_grant_scan_performed'",
]:
    if marker_text not in current_status_body:
        raise SystemExit("current write-authority status projection missing: " + marker_text)
status_registration = write.split("public static function register_status_ability()", 1)[1]
if "'execute_callback' => array( __CLASS__, 'current_status' )" not in status_registration:
    raise SystemExit("write-authority status ability must expose current truth, not persisted checkpoint status")

reconcile_body = write.split("public static function reconcile()", 1)[1]
for marker_text in ["$seen_current_exact_allow", "$duplicate_grants_revoked", ":duplicate_grant"]:
    if marker_text not in reconcile_body:
        raise SystemExit("explicit reconcile lost deterministic duplicate-grant cleanup: " + marker_text)
for marker_text in [
    "$preflight_plan = self::reconciliation_plan();",
    "'unreviewed_stale_allow_grants_count'",
    "self::fail_closed_persisted_authority( 'unreviewed_stale_write_authority' )",
]:
    if marker_text not in reconcile_body:
        raise SystemExit("unknown stale authority pre-mutation guard missing: " + marker_text)
preflight_pos = reconcile_body.index("$preflight_plan = self::reconciliation_plan();")
for mutation_marker in [
    "MAD4B_SCP_Agent_Registry::create_agent",
    "MAD4B_SCP_Agent_Registry::update_agent",
    "MAD4B_SCP_Agent_Registry::bind_subject",
    "MAD4B_SCP_Agent_Registry::set_subject_status",
    "MAD4B_SCP_Agent_Registry::grant_ability",
    "MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id",
]:
    if mutation_marker in reconcile_body and preflight_pos > reconcile_body.index(mutation_marker):
        raise SystemExit("unknown stale authority guard runs after authority mutation: " + mutation_marker)

if not write.rstrip().endswith('}'):
    raise SystemExit('write authority file must end at the canonical class closing brace')

augment_body = write.split('public static function augment_write_ability', 1)[1].split('public static function reconciliation_plan', 1)[0]
dispatch_exclusion = "if ( in_array( $mcp_surface, array( 'enrollment', 'developer-dispatch', 'write-dispatch' ), true ) ) return $args;"
if dispatch_exclusion not in augment_body:
    raise SystemExit('bounded Enrollment, Developer and governed-write transport dispatchers must remain outside target mutation augmentation')
if augment_body.find(dispatch_exclusion) > augment_body.find("self::APPROVAL_INPUT_KEY"):
    raise SystemExit('Dispatcher exclusions must run before normal write approval input augmentation')
for marker in [
    "$mcp_meta['surface'] = 'enrollment';",
    "$mcp_meta['generic_remote_admin'] = false;",
    "$mcp_meta['production_mutation_allowed'] = false;",
]:
    if marker not in abilities:
        raise SystemExit('core Enrollment registration lost isolated authority metadata: ' + marker)

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
    "MAD4B_SCP_Site_Profile::current_environment()",
    "MAD4B_SCP_Site_Profile::agent_slug()",
    "site_profile_unconfigured",
    "site_profile_write_disabled",
    "MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled()",
    "MAD4B_SCP_Governed_Runtime_Gates::production_auto_enable()",
    "MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()",
    "'breakglass_auto_enable' => false",
    "$policy['production_exact_approval_only'] = $production;",
    "'all_remote_writes_require_exact_approval' => 'production' === $environment",
    "'normal_remote_writes_require_exact_approval' => true",
    "exact_approval_with_bounded_standing_exceptions",
    "exact_approval_required",
    "'remote_write_prior_approval_exceptions' => 'production' === $environment ? array() : array(",
    "if ( 'production' === self::current_environment() ) return self::effective() ? true : $required;",
    "$policy['remote_write_prior_approval_exceptions'][] = $ai_approval_ability",
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


dispatch_scope = write.split('public static function remote_scope_delegation_allowed', 1)[1].split('public static function force_remote_write_approval', 1)[0]
dispatcher_scope_helper = write.split('private static function write_dispatch_scope_delegation_allowed', 1)[1].split('public static function remote_scope_delegation_allowed', 1)[0]
for marker in [
    "if ( 'mad4b/write-execute' === (string) $ability_name )",
    "return self::write_dispatch_scope_delegation_allowed( $server_id, $input );",
]:
    if marker not in dispatch_scope:
        raise SystemExit(f'bounded ChatGPT write dispatcher routing invariant missing: {marker}')
for marker in [
    "'mad4b-chatgpt' !== $server_id",
    "MAD4B_SCP_Transport_Context::current_server_id()",
    "$target_ability = isset( $input['ability_name'] )",
    "self::is_write_ability( $target_ability )",
    "MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $target_ability )",
    "hash_equals( $actual_schema_sha256, $expected_schema_sha256 )",
]:
    if marker not in dispatcher_scope_helper:
        raise SystemExit(f'bounded ChatGPT write dispatcher delegation invariant missing: {marker}')
if "'server:mad4b-write'" in dispatch_scope or "'server:mad4b-write'" in dispatcher_scope_helper:
    raise SystemExit('write dispatcher delegation must not mint or require a broad mad4b-write token scope')

write_execute_body = abilities.split('public function write_execute', 1)[1].split('private function governed_enrollment_target', 1)[0]
for marker in [
    "if ( 'mad4b/approval-plan' === $ability_name )",
    "mad4b_approval_plan_dispatch_target_error",
    "original_error_code",
    "reconciliation_required",
    "blind_retry_allowed",
    "MAD4B_SCP_Connector_Resilience::execute_mutation(",
]:
    if marker not in write_execute_body:
        raise SystemExit('approval-plan deterministic dispatcher contract missing: ' + marker)
if write_execute_body.count("if ( 'mad4b/approval-plan' === $ability_name )") != 1:
    raise SystemExit('approval-plan dispatcher specialization must be singular')
if "automatic_retry_performed' => false" not in write_execute_body:
    raise SystemExit('approval-plan dispatcher must never auto-retry')


for marker in [
    "const CONTRACT = 'mad4b.ai-approval.v1'",
    "const DELEGATION_CONTRACT = 'mad4b.ai-approval-standing-delegation.v1'",
    "const ABILITY = 'mad4b/approval-ai-decide'",
    "'production_authorized' => false",
    "'breakglass_authorized' => false",
    "MAD4B_SCP_Approval_Tickets::decide_pending_by_ai",
    "expected_classification_sha256",
    "ai_approval_operation_human_only",
]:
    if marker not in ai_approval:
        raise SystemExit('AI approval authority invariant missing: ' + marker)
for marker in [
    "const CLASSIFICATION_CONTRACT = 'mad4b.operation-classification.v1'",
    "'operation_type' => $operation_type",
    "'mutation_kind' => $mutation_kind",
    "'side_effect_scope' => $side_effect_scope",
    "'approval_lane' => $approval_lane",
    "'ai_approval_eligible' => $ai_eligible",
    "'human_approval_required' => ! $readonly && ! $ai_eligible",
    "$operation_type = $readonly ? 'observe' : 'governed_mutation'",
    "$mutation_kind = $readonly ? 'read' : 'mutate'",
    "$approval_lane = $readonly ? 'none'",
    "'governance_decision'",
    "'production_auto_approval' => false",
    "'breakglass_auto_approval' => false",
    "'exceptional'",
    "'certified_package'",
    "'content_publish'",
    "'system_admin'",
    "'recovery'",
]:
    if marker not in impact_policy:
        raise SystemExit('operation classification invariant missing: ' + marker)
for marker in [
    "MAD4B_SCP_AI_Approval::delegation_allowed( $ability_name, $input, $identity )",
    "MAD4B_SCP_AI_Approval::delegation_allowed( $ability_name, $input )",
    "mad4b_ai_approval_standing_delegation",
    "mad4b_ai_approval_production_authorized",
    "mad4b_ai_approval_breakglass_authorized",
]:
    if marker not in write:
        raise SystemExit('AI approval standing delegation wiring missing: ' + marker)


for marker in [
    "MAD4B_SCP_AI_Approval::ABILITY",
    "MAD4B_SCP_AI_Approval::catalog_eligible()",
    "'ai_approval_standing_delegation_not_eligible'",
    "'mad4b/operation-classify'",
]:
    if marker not in servers:
        raise SystemExit('Core AI approval server projection invariant missing: ' + marker)

for marker in [
    "const CONTRACT = 'mad4b.staging-write-grant-reconciliation-plan.v2'",
    "private static function transport_inventory()",
    "MAD4B_SCP_Servers::chatgpt_dispatch_transport_tools()",
    "'server_id' => 'mad4b-chatgpt'",
    "'expected_transport_tool_count'",
    "'expected_transport_inventory_fingerprint'",
    "'expected_missing_transport_abilities'",
    "duplicate_transport_allow:",
    "non_current_environment_transport_allow:",
]:
    if marker not in grant_plan:
        raise SystemExit(f'missing exact transport grant planning invariant: {marker}')

# Connector-facing reconciliation schemas must remain evolution-safe. Runtime
# allowlists and exact provider checks remain authoritative and fail closed.
reconcile_schema = grant_reconcile.split("'input_schema' => array(", 1)[1].split("'output_schema' => array", 1)[0]
stale_schema = reconcile_schema.split("'expected_stale_grant_ids' => array(", 1)[1].split("'expected_transport_tool_count'", 1)[0]
required_schema = reconcile_schema.split("'required' => array(", 1)[1].split("'additionalProperties' => false", 1)[0]
if "'default' => array()" not in stale_schema:
    raise SystemExit('stale-grant assertion must default to an empty set for stale connector compatibility')
if "'expected_stale_grant_ids'" in required_schema:
    raise SystemExit('stale-grant assertion must remain optional on the wire for backward-compatible connector schemas')
for marker in [
    "expected_stale_grant_ids_optional_when_empty",
    "stale_grant_omission_fails_closed_on_live_stale",
    "mad4b_grant_reconcile_stale_set_mismatch",
]:
    if marker not in grant_reconcile:
        raise SystemExit('stale-schema compatibility lost fail-closed protection: ' + marker)
for marker in [
    "'expected_stale_grant_ids_required_on_wire' => false",
    "'omission_semantics' => 'assert_empty_stale_set'",
    "'legacy_schema_compatible' => empty( $payload['expected_stale_grant_ids'] )",
    "'fails_closed_if_live_stale_grants_exist' => true",
]:
    if marker not in grant_plan:
        raise SystemExit('reconciliation plan does not expose connector compatibility semantics: ' + marker)

if "'items' => array( 'type' => 'string', 'enum' => self::allowed_abilities() )" in grant_reconcile:
    raise SystemExit('grant reconciliation input schema must not freeze the live write allowlist into a client enum')
if "'items' => array( 'type' => 'string', 'enum' => self::allowed_transport_abilities() )" in grant_reconcile:
    raise SystemExit('grant reconciliation transport schema must not freeze the live transport allowlist into a client enum')
for marker in [
    "mad4b_grant_reconcile_missing_outside_allowlist",
    "mad4b_grant_reconcile_provider_mismatch",
    "mad4b_grant_reconcile_missing_set_mismatch",
    "mad4b_grant_reconcile_transport_outside_allowlist",
    "mad4b_grant_reconcile_transport_provider_mismatch",
    "mad4b_grant_reconcile_transport_missing_set_mismatch",
    "mad4b_grant_reconcile_stale_set_mismatch",
    "mad4b_grant_reconcile_stale_allow_unreviewed",
]:
    if marker not in grant_reconcile:
        raise SystemExit('runtime grant reconciliation allowlist protection missing: ' + marker)

for marker in [
    "const CONTRACT = 'mad4b.staging-write-grant-reconciliation.v3'",
    "const ABILITY = 'mad4b/staging-write-grant-reconcile'",
    "const CONFIRMATION = 'RECONCILE EXACT STAGING WRITE AUTHORITY'",
    "const PRODUCTION_CONFIRMATION = 'RECONCILE EXACT PRODUCTION WRITE AUTHORITY'",
    "public static function required_confirmation()",
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
    "'context/brand-draft-create' => 'google_drive_context'",
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
    "'mad4b/control-plane-upload-apply' => 'core'",
    "'mad4b/context-ai-review' => 'core'",
    "'mad4b/approval-ai-decide' => 'core'",
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
    "expected_transport_tool_count",
    "expected_transport_inventory_fingerprint",
    "expected_missing_transport_abilities",
    "public static function allowed_transport_ability_providers()",
    "MAD4B_SCP_Servers::chatgpt_dispatch_transport_tools()",
    "grant_ability( $agent['public_id'], 'mad4b-chatgpt'",
    "exact_grant( $agent['id'], 'mad4b-chatgpt'",
    "mad4b/exact-staging-transport-grant-reconciled",
    "created_transport_grant_ids",
    "transport_grant_ready",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan()",
    "mad4b_grant_reconcile_plan_changed",
    "MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint()",
    "rollback_transaction",
    "mad4b/staging-write-authority-prepared",
    "candidate_binding_is_commit_point",
    "'post_commit_governance_mutation' => false",
    "expected_missing_abilities",
    "expected_stale_grant_ids",
    "revoked_stale_grant_ids",
    "mad4b/exact-staging-write-stale-grant-retired",
    "'authority_change' => 'narrowing'",
    "stale_grants_retired_pending_reconciliation",
    "expected_agent_public_id",
    "Breakglass/raw SQL must never enter governed grant reconciliation",
    "'native-provider'",
    "array( 'staging', 'production' )",
    "'production_mutation' => 'production' === $environment",
    "'production_allowed' => true",
    "'breakglass_included' => false",
    "mad4b/staging-write-grant-reconciliation-authorized",
    "mad4b/exact-staging-write-grant-reconciled",
]:
    if marker not in grant_reconcile:
        raise SystemExit(f'missing bounded exact grant-reconciliation invariant: {marker}')

allowlist = grant_reconcile.split('public static function allowed_ability_providers()', 1)[1].split('public static function allowed_abilities()', 1)[0]
retirement_allowlist = grant_reconcile.split('public static function retirable_stale_ability_providers()', 1)[1].split('public static function allowed_transport_ability_providers()', 1)[0]
transport_allowlist = grant_reconcile.split('public static function allowed_transport_ability_providers()', 1)[1].split('public static function chatgpt_read_tools()', 1)[0]
if "MAD4B_SCP_Servers::chatgpt_dispatch_transport_tools()" not in transport_allowlist:
    raise SystemExit('ChatGPT mutation transport grant allowlist must derive from the canonical dispatcher inventory')
for dispatcher in ("'mad4b/write-execute'", "'mad4b/enrollment-execute'"):
    if dispatcher in allowlist:
        raise SystemExit('direct ChatGPT mutation transport grant leaked into the normal governed-write allowlist: ' + dispatcher)
for required_pair in [
    "'mad4b/control-plane-upload-apply' => 'core'",
    "$allowed[ $ability ] = 'media'",
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

feature007_core_grants = [
    "mad4b/data-processing-bound-decision-record",
    "mad4b/data-processing-profile-apply",
    "mad4b/decommission-finalize-apply",
    "mad4b/decommission-quiesce-apply",
    "mad4b/decommission-resume-apply",
    "mad4b/portability-import-quarantine-apply",
    "mad4b/rights-record-apply",
    "mad4b/rights-takedown-apply",
    "mad4b/scheduler-backlog-claim-next",
    "mad4b/scheduler-backlog-complete",
    "mad4b/scheduler-backlog-enqueue",
    "mad4b/scheduler-backlog-heartbeat",
    "mad4b/scheduler-backlog-reconcile",
    "mad4b/search-budget-apply",
    "mad4b/search-capture-apply",
    "mad4b/search-compile-apply",
    "mad4b/search-control",
    "mad4b/search-import-evidence",
    "mad4b/search-post-change-apply",
    "mad4b/search-profile-apply",
    "mad4b/search-provider-probe",
    "mad4b/search-recompute",
    "mad4b/search-reconcile",
    "mad4b/search-retention",
]
for ability_name in feature007_core_grants:
    exact_pair = f"'{ability_name}' => 'core'"
    if exact_pair not in allowlist:
        raise SystemExit(f'Feature 007 governed core write grant is outside exact reconciliation allowlist: {ability_name}')
if len(feature007_core_grants) != 24:
    raise SystemExit('Feature 007 exact core grant regression fixture count changed unexpectedly')

for media_ability in ("media/update-metadata", "media/set-featured", "media/set-parent"):
    if media_ability not in grant_reconcile:
        raise SystemExit(f'reviewed media ability missing from bounded grant reconciliation: {media_ability}')
for media_contract in (
    "mad4b.rollback.media-metadata.v1",
    "mad4b.rollback.featured-image.v1",
    "mad4b.rollback.media-parent.v1",
):
    if media_contract not in (wp / "includes" / "adapters" / "class-mad4b-scp-media-adapter.php").read_text(encoding="utf-8"):
        raise SystemExit(f'reviewed media grant lacks reversible contract: {media_contract}')
if "foreach ( array( 'media/update-metadata', 'media/set-featured', 'media/set-parent' ) as $ability )" not in allowlist:
    raise SystemExit('media grant creation must remain an exact reviewed ability set, not a dynamic adapter wildcard')
if "$allowed[ $ability ] = 'media';" not in allowlist:
    raise SystemExit('reviewed media grants must resolve to exact media provider')


if "'elementor/update-widget-settings' => 'elementor'" in allowlist:
    raise SystemExit('historical Elementor grant leaked back into grant-creation allowlist')
if "'elementor/update-widget-settings'] = 'elementor'" not in retirement_allowlist:
    raise SystemExit('historical Elementor stale grant is not explicitly retirement-only')
if "retirable_stale_ability_providers" not in grant_plan:
    raise SystemExit('stale grant plan does not use retirement-only historical allowlist')

for marker in [
    "'unreviewed_stale_allow_grants_count'",
    "'unknown_stale_authority_fail_closed' => true",
    "'stale_allow_unreviewed:'",
    "'non_current_environment_allow:'",
    "$reviewed_stale_grants_revoked",
    "$broad_environment_grants_revoked",
]:
    if marker not in write:
        raise SystemExit('governed write authority does not fail closed on unknown stale authority: ' + marker)


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
chatgpt_base = servers.split('public static function chatgpt_base_tools()', 1)[1].split('public static function chatgpt_tools()', 1)[0]
direct_read_transport = servers.split('public static function chatgpt_direct_read_transport_tools()', 1)[1].split('public static function chatgpt_dispatch_transport_tools()', 1)[0]
chatgpt_transport = servers.split('public static function chatgpt_tools()', 1)[1].split('private static function chatgpt_internal_enrollment_mutations()', 1)[0]
if (
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" not in chatgpt_base
    and "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" not in direct_read_transport
):
    raise SystemExit('bounded Staging Write Authority read plan projection is missing from the required base catalog')
if "$base = self::chatgpt_base_tools();" not in chatgpt_transport or "array_merge( $base, $dynamic )" not in chatgpt_transport:
    raise SystemExit('dynamic ChatGPT projection no longer composes on top of the required base catalog')
for marker in (
    'public static function chatgpt_reviewed_direct_step_up_tools()',
    'MAD4B_SCP_Full_Staging_Authority::APPLY_ABILITY',
    'MAD4B_SCP_Self_Update::BOOTSTRAP_APPLY_ABILITY',
    'MAD4B_SCP_Governed_Runtime_Gates::APPLY_ABILITY',
    '$step_up = self::chatgpt_reviewed_direct_step_up_tools();',
    'array_merge( self::chatgpt_dispatch_transport_tools(), $step_up )',
):
    if marker not in servers:
        raise SystemExit('reviewed direct ChatGPT step-up invariant missing: ' + marker)
dispatcher_helper = servers.split('public static function chatgpt_dispatch_transport_tools()', 1)[1].split('public static function chatgpt_reviewed_direct_step_up_tools()', 1)[0]
for dispatcher in ("'mad4b/write-execute'", "'mad4b/enrollment-execute'"):
    if dispatcher not in dispatcher_helper:
        raise SystemExit('canonical ChatGPT mutation dispatcher inventory is incomplete: ' + dispatcher)
core_chatgpt = servers.split("'mad4b-chatgpt' => array_merge( array(", 1)[1].split("), $governed_status", 1)[0]
if "'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute'" not in core_chatgpt:
    raise SystemExit('bounded Enrollment discover/info/execute projection is missing from the canonical compact ChatGPT core catalog')
if "$candidates = array_merge( $core, $bootstrap )" not in chatgpt_base:
    raise SystemExit('required ChatGPT base catalog no longer starts from the canonical compact core catalog')
runtime_direct_surface = chatgpt_base + "\n" + chatgpt_transport
for bootstrap_ability in (
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
):
    if bootstrap_ability in runtime_direct_surface:
        raise SystemExit('low-level enrollment mutation leaked into required/dynamic ChatGPT direct transport: ' + bootstrap_ability)
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
for forbidden_write in (
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
    "'mad4b/control-plane-bootstrap-apply'",
    "'mad4b/enrollment-execute'",
    "'mad4b/reconcile-managed-skills'",
):
    if forbidden_write in core_write:
        raise SystemExit('bootstrap/internal enrollment ability leaked into normal mad4b-write authority: ' + forbidden_write)

# A package transition may expose one bootstrap write before the persisted candidate
# binding is refreshed. Lock the exception to the isolated acceptance target and
# require all normal authorization layers to remain downstream.
bootstrap_body = write.split("public static function candidate_bootstrap_status", 1)[1].split("public static function candidate_bootstrap_allowed", 1)[0]
for marker in [
    "self::CANDIDATE_BOOTSTRAP_ABILITY !== $ability_name",
    "'staging' !== MAD4B_SCP_Site_Profile::current_environment()",
    "environment_not_staging",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "MAD4B_SCP_Site_Profile::write_enabled()",
    "MAD4B_SCP_Policy::mutation_gate_status()",
    "mutation_gate_disabled",
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
if "if ( ! $production && $bootstrap ) return true;" not in scope_body:
    raise SystemExit('candidate bootstrap must have one explicit non-Production no-prior-ticket scope delegation branch')
if "$production = 'production' === self::current_environment();" not in scope_body:
    raise SystemExit('candidate bootstrap scope delegation must fail closed for Production')
if "mad4b:read" not in scope_body or "oauth2_bearer" not in scope_body:
    raise SystemExit('candidate bootstrap scope delegation must retain verified OAuth read identity')

# approval-plan is the one bounded mutation that creates a PENDING ticket.
# Its catalog registration is administrative for local operator discovery, but
# remote execution is always classified on the canonical governed-write lane.
# The planning guard must establish that lane before Authorization seals the
# central execution boundary; classification never grants authority by itself.
for marker in [
    "$args['meta']['mcp']['surface'] = 'write';",
    "$args['meta']['annotations']['readonly'] = false;",
    "$args['meta']['annotations']['destructive'] = false;",
    "$args['meta']['annotations']['idempotent'] = false;",
    "$args['meta']['mcp']['mad4b_execution_lane_binding'] = 'governed_write_bootstrap';",
]:
    if marker not in planning:
        raise SystemExit('approval-plan canonical write-lane binding missing: ' + marker)
if planning.find("$args['meta']['mcp']['surface'] = 'write';") > planning.find("$args['meta']['mcp']['mad4b_governed_write_authority']"):
    raise SystemExit('approval-plan canonical write lane must be fixed before governed authority metadata is finalized')
if "add_filter( 'wp_register_ability_args', array( __CLASS__, 'govern_registration' ), 85, 2 );" not in planning:
    raise SystemExit('approval-plan lane binding must remain before central Authorization execution-boundary wrapping')
if "add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_execution_boundary' ), 190, 2 );" not in auth:
    raise SystemExit('central Authorization boundary priority drifted; approval-plan lane proof is no longer ordered')

# approval-plan is the one bounded mutation that creates a PENDING ticket. It
# cannot require that same ticket merely to pass OAuth scope delegation.
for marker in [
    "MAD4B_SCP_Staging_Write_Planning_Guard::ABILITY === (string) $ability_name",
    "MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_remote_plan_input( $input )",
    "MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $canonical )",
    "return ! is_wp_error( $guard );",
]:
    if marker not in scope_body:
        raise SystemExit('remote approval-plan bootstrap scope guard missing: ' + marker)
planner_branch = scope_body.find("MAD4B_SCP_Staging_Write_Planning_Guard::ABILITY === (string) $ability_name")
ticket_branch = scope_body.find("approval_ticket_from_input( $input )")
if planner_branch < 0 or ticket_branch < 0 or planner_branch > ticket_branch:
    raise SystemExit('approval-plan scope bootstrap must execute before the normal existing-ticket delegation path')
planner_server_guard = scope_body.find("if ( ! in_array( $server_id, array( 'mad4b-admin', 'mad4b-write' ), true ) || ! self::effective() ) return false;")
write_server_guard = scope_body.find("if ( 'mad4b-write' !== $server_id ) return false;")
if planner_server_guard < 0 or write_server_guard < 0:
    raise SystemExit('remote approval-plan bootstrap must accept only declared admin or resolved write authority before normal write delegation')
if not (planner_branch < planner_server_guard < write_server_guard < ticket_branch):
    raise SystemExit('approval-plan dual-identity scope guard must execute before the normal mad4b-write-only delegation path')
if "array( 'mad4b-admin', 'mad4b-write' )" not in scope_body:
    raise SystemExit('approval-plan scope bootstrap lost exact declared/resolved server pair')
if "if ( 'mad4b-write' !== $server_id ) return false;" not in scope_body:
    raise SystemExit('normal governed writes must remain restricted to mad4b-write')
if scope_body.count("array( 'mad4b-admin', 'mad4b-write' )") != 1:
    raise SystemExit('planner dual-server exception must remain unique to the exact approval-plan branch')

# The compact write dispatcher is itself an exact NHI-granted ChatGPT transport
# ability. Its OAuth scope exception must be narrow: current ChatGPT transport,
# effective candidate-bound write authority, exact runtime-eligible target,
# mounted mad4b-write provider, mutating target metadata, and exact live schema.
for marker in [
    "private static function write_dispatch_scope_delegation_allowed",
    "'mad4b-chatgpt' !== $server_id || ! self::effective()",
    "'mad4b-chatgpt' !== MAD4B_SCP_Transport_Context::current_server_id()",
    "array( 'mad4b/write-execute', 'mad4b/enrollment-execute', 'mad4b/database-raw-query' )",
    "self::is_write_ability( $target_ability )",
    "MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $target_ability )",
    "wp_has_ability( $target_ability )",
    "false !== $annotations['readonly']",
    "expected_input_schema_sha256",
    "hash_equals( $actual_schema_sha256, $expected_schema_sha256 )",
    "if ( 'mad4b/write-execute' === (string) $ability_name )",
    "return self::write_dispatch_scope_delegation_allowed( $server_id, $input );",
]:
    if marker not in scope_body and marker not in write:
        raise SystemExit('bounded write-dispatch scope delegation missing: ' + marker)

dispatcher_scope_helper = write.split("private static function write_dispatch_scope_delegation_allowed", 1)[1].split("public static function remote_scope_delegation_allowed", 1)[0]
for forbidden in [
    "return true;\n\t}",
    "mad4b:write",
    "server:mad4b-write",
    "grant_ability(",
    "reconcile()",
    "bind_candidate_identity(",
]:
    if forbidden in dispatcher_scope_helper:
        raise SystemExit('write-dispatch scope delegation became provisioning or broad authority: ' + forbidden)
if dispatcher_scope_helper.count("MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $target_ability )") != 1:
    raise SystemExit('write-dispatch target provider must be resolved exactly once on mad4b-write')
if scope_body.find("if ( 'mad4b/write-execute' === (string) $ability_name )") > planner_branch:
    raise SystemExit('transport dispatcher scope delegation must be evaluated before nested target planner delegation')

# The transport resolver intentionally collapses externally dispatched write
# candidates onto mad4b-write. The planner scope exception must therefore remain
# compatible with both its declared admin registration and resolved write grant.
transport_resolver = transport.split("public static function resolve_server_for_ability", 1)[1].split("public static function current_server_id", 1)[0]
for marker in [
    "'mad4b-chatgpt' === $current && MAD4B_SCP_Servers::is_external_write_candidate( $ability_name )",
    "return 'mad4b-write';",
]:
    if marker not in transport_resolver:
        raise SystemExit('transport resolver lost governed ChatGPT write delegation invariant: ' + marker)

if "if ( ! $production && self::ai_review_delegation_allowed( $ability_name, $input, $identity ) ) return true;" not in scope_body:
    raise SystemExit('AI review standing delegation must have one exact non-Production request-time scope branch')
if "$production = 'production' === self::current_environment();" not in scope_body:
    raise SystemExit('AI review standing delegation must fail closed in Production')

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
    "public static function chatgpt_reviewed_direct_step_up_tools()",
    "MAD4B_SCP_Full_Staging_Authority::APPLY_ABILITY",
    "MAD4B_SCP_Self_Update::BOOTSTRAP_APPLY_ABILITY",
    "MAD4B_SCP_Governed_Runtime_Gates::APPLY_ABILITY",
    "MAD4B_SCP_Remote_Operation_Parity::chatgpt_direct_step_up_catalog_tools()",
    "public static function chatgpt_dispatch_transport_tools()",
    "$step_up = self::chatgpt_reviewed_direct_step_up_tools();",
    "array_merge( self::chatgpt_dispatch_transport_tools(), $step_up )",
    "'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute'",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()",
    "self::provider_for_ability( 'mad4b-write', $ability_name )",
    "'mad4b/write-authority-status'",
    "'mad4b/write-runtime-certification'",
    "'mad4b/rest-compatibility-status'",
]:
    if marker not in servers:
        raise SystemExit(f'missing logical write inventory/minimal transport invariant: {marker}')
logical_direct = chatgpt_base + "\n" + servers.split('public static function chatgpt_tools()', 1)[1].split('private static function chatgpt_internal_enrollment_mutations()', 1)[0]
for forbidden_direct in (
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
):
    if forbidden_direct in logical_direct:
        raise SystemExit('low-level enrollment mutation leaked into logical direct transport: ' + forbidden_direct)

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
    "MAD4B_SCP_Staging_Write_Authority::authorization_input( $input, $ability_name )",
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

planning_registration = planning[planning.index('public static function govern_registration'):planning.index('/**\n\t * Canonicalize the operation nested inside approval-plan')]
if "MAD4B_SCP_Staging_Write_Authority::eligible()" in planning_registration:
    raise SystemExit('approval-plan registration governance must not depend on authority eligibility at registration time')
for marker in (
    "'mad4b_governed_write_authority'] = MAD4B_SCP_Staging_Write_Authority::CONTRACT",
    "'mad4b_target_server'] = 'mad4b-write'",
):
    if marker not in planning_registration:
        raise SystemExit('approval-plan immutable registration governance marker missing: ' + marker)

for marker in [
    "public static function local_approval_planner_execution",
    "MAD4B_SCP_Authorization::local_approval_planner_execution( $name )",
    "'mad4b_local_admin_planner_compatibility'] = 'local_only_remote_claim_required'",
    "array( 'mad4b-chatgpt', 'mad4b-write' )",
]:
    if marker not in auth:
        raise SystemExit('approval-plan local/remote execution-boundary compatibility marker missing: ' + marker)

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
    "MAD4B_SCP_Provider_Diagnostic_Policy::rest_route_snapshot( self::WPML_ROUTE )",
    "'provider_probe_mode' => 'passive_snapshot'",
    "'provider_self_calls_started' => 0",
    "'internal_rest_dispatch_performed' => false",
    "'automatic_probe_retry_allowed' => false",
    "'query_parameters_preserved'",
    "hook_inventory( 'rest_enabled' )",
    "hook_inventory( 'rest_authentication_errors' )",
    "'control_plane_detected'",
    "ReflectionMethod",
    "ReflectionFunction",
    "wpml_route_missing",
    "passive_route_unobserved",
    "self::$wpml_probe_cache",
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
    "'ai_approval_standing_delegation_defined' => ! empty( $approval_policy['ai_approval_standing_delegation_defined'] )",
    "'ai_approval_standing_delegation_contract' => isset( $approval_policy['ai_approval_standing_delegation_contract'] )",
    "'ai_approval_standing_delegation_configured' => ! empty( $approval_policy['ai_approval_standing_delegation_configured'] )",
    "'ai_approval_operation_classification_contract' => isset( $approval_policy['ai_approval_operation_classification_contract'] )",
]:
    if marker not in live_truth:
        raise SystemExit(f'live truth must preserve AI approval policy projection: {marker}')

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
    "approval_planner_target_agent_bounded",
    "approval_planner_target_server_bounded",
    "approval_planner_developer_dispatch_explicit",
    "approval_planner_developer_breakglass_denied",
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

expected_missing_schema = grant_reconcile.split("'expected_missing_abilities' => array(", 1)[1].split("'expected_transport_tool_count'", 1)[0]
if "'minItems' => 0" not in expected_missing_schema:
    raise SystemExit('exact grant reconciliation must permit an empty write missing set for same-inventory package candidate rebind')
expected_stale_schema = grant_reconcile.split("'expected_stale_grant_ids' => array(", 1)[1].split("'expected_transport_tool_count'", 1)[0]
if "'minItems' => 0" not in expected_stale_schema:
    raise SystemExit('exact grant reconciliation must permit an empty stale-grant retirement set')
expected_transport_schema = grant_reconcile.split("'expected_missing_transport_abilities' => array(", 1)[1].split("'confirmation' => array(", 1)[0]
if "'minItems' => 0" not in expected_transport_schema:
    raise SystemExit('exact grant reconciliation must permit an empty transport missing set after transport convergence')
if 'mad4b_grant_reconcile_nothing_to_do' in grant_reconcile:
    raise SystemExit('same-inventory package candidate rebind may not fail as nothing-to-do')
if "'state' => ( empty( $created_abilities ) && empty( $created_transport_abilities ) && empty( $revoked_stale_ids ) ) ? 'candidate_rebound' : 'reconciled'" not in grant_reconcile:
    raise SystemExit('grant reconciliation must classify stale-authority retirement as a real reconciliation change')
if "'candidate_rebound_without_grant_changes' => empty( $created_abilities ) && empty( $created_transport_abilities ) && empty( $revoked_stale_ids )" not in grant_reconcile:
    raise SystemExit('candidate rebound evidence must account for write, transport, and stale-authority mutation')
if "'source_commit_sha' => $current_sha" not in grant_reconcile or "'build_fingerprint' => $current_fingerprint" not in grant_reconcile:
    raise SystemExit('grant reconciliation completion evidence must remain exact-build bound')

# Certification and the actual mutation guard must agree on peer-governance safety.
for marker in [
    "peer_write_side_channel_absent",
    "peer_governance_safe",
    "mcp_write_side_channel_detected",
    "mcp_peer_governance",
]:
    if marker not in cert:
        raise SystemExit('write runtime certification does not expose the live MCP peer mutation gate: ' + marker)

effective_body = write.split("public static function effective()", 1)[1].split("public static function status()", 1)[0]
if "candidate_binding_status()" not in effective_body:
    raise SystemExit('authority effective() must fail closed on an exact packaged candidate mismatch')
if "current_authority_status()" in effective_body:
    raise SystemExit('authority effective() may not recursively rebuild Live Truth/write inventory')

current_execution_body = write.split("public static function current_execution_readiness(", 1)[1].split("public static function persisted_status()", 1)[0]
for marker in [
    "self::reconciliation_plan()",
    "'current_grant_snapshot_ready'",
    "'runtime_authority_candidate_not_reconciled'",
    "self::CANDIDATE_BOOTSTRAP_ABILITY === $ability_name",
    "self::candidate_bootstrap_allowed( $ability_name, $input )",
]:
    if marker not in current_execution_body:
        raise SystemExit('mutation-bound current execution readiness invariant missing: ' + marker)
if "MAD4B_SCP_Live_Truth::current_authority_status()" in current_execution_body:
    raise SystemExit('mutation-bound readiness must consume canonical reconciliation snapshot directly, not recurse through Live Truth')

candidate_bootstrap_body = write.split("public static function candidate_bootstrap_status( $ability_name, $input = null )", 1)[1].split("public static function candidate_bootstrap_allowed", 1)[0]
for marker in (
    "$bootstrap_write_plan = self::reconciliation_plan();",
    "'bootstrap_write_reconciliation_unavailable'",
    "'unreviewed_stale_allow_grants_count'",
    "'bootstrap_unreviewed_stale_write_authority'",
):
    if marker not in candidate_bootstrap_body:
        raise SystemExit('candidate bootstrap lost execution-time stale-authority gate: ' + marker)
execution_gate_pos = candidate_bootstrap_body.index("if ( is_array( $input ) )")
snapshot_pos = candidate_bootstrap_body.index("$bootstrap_write_plan = self::reconciliation_plan();")
if snapshot_pos < execution_gate_pos:
    raise SystemExit('candidate bootstrap deep grant scan leaked into read-only capability projection')

manages_agent_body = write.split("public static function manages_agent( array $agent )", 1)[1].split("public static function effective()", 1)[0]
for marker in [
    "self::agent_slug()",
    "isset( $agent['slug'] )",
    "hash_equals( $expected, $slug )",
]:
    if marker not in manages_agent_body:
        raise SystemExit('managed ChatGPT write-agent boundary invariant missing: ' + marker)

planning_validate = planning.split("public static function validate_remote_plan_input", 1)[1]
if "MAD4B_SCP_Staging_Write_Authority::current_execution_readiness" not in planning_validate:
    raise SystemExit('remote approval planning does not fail closed on current write drift')
if "array( 'ready' => MAD4B_SCP_Staging_Write_Authority::effective(), 'blockers' => array() )" in planning_validate:
    raise SystemExit('remote approval planning must never downgrade missing current-readiness support to persisted effective authority')
for marker in ("'ready' => false", "'write_current_readiness_unavailable'"):
    if marker not in planning_validate:
        raise SystemExit('remote approval planning missing fail-closed current-readiness fallback: ' + marker)

authorize_mutation = auth.split("public static function authorize_mutation", 1)[1].split("public static function claim_mutation", 1)[0]
for marker in [
    "'mad4b-write' === $server_id",
    "MAD4B_SCP_Staging_Write_Authority::manages_agent( $agent )",
    "MAD4B_SCP_Staging_Write_Authority::current_execution_readiness",
    "'mad4b_write_authority_current_drift'",
]:
    if marker not in authorize_mutation:
        raise SystemExit('central mutation authorization lost current write-drift gate: ' + marker)

authority_status = auth.split("public static function authority_status()", 1)[1].split("public static function audit_remote_permission_denial", 1)[0]
for marker in (
    "'status_scope' => 'global_policy_and_peer_governance_only'",
    "'per_ability_authorization_required' => true",
    "'current_write_authority_evaluated' => false",
    "'current_write_authority_evaluation' => 'deferred_to_authorize_mutation'",
    "'legacy_status_ready_semantics' => 'global_gate_ready_not_execution_authorized'",
    "'staging_write_authority_projection' => 'checkpoint_diagnostic_not_per_ability_execution_verdict'",
):
    if marker not in authority_status:
        raise SystemExit('global authorization status semantic boundary missing: ' + marker)
if "current_execution_readiness(" in authority_status:
    raise SystemExit('global authorization status must not perform deep per-ability current write evaluation')

current_authority_body = live_truth.split("public static function current_authority_status()", 1)[1].split("public static function current_write_certification()", 1)[0]
for marker in [
    "MAD4B_SCP_Staging_Write_Authority::reconciliation_plan()",
    "'grant_snapshot_current_ready'",
    "'grant_snapshot_blockers'",
    "'unreviewed_stale_allow_grants_count'",
]:
    if marker not in current_authority_body:
        raise SystemExit(f'Live Truth does not consume canonical current grant snapshot: {marker}')
if "MAD4B_SCP_Agent_Registry::exact_grant(" in current_authority_body:
    raise SystemExit('Live Truth regressed to a parallel N+1 grant-readiness interpretation')
if "$grant_snapshot_ready" not in current_authority_body or "'grant_reconciliation_incomplete'" not in current_authority_body:
    raise SystemExit('Live Truth must fail closed when canonical grant snapshot is not current-ready')

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
expected_adapter_version = str(runtime_release_policy.get('target_adapter_version') or '')
if not expected_adapter_version:
    raise SystemExit('runtime release policy target Adapter version is missing')
if target.get('mcp_adapter') != {
    'slug': 'mcp-adapter',
    'required_version': expected_adapter_version,
    'required_version_source': 'runtime_release_policy.target_adapter_version',
    'deployment_mode': 'require_exact_preinstalled_or_verified_bundled',
}:
    raise SystemExit('deployment handoff MCP Adapter dependency contract drift')

executor = deployment.get('executor', {})
required_executor = {
    'authority': 'deployment_connector_selected_by_site_owner',
    'operation': 'wordpress_plugin_deploy',
    'dry_run_default': True,
    'human_approval_required_for_apply': True,
    'exact_capability_envelope_required': True,
    'exact_target_allowlist_required': True,
    'caller_supplied_credentials_allowed': False,
    'semantic_contract': 'mad4b.host-runner-wordpress-plugin-deploy-plan.v1',
    'bridge_ability': 'mad4b/host-operation-apply',
    'execution_location': 'host_runner',
    'generic_shell_required': False,
    'caller_supplied_path_allowed': False,
    'caller_supplied_url_allowed': False,
}
for key, expected in required_executor.items():
    if executor.get(key) != expected:
        raise SystemExit(f'deployment executor contract drift: {key}={executor.get(key)!r} expected={expected!r}')
for forbidden in ('command', 'shell', 'url', 'path', 'credentials', 'token', 'secret'):
    if forbidden in executor:
        raise SystemExit(f'deployment executor unexpectedly exposes caller-controlled field: {forbidden}')

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
_fixture_version = '0.0.0-fixture'
_safe = _re.compile(r'[A-Za-z0-9._-]+')
def _artifact_identity_valid(value, sha):
    return (
        bool(_re.fullmatch(r'[a-f0-9]{40}', sha))
        and 0 < len(value) <= 191
        and value.startswith('mad4b-site-control-plane-')
        and bool(_safe.fullmatch(value))
        and value.endswith('-' + sha)
    )

if not _artifact_identity_valid('mad4b-site-control-plane-' + _fixture_version + '-' + _exact_sha, _exact_sha):
    raise SystemExit('canonical versioned plugin artifact identity must be accepted')
if not _artifact_identity_valid('mad4b-site-control-plane-general-distribution-kit-' + _exact_sha, _exact_sha):
    raise SystemExit('historical general-distribution artifact identity must remain accepted')
if _artifact_identity_valid('mad4b-site-control-plane-' + _fixture_version + '-' + ('0' * 40), _exact_sha):
    raise SystemExit('artifact identity with a different source SHA must be rejected')
if _artifact_identity_valid('../mad4b-site-control-plane-' + _fixture_version + '-' + _exact_sha, _exact_sha):
    raise SystemExit('unsafe artifact identity characters/prefix must be rejected')


# Live Truth must project the bounded AI-review standing delegation metadata
# consumed by current_write_certification(); otherwise a legitimate configured
# exception is misclassified as an unexpected prior-approval bypass.
for required in [
    "'ai_review_standing_delegation_defined' => ! empty( $approval_policy['ai_review_standing_delegation_defined'] )",
    "'ai_review_standing_delegation_contract' => isset( $approval_policy['ai_review_standing_delegation_contract'] )",
    "'ai_review_standing_delegation_configured' => ! empty( $approval_policy['ai_review_standing_delegation_configured'] )",
    "'remote_write_prior_approval_exceptions' => isset( $approval_policy['remote_write_prior_approval_exceptions'] )",
]:
    if required not in live_truth:
        raise SystemExit('Live Truth lost AI-review approval-policy projection: ' + required)


# Write runtime certification must consume the canonical candidate-binding
# completeness projection instead of hard-coding one package producer name.
for required in [
    "'complete' === (string) $candidate_binding['identity_completeness']",
    "Reuse that projection here",
]:
    if required not in live_truth:
        raise SystemExit(f'write runtime package identity projection drift: {required}')
if "/^mad4b-site-control-plane-general-distribution-kit-[a-f0-9]{40}$/" in live_truth:
    raise SystemExit('live write certification must not hard-code one artifact producer identity')

# Effective prior-approval exceptions are bounded by runtime policy state:
# bootstrap only while active, AI review only while configured, no duplicates,
# and no bootstrap exception after closure.
for text_value, label in [
    (live_truth, 'live truth'),
    (cert, 'write certification fallback'),
]:
    for required in [
        "$allowed_exceptions = array();",
        "candidate_bootstrap_exception_active",
        "ai_review_standing_delegation_configured",
        "$unexpected_exceptions = array_values( array_diff(",
        "$duplicate_exceptions = count(",
        "$closed_bootstrap_exception = ! empty( $bootstrap_closure['closed'] )",
        "in_array( MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY",
        "empty( $unexpected_exceptions ) && ! $duplicate_exceptions && ! $closed_bootstrap_exception",
    ]:
        if required not in text_value:
            raise SystemExit(f'{label} bounded exception semantics missing: {required}')

print('mad4b.staging-write-authority.tenant-profile.v15: PASS')
# Keep nested transport regression proofs baseline-workflow owned without mutating
# repository-root workflow files from a feature branch.
import subprocess as _mad4b_subprocess
import sys as _mad4b_sys

_mad4b_tests = Path(__file__).resolve().parent
_mad4b_subprocess.run([
    _mad4b_sys.executable,
    str(_mad4b_tests / "write-dispatch-nested-transport-contract.py"),
], check=True)
_mad4b_subprocess.run([
    "php",
    str(_mad4b_tests / "write-dispatch-nested-transport-runtime.php"),
], check=True)

