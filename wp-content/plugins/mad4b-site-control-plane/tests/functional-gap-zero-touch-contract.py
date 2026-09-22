#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
runtime=(ROOT/'includes/class-mad4b-scp-functional-gap-evidence.php').read_text('utf-8')
registry=(ROOT/'includes/class-mad4b-scp-adapter-registry.php').read_text('utf-8')
main=(ROOT/'mad4b-site-control-plane.php').read_text('utf-8')
repo=ROOT.parents[2]
control=(repo/'.github/workflows/mad4b-control-plane-package.yml').read_text('utf-8')
plugin=(repo/'.github/workflows/mad4b-plugin-package.yml').read_text('utf-8')
capture=(repo/'tools/capture-functional-gap-contract-evidence.py').read_text('utf-8')
offline=(repo/'tools/evaluate-functional-gap-contract-promotion.py').read_text('utf-8')
policy=(ROOT/'config/functional-gap-policy.json').read_text('utf-8')
discovery=(ROOT/'includes/class-mad4b-scp-plugin-discovery.php').read_text('utf-8')
ui=(ROOT/'includes/class-mad4b-scp-adapter-coverage-admin-ui.php').read_text('utf-8')

for marker in [
    "mad4b.functional-gap-zero-touch.v1",
    "mad4b.runtime-functional-gap-evidence.v2",
    "mad4b.functional-gap-promotion-evaluation.v2",
    "functional-gap-contract-evidence.generated.json",
    "'promotion_authorized' => false",
    "'mutation_performed' => false",
    "'remote_request_performed' => false",
    "'secret_values_returned' => false",
    "'raw_sql_performed' => false",
    "repository_evidence_build_source_mismatch",
    "read_contract_candidate",
    "redacted_read_contract_candidate",
    "runtime_alignment_required",
    "semantic_attestation_required",
    "repository_evidence_sha256_mismatch",
    "repository_evidence_bytes_mismatch",
    "repository_evidence_not_bound_in_build_provenance",
    "runtime_evidence_unstable",
    "runtime_tree_changed_during_scan",
    "MAX_TREE_FILES",
    "MAX_TREE_BYTES",
    "MAX_SNAPSHOT_TREE_FILES",
    "MAX_SNAPSHOT_TREE_BYTES",
    "MAX_SNAPSHOT_SCAN_SECONDS",
    "snapshot_scan_budget_exceeded",
    "snapshot_scan_time_budget_exceeded",
    "'scan_budget' => array(",
    "tree_scan_budget_exceeded",
    "tree_file_hash_failed",
    "tree_symlink_detected",
    "tree_file_changed_during_hash",
    "repository_evidence_invalid_not_scanned",
    "'deep_scan_performed' => $deep_scan",
    "scan_attempts",
    "evidence_integrity_bound",
    "mad4b.functional-gap-policy.v1",
    "functional-gap-policy.json",
    "repository_evidence_policy_sha256_mismatch",
    "functional_gap_policy_not_bound_in_build_provenance",
    "functional_gap_policy_sha256_mismatch",
    "functional_gap_policy_bytes_mismatch",
    "functional_gap_policy_mutation_default_not_deny",
    "functional_gap_policy_promotion_not_false",
    "functional_gap_policy_match_overlap_",
    "functional_gap_policy_probe_regex_invalid_",
    "plugin_matches_policy_family",
    "'versioned_match'",
    "-v[0-9]+(?:\\.[0-9]+)*/",
    "unsupported_evaluation_mode_",
    "runtime_identity_key",
    "snapshot_identity_sha256",
]:
    if marker not in runtime:
        raise SystemExit(f'missing zero-touch runtime invariant: {marker}')

