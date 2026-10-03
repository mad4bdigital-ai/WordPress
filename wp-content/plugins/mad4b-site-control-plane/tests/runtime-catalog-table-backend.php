<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = static function ( $message, $context = null ) {
	fwrite( STDERR, 'FAIL runtime-catalog-table-backend: ' . $message . ( null === $context ? '' : ' ' . wp_json_encode( $context ) ) . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message, $context = null ) use ( $fail ) {
	if ( ! $condition ) $fail( $message, $context );
};

$check( class_exists( 'MAD4B_SCP_Catalog_Table_Backend' ) && class_exists( 'MAD4B_SCP_Catalog_Backend_Controller' ), 'Catalog table backend runtime is unavailable.' );
$check( MAD4B_SCP_Catalog_Table_Backend::ready(), 'Catalog table backend schema is not ready.', MAD4B_SCP_Schema::status( true ) );

$scope_a = MAD4B_SCP_Catalog_Backend_Controller::storage_scope();
$scope_b = MAD4B_SCP_Catalog_Backend_Controller::storage_scope();
$check( 64 === strlen( $scope_a ) && hash_equals( $scope_a, $scope_b ), 'Catalog storage scope is not stable within the site runtime.' );

$t = MAD4B_SCP_Schema::tables();
$protected_before = array(
	'grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['grants']}" ),
	'approvals' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['approvals']}" ),
	'audit_events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['audit_events']}" ),
	'site_profile' => get_option( MAD4B_SCP_Site_Profile::OPTION, null ),
);

$status_before = MAD4B_SCP_Catalog_Backend_Controller::status( $scope_a );
$check( 'options' === $status_before['authority_backend'], 'Disposable runtime must start with options catalog authority.', $status_before );

$key = 'ci.catalog-table.' . strtolower( wp_generate_uuid4() );
$value = array( 'contract' => 'ci.catalog-table-payload.v1', 'nonce' => hash( 'sha256', $key ) );
$store = new MAD4B_SCP_Catalog_Object_Store();
$store->put( $key, $value, 900 );
$store->flush();

$status = MAD4B_SCP_Catalog_Backend_Controller::status( $scope_a );
$shadow = isset( $status['shadow'] ) && is_array( $status['shadow'] ) ? $status['shadow'] : array();
$check( 'options' === $status['authority_backend'] && empty( $status['dual_authority'] ), 'Shadow publication created dual catalog authority.', $status );
$check( ! empty( $shadow['parity'] ) && ! empty( $shadow['options_logical_sha256'] ) && ! empty( $shadow['table_directory_sha256'] ) && ! empty( $shadow['table_fencing_token'] ), 'Options/table shadow parity evidence is incomplete.', $shadow );

$cutover = MAD4B_SCP_Catalog_Backend_Controller::cutover(
	$scope_a,
	$shadow['options_logical_sha256'],
	$shadow['table_directory_sha256'],
	(int) $shadow['table_fencing_token']
);
$check( ! is_wp_error( $cutover ) && 'table' === $cutover['authority_backend'] && empty( $cutover['dual_authority'] ), 'Bounded catalog cutover failed.', $cutover );

// Poison the legacy options object cache. Table-authoritative reads must ignore it.
wp_cache_set( MAD4B_SCP_Catalog_Object_Store::DIRECTORY, array( 'poisoned' => array( 'option'=>'missing', 'expires'=>time()+9999, 'bytes'=>1 ) ), 'options' );
wp_cache_set( 'notoptions', array( MAD4B_SCP_Catalog_Object_Store::DIRECTORY => true ), 'options' );
$table_reader = new MAD4B_SCP_Catalog_Object_Store();
$readback = $table_reader->get( $key );
$check( $value === $readback, 'Stale options/object cache hid or replaced the table-authoritative catalog object.', array( 'readback'=>$readback ) );

$table_status = MAD4B_SCP_Catalog_Table_Backend::status( $scope_a );
$check( ! empty( $table_status['ready'] ) && (int)$table_status['object_count'] > 0 && (int)$table_status['physical_bytes'] > 0, 'Table catalog bytes are not explicitly accounted.', $table_status );

$rollback = MAD4B_SCP_Catalog_Backend_Controller::rollback( $scope_a );
$check( ! is_wp_error( $rollback ) && 'options' === $rollback['authority_backend'] && empty( $rollback['dual_authority'] ), 'Bounded catalog rollback failed.', $rollback );
wp_cache_delete( MAD4B_SCP_Catalog_Object_Store::DIRECTORY, 'options' );
wp_cache_delete( 'notoptions', 'options' );

$protected_after = array(
	'grants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['grants']}" ),
	'approvals' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['approvals']}" ),
	'audit_events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['audit_events']}" ),
	'site_profile' => get_option( MAD4B_SCP_Site_Profile::OPTION, null ),
);
$check( $protected_before === $protected_after, 'Catalog backend cutover/rollback mutated governance authority or Site Profile state.', array( 'before'=>$protected_before, 'after'=>$protected_after ) );

echo "mad4b.catalog-table-backend.runtime.v1: PASS\n";
