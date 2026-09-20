#!/usr/bin/env python3
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
src = (ROOT / 'includes/adapters/class-mad4b-scp-wp-import-export-adapter.php').read_text('utf-8')
main = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
registry = (ROOT / 'includes/class-mad4b-scp-adapter-registry.php').read_text('utf-8')
capability_catalog = json.loads((ROOT / 'config/provider-capability-contracts.json').read_text('utf-8'))

required = [
    'mad4b.wp-import-export-governed-adapter.v3',
    'mad4b.wp-import-export-execution-readiness.v3',
    'wp-import-export/list-imports',
    'wp-import-export/get-import',
    'wp-import-export/inspect-import-contract',
    'wp-import-export/validate-import',
    'wp-import-export/plan-import-run',
    'wp-import-export/list-exports',
    'wp-import-export/get-export',
    'wp-import-export/inspect-export-contract',
    'wp-import-export/get-export-file-metadata',
    'wp-import-export/validate-export',
    'wp-import-export/plan-export-run',
    'wp-import-export/execution-readiness',
    'mad4b.wp-all-import-exact-contract.v1',
    'mad4b.wp-all-export-exact-contract.v1',
    'mad4b.bulk-source-artifact.v1',
    'mad4b.bulk-identity-strategy.v1',
    '0_create_1_update_gt1_fail_closed',
    'mad4b.bulk-delete-policy.v1',
    'default_allow_delete',
    'separate_high_risk_approval_required',
    'wp_all_import_delete_policy_unknown',
    'wp_all_import_target_content_schema_unknown',
    'mad4b.bulk-field-effect-policy.v1',
    'mad4b.bulk-import-dry-run.v1',
    'provider_exact_diff_simulation_not_yet_certified',
    'mad4b.bulk-import-plan.v1',
    'mad4b.bulk-export-plan.v1',
    'mad4b.bulk-content-io-operation.v1',
    'mad4b.content-operations-ledger.v1',
    'mad4b.bulk-content-io-reconciliation.v1',
    'mad4b.bulk-content-io-receipt.v1',
    'mad4b.bulk-export-artifact.v1',
    'mad4b.rollback.wp-all-import-run.v1',
    'expected_configuration_sha256',
    'expected_source_sha256',
    'expected_candidate_sha256',
    'configuration_sha256',
    'provider_versions',
    'content_schema_binding_required',
    'mad4b.bulk-target-content-schema.v1',
    'target_content_schema',
    'schema_sha256',
    'expected_target_schema_sha256',
    'post_type_exists',
    'get_post_type_object',
    'get_all_post_type_supports',
    'get_object_taxonomies',
    'source_artifact',
    'identity_strategy',
    'deletion_policy',
    'data_classification',
    'retention_hours',
    "destination'=>'mad4b_artifact_registry'",
    "'mutation_requires_certification'=>true",
    "'provider_certification_required'=>true",
    "'caller_supplied_secret_allowed'=>false",
    "'secret_material_exposed'=>false",
    "'cron_url_execution_allowed'=>false",
    "'mounted_execution_abilities'=>array()",
    'mad4b_wp_all_import_direct_execution_contract_unverified',
    'mad4b_wp_all_import_dry_run_diff_not_certified',
    'mad4b_wp_all_import_run_rollback_not_certified',
    'mad4b_wp_all_export_direct_execution_contract_unverified',
    'mad4b_wp_all_export_artifact_registry_ingest_not_certified',
    'mad4b_bulk_content_io_operation_receipt_not_certified',
]
for marker in required:
    assert marker in src, marker

# Execution is deliberately composite and unmounted. Provider trigger/process
# primitives are internal lifecycle details, not public write abilities.
assert "'wp-import-export/run-import','wp-import-export/run-export'" in src
assert "'content' => array(), 'admin' => array()" in src
assert "protected function mutation_requires_certification() { return false; }" not in src
assert "protected function provider_certification( $available ) { return null; }" not in src

# Read/plan code must not invoke provider network/cron execution or accept raw provider keys.
for forbidden in [
    'wp_remote_get(',
    'wp_remote_post(',
    'wp_remote_request(',
    'import_key',
    'export_key',
]:
    assert forbidden not in src, forbidden

# Provider cron keys may only be tested for server-side presence.
assert "provider_option_present('PMXI_Plugin','cron_job_key')" in src
assert "provider_option_present('PMXE_Plugin','cron_job_key')" in src
assert "'server_secret_configured'=>$import_secret" in src
assert "'server_secret_configured'=>$export_secret" in src
assert "'server_secret_required'=>false" in src
assert "'execution_transport_candidate'=>$import_cli?'server_local_wp_cli':'unresolved'" in src
assert "'execution_transport_candidate'=>$export_record?'server_local_provider_record_execute':'unresolved'" in src
assert 'wp_all_import_server_secret_missing' not in src
assert 'wp_all_export_server_secret_missing' not in src
assert 'mad4b_wp_import_export_exact_composite_artifact_not_certified' in src

# Import configuration/source identity is hash-bound while raw options/paths stay hidden.
assert "'raw_options_exposed'=>false" in src
assert "'source_path_exposed'=>false" in src
assert "'filesystem_path_exposed'=>false" in src
assert "'source_url_exposed'=>false" in src
assert "self::canonical_hash($options)" in src
assert "hash_file('sha256',$path)" in src

# Export planning requires explicit data classification and bounded retention.
assert "'enum'=>array('public','internal','sensitive','restricted')" in src
assert "'minimum'=>1,'maximum'=>720" in src
assert 'mad4b_wp_all_export_classification_required' in src
assert 'mad4b_wp_all_export_retention_invalid' in src

assert 'class-mad4b-scp-wp-import-export-adapter.php' in main
assert 'MAD4B_SCP_WP_Import_Export_Adapter' in registry

provider = capability_catalog.get('providers', {}).get('wp-import-export', {})
assert provider.get('adapter_id') == 'wp-import-export'
caps = provider.get('capabilities', {})
for capability_id in ('jobs.read', 'import.plan', 'export.plan', 'import.execute', 'export.execute'):
    assert capability_id in caps, capability_id
assert caps['import.execute'].get('risk') == 'high_risk_write'
assert caps['import.execute'].get('reversible') is True
assert caps['import.execute'].get('rollback_contract') == 'mad4b.rollback.wp-all-import-run.v1'
assert caps['export.execute'].get('risk') == 'high_risk_write'
assert caps['export.execute'].get('reversible') is False
assert caps['import.execute'].get('abilities') == ['wp-import-export/run-import']
assert caps['export.execute'].get('abilities') == ['wp-import-export/run-export']

print('mad4b.wp-import-export-governed-adapter.contract.v3: PASS')
