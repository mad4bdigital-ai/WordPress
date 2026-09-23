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
adapter_catalog=(ROOT/'config/adapter-support-catalog.json').read_text('utf-8')
repository_artifacts=(ROOT/'config/repository-plugin-artifacts.json').read_text('utf-8')
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
    "'secret_option_values_read' => false",
    "'probe' => 'omitted_secret_value'",
    "'value_read' => false",
    "exact_runtime_tree_and_secret_values_unread",
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
    "private static function probe_regex_is_bounded",
    "functional_gap_policy_probe_regex_overbroad_",
    "functional_gap_policy_redacted_key_not_probed_",
    "functional_gap_policy_redacted_key_not_secret_classified_",
    "functional_gap_policy_constant_probe_identity_invalid",
    "functional_gap_policy_constant_probe_secret_forbidden",
    "functional_gap_adapter_catalog_invalid",
    "functional_gap_repository_artifact_catalog_invalid",
    "functional_gap_policy_family_missing_from_adapter_catalog_",
    "functional_gap_policy_match_escapes_adapter_catalog_",
    "functional_gap_policy_versioned_match_escapes_adapter_catalog_",
    "functional_gap_policy_repository_authority_family_missing_",
    "functional_gap_policy_artifact_escapes_canonical_map_",
    "functional_gap_policy_read_permission_boundary_missing_",
    "plugin_matches_policy_family",
    "callback_descriptor",
    "get_permission_callbacks",
    "get_permission_missing",
    "get_permission_public",
    "route_security",
    "insecure_get_routes",
    "build_provenance_package_file_changed_during_hash",
    "build_provenance_package_file_changed_after_hash",
    "'versioned_match'",
    "unsupported_evaluation_mode_",
    "runtime_identity_key",
    "snapshot_identity_sha256",
    "private static $fixed_point_retry_depth = 0;",
    "snapshot_runtime_identity_changed_during_evaluation",
    "snapshot_runtime_census_changed_during_evaluation",
    "'snapshot_fixed_point_stable' => $fixed_point_stable",
    "'snapshot_fixed_point_attempts' => self::$fixed_point_retry_depth + 1",
    "'snapshot_census_required' => $census_required",
    "snapshot_end_identity_sha256",
    "snapshot_end_census_sha256",
    "verify_build_provenance_package",
    "MAX_PROVENANCE_VERIFY_FILES",
    "MAX_PROVENANCE_VERIFY_BYTES",
    "MAX_PROVENANCE_VERIFY_SECONDS",
    "MAX_CENSUS_FILES",
    "MAX_CENSUS_BYTES",
    "MAX_CENSUS_SCAN_SECONDS",
    "mad4b.functional-gap-runtime-census.v1",
    "private static function plugin_census_once",
    "private static function runtime_census_from_runtime",
    "public static function current_runtime_census_status()",
    "'metadata_only' => true",
    "'content_rehashed' => false",
    "runtime_census_sha256",
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
    "identity_components",
    "repository_identity_components",
    "version_preflight_tree",
    "version_preflight_metadata_only",
    "repository_version_mismatch",
    "identity_component_header_ambiguous_",
    "identity_component_artifact_missing_",
    "functional_gap_policy_identity_components_missing_",
    "functional_gap_policy_identity_component_artifact_set_mismatch_",
    "config/adapter-support-catalog.json",
    "config/repository-plugin-artifacts.json",
    "$repeat_on_mismatch = ! empty( $rule['repository_evidence'] );",
    "premium_provider_runtime_tree_does_not_match_repository_identity",
    "premium_provider_exact_repository_identity_verified_semantic_review_still_required",
    "composite_provider_runtime_components_do_not_match_repository_identity",
    "composite_provider_exact_component_identity_verified_behavioral_execution_contract_still_required",
    "functional_gap_policy_identity_evidence_required_",
    "functional_gap_policy_identity_artifacts_required_",
    "runtime_evidence_fingerprint",
    "private static function runtime_only_metadata_identity",
    "runtime_only_metadata_identity",
    "$runtime_only_metadata_budget = array( 'files'=>0, 'bytes'=>0, 'started_at'=>microtime( true ) );",
    "runtime_only_metadata_identity( $plugin_file, $runtime_only_metadata_budget )",
    "'runtime_only_metadata_budget' => array(",
    "metadata_only_runtime_identity",
    "'metadata_only' => true",
    "'content_rehashed' => false",
    "'census_sha256'",
    "private static function runtime_evidence_fingerprint( array $runtime )",
    "runtime_dynamic_surface_fingerprint",
    "public static function current_dynamic_surface_fingerprint()",
    "snapshot_dynamic_surface_stable",
    "snapshot_end_dynamic_surface_sha256",
    "snapshot_dynamic_surface_changed_during_evaluation",
    "required_get_permission_callbacks",
    "permission_callback_mismatch",
    "callback_identity_match",
    "functional_gap_policy_read_permission_callback_identity_invalid_",
    "mad4b.functional-gap-decision-handoff.v1",
    "'mutation_authorized' => false",
    "'must_revalidate_before_mutation' => true",
    "recompute_current_runtime_and_require_exact_fingerprint_match",
    "public static function decision_handoff()",
    "'runtime_tree_evidence'=>$runtime_tree_evidence",
    "'runtime_evidence_fingerprint' => (string) $runtime_evidence_fingerprint",
    "canonical_digest_lines",
    "base64_encode",
    "$token = 'b:'",
    "$token = 'i:'",
    "$token = 's:'",
    '"\\tl:" . count( $value )',
    '"\\tm:" . count( $ordered )',
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

