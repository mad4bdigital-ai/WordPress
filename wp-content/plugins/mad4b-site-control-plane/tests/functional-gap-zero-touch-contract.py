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
    "tree_census_changed_during_hash",
    "tree_census_stat_failed",
    "'census_sha256' => hash( 'sha256'",
    "RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )",
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
    "unsupported_evaluation_mode_",
    "runtime_identity_key",
    "snapshot_identity_sha256",
    "verify_build_provenance_package",
    "MAX_PROVENANCE_VERIFY_FILES",
    "MAX_PROVENANCE_VERIFY_BYTES",
    "MAX_PROVENANCE_VERIFY_SECONDS",
    "build_provenance_package_path_invalid",
    "build_provenance_package_path_duplicate",
    "build_provenance_package_file_missing",
    "build_provenance_package_file_sha256_mismatch",
    "build_provenance_untracked_runtime_files",
    "build_provenance_declared_files_missing",
    "build_provenance_manifest_recompute_mismatch",
    "build_provenance_fingerprint_recompute_mismatch",
    "build_provenance_adapter_identity_invalid",
    "'integrity_level' => empty( $blockers ) ? 'self_consistent_package' : 'failed_closed'",
    "'external_cryptographic_attestation' => false",
    "'package_self_consistent' => ! empty( $repository['package_integrity']['valid'] )",
    "mad4b.functional-gap-package-integrity.v1",
    "public static function package_integrity_status()",
    "'package_integrity_elapsed_ms'",
    "'package_verified_file_count'",
    "'package_verified_bytes'",
    "'runtime_scan_elapsed_ms'",
    "'runtime_scan_files_hashed'",
    "'runtime_scan_bytes_hashed'",
    "single_pass_exact_match",
    "single_pass_non_authorizing_identity",
    "double_pass_drift_confirmation",
    "runtime_identity_captured",
    "$repeat_on_mismatch = ! in_array( $mode, array( 'runtime_only','premium_semantic','composite_behavioral' ), true );",
    "runtime_evidence_fingerprint",
    "private static function runtime_evidence_fingerprint( array $runtime )",
    "'runtime_tree_evidence'=>$runtime_tree_evidence",
    "'runtime_evidence_fingerprint' => (string) $runtime_evidence_fingerprint",
    "canonical_digest_lines",
    "base64_encode",
    "\\tb:",
    "\\ti:",
    "\\ts:",
    "\\tl:",
    "\\tm:",
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
    "Package integrity",
    "package_self_consistent",
    "package_integrity_level",
    "Integrity verify cost",
    "Runtime scan cost",
    "package_integrity_elapsed_ms",
    "runtime_scan_elapsed_ms",
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
    "mad4b.functional-gap-package-integrity.v1: PASS",
    "package_integrity_status",
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

import re
menu_policy=policy_data.get('families',{}).get('custom-mega-menu',{})
versioned=list(menu_policy.get('versioned_match',[]))
if versioned != ['custom-mega-menu']:
    raise SystemExit('Custom Mega Menu versioned identity policy drifted')
base=re.escape(versioned[0])
numeric=re.compile(r'^'+base+r'-v[0-9]+(?:[.][0-9]+)*/')
for accepted in ('custom-mega-menu-v49/custom-mega-menu.php','custom-mega-menu-v1.2/custom-mega-menu.php'):
    if not numeric.match(accepted):
        raise SystemExit(f'numeric versioned identity rejected: {accepted}')
for rejected in ('custom-mega-menu-villain/custom-mega-menu.php','custom-mega-menu-v/custom-mega-menu.php'):
    if numeric.match(rejected):
        raise SystemExit(f'lookalike versioned identity accepted: {rejected}')
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

# MCP Adapter is a sibling distribution artifact. Runtime package verification
# must validate the declared adapter identity and fingerprint binding without
# pretending the adapter archive lives inside the Control Plane ZIP.
if "dependencies/mcp-adapter.zip" in runtime:
    raise SystemExit('Control Plane package verifier incorrectly requires sibling MCP Adapter bytes inside the plugin ZIP')
if "build_provenance_adapter_identity_invalid" not in runtime:
    raise SystemExit('Control Plane package verifier lost adapter identity validation')

# Runtime provenance verification must remain self-consistency evidence only;
# it must never pretend to be an external signature/attestation.
if "'external_cryptographic_attestation' => true" in runtime:
    raise SystemExit('zero-touch runtime must not claim external cryptographic attestation without an external trust root')
if "self_consistent_package" not in runtime or "failed_closed" not in runtime:
    raise SystemExit('runtime package integrity states are incomplete')

# Only non-authorizing families may skip the second full hash pass.
if "single_pass_non_authorizing_identity" not in runtime:
    raise SystemExit('non-authorizing single-pass scan strategy missing')
if "array( 'runtime_only','premium_semantic','composite_behavioral' )" not in runtime:
    raise SystemExit('single-pass optimization escaped its non-authorizing modes')
if "double_pass_drift_confirmation" not in runtime:
    raise SystemExit('repository-backed drift confirmation lost its second pass')

# Decision identity must include canonical runtime evidence, not only the resulting state.
if "self::decision_fingerprint( $repository, $policy, $decisions, $runtime_evidence_fingerprint )" not in runtime:
    raise SystemExit('decision fingerprint is not bound to runtime evidence fingerprint')
for marker in [
    "'families' => $families",
    "'rest_routes' => $routes",
    "'ajax_hooks' => $ajax",
    "'option_presence' => $options",
    "'cron_hooks' => $cron",
    "'constants' => $constants",
]:
    if marker not in runtime:
        raise SystemExit(f'canonical runtime evidence fingerprint missing input: {marker}')

print('mad4b.functional-gap-zero-touch.contract.v1: PASS')
