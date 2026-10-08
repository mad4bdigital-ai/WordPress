<?php
/**
 * Live DB integration: two independent PHP processes race one WordPress-option
 * compare-and-swap against disposable MySQL/MariaDB database storage.
 * NEVER load credentials from the site or run on a real WordPress database.
 */
if ( '1' !== getenv( 'G8_CAS_DISPOSABLE' ) ) {
	fwrite( STDERR, "G8_CAS_DISPOSABLE=1 required; refusing any DB access\n" ); exit( 2 );
}
/** Admit only the fixture database and recognizable CI-only credentials. */
function g8_disposable_identity( $host, $user, $password, $database, $port ) {
	return in_array( $host, array( '127.0.0.1', 'localhost' ), true )
		&& 'root' === $user && 'g8_ci_contract' === $database
		&& is_string( $password ) && ( 'g8_ci_only_not_a_site_secret' === $password
			|| 1 === preg_match( '/\Ag8_ci_only_[A-Za-z0-9_-]{32}\z/', $password ) )
		&& is_string( $port ) && 1 === preg_match( '/\A[1-9][0-9]{3,4}\z/', $port )
		&& (int) $port >= 1024 && (int) $port <= 65535;
}
if ( 'identity-self-test' === ( $argv[1] ?? '' ) ) {
	$old = 'g8_ci_only_not_a_site_secret'; $new = 'g8_ci_only_' . str_repeat( 'a', 32 );
	$checks = array(
		array( true, '127.0.0.1', 'root', $old, 'g8_ci_contract', '3306' ),
		array( true, '127.0.0.1', 'root', $new, 'g8_ci_contract', '1024' ),
		array( true, 'localhost', 'root', $new, 'g8_ci_contract', '65535' ),
		array( false, 'example.com', 'root', $new, 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1.example.com', 'root', $new, 'g8_ci_contract', '49170' ),
		array( false, '::1', 'root', $new, 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1 ', 'root', $new, 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1', 'wordpress', $new, 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1', 'root', $new, 'wordpress', '49170' ),
		array( false, '127.0.0.1', 'root', 'site-secret', 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1', 'root', $old . ' ', 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1', 'root', $new . "\n", 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1', 'root', 'g8_ci_only_' . str_repeat( 'a', 31 ), 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1', 'root', 'g8_ci_only_' . str_repeat( 'a', 33 ), 'g8_ci_contract', '49170' ),
		array( false, '127.0.0.1', 'root', $new, 'g8_ci_contract', '1023' ),
		array( false, '127.0.0.1', 'root', $new, 'g8_ci_contract', '65536' ),
		array( false, '127.0.0.1', 'root', $new, 'g8_ci_contract', '03306' ),
		array( false, '127.0.0.1', 'root', $new, 'g8_ci_contract', '+3306' ),
		array( false, '127.0.0.1', 'root', $new, 'g8_ci_contract', "3306\n" ),
		array( false, '127.0.0.1', 'root', $new, 'g8_ci_contract', false ),
	);
	foreach ( $checks as $row ) {
		$expected = array_shift( $row );
		if ( $expected !== g8_disposable_identity( ...$row ) ) {
			fwrite( STDERR, "DISPOSABLE_IDENTITY_SELFTEST_FAIL\n" ); exit( 1 );
		}
	}
	echo 'DISPOSABLE_IDENTITY_SELFTEST_PASS: ' . count( $checks ) . " bounded cases; no database connection\n";
	exit( 0 );
}
// Both protections are mandatory. This harness drops only its own fixture
// table, and must never be redirected at a real Staging/Production database.
if ( ! g8_disposable_identity( getenv( 'G8_CAS_HOST' ), getenv( 'G8_CAS_USER' ),
	getenv( 'G8_CAS_PASSWORD' ), getenv( 'G8_CAS_DATABASE' ), getenv( 'G8_CAS_PORT' ) ) ) {
	fwrite( STDERR, "Disposable loopback CI database identity mismatch; refusing DB access\n" );
	exit( 2 );
}
if ( ! extension_loaded( 'mysqli' ) ) { fwrite( STDERR, "mysqli required\n" ); exit( 2 ); }
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_salt( $scheme = '' ) { return str_repeat( 'ci-only-no-live-secrets-', 4 ); }
function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
function maybe_unserialize( $value ) {
	if ( ! is_string( $value ) ) return $value;
	$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
	return false === $decoded && 'b:0;' !== $value ? $value : $decoded;
}
function wp_cache_delete( $key, $group = '' ) { return true; }
class G8_Disposable_WPDB {
	public $options = 'g8_ci_cas_options';
	public $db;
	public function __construct() {
		$this->db = new mysqli(
			'127.0.0.1',
			'root',
			getenv( 'G8_CAS_PASSWORD' ),
			'g8_ci_contract',
			(int) getenv( 'G8_CAS_PORT' )
		);
		$this->db->set_charset( 'utf8mb4' );
	}
	public function prepare( $query ) {
		$args = func_get_args(); array_shift( $args );
		$i = 0; $db = $this->db;
		return preg_replace_callback( '/%s/', static function () use ( &$i, $args, $db ) {
			return "'" . $db->real_escape_string( (string) $args[ $i++ ] ) . "'";
		}, $query );
	}
	public function query( $sql ) {
		$result = $this->db->query( $sql );
		return false === $result ? false : $this->db->affected_rows;
	}
}
$GLOBALS['wpdb'] = new G8_Disposable_WPDB();
function get_option( $name, $default = false ) {
	global $wpdb;
	$q = $wpdb->prepare( "SELECT option_value FROM `g8_ci_cas_options` WHERE option_name = %s", $name );
	$r = $wpdb->db->query( $q );
	if ( ! $r ) throw new RuntimeException( 'Cannot read disposable option' );
	$row = $r->fetch_assoc(); $r->free();
	return null === $row ? $default : maybe_unserialize( $row['option_value'] );
}
function add_option( $name, $value, $deprecated = '', $autoload = false ) {
	global $wpdb;
	$q = $wpdb->prepare(
		"INSERT IGNORE INTO `g8_ci_cas_options` (option_name,option_value,autoload) VALUES (%s,%s,%s)",
		$name, maybe_serialize( $value ), 'no'
	);
	return 1 === (int) $wpdb->query( $q );
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-record.php';
function check_g8( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, 'G8_DB_CAS_FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$option = 'mad4b_scp_g8_disposable_db_cas';
$mode = $argv[1] ?? 'controller';
if ( 'worker' === $mode ) {
	$id = $argv[2] ?? '';
	$gate = $argv[3] ?? '';
	$expected = MAD4B_SCP_G8_Record::read( $option );
	check_g8( is_array( $expected ) && 0 === ( $expected['revision'] ?? -1 ), 'worker baseline' );
	file_put_contents( $gate . '/' . $id . '.ready', 'ready' );
	$start = microtime( true );
	while ( ! is_file( $gate . '/go' ) && microtime( true ) - $start < 20 ) usleep( 20000 );
	check_g8( is_file( $gate . '/go' ), 'barrier timed out' );
	$next = $expected;
	$next['revision'] = 1; $next['winner'] = $id;
	$next['seal'] = MAD4B_SCP_G8_Record::seal( $next );
	$outcome = MAD4B_SCP_G8_Record::replace( $option, $expected, $next );
	echo json_encode( array( 'worker' => $id, 'won' => true === $outcome,
		'error' => is_wp_error( $outcome ) ? $outcome->get_error_code() : '' ) ) . PHP_EOL;
	exit( 0 );
}
if ( 'controller' !== $mode ) { fwrite( STDERR, 'unknown mode' ); exit( 2 ); }
$db = $GLOBALS['wpdb']->db;
$db->query( 'DROP TABLE IF EXISTS `g8_ci_cas_options`' );
check_g8( (bool) $db->query( 'CREATE TABLE `g8_ci_cas_options` (
 option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 option_name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE,
 option_value LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 autoload VARCHAR(20) NOT NULL
 ) ENGINE=InnoDB' ), 'disposable table create' );
$base = array( 'contract' => 'mad4b.g8-disposable-cas-test.v1', 'revision' => 0,
	'authorizing' => false, 'winner' => '', 'seal' => '' );
$base['seal'] = MAD4B_SCP_G8_Record::seal( $base );
check_g8( true === MAD4B_SCP_G8_Record::replace( $option, null, $base ), 'initial INSERT + readback' );
check_g8( is_wp_error( MAD4B_SCP_G8_Record::replace( $option, null, $base ) ), 'same prestate INSERT replay' );
$gate = sys_get_temp_dir() . '/g8-cas-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
check_g8( mkdir( $gate, 0700 ), 'fixture barrier create' );
$workers = array();
for ( $id = 1; $id <= 2; ++$id ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ )
		. ' worker ' . escapeshellarg( (string) $id ) . ' ' . escapeshellarg( $gate );
	$process = proc_open( $command, array(
		0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ),
	), $pipes );
	check_g8( is_resource( $process ), 'worker process create' );
	fclose( $pipes[0] );
	$workers[] = array( $process, $pipes );
}
$start = microtime( true );
while ( ( ! is_file( $gate . '/1.ready' ) || ! is_file( $gate . '/2.ready' ) )
	&& microtime( true ) - $start < 20 ) usleep( 20000 );
check_g8( is_file( $gate . '/1.ready' ) && is_file( $gate . '/2.ready' ), 'both prestate reads arrived' );
file_put_contents( $gate . '/go', 'release' );
$results = array();
foreach ( $workers as $worker ) {
	list( $proc, $pipes ) = $worker;
	$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	$status = proc_close( $proc );
	check_g8( 0 === $status, 'worker exited: ' . $err );
	$row = json_decode( $out, true );
	check_g8( is_array( $row ), 'worker emitted structured outcome: ' . $out );
	$results[] = $row;
}
$wins = count( array_filter( $results, static function ( $r ) { return true === $r['won']; } ) );
$conflicts = count( array_filter( $results, static function ( $r ) {
	return 'mad4b_g8_record_conflict' === $r['error'];
} ) );
check_g8( 1 === $wins && 1 === $conflicts, 'exactly one winner and one CAS conflict' );
$stored = MAD4B_SCP_G8_Record::read( $option );
check_g8( 1 === $stored['revision'] && in_array( $stored['winner'], array( '1', '2' ), true ), 'winner readback' );
check_g8( true === MAD4B_SCP_G8_Record::valid( $stored, 'mad4b.g8-disposable-cas-test.v1' ), 'stored HMAC valid' );
check_g8( is_wp_error( MAD4B_SCP_G8_Record::replace( $option, $base, $stored ) ), 'stale expected prestate cannot replay' );
$db->query( 'DROP TABLE IF EXISTS `g8_ci_cas_options`' );
foreach ( array( '1.ready', '2.ready', 'go' ) as $name ) @unlink( $gate . '/' . $name );
@rmdir( $gate );
echo 'G8_MYSQL_MARIADB_CAS: PASS (one winner, one conflict, signed readback, replay rejected)' . PHP_EOL;