runtime_evidence_body = runtime.split("private static function runtime_evidence", 1)[1].split("private static function route_methods", 1)[0]
if runtime_evidence_body.count("$runtime_only_metadata_budget = array(") != 1:
    raise SystemExit('runtime-only metadata budget must be initialized exactly once per runtime evidence snapshot')
if "runtime_only_metadata_identity( $plugin_file, $runtime_only_metadata_budget )" not in runtime_evidence_body:
    raise SystemExit('runtime-only metadata identity is not bound to the shared snapshot budget')

runtime_only_body = runtime.split("private static function runtime_only_metadata_identity", 1)[1].split("private static function runtime_census_from_runtime", 1)[0]
if "hash_file(" in runtime_only_body:
    raise SystemExit('runtime-only metadata identity unexpectedly hashes provider file content')
for marker in ["plugin_census_once(", "'metadata_only' => true", "'content_rehashed' => false", "'census_sha256'"]:
    if marker not in runtime_only_body:
        raise SystemExit(f'runtime-only metadata identity missing invariant: {marker}')

fingerprint_body = runtime.split("private static function runtime_evidence_fingerprint", 1)[1].split("private static function runtime_dynamic_surface_fingerprint", 1)[0]
for marker in ["'census_sha256'", "'metadata_only'", "'content_rehashed'"]:
    if marker not in fingerprint_body:
        raise SystemExit(f'runtime evidence fingerprint missing metadata identity field: {marker}')

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
    "MAD4B_SCP_Functional_Gap_Evidence::coverage_projection()",
    "MAD4B_SCP_Functional_Gap_Evidence::current_runtime_identity_sha256()",
    "coverage_projection_runtime_identity_changed",
    "coverage_projection_dynamic_surface_changed",
    "coverage_projection_runtime_census_changed",
    "'census_required' => $projection_census_required",
    "'census_match' => $projection_census_match",
    "current_runtime_census_status()",
    "'identity_match' => $projection_identity_match",
    "'decision_count' => count( $zero_touch_map )",
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
    'def probe_regex_is_bounded',
    'functional_gap_policy_probe_regex_overbroad_',
    'functional_gap_policy_redacted_key_not_probed_',
    'functional_gap_policy_redacted_key_not_secret_classified_',
    'functional_gap_policy_constant_probe_secret_forbidden',
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

probe_config=policy_data.get('probes',{}) if isinstance(policy_data.get('probes',{}),dict) else {}
secret_re=re.compile(str(probe_config.get('secret_key_regex','')),re.I)
option_probe_keys=set(str(x) for x in probe_config.get('option_keys',[]) if str(x))
for family,rule in policy_data.get('families',{}).items():
    if rule.get('evaluation_mode')!='redacted_status':
        continue
    for key in rule.get('redacted_secret_option_keys',[]) or []:
        if key not in option_probe_keys:
            raise SystemExit(f'redacted secret key is not included in option probes: {family}:{key}')
        if secret_re.search(str(key)) is None:
            raise SystemExit(f'redacted secret key escapes secret classifier: {family}:{key}')
for constant_name in probe_config.get('constants',[]) or []:
    if secret_re.search(str(constant_name)):
        raise SystemExit(f'secret-like constant probe is forbidden: {constant_name}')

catalog_data=json.loads(adapter_catalog)
artifact_data=json.loads(repository_artifacts)
catalog_families={row.get('id'):row for row in catalog_data.get('families',[]) if isinstance(row,dict) and row.get('id')}
artifact_families=artifact_data.get('families',{}) if isinstance(artifact_data.get('families',{}),dict) else {}
for family,rule in policy_data.get('families',{}).items():
    catalog=catalog_families.get(family)
    if not isinstance(catalog,dict):
        raise SystemExit(f'functional-gap policy family missing from adapter catalog: {family}')
    policy_match=set(rule.get('match',[]) or [])
    catalog_match=set(catalog.get('match',[]) or [])
    if not policy_match.issubset(catalog_match):
        raise SystemExit(f'functional-gap policy match escapes adapter catalog identity: {family}: {sorted(policy_match-catalog_match)}')
    policy_versioned=set(rule.get('versioned_match',[]) or [])
    catalog_versioned=set(catalog.get('versioned_match',[]) or [])
    if not policy_versioned.issubset(catalog_versioned):
        raise SystemExit(f'functional-gap policy versioned identity escapes adapter catalog: {family}: {sorted(policy_versioned-catalog_versioned)}')
    if rule.get('evaluation_mode')=='bounded_read_routes' and rule.get('require_non_public_permissions') is not True:
        raise SystemExit(f'bounded read family lost non-public permission requirement: {family}')
    if rule.get('repository_evidence'):
        authority_family=str(catalog.get('adapter_id') or family)
        artifacts=artifact_families.get(authority_family,{}).get('artifacts',[]) if isinstance(artifact_families.get(authority_family,{}),dict) else []
        missing=set(rule.get('repository_artifacts',[]) or [])-set(artifacts)
        if missing:
            raise SystemExit(f'functional-gap policy repository artifacts escape canonical artifact map: {family} via {authority_family}: {sorted(missing)}')

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

