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

for workflow_name,workflow in [('control-plane',control),('plugin-package',plugin)]:
    for marker in [
        "capture-functional-gap-contract-evidence.py",
        "functional-gap-contract-evidence.generated.json",
        "mad4b.functional-gap-contract-evidence.v1",
        "SOURCE_SHA",
    ]:
        if marker not in workflow:
            raise SystemExit(f'{workflow_name}: missing build-embedded evidence invariant: {marker}')

for marker in [
    'os.environ.get("SOURCE_SHA","")',
    '"promotion_authorized":False',
    '"evidence_only":True',
    '"package_tree":tree',
]:
    if marker not in capture:
        raise SystemExit(f'repository evidence capture invariant missing: {marker}')

print('mad4b.functional-gap-zero-touch.contract.v1: PASS')