for forbidden in [
    "update_option(",
    "add_option(",
    "delete_option(",
    "update_post_meta(",
    "delete_post_meta(",
    "wp_remote_get(",
    "wp_remote_post(",
    "wp_remote_request(",
    "$wpdb->query(",
    "$wpdb->insert(",
    "$wpdb->update(",
    "$wpdb->delete(",
    "activate_plugin(",
    "deactivate_plugins(",
]:
    if forbidden in runtime:
        raise SystemExit(f'zero-touch runtime must remain read-only: {forbidden}')

if "class-mad4b-scp-functional-gap-evidence.php" not in main:
    raise SystemExit('zero-touch runtime class is not loaded')
for marker in [
    "mad4b/functional-gap-runtime-evidence",
    "functional_gap_runtime_evidence",
    "MAD4B_SCP_Functional_Gap_Evidence::snapshot()",
    "MAD4B_SCP_Functional_Gap_Evidence::summary()",
    "'functional_gap_zero_touch' => $functional_gap_zero_touch",
]:
    if marker not in registry:
        raise SystemExit(f'zero-touch ability/summary not wired: {marker}')

for marker in [
    "'zero_touch' => $zero_touch",
    "'zero_touch_decision'",
    "'zero_touch_state'",
    "'zero_touch_reason'",
    "MAD4B_SCP_Functional_Gap_Evidence::decision_map()",
]:
    if marker not in discovery:
        raise SystemExit(f'standard coverage report missing zero-touch projection: {marker}')

for marker in [
    "Zero-touch evidence",
    "Unstable scans",
    "zero_touch_decision",
    "zero_touch_state",
]:
    if marker not in ui:
        raise SystemExit(f'coverage UI missing zero-touch projection: {marker}')

for workflow_name,workflow in [('control-plane',control),('plugin-package',plugin)]:
    for marker in [
        "capture-functional-gap-contract-evidence.py",
        "functional-gap-contract-evidence.generated.json",
        "mad4b.functional-gap-contract-evidence.v1",
        "functional-gap-policy.json",
        "SOURCE_SHA",
        "wp-content/plugins/*.zip",
    ]:
        if marker not in workflow:
            raise SystemExit(f'{workflow_name}: missing build-embedded evidence invariant: {marker}')

for marker in [
    "functional_gap_policy_bound_to_build_provenance",
    "config/functional-gap-policy.json",
    "embedded evidence policy SHA mismatch",
]:
    if marker not in control:
        raise SystemExit(f'control-plane package missing policy provenance invariant: {marker}')

for marker in [
    'os.environ.get("SOURCE_SHA","")',
    '"promotion_authorized":False',
    '"evidence_only":True',
    '"package_tree":tree',
    'POLICY_PATH',
    '"policy_contract":POLICY["contract"]',
    '"policy_sha256":sha256(POLICY_RAW)',
    'repository_evidence',
    'repository_artifacts',
    'evaluation_mode',
]:
    if marker not in capture:
        raise SystemExit(f'repository evidence capture invariant missing: {marker}')

import json
policy_data=json.loads(policy)
if policy_data.get('contract')!='mad4b.functional-gap-policy.v1':
    raise SystemExit('canonical functional-gap policy contract mismatch')
if policy_data.get('default_mutation')!='deny' or policy_data.get('promotion_authorized') is not False:
    raise SystemExit('canonical functional-gap policy weakened mutation/promotion defaults')

for marker in [
    'POLICY_PATH',
    'mad4b.functional-gap-policy.v1',
    'policy_sha256',
    'evaluation_mode',
    'promotion_authorized',
]:
    if marker not in offline:
        raise SystemExit(f'offline evaluator is not policy-driven: {marker}')

for forbidden in [
    'FAMILIES = {\n    "bulk-taxonomy-editor"',
    "foreach ( array( 'custom-mega-menu','google-tag-manager'",
    "foreach ( array( 'duplicator','elementskit'",
]:
    if forbidden in capture or forbidden in runtime or forbidden in offline:
        raise SystemExit(f'functional-gap implementation retained hardcoded family policy: {forbidden}')

print('mad4b.functional-gap-zero-touch.contract.v1: PASS')
