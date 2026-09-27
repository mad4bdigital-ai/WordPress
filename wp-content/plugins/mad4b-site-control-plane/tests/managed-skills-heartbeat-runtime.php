<?php

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function maybe_serialize( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) return serialize( $value );
	return $value;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $name ] : $default;
}
function wp_cache_delete( $key, $group = '' ) { return true; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }

final class MAD4B_Test_WPDB {
	public $options = 'wp_options';
	public $update_calls = 0;
	public $delete_calls = 0;

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->update_calls++;
		return 0;
	}

	public function delete( $table, $where, $where_format = null ) {
		$this->delete_calls++;
		return 0;
	}
}

$GLOBALS['wpdb'] = new MAD4B_Test_WPDB();
$GLOBALS['mad4b_test_options'] = array();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-operation-parity.php';

$method = new ReflectionMethod( 'MAD4B_SCP_Remote_Operation_Parity', 'refresh_skills_lock' );
$method->setAccessible( true );
$owner = '11111111-1111-4111-8111-111111111111';
$lock_option = 'mad4b_scp_remote_skills_reconciliation_lock_v1';

$stable_same_second = false;
for ( $attempt = 0; $attempt < 8; $attempt++ ) {
	$now = time();
	$GLOBALS['mad4b_test_options'][ $lock_option ] = array(
		'owner' => $owner,
		'expires_at_epoch' => $now + 900,
		'acquired_at' => gmdate( 'c', $now ),
		'heartbeat_at' => gmdate( 'c', $now ),
	);
	$GLOBALS['wpdb']->update_calls = 0;
	$result = $method->invoke( null, $owner );
	if ( time() !== $now ) continue;
	if ( true !== $result ) {
		fwrite( STDERR, "same-second no-op heartbeat did not succeed\n" );
		exit( 1 );
	}
	if ( 0 !== $GLOBALS['wpdb']->update_calls ) {
		fwrite( STDERR, "same-second no-op heartbeat reached wpdb update\n" );
		exit( 2 );
	}
	$stable_same_second = true;
	break;
}
if ( ! $stable_same_second ) {
	fwrite( STDERR, "unable to obtain a stable same-second heartbeat test window\n" );
	exit( 3 );
}

$now = time();
$GLOBALS['mad4b_test_options'][ $lock_option ] = array(
	'owner' => $owner,
	'expires_at_epoch' => $now + 899,
	'acquired_at' => gmdate( 'c', $now - 10 ),
	'heartbeat_at' => gmdate( 'c', $now - 1 ),
);
$GLOBALS['wpdb']->update_calls = 0;
$result = $method->invoke( null, $owner );
if ( ! is_wp_error( $result ) || 'mad4b_remote_skill_lock_heartbeat_raced' !== $result->get_error_code() ) {
	fwrite( STDERR, "changed heartbeat record did not remain fail-closed on CAS failure\n" );
	exit( 4 );
}
if ( 1 !== $GLOBALS['wpdb']->update_calls ) {
	fwrite( STDERR, "changed heartbeat record did not attempt exactly one fenced CAS update\n" );
	exit( 5 );
}

$GLOBALS['mad4b_test_options'][ $lock_option ]['owner'] = '22222222-2222-4222-8222-222222222222';
$GLOBALS['wpdb']->update_calls = 0;
$result = $method->invoke( null, $owner );
if ( ! is_wp_error( $result ) || 'mad4b_remote_skill_lock_fenced' !== $result->get_error_code() ) {
	fwrite( STDERR, "foreign lock owner did not fail closed\n" );
	exit( 6 );
}
if ( 0 !== $GLOBALS['wpdb']->update_calls ) {
	fwrite( STDERR, "foreign lock owner unexpectedly attempted a database update\n" );
	exit( 7 );
}

echo "mad4b.managed-skills-heartbeat-runtime.v1: PASS\n";
