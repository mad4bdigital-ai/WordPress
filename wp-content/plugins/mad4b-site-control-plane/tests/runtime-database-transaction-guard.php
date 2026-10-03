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
$check( class_exists( 'MAD4B_SCP_Database_Topology' ), 'Database topology contract unavailable' );
$check( class_exists( 'MAD4B_SCP_Database_Failure_Semantics' ), 'Database failure semantics unavailable' );
$check( class_exists( 'MAD4B_SCP_Operation_Context' ), 'Operation context unavailable' );
$check( class_exists( 'MAD4B_SCP_Operation_Journal' ), 'Operation journal unavailable' );

$topology = MAD4B_SCP_Database_Topology::assert_write_ready( true );
$check( is_array( $topology ) && ! empty( $topology['ready'] ) && ! empty( $topology['read_your_writes'] ), 'Database topology is not write-safe', $topology );
$check( empty( $topology['database_dropin_present'] ), 'Disposable runtime unexpectedly has a database router drop-in', $topology );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $topology['connection_fingerprint'] ), 'Topology connection fingerprint missing', $topology );
$storage = MAD4B_SCP_Schema::transactional_storage_status( array(), true );
$check( is_array( $storage ) && ! empty( $storage['ready'] ), 'Transactional storage is not ready', $storage );
foreach ( $storage['engines'] as $table_key => $engine ) {
	$check( 'innodb' === strtolower( (string) $engine ), 'Governed table is not InnoDB: ' . $table_key, $storage );
}
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $storage['connection_fingerprint'] ), 'Connection fingerprint missing', $storage );
$check( ! empty( $storage['read_your_writes'] ) && isset( $storage['database_topology']['connection_fingerprint'] ), 'Storage readiness did not bind database topology', $storage );
$deadlock_semantics = MAD4B_SCP_Database_Failure_Semantics::classify( 'runtime_fixture', 'Deadlock found when trying to get lock; try restarting transaction', true );
$check( 'deadlock' === $deadlock_semantics['failure_class'] && empty( $deadlock_semantics['blind_retry_allowed'] ) && empty( $deadlock_semantics['reconciliation_required'] ), 'Deadlock semantics are not bounded', $deadlock_semantics );
$lost_semantics = MAD4B_SCP_Database_Failure_Semantics::classify( 'runtime_fixture', 'Lost connection to MySQL server during query', null );
$check( 'connection_loss' === $lost_semantics['failure_class'] && ! empty( $lost_semantics['reconciliation_required'] ), 'Connection-loss semantics failed open', $lost_semantics );

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
$check( 'mad4b_operation_id_invalid' === $code( $alias ), 'Case-alias operation id bypassed canonical write identity boundary', $alias );

echo "mad4b.database-transaction-guard.real-db.v1: PASS\n";
