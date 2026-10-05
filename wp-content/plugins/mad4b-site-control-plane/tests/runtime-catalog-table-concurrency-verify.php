<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$run_id = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';
if ( '' === $run_id ) {
	fwrite( STDERR, "FAIL runtime-catalog-table-concurrency-verify: missing run identity\n" );
	exit( 2 );
}
$fail = static function ( $message, $context = null ) {
	fwrite( STDERR, 'FAIL runtime-catalog-table-concurrency-verify: ' . $message . ( null === $context ? '' : ' ' . wp_json_encode( $context ) ) . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message, $context = null ) use ( $fail ) {
	if ( ! $condition ) $fail( $message, $context );
};

$scope = MAD4B_SCP_Catalog_Backend_Controller::storage_scope();
$backend = new MAD4B_SCP_Catalog_Table_Backend( $scope );
$hosts = array( 'worker-a' => 'host-a', 'worker-b' => 'host-b' );
foreach ( $hosts as $worker_id => $host_id ) {
	$key = 'ci.catalog-table.concurrent.' . $run_id . '.' . $worker_id;
	$value = $backend->get( $key );
	$check(
		is_array( $value )
			&& 'ci.catalog-table-concurrent-payload.v1' === ( isset( $value['contract'] ) ? $value['contract'] : '' )
			&& $run_id === ( isset( $value['run_id'] ) ? $value['run_id'] : '' )
			&& $worker_id === ( isset( $value['worker_id'] ) ? $value['worker_id'] : '' )
			&& $host_id === ( isset( $value['host_id'] ) ? $value['host_id'] : '' ),
		'Concurrent host-equivalent worker generation lost, crossed or replaced a payload.',
		array( 'worker_id' => $worker_id, 'value' => $value )
	);
}
$head = $backend->head();
$check( ! is_wp_error( $head ) && (int) $head['fencing_token'] >= 2, 'Concurrent workers did not advance a fenced table head twice after retirement.', $head );

$status = MAD4B_SCP_Catalog_Backend_Controller::status( $scope );
$check( 'options' === $status['authority_backend'] && empty( $status['dual_authority'] ), 'Concurrent shadow publication changed catalog authority.', $status );
$shadow = isset( $status['shadow'] ) && is_array( $status['shadow'] ) ? $status['shadow'] : array();
if ( ! empty( $shadow['parity'] ) ) {
	$stale_cutover = MAD4B_SCP_Catalog_Backend_Controller::cutover(
		$scope,
		$shadow['options_logical_sha256'],
		$shadow['table_directory_sha256'],
		(int) $shadow['table_fencing_token']
	);
	$check(
		is_wp_error( $stale_cutover ) && 'mad4b_catalog_cutover_head_drift' === $stale_cutover->get_error_code(),
		'Stale parity evidence crossed an advanced publication fence.',
		$stale_cutover
	);
}
echo "mad4b.catalog-table-concurrency.runtime.v1: PASS\n";
