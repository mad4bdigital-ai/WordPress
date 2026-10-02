<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$fail = static function ( $message, $data = null ) {
	fwrite( STDERR, 'FAIL runtime-database-transaction-guard: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message, $data = null ) use ( $fail ) {
	if ( ! $condition ) $fail( $message, $data );
};
$code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

$check( class_exists( 'MAD4B_SCP_Schema' ), 'Schema contract unavailable' );
$check( class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ), 'Transaction guard unavailable' );
$check( class_exists( 'MAD4B_SCP_Operation_Context' ), 'Operation context unavailable' );
$check( class_exists( 'MAD4B_SCP_Operation_Journal' ), 'Operation journal unavailable' );

$storage = MAD4B_SCP_Schema::transactional_storage_status( array(), true );
$check( is_array( $storage ) && ! empty( $storage['ready'] ), 'Transactional storage is not ready', $storage );
foreach ( $storage['engines'] as $table_key => $engine ) {
	$check( 'innodb' === strtolower( (string) $engine ), 'Governed table is not InnoDB: ' . $table_key, $storage );
}
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $storage['connection_fingerprint'] ), 'Connection fingerprint missing', $storage );

$context = MAD4B_SCP_Operation_Context::create(
	'bulk.db.transaction.guard',
	array( 'fixture' => 'runtime-database-transaction-guard', 'site' => home_url( '/' ) ),
	array( 'hard_deadline_seconds' => 300 )
);
$check( is_array( $context ), 'Could not create operation context', $context );
$started = MAD4B_SCP_Operation_Journal::begin( $context, 'running', array( 'fixture' => 'transaction-ownership' ) );
$check( is_array( $started ), 'Could not initialize operation journal', $started );

global $wpdb;
$check( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not create caller-owned transaction fixture' );
$state = MAD4B_SCP_Database_Transaction_Guard::transaction_state();
$check( 1 === $state, 'Caller-owned transaction fixture is not active', $state );

$nested = MAD4B_SCP_Operation_Journal::append( $context, 'nested_denied_fixture', array( 'lifecycle_state' => 'running' ) );
$check( 'mad4b_database_nested_transaction_denied' === $code( $nested ), 'Operation journal nested transaction was not denied', $nested );
$check( 1 === MAD4B_SCP_Database_Transaction_Guard::transaction_state(), 'Nested denial changed caller transaction state' );
$check( false !== $wpdb->query( 'ROLLBACK' ), 'Could not rollback caller-owned transaction fixture' );
$check( 0 === MAD4B_SCP_Database_Transaction_Guard::transaction_state(), 'Caller rollback did not clear transaction' );

$appended = MAD4B_SCP_Operation_Journal::append( $context, 'owned_transaction_fixture', array( 'lifecycle_state' => 'running' ) );
$check( is_array( $appended ) && 0 === MAD4B_SCP_Database_Transaction_Guard::transaction_state(), 'Guard-owned journal transaction failed', $appended );

$uppercase = $context;
$uppercase['operation_id'] = strtoupper( $context['operation_id'] );
$alias = MAD4B_SCP_Operation_Journal::append( $uppercase, 'collation_alias_fixture', array( 'lifecycle_state' => 'running' ) );
$check( 'mad4b_operation_journal_append_failed' === $code( $alias ), 'Case-alias operation id matched under database collation', $alias );

echo "mad4b.database-transaction-guard.real-db.v1: PASS\n";
