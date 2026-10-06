from pathlib import Path

root = Path(__file__).resolve().parents[1]
matrix = (root / 'includes' / 'class-mad4b-scp-provider-closure-matrix.php').read_text(encoding='utf-8')
servers = (root / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
parity = (root / 'includes' / 'class-mad4b-scp-remote-operation-parity.php').read_text(encoding='utf-8')
operator = (root / 'includes' / 'class-mad4b-scp-operator-control-center.php').read_text(encoding='utf-8')

required = [
    "const CONTRACT = 'mad4b.provider-closure-matrix.v1';",
    "const ABILITY = 'mad4b/provider-closure-matrix';",
    "MAD4B_SCP_Servers::blocked_write_tools()",
    "MAD4B_SCP_Staging_Write_Authority::status()",
    "MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()",
    "MAD4B_SCP_Staging_Write_Authority::current_execution_readiness()",
    "MAD4B_SCP_Provider_Compatibility_Certification::inventory()",
    "MAD4B_SCP_Provider_Compatibility_Certification::recertification_plan",
    "'authorizing' => false",
    "'mutation_performed' => false",
    "'auto_activation_allowed' => false",
    "'closure_class'",
    "'next_action'",
    "'evidence_required'",
    "'owner_review_required'",
    "'provider_gated_count'",
    "'ambiguous_mapping'",
    "'candidate_count'",
    "'candidates'",
    "'ambiguous_provider_capability_mapping'",
    "'resolve_provider_capability_mapping'",
    "MAD4B_SCP_Skill_Provider_Discovery::inspect()",
    "private static function site_applicability",
    "private static function family_candidates",
    "'not_applicable_on_site'",
    "'no_action_required_while_provider_inactive'",
    "'applicability_state'",
    "'site_applicable'",
    "'provider_family_active'",
    "'provider_adapter_ready'",
    "'provider_coverage_state'",
    "'operational_action_required'",
    "'applicability_state_counts'",
    "'site_applicable_count'",
    "'not_applicable_count'",
    "'unresolved_applicability_count'",
    "'operational_action_required_count'",
    "'owner_review_required_count'",
    "'provider_closure_actions_pending'",
    "'runtime_eligibility_code'",
    "'surface_violations'",
    "'runtime_capability_prerequisite'",
    "'provider_scope_prerequisite'",
    "'provider_source_prerequisite'",
    "'provider_source_policy'",
    "'rollback_contract_certification'",
    "'adapter_contract_defect'",
]
for marker in required:
    if marker not in matrix:
        raise SystemExit(f'missing provider closure matrix invariant: {marker}')

runtime_priority = matrix.index("if ( 'adapter_runtime_capability_not_eligible' === $surface_reason )")
artifact_priority = matrix.index("elseif ( ! empty( $status['artifact_authority_required'] )")
if runtime_priority > artifact_priority:
    raise SystemExit('runtime capability prerequisite must take precedence over generic artifact authority diagnosis')

for marker in [
    "'write_authority_state' => $write_authority_ready ? 'write_authority_current' : 'write_authority_reconciliation_required'",
    "'write_authority_ready_semantics' => 'checkpoint_plus_current_exact_grants_plus_current_candidate_binding'",
    "'write_authority_checkpoint_ready' => $checkpoint_ready",
    "'write_authority_current_grant_snapshot_ready' => $current_grants_ready",
    "'write_authority_blockers' => $write_authority_blockers",
    "'write_authority_reconciliation_required' => ! $write_authority_ready",
    "'write_authority_recovery_action' => ! $write_authority_ready ? 'reconcile_exact_staging_write_authority_then_refresh_provider_closure_matrix' : ''",
    "$write_authority_ready = $checkpoint_ready && $current_grants_ready && $candidate_binding_match;",
]:
    if marker not in matrix:
        raise SystemExit(f'provider closure matrix current-authority semantics missing: {marker}')
if "'write_authority_ready' => isset( $authority_status['ready'] ) ? (bool) $authority_status['ready'] : false" in matrix:
    raise SystemExit('provider closure matrix regressed to checkpoint-only write readiness')

for marker in [
    "'state' => 'unresolved'",
    "'site_applicable' => null",
    "'state' => $active ? 'active' : 'inactive'",
    "$operational_action_required = 'inactive' !== $applicability['state']",
    "'operational_state' => $action_required_count > 0 ? 'provider_closure_actions_pending' : 'provider_closure_operationally_clean'",
]:
    if marker not in matrix:
        raise SystemExit(f'provider closure applicability semantics missing: {marker}')

for forbidden in [
    'MAD4B_SCP_Local_OAuth_Server::consent_grant_projection',
    'update_option(',
    'delete_option(',
    'wp_insert_post(',
    'update_post_meta(',
    'wp_remote_post(',
    'shell_exec(',
    'exec(',
    'system(',
    'passthru(',
    'proc_open(',
    'mad4b/database-raw-query',
]:
    if forbidden in matrix:
        raise SystemExit(f'provider closure matrix must remain read-only: {forbidden}')

if servers.count("'mad4b/provider-closure-matrix'") < 2:
    raise SystemExit('provider closure matrix must be available on both read and ChatGPT surfaces')

if "class-mad4b-scp-provider-closure-matrix.php" not in main:
    raise SystemExit('provider closure matrix runtime is not loaded by the plugin')

if "mad4b_scp_remote_operation_catalog" in matrix:
    raise SystemExit("Provider Closure Matrix must not self-register core operations through the external catalog filter")

for marker in [
    "'provider_closure_matrix' => array(",
    "'remote_ability' => 'mad4b/provider-closure-matrix'",
    "'provider_behavioral_recertification' => array(",
    "'remote_ability' => 'mad4b/provider-behavioral-recertify'",
    "'remote_mode' => 'exact_reversible_probe'",
    "'provider_canary_execution' => array(",
    "'remote_ability' => 'mad4b/provider-canary-execute'",
    "'remote_mode' => 'owner_governed_canary'",
    "'production_policy' => 'deny'",
    "'human_decision_required' => true",
]:
    if marker not in parity:
        raise SystemExit(f'provider closure core operation is not remotely discoverable/fail-closed: {marker}')

for marker in [
    "'staging_candidate_binding' => array(",
    "'remote_ability' => 'mad4b/staging-write-candidate-bind'",
    "'full_staging_authority_convergence' => array(",
    "'remote_ability' => 'mad4b/full-staging-authority-apply'",
    "'human_decision_required' => true",
]:
    if marker not in parity:
        raise SystemExit(f'authority convergence operation is not future-discoverable: {marker}')

for marker in [
    "MAD4B_SCP_Full_Staging_Authority::status()",
    "MAD4B_SCP_Provider_Closure_Matrix::matrix()",
    "'governed_write_lane_ready'",
    "'developer_lane_ready'",
    "'developer_breakglass_lane_ready'",
    "'provider_closure_action_required'",
    "'provider_closure_actions_pending'",
    "'developer_lane_not_ready'",
    "'developer_breakglass_lane_not_ready'",
    "'close_active_provider_certification_gaps'",
    "'lanes' => array(",
    "'provider_closure' => array(",
    "'action_required_count'",
    "'not_applicable_count'",
    "'unresolved_applicability_count'",
    "'production_authorized' => false",
    "'authorizing' => false",
    "'mutation_performed' => false",
]:
    if marker not in operator:
        raise SystemExit(f'operator control operational-truth marker missing: {marker}')

for forbidden in [
    'update_option(',
    'delete_option(',
    'wp_insert_post(',
    'update_post_meta(',
    'wp_remote_post(',
    'shell_exec(',
    'exec(',
    'proc_open(',
]:
    if forbidden in operator:
        raise SystemExit(f'operator control must remain read-only: {forbidden}')

print('mad4b.provider-closure-matrix.v1: PASS')
