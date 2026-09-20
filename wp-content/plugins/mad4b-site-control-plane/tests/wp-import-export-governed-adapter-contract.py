#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
src=(ROOT/'includes/adapters/class-mad4b-scp-wp-import-export-adapter.php').read_text('utf-8')
main=(ROOT/'mad4b-site-control-plane.php').read_text('utf-8')
registry=(ROOT/'includes/class-mad4b-scp-adapter-registry.php').read_text('utf-8')
required=[
'mad4b.wp-import-export-governed-adapter.v2',
'wp-import-export/list-imports','wp-import-export/get-import','wp-import-export/validate-import','wp-import-export/plan-import-run',
'wp-import-export/list-exports','wp-import-export/get-export','wp-import-export/get-export-file-metadata','wp-import-export/validate-export','wp-import-export/plan-export-run',
'wp-import-export/execution-readiness','mad4b.rollback.wp-all-import-run.v1',
"'caller_supplied_secret_allowed'=>false","'secret_material_exposed'=>false","'cron_url_execution_allowed'=>false",
"'mounted_execution_abilities'=>array()",'mad4b_wp_all_import_run_rollback_not_certified',
'mad4b_wp_all_import_direct_execution_contract_unverified','mad4b_wp_all_export_direct_execution_contract_unverified'
]
for marker in required:
    assert marker in src, marker
for forbidden in ['wp_remote_get(','wp_remote_post(','wp_remote_request(','import_key','export_key']:
    assert forbidden not in src, forbidden
assert 'class-mad4b-scp-wp-import-export-adapter.php' in main
assert 'MAD4B_SCP_WP_Import_Export_Adapter' in registry
print('mad4b.wp-import-export-governed-adapter.contract.v1: PASS')
