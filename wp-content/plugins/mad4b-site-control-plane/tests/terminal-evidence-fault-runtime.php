<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = (string) $code;
		$this->message = (string) $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

class MAD4B_SCP_Identifiers {
	public static function receipt_id_from_sha256( $sha ) {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $sha )
			? 'receipt:' . substr( (string) $sha, 0, 32 )
			: '';
	}
}
class MAD4B_SCP_Approval_Impact_Binding {}
class MAD4B_SCP_Authorization_Decision_Graph {}

class MAD4B_SCP_Execution_Receipt {
	public static $fail = false;
	public static function build( array $claim, $result, array $terminal ) {
		if ( self::$fail ) return new WP_Error( 'fixture_execution_receipt_store_unavailable', 'fixture' );
		return array(
			'receipt_sha256' => hash( 'sha256', 'execution-receipt|' . (string) $terminal['receipt_sha256'] ),
			'signature_state' => 'verified',
		);
	}
}

class MAD4B_SCP_Audit {
	public static $mode = 'ok';
	public static $calls = 0;
	public static $events = array();
	public static $chain_valid = true;
	public static $head_consistent = true;
	public static function reset( $mode = 'ok' ) {
		self::$mode = (string) $mode;
		self::$calls = 0;
		self::$events = array();
		self::$chain_valid = true;
		self::$head_consistent = true;
	}
	public static function record( $ability, array $summary, $status ) {
		self::$calls++;
		if ( 'fail_first' === self::$mode && 1 === self::$calls ) {
			return new WP_Error( 'fixture_audit_store_unavailable', 'fixture' );
		}
		if ( 'fail_second' === self::$mode && 2 === self::$calls ) {
			return new WP_Error( 'fixture_receipt_audit_store_unavailable', 'fixture' );
		}
		$entry = array(
			'event_id' => sprintf( '11111111-1111-4111-8111-%012d', self::$calls ),
			'entry_hash' => hash( 'sha256', 'audit|' . self::$calls . '|' . (string) $status ),
			'ability' => (string) $ability,
			'status' => (string) $status,
			'summary' => $summary,
		);
		self::$events[] = $entry;
		return $entry;
	}
	public static function execution_checkpoint_events( $attempt, $limit = 20 ) {
		$events = array_values( array_filter( self::$events, static function ( $entry ) use ( $attempt ) {
			return isset( $entry['summary']['execution_attempt_sha256'] )
				&& hash_equals( strtolower( (string) $attempt ), strtolower( (string) $entry['summary']['execution_attempt_sha256'] ) )
				&& in_array( sanitize_key( (string) ( $entry['summary']['reason_code'] ?? '' ) ), array( 'execution_checkpoint', 'unified_execution_receipt_committed' ), true );
		} ) );
		if ( count( $events ) > $limit ) $events = array_slice( $events, -$limit );
		return array(
			'events' => $events,
			'chain_valid' => self::$chain_valid,
			'head_consistent' => self::$head_consistent,
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-execution-evidence-policy.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php';

$fail = static function( $message, $value = null ) {
	fwrite( STDERR, 'FAIL terminal-evidence-fault-runtime: ' . $message . ( null === $value ? '' : ' ' . json_encode( $value ) ) . PHP_EOL );
	exit( 1 );
};
$check = static function( $condition, $message, $value = null ) use ( $fail ) {
	if ( ! $condition ) $fail( $message, $value );
};
$assert_reconciling = static function( $value, $label ) use ( $check ) {
	$check( is_wp_error( $value ), $label . ' did not fail closed', $value );
	$check( 'mad4b_execution_terminal_evidence_persist_failed' === $value->get_error_code(), $label . ' returned an unstable terminal error code', $value->get_error_code() );
	$data = $value->get_error_data();
	$check( is_array( $data ), $label . ' omitted structured error data', $data );
	$check( 'RECONCILING' === ( $data['state'] ?? '' ), $label . ' did not enter RECONCILING', $data );
	$check( ! empty( $data['reconciliation_required'] ), $label . ' did not require reconciliation', $data );
	$check( empty( $data['blind_retry_allowed'] ), $label . ' allowed blind retry', $data );
	$check( empty( $data['terminal_success'] ), $label . ' emitted terminal success', $data );
};

$claim = array(
	'ability' => 'fixture/mutate',
	'provider' => 'core',
	'server_id' => 'mad4b-write',
	'request_id' => 'request-evidence-fault',
	'approval_required' => false,
	'target_fingerprint' => str_repeat( 'a', 64 ),
	'resource_set_sha256' => str_repeat( 'b', 64 ),
	'context_receipt_sha256' => str_repeat( 'c', 64 ),
	'commit_guard_receipt' => array( 'material_sha256' => str_repeat( 'd', 64 ) ),
);
$result = array( 'updated' => true, 'id' => 7 );

// A provider-entry checkpoint must be durable before the governed callback can run.
MAD4B_SCP_Audit::reset();
$entry_checkpoint = MAD4B_SCP_Authorization::execution_checkpoint( $claim, 'provider_entry_possible' );
$check( is_array( $entry_checkpoint ) && 'provider_entry_possible' === $entry_checkpoint['crash_point'] && 'RECONCILING' === $entry_checkpoint['state'], 'provider-entry checkpoint contract invalid', $entry_checkpoint );
$attempt = $entry_checkpoint['execution_attempt_sha256'];
$restart_view = MAD4B_SCP_Authorization::execution_attempt_state( $attempt );
$check( is_array( $restart_view ) && 'RECONCILING' === $restart_view['state'] && ! empty( $restart_view['reconciliation_required'] ), 'restart view inferred safe retry after provider entry', $restart_view );

MAD4B_SCP_Audit::reset( 'fail_first' );
$entry_checkpoint_failure = MAD4B_SCP_Authorization::execution_checkpoint( $claim, 'provider_entry_possible' );
$check( is_wp_error( $entry_checkpoint_failure ) && 'mad4b_execution_checkpoint_persist_failed' === $entry_checkpoint_failure->get_error_code(), 'provider-entry persistence failure did not fail closed', $entry_checkpoint_failure );
$entry_data = $entry_checkpoint_failure->get_error_data();
$check( 'RECONCILING' === ( $entry_data['state'] ?? '' ) && ! empty( $entry_data['reconciliation_required'] ) && empty( $entry_data['blind_retry_allowed'] ), 'provider-entry persistence failure lost reconciliation semantics', $entry_data );

$unknown_checkpoint = MAD4B_SCP_Authorization::execution_checkpoint( $claim, 'future_unknown_point' );
$check( is_wp_error( $unknown_checkpoint ) && 'mad4b_execution_crash_point_unknown' === $unknown_checkpoint->get_error_code(), 'unknown crash boundary failed open', $unknown_checkpoint );

// Provider has returned successfully, but the first durable audit write fails.
MAD4B_SCP_Audit::reset( 'fail_first' );
MAD4B_SCP_Execution_Receipt::$fail = false;
$first_audit = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $result );
$assert_reconciling( $first_audit, 'terminal audit persistence failure' );

// Provider has returned, but the result cannot be canonically represented as terminal evidence.
MAD4B_SCP_Audit::reset();
$resource = fopen( 'php://memory', 'r' );
$invalid_material = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, array( 'opaque' => $resource ) );
fclose( $resource );
$assert_reconciling( $invalid_material, 'terminal evidence canonicalization failure' );

// Provider has returned and audit is durable, but unified receipt construction fails.
MAD4B_SCP_Audit::reset();
MAD4B_SCP_Execution_Receipt::$fail = true;
$receipt_build = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $result );
MAD4B_SCP_Execution_Receipt::$fail = false;
$assert_reconciling( $receipt_build, 'execution receipt build failure' );

