<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

class MAD4B_SCP_Schema {
	public static $ready = true;
	public static function transactional_storage_status( array $required = array(), $refresh = false ) {
		return array(
			'contract' => 'mad4b.database-transactional-storage.v1',
			'ready' => self::$ready,
			'blockers' => self::$ready ? array() : array( 'nontransactional_engine:operation_heads:myisam' ),
			'connection_fingerprint' => str_repeat( 'a', 64 ),
		);
	}
}

class MAD4B_Fake_Transaction_DB {
	public $state = 0;
	public $state_available = true;
	public $queries = array();
	public $last_error = '';
	private $savepoints = array();
	public function suppress_errors( $value = null ) { return false; }
	public function get_var( $sql ) { $this->queries[] = $sql; return null; }
	public function query( $sql ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		if ( ! $this->state_available && 0 === strpos( $sql, 'SAVEPOINT ' ) ) { $this->last_error = 'probe unavailable'; return false; }
		if ( 0 === strpos( $sql, 'SAVEPOINT ' ) ) {
			$name = substr( $sql, strlen( 'SAVEPOINT ' ) );
			if ( $this->state ) $this->savepoints[ $name ] = true;
			return true;
		}
		if ( 0 === strpos( $sql, 'RELEASE SAVEPOINT ' ) ) {
			$name = substr( $sql, strlen( 'RELEASE SAVEPOINT ' ) );
			if ( isset( $this->savepoints[ $name ] ) ) { unset( $this->savepoints[ $name ] ); return true; }
			$this->last_error = 'SAVEPOINT ' . $name . ' does not exist';
			return false;
		}
		if ( 'START TRANSACTION' === $sql ) { $this->state = 1; return true; }
		if ( 'COMMIT' === $sql || 'ROLLBACK' === $sql ) { $this->state = 0; $this->savepoints = array(); return true; }
		return true;
	}

$GLOBALS['wpdb'] = new MAD4B_Fake_Transaction_DB();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-database-transaction-guard.php';

$check = static function ( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL database-transaction-guard-runtime: {$message}\n" ); exit( 1 ); }
};
$code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

MAD4B_SCP_Schema::$ready = false;
$blocked = MAD4B_SCP_Database_Transaction_Guard::begin( 'test', array( 'operation_heads' ), true );
$check( 'mad4b_database_transactional_storage_not_ready' === $code( $blocked ), 'nontransactional storage was admitted' );
$check( 0 === $GLOBALS['wpdb']->state, 'storage rejection started a transaction' );

MAD4B_SCP_Schema::$ready = true;
$GLOBALS['wpdb']->state = 1;
$nested = MAD4B_SCP_Database_Transaction_Guard::begin( 'test', array( 'operation_heads' ), true );
$check( 'mad4b_database_nested_transaction_denied' === $code( $nested ), 'caller-owned transaction was not denied' );
$check( 1 === $GLOBALS['wpdb']->state, 'nested denial implicitly committed or rolled back caller transaction' );

$GLOBALS['wpdb']->state = 0;
$lease = MAD4B_SCP_Database_Transaction_Guard::begin( 'test', array( 'operation_heads' ), true );
$check( is_array( $lease ) && 1 === $GLOBALS['wpdb']->state, 'owned transaction did not start' );
$reentrant = MAD4B_SCP_Database_Transaction_Guard::begin( 'nested', array( 'operation_heads' ), false );
$check( 'mad4b_database_transaction_reentrant_denied' === $code( $reentrant ), 'reentrant MAD4B transaction was admitted' );

$wrong = $lease;
$wrong['token'] = str_repeat( '0', 32 );
$wrong_commit = MAD4B_SCP_Database_Transaction_Guard::commit( $wrong );
$check( 'mad4b_database_transaction_ownership_invalid' === $code( $wrong_commit ), 'wrong ownership token committed transaction' );
$check( 1 === $GLOBALS['wpdb']->state, 'wrong ownership token changed transaction state' );
$check( true === MAD4B_SCP_Database_Transaction_Guard::commit( $lease ), 'owned transaction could not commit' );
$check( 0 === $GLOBALS['wpdb']->state, 'commit did not leave transaction state clean' );

$lease = MAD4B_SCP_Database_Transaction_Guard::begin( 'rollback', array( 'operation_heads' ), false );
$check( is_array( $lease ) && true === MAD4B_SCP_Database_Transaction_Guard::rollback( $lease ), 'owned rollback failed' );
$check( 0 === $GLOBALS['wpdb']->state, 'rollback did not leave transaction state clean' );

$GLOBALS['wpdb']->state_available = false;
$unknown = MAD4B_SCP_Database_Transaction_Guard::preflight( array( 'operation_heads' ), false );
$check( 'mad4b_database_transaction_state_unavailable' === $code( $unknown ), 'unknown transaction state failed open' );

echo "mad4b.database-transaction-guard.runtime.v1: PASS\n";
