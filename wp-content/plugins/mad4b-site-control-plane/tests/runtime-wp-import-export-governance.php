<?php
/**
 * Runtime regression: bulk import/export must remain read/plan-only until
 * provider execution, exact diff, rollback, artifact and receipt contracts are certified.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B WP Import/Export governance] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( ! current_user_can( 'manage_options' ) ) $fail( 'Administrator context is required.' );
if ( ! class_exists( 'MAD4B_SCP_WP_Import_Export_Adapter' ) ) $fail( 'Governed WP Import/Export adapter is unavailable.' );

$adapter = new MAD4B_SCP_WP_Import_Export_Adapter();
$map = $adapter->ability_names();
$reads = isset( $map['read'] ) && is_array( $map['read'] ) ? $map['read'] : array();
$content = isset( $map['content'] ) && is_array( $map['content'] ) ? $map['content'] : array();
$admin = isset( $map['admin'] ) && is_array( $map['admin'] ) ? $map['admin'] : array();

foreach ( array(
	'wp-import-export/inspect-import-contract',
	'wp-import-export/plan-import-run',
	'wp-import-export/inspect-export-contract',
	'wp-import-export/plan-export-run',
	'wp-import-export/execution-readiness',
) as $required ) {
	if ( ! in_array( $required, $reads, true ) ) $fail( 'Missing governed read/plan ability: ' . $required );
}
if ( ! empty( $content ) || ! empty( $admin ) ) $fail( 'Bulk provider execution leaked into write/admin ability surfaces.' );

$status = $adapter->status();
if ( ! is_array( $status ) || 'mad4b.wp-import-export-governed-adapter.v3' !== ( $status['contract'] ?? '' ) ) $fail( 'Unexpected adapter status contract.' );
if ( empty( $status['mutation_requires_certification'] ) ) $fail( 'Future bulk mutation must require provider certification.' );
if ( ! empty( $status['mutation_exposed'] ) ) $fail( 'Bulk mutation is unexpectedly exposed.' );
if ( ! empty( $status['caller_supplied_secret_allowed'] ) || ! empty( $status['secret_material_exposed'] ) || ! empty( $status['cron_url_execution_allowed'] ) ) $fail( 'Secret/cron safety invariant failed.' );

$ready = $adapter->execution_readiness();
if ( ! is_array( $ready ) || 'mad4b.wp-import-export-execution-readiness.v2' !== ( $ready['contract'] ?? '' ) ) $fail( 'Unexpected execution readiness contract.' );
if ( empty( $ready['provider_certification_required'] ) ) $fail( 'Provider certification requirement is missing.' );
if ( ! empty( $ready['mounted_execution_abilities'] ) ) $fail( 'Execution abilities must remain unmounted.' );

$desired = isset( $ready['desired_execution_abilities'] ) && is_array( $ready['desired_execution_abilities'] ) ? $ready['desired_execution_abilities'] : array();
sort( $desired, SORT_STRING );
$expected = array( 'wp-import-export/run-export', 'wp-import-export/run-import' );
sort( $expected, SORT_STRING );
if ( $desired !== $expected ) $fail( 'Public execution model must expose only composite run-import/run-export candidates.' );

foreach ( array(
	'mad4b.bulk-content-io-operation.v1' => $ready['operation_contract'] ?? '',
	'mad4b.content-operations-ledger.v1' => $ready['ledger_contract'] ?? '',
	'mad4b.bulk-content-io-reconciliation.v1' => $ready['reconciliation_contract'] ?? '',
	'mad4b.bulk-content-io-receipt.v1' => $ready['receipt_contract'] ?? '',
) as $expected_contract => $actual_contract ) {
	if ( $expected_contract !== $actual_contract ) $fail( 'Missing bulk operation contract: ' . $expected_contract );
}

$import_blockers = isset( $ready['import']['blockers'] ) && is_array( $ready['import']['blockers'] ) ? $ready['import']['blockers'] : array();
foreach ( array(
	'mad4b_wp_all_import_direct_execution_contract_unverified',
	'mad4b_wp_all_import_dry_run_diff_not_certified',
	'mad4b_wp_all_import_run_rollback_not_certified',
	'mad4b_bulk_content_io_operation_receipt_not_certified',
) as $blocker ) {
	if ( ! in_array( $blocker, $import_blockers, true ) ) $fail( 'Missing import fail-closed blocker: ' . $blocker );
}

$export_blockers = isset( $ready['export']['blockers'] ) && is_array( $ready['export']['blockers'] ) ? $ready['export']['blockers'] : array();
foreach ( array(
	'mad4b_wp_all_export_direct_execution_contract_unverified',
	'mad4b_wp_all_export_artifact_registry_ingest_not_certified',
	'mad4b_bulk_content_io_operation_receipt_not_certified',
) as $blocker ) {
	if ( ! in_array( $blocker, $export_blockers, true ) ) $fail( 'Missing export fail-closed blocker: ' . $blocker );
}

echo "mad4b.wp-import-export-runtime-governance.v1: PASS\n";
