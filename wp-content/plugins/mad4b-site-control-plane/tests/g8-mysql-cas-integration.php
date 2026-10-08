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

/**
 * Exercise the ACTUAL Operation_Journal::begin/append source against disposable
 * InnoDB tables. WordPress framework and transaction ownership are narrow test
 * shims; this is SQL acceptance, not live Site Profile or host certification.
 */
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );
class MAD4B_SCP_Identifiers {
    public static function operation_id_for_write( $value ) {
        return is_string( $value ) && preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value ) ? $value : '';
    }
    public static function operation_lookup( $value ) {
        $id = self::operation_id_for_write( $value );
        return $id ? array( 'value' => $id, 'identity_class' => 'canonical_uuid',
            'historical_identity_preserved' => false, 'rewrite_allowed' => false )
            : new WP_Error( 'disposable_operation_id_invalid' );
    }
}
class MAD4B_SCP_Schema {
    public static function tables() { return array(
        'operation_heads' => 'g8_ci_journal_heads',
        'operation_events' => 'g8_ci_journal_events',
    ); }
}
class MAD4B_SCP_Canonicalization {
    public static function digest( $version, $payload ) {
        return hash( 'sha256', serialize( array( $version, $payload ) ) );
    }
}
class MAD4B_SCP_Database_Failure_Semantics {
    public static function classify( $phase, $error, $rollback_verified ) {
        return array( 'reconciliation_required' => ! $rollback_verified,
            'blind_retry_allowed' => false, 'authorizing' => false );
    }
}
class G8_Journal_DB {
    public $db, $last_error = '', $fail_event_insert = false, $fail_head_update = false;
    public function __construct( $connection ) { $this->db = $connection; }
    public function prepare( $query ) {
        $args = func_get_args(); array_shift( $args ); $index = 0; $db = $this->db;
        return preg_replace_callback( '/%[sd]/', static function ( $match ) use ( &$index, $args, $db ) {
            $value = $args[ $index++ ];
            return '%d' === $match[0] ? (string) (int) $value
                : "'" . $db->real_escape_string( (string) $value ) . "'";
        }, $query );
    }
    public function query( $sql ) {
        if ( $this->fail_event_insert && 0 === strpos( $sql, 'INSERT INTO g8_ci_journal_events' ) ) {
            $this->fail_event_insert = false; $this->last_error = 'injected_event_insert_refusal'; return false;
        }
        if ( $this->fail_head_update && 0 === strpos( $sql, 'UPDATE g8_ci_journal_heads' ) ) {
            $this->fail_head_update = false; $this->last_error = 'injected_head_cas_refusal'; return false;
        }
        try {
            $result = $this->db->query( $sql );
            $this->last_error = $this->db->error;
            return false === $result ? false : $this->db->affected_rows;
        } catch ( Throwable $e ) {
            $this->last_error = $e->getMessage(); return false;
        }
    }
    public function get_row( $query, $format ) {
        $result = $this->db->query( $query );
        if ( ! $result ) return null;
        $row = $result->fetch_assoc(); $result->free(); return $row;
    }
    public function get_results( $query, $format ) {
        $result = $this->db->query( $query );
        if ( ! $result ) return false;
        $rows = array();
        while ( $row = $result->fetch_assoc() ) $rows[] = $row;
        $result->free(); return $rows;
    }
}
class MAD4B_SCP_Database_Transaction_Guard {
    public static function preflight( $tables, $refresh ) { return true; }
    public static function begin( $scope, $tables, $refresh ) {
        global $wpdb;
        try { return $wpdb->db->begin_transaction() ? array( 'scope' => $scope )
            : new WP_Error( 'disposable_transaction_begin_failed' ); }
        catch ( Throwable $e ) { return new WP_Error( 'disposable_transaction_begin_failed' ); }
    }
    public static function commit( $lease ) {
        global $wpdb;
        try { return $wpdb->db->commit() ? true : new WP_Error( 'disposable_transaction_commit_unverified' ); }
        catch ( Throwable $e ) { return new WP_Error( 'disposable_transaction_commit_unverified' ); }
    }
    public static function rollback( $lease ) {
        global $wpdb;
        try { return $wpdb->db->rollback() ? true : new WP_Error( 'disposable_transaction_rollback_unverified' ); }
        catch ( Throwable $e ) { return new WP_Error( 'disposable_transaction_rollback_unverified' ); }
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-operation-journal.php';
function g8_journal_assert( $predicate, $why ) {
    if ( ! $predicate ) throw new RuntimeException( 'G8_JOURNAL_FAIL:' . $why );
}
$journal_heads = 'g8_ci_journal_heads';
$journal_events = 'g8_ci_journal_events';
$old_wpdb = $GLOBALS['wpdb'];
try {
    $db->query( 'DROP TABLE IF EXISTS g8_ci_journal_events' );
    $db->query( 'DROP TABLE IF EXISTS g8_ci_journal_heads' );
    g8_journal_assert( (bool) $db->query(
        'CREATE TABLE g8_ci_journal_heads (
            operation_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            operation_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            operation_binding_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
            latest_sequence BIGINT UNSIGNED NOT NULL,
            latest_event_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
            lifecycle_state VARCHAR(64) NOT NULL,
            terminal_outcome VARCHAR(64) NOT NULL,
            heartbeat_at DATETIME NOT NULL,
            lock_expires_at DATETIME NULL,
            stale_after DATETIME NOT NULL,
            hard_deadline_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB' ), 'create_heads' );
    g8_journal_assert( (bool) $db->query(
        'CREATE TABLE g8_ci_journal_events (
            operation_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            operation_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            operation_binding_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
            sequence BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            checkpoint VARCHAR(80) NOT NULL,
            lifecycle_state VARCHAR(80) NOT NULL,
            terminal_outcome VARCHAR(80) NOT NULL,
            safe_metadata_json LONGTEXT NOT NULL,
            previous_event_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
            event_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (operation_id, sequence)
        ) ENGINE=InnoDB' ), 'create_events' );
    $GLOBALS['wpdb'] = new G8_Journal_DB( $db );
    $context = array( 'operation_id' => '88cb12f3-a7ae-49da-8543-ad3acd163b51',
        'operation_key' => 'disposable-journal-check', 'operation_binding_sha256' => str_repeat( 'b', 64 ),
        'hard_deadline_at' => gmdate( 'c', time() + 1200 ) );
    $GLOBALS['wpdb']->fail_event_insert = true;
    $bad = MAD4B_SCP_Operation_Journal::begin( $context, 'planned', array( 'ticket' => 'disposable' ) );
    g8_journal_assert( is_wp_error( $bad ), 'injected_event_refused' );
    $empty = $db->query( 'SELECT operation_id FROM g8_ci_journal_heads' );
    g8_journal_assert( 0 === $empty->num_rows, 'rollback_clears_orphan_head' );
    $empty->free();
    $GLOBALS['wpdb']->fail_head_update = true;
    $bad = MAD4B_SCP_Operation_Journal::begin( $context, 'planned', array( 'ticket' => 'disposable' ) );
    g8_journal_assert( is_wp_error( $bad ), 'injected_head_update_refused' );
    $empty = $db->query( 'SELECT operation_id FROM g8_ci_journal_heads' );
    g8_journal_assert( 0 === $empty->num_rows, 'rollback_clears_event_and_head' );
    $empty->free();
    $first = MAD4B_SCP_Operation_Journal::begin( $context, 'planned', array( 'ticket' => 'disposable' ) );
    g8_journal_assert( is_array( $first ) && 1 === $first['sequence'], 'atomic_genesis_commit' );
    $forged_genesis_append = MAD4B_SCP_Operation_Journal::append( $context, 'operation_started', array(
        'expected_sequence' => 1, 'expected_event_sha256' => $first['event_sha256'] ) );
    g8_journal_assert( is_wp_error( $forged_genesis_append )
        && 'mad4b_operation_genesis_event_reserved' === $forged_genesis_append->get_error_code(),
        'second_genesis_refused' );
    $duplicate = MAD4B_SCP_Operation_Journal::begin( $context, 'planned', array( 'ticket' => 'disposable' ) );
    g8_journal_assert( is_wp_error( $duplicate ) && 'mad4b_operation_journal_head_already_exists' === $duplicate->get_error_code(), 'duplicate_genesis_denied' );
    $stale = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', array(
        'expected_sequence' => 0, 'expected_event_sha256' => str_repeat( '0', 64 ),
        'checkpoint' => 'review_pending', 'lifecycle_state' => 'planned' ) );
    g8_journal_assert( is_wp_error( $stale ), 'stale_event_head_denied' );
    $second = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', array(
        'expected_sequence' => 1, 'expected_event_sha256' => $first['event_sha256'],
        'checkpoint' => 'review_pending', 'lifecycle_state' => 'planned' ) );
    g8_journal_assert( is_array( $second ) && 2 === $second['sequence'], 'exact_event_cas_committed' );
    $trace = MAD4B_SCP_Operation_Journal::trace( $context['operation_id'] );
    g8_journal_assert( is_array( $trace ) && ! empty( $trace['chain_valid'] )
        && ! empty( $trace['complete'] ) && 2 === $trace['count'], 'native_hash_chain_readback' );
    // A coherent-looking head with zero events MUST be rejected.
    $empty_id = '7dc87343-aa9d-44d9-9a24-4b4541e97502';
    $insert_orphan = $GLOBALS['wpdb']->prepare(
        'INSERT INTO g8_ci_journal_heads (operation_id,operation_key,operation_binding_sha256,latest_sequence,latest_event_sha256,lifecycle_state,terminal_outcome,heartbeat_at,lock_expires_at,stale_after,hard_deadline_at,created_at,updated_at) VALUES (%s,%s,%s,0,%s,%s,%s,%s,NULL,%s,%s,%s,%s)',
        $empty_id, 'orphan-head-test', str_repeat( 'b', 64 ), str_repeat( '0', 64 ), 'planned', '',
        gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + 300 ),
        gmdate( 'Y-m-d H:i:s', time() + 1200 ), gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) );
    g8_journal_assert( 1 === $GLOBALS['wpdb']->query( $insert_orphan ), 'seed_disposable_orphan' );
    $orphan_trace = MAD4B_SCP_Operation_Journal::trace( $empty_id );
    g8_journal_assert( is_array( $orphan_trace )
        && empty( $orphan_trace['chain_valid'] ) && empty( $orphan_trace['complete'] ), 'orphan_is_invalid' );
    $orphan_context = $context;
    $orphan_context['operation_id'] = $empty_id;
    $orphan_context['operation_key'] = 'orphan-head-test';
    $orphan_write = MAD4B_SCP_Operation_Journal::append( $orphan_context, 'assistant_task_transition', array(
        'expected_sequence' => 0, 'expected_event_sha256' => str_repeat( '0', 64 ),
        'checkpoint' => 'not_allowed' ) );
    g8_journal_assert( is_wp_error( $orphan_write ), 'orphan_cannot_append' );
    $bad_head_key = $GLOBALS['wpdb']->prepare(
        'UPDATE g8_ci_journal_heads SET operation_key = %s WHERE operation_id = %s',
        'tampered-head-key', $context['operation_id'] );
    g8_journal_assert( 1 === $GLOBALS['wpdb']->query( $bad_head_key ), 'tamper_disposable_head' );
    $drifted_trace = MAD4B_SCP_Operation_Journal::trace( $context['operation_id'] );
    g8_journal_assert( is_array( $drifted_trace ) && empty( $drifted_trace['chain_valid'] )
        && empty( $drifted_trace['complete'] ), 'head_identity_forgery_denied' );
} catch ( Throwable $e ) {
    $journal_error = substr( $e->getMessage(), 0, 160 );
} finally {
    $GLOBALS['wpdb'] = $old_wpdb;
    $drop_events = $db->query( 'DROP TABLE IF EXISTS g8_ci_journal_events' );
    $drop_heads = $db->query( 'DROP TABLE IF EXISTS g8_ci_journal_heads' );
}
if ( isset( $journal_error ) || false === $drop_events || false === $drop_heads ) {
    fwrite( STDERR, 'G8_JOURNAL_FAIL: ' . ( isset( $journal_error ) ? $journal_error : 'cleanup_unverified' ) . PHP_EOL );
    exit( 1 );
}
echo "G8_JOURNAL_TRANSACTION: PASS (atomic genesis, rollback, exact CAS, replay denial, chain readback, cleanup)\n";

$db->query( 'DROP TABLE IF EXISTS `g8_ci_cas_options`' );
foreach ( array( '1.ready', '2.ready', 'go' ) as $name ) @unlink( $gate . '/' . $name );
@rmdir( $gate );
echo 'G8_MYSQL_MARIADB_CAS: PASS (one winner, one conflict, signed readback, replay rejected)' . PHP_EOL;