// Provider, terminal audit and receipt build succeed, but receipt-chain persistence fails.
MAD4B_SCP_Audit::reset( 'fail_second' );
$receipt_audit = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $result );
$assert_reconciling( $receipt_audit, 'receipt audit persistence failure' );

// The same path remains terminally successful only when all mandatory evidence commits.
MAD4B_SCP_Audit::reset();
$ok = MAD4B_SCP_Authorization::finalize_execution_claim( $claim, $result );
$check( true === $ok, 'fully durable terminal evidence path did not succeed', $ok );
$check( 2 === MAD4B_SCP_Audit::$calls, 'success path did not persist both audit layers', MAD4B_SCP_Audit::$calls );
$terminal_attempt = '';
foreach ( MAD4B_SCP_Audit::$events as $entry ) {
	if ( 'unified_execution_receipt_committed' === ( $entry['summary']['reason_code'] ?? '' ) ) $terminal_attempt = (string) ( $entry['summary']['execution_attempt_sha256'] ?? '' );
}
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $terminal_attempt ), 'terminal receipt audit lost execution attempt correlation', $terminal_attempt );
$terminal_view = MAD4B_SCP_Authorization::execution_attempt_state( $terminal_attempt );
$check( is_array( $terminal_view ) && 'COMMITTED' === $terminal_view['state'] && ! empty( $terminal_view['terminal'] ), 'durable receipt did not recover as COMMITTED', $terminal_view );
MAD4B_SCP_Audit::$chain_valid = false;
$corrupt_view = MAD4B_SCP_Authorization::execution_attempt_state( $terminal_attempt );
$check( is_array( $corrupt_view ) && 'RECONCILING' === $corrupt_view['state'] && empty( $corrupt_view['terminal'] ), 'invalid audit chain still recovered terminal success', $corrupt_view );

echo "mad4b.terminal-evidence-fault.runtime.v2: PASS\n";
