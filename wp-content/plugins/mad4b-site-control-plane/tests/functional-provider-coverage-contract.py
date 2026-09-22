#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
discovery=(ROOT/'includes/class-mad4b-scp-plugin-discovery.php').read_text('utf-8')
registry=(ROOT/'includes/class-mad4b-scp-adapter-registry.php').read_text('utf-8')
ui=(ROOT/'includes/class-mad4b-scp-adapter-coverage-admin-ui.php').read_text('utf-8')
for marker in ['mad4b.provider-functional-coverage.v1','mad4b.provider-functional-coverage-item.v1','status_only_candidate','contract_discovery_required','provider_contract_evidence_incomplete','evidence_requirements','safe_now','prohibited_until_certified','safety_blocked','adapter_missing','adapter_status_unavailable','adapter_status_invalid_contract','adapter_runtime_unavailable','provider_certification_required','parallel_mcp_write_plane_requires_isolation','restore_or_certify_exact_provider_runtime_before_functional_readiness','complete_exact_provider_certification_before_write_readiness','certify_provider_side_channel_isolation_before_functional_readiness','functional_coverage_report','functional_family_counts','functional_family_states','functional_state_counts','authority_created']:
    assert marker in discovery, marker
for marker in [
    "class_exists( 'MAD4B_SCP_Adapter_Registry' )",
    "MAD4B_SCP_Adapter_Registry::instance()->register_defaults();",
    'creates no persisted',
]:
    assert marker in discovery, marker
assert 'mad4b/provider-functional-coverage' in registry
for marker in ['Functional Gaps','Functional coverage gaps','Provider-family summary','ordered by blocking state and provider risk','deduplicated by family','Contract discovery','Contract discovery required','Evidence needed','Safe now','Blocked scope','risk_ranks','uasort( $groups','Next safe action','status_only_candidate','contract_discovery_required','safety_blocked']:
    assert marker in ui, marker
for forbidden in ['$_POST','admin_post_','$wpdb->insert','$wpdb->update','$wpdb->delete']:
    assert forbidden not in ui, forbidden
print('mad4b.provider-functional-coverage.contract.v1: PASS')