# Only families without repository evidence may skip the second full hash pass.
if "single_pass_non_authorizing_identity" not in runtime:
    raise SystemExit('non-authorizing single-pass scan strategy missing')
if "$repeat_on_mismatch = ! empty( $rule['repository_evidence'] );" not in runtime:
    raise SystemExit('repository-backed mismatch must receive drift-confirmation scan')
if "double_pass_drift_confirmation" not in runtime:
    raise SystemExit('repository-backed drift confirmation lost its second pass')
if "version_preflight_metadata_only" not in runtime or "repository_version_mismatch" not in runtime:
    raise SystemExit('identity-first version mismatch no longer short-circuits content hashing')
if "self::plugin_census_once( $plugin_file, $budget )" not in runtime:
    raise SystemExit('version preflight must retain metadata-census fixed-point evidence')

if "empty( $options[ $key ]['exists'] )" not in runtime:
    raise SystemExit('redacted-status model no longer requires status options to exist at runtime')
if "status_ok = bool(status_keys) and all(key in options and options.get(key, {}).get(\"exists\") is True for key in status_keys)" not in offline:
    raise SystemExit('offline evaluator no longer requires WPL status options to exist')

option_loop = runtime.split("foreach ( $option_candidates as $key )", 1)[1].split("$cron = array();", 1)[0]
secret_continue = option_loop.find("'probe' => 'omitted_secret_value'")
secret_get = option_loop.find("$value = get_option( $key, null );")
if secret_continue < 0 or secret_get < 0 or secret_continue > secret_get:
    raise SystemExit('secret option probe must short-circuit before get_option reads any secret value')
for marker in [
    'options.get(key, {}).get("value_read") is False',
    'options.get(key, {}).get("probe") == "omitted_secret_value"',
    'exact_runtime_tree_and_secret_values_unread',
]:
    if marker not in offline:
        raise SystemExit(f'offline evaluator lost never-read secret contract: {marker}')

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

# Bounded-read route security must pin callback identity, not merely require a non-public callback.
for marker in [
    "required_get_permission_callbacks",
    "permission_callback_mismatch",
    "callback_identity_match",
    "exact_runtime_tree_required_get_routes_and_permission_callbacks_verified",
    "exact_runtime_tree_routes_or_permission_callback_boundary_unverified",
]:
    if marker not in runtime or marker not in offline:
        raise SystemExit(f'bounded-read callback identity contract missing across evaluators: {marker}')

if "if ( '__return_true' === strtolower( $descriptor ) )" not in runtime:
    raise SystemExit('public permission callback detection must be case-insensitive')

# Request-local snapshots must revalidate both dynamic route/option/cron
# state and the metadata-only provider census before reuse.
cache_body=runtime.split("public static function snapshot()",1)[1].split("public static function decision_handoff()",1)[0]
for marker in [
    "current_dynamic_surface_fingerprint()",
    "snapshot_end_dynamic_surface_sha256",
    "snapshot_dynamic_surface_changed_during_evaluation",
    "snapshot_census_required",
    "snapshot_end_census",
    "current_runtime_census_status()",
    "$census_match",
    "hash_equals( $cached_census, $current_census_sha )",
    "if ( $dynamic_match && $census_match ) return self::$snapshot;",
]:
    if marker not in cache_body:
        raise SystemExit(f'dynamic/census fixed-point cache invalidation missing: {marker}')

census_body=runtime.split("private static function plugin_census_once",1)[1].split("private static function runtime_census_from_runtime",1)[0]
if "hash_file(" in census_body:
    raise SystemExit('metadata-only runtime census must not re-hash file content')
for marker in ["filesize(", "filemtime(", "census_sha256", "runtime_census_time_budget_exceeded", "runtime_census_snapshot_budget_exceeded"]:
    if marker not in census_body:
        raise SystemExit(f'metadata census invariant missing: {marker}')

identity_body=runtime.split("private static function runtime_identity_key()",1)[1].split("private static function plugin_tree_once",1)[0]
provider_identity_body=identity_body.split("foreach ( array(",1)[0]
if "hash_file(" in provider_identity_body:
    raise SystemExit('request-local provider identity must not hash provider content outside bounded tree evidence')
for marker in ["@lstat( $main )", "$main_stat['size']", "$main_stat['mtime']", "$main_stat['ctime']", "$main_stat['ino']"]:
    if marker not in provider_identity_body:
        raise SystemExit(f'metadata-only provider identity invariant missing: {marker}')

print('mad4b.functional-gap-zero-touch.contract.v1: PASS')
