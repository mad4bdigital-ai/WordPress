<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$run_id = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';
$worker_id = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';
$host_id = isset( $args[2] ) ? sanitize_key( (string) $args[2] ) : '';
if ( '' === $run_id || '' === $worker_id || '' === $host_id ) {
	fwrite( STDERR, "FAIL runtime-catalog-table-worker: missing run/worker identity\n" );
	exit( 2 );
}
$scope = MAD4B_SCP_Catalog_Backend_Controller::storage_scope();
$key = 'ci.catalog-table.concurrent.' . $run_id . '.' . $worker_id;
$value = array(
	'contract' => 'ci.catalog-table-concurrent-payload.v1',
	'run_id' => $run_id,
	'worker_id' => $worker_id,
	'host_id' => $host_id,
	'payload_sha256' => hash( 'sha256', $run_id . '|' . $worker_id . '|' . $host_id ),
);
$backend = new MAD4B_SCP_Catalog_Table_Backend( $scope );
$backend->put( $key, $value, 1200 );
$result = $backend->flush();
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'FAIL runtime-catalog-table-worker: ' . $result->get_error_code() . ' ' . wp_json_encode( $result->get_error_data() ) . PHP_EOL );
	exit( 3 );
}
$head = $backend->head();
if ( is_wp_error( $head ) || empty( $head['generation_id'] ) || empty( $head['fencing_token'] ) ) {
	fwrite( STDERR, 'FAIL runtime-catalog-table-worker: invalid head ' . wp_json_encode( $head ) . PHP_EOL );
	exit( 4 );
}
echo wp_json_encode( array(
	'contract' => 'mad4b.catalog-table-concurrency-worker.v1',
	'run_id' => $run_id,
	'worker_id' => $worker_id,
	'host_id' => $host_id,
	'key' => $key,
	'generation_id' => (string) $head['generation_id'],
	'fencing_token' => (int) $head['fencing_token'],
) ) . PHP_EOL;
