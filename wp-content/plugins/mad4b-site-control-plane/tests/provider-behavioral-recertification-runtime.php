<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MAD4B_SCP_DIR', __DIR__ . '/' );

$GLOBALS['opts'] = array();
$GLOBALS['provider_state'] = array( 'value' => 'before_value' );
$GLOBALS['probe_mode'] = 'success';
$GLOBALS['ticket_status'] = 'executing';
$GLOBALS['ticket_id'] = '11111111-1111-1111-1111-111111111111';
$GLOBALS['binding_sha'] = str_repeat( 'a', 40 );
$GLOBALS['binding_build'] = str_repeat( 'b', 64 );
$GLOBALS['audit_rows'] = array();
$GLOBALS['audit_seq'] = 0;

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function current_user_can( $capability ) { return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function add_action() { return true; }
function add_filter() { return true; }
function wp_register_ability() { return true; }
function wp_has_ability() { return false; }
function wp_salt( $scheme = 'auth' ) { return str_repeat( 's', 64 ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $key ] : $default; }
function update_option( $key, $value, $autoload = false ) { $changed = ! array_key_exists( $key, $GLOBALS['opts'] ) || $GLOBALS['opts'][ $key ] !== $value; $GLOBALS['opts'][ $key ] = $value; return $changed; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data( $code = '' ) { return $this->data; }
}

class MAD4B_SCP_Adapter_Base {}
class MAD4B_SCP_Policy { public static function can_mutate() { return true; } }
class MAD4B_SCP_Site_Profile { public static function nonproduction_governed( $feature = '' ) { return 'write' === (string) $feature; } }
class MAD4B_SCP_Staging_Write_Authority {
	const APPROVAL_INPUT_KEY = '_mad4b_approval';
	public static function eligible() { return true; }
}
class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array(
			'source_commit_sha' => str_repeat( 'a', 40 ),
			'build_fingerprint' => str_repeat( 'b', 64 ),
			'manifest_present' => true,
			'manifest_valid' => true,
			'runtime_manifest_match' => true,
			'stale' => false,
		);
	}
}
class MAD4B_SCP_Provider_Behavioral_Evidence {
	const RECEIPT_CONTRACT = 'mad4b.provider-behavioral-evidence-receipt.v1';
	const MAX_RECEIPTS = 64;
	const MAX_RECEIPT_TTL = 604800;
}
class MAD4B_SCP_Servers { public static function ability_is_mounted( $server, $ability ) { return false; } }
class MAD4B_SCP_Provider_Compatibility_Certification {
	const ACTIVATION_SHADOW = 'shadow';
	public static $cache_clears = 0;
	public static function supports_provider( $provider ) { return 'jetsmartfilters' === $provider; }
	public static function ability_status( $provider, $ability, $adapter ) {
		return array(
			'capability_id' => 'filter_meta.bounded-write',
			'risk' => 'bounded_write',
			'reversible' => true,
			'structural_compatible' => true,
			'write_eligible' => false,
			'activation_stage' => 'shadow',
			'rollback_contract' => 'mad4b.rollback.jetsmartfilters-filter-meta.v1',
			'artifact' => array( 'runtime_artifact_fingerprint' => str_repeat( 'c', 64 ) ),
			'capability_contract_digest' => str_repeat( 'd', 64 ),
		);
	}
	public static function clear_request_cache() { self::$cache_clears++; }
}
class FakeAdapter extends MAD4B_SCP_Adapter_Base {
	public function provider_key() { return 'jetsmartfilters'; }
	public function reversible_contract_for( $ability ) { return 'mad4b.rollback.jetsmartfilters-filter-meta.v1'; }
	public function capture_reversible_state( $ability, array $input ) {
		return array(
			'target_type' => 'jetsmartfilters-filter-meta',
			'target_id' => '7:_query_var',
			'target' => array( 'filter_id' => 7, 'meta_key' => '_query_var' ),
			'state' => array( 'exists' => true, 'value' => $GLOBALS['provider_state']['value'] ),
		);
	}
	public function read_reversible_state( $ability, array $target ) { return array( 'exists' => true, 'value' => $GLOBALS['provider_state']['value'] ); }
	public function restore_reversible_state( $ability, array $target, array $state, array $record ) { $GLOBALS['provider_state']['value'] = (string) $state['value']; return true; }
	public function update_filter_meta( array $input ) {
		$GLOBALS['provider_state']['value'] = (string) $input['value'];
		if ( 'fail_after_side_effect' === $GLOBALS['probe_mode'] ) return new WP_Error( 'provider_failed_after_side_effect', 'fixture failure' );
		return array( 'updated' => true, 'sha256' => hash( 'sha256', (string) $input['value'] ) );
	}
}
class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	private $adapter;
	public static function instance() { if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
	public function __construct() { $this->adapter = new FakeAdapter(); }
	public function get( $id ) { return 'jetsmartfilters' === $id ? $this->adapter : null; }
	public function register( $adapter ) { return true; }
}
class MAD4B_SCP_Identity_Context { public static function current() { return array( 'approval_ticket_id' => $GLOBALS['ticket_id'] ); } }
class MAD4B_SCP_Approval_Tickets {
	public static function get( $ticket_id ) {
		return array(
			'ticket_id' => $ticket_id,
			'status' => $GLOBALS['ticket_status'],
			'server_id' => 'mad4b-write',
			'ability_name' => 'mad4b/provider-behavioral-recertify',
		);
	}
	public static function candidate_binding( $ticket_id ) { return array( 'candidate_sha' => $GLOBALS['binding_sha'], 'build_fingerprint' => $GLOBALS['binding_build'] ); }
}
class MAD4B_SCP_Schema { public static function tables() { return array( 'audit_events' => 'wp_mad4b_scp_audit_events' ); } }
class MAD4B_SCP_Audit {
	public static function storage_status() { return array( 'ready' => true ); }
	public static function record( $ability, $summary = array(), $status = 'ok' ) {
		$GLOBALS['audit_seq']++;
		$event_id = sprintf( '22222222-2222-2222-2222-%012d', $GLOBALS['audit_seq'] );
		$entry_hash = hash( 'sha256', $ability . '|' . $status . '|' . json_encode( $summary ) );
		$row = array( 'event_id' => $event_id, 'entry_hash' => $entry_hash, 'ability' => $ability, 'status' => $status, 'sequence' => $GLOBALS['audit_seq'] );
		$GLOBALS['audit_rows'][ $event_id ] = $row;
		return $row;
	}
}
class FakeWpdb {
	public function prepare( $sql, $value ) { return array( $sql, $value ); }
	public function get_row( $prepared, $mode ) { $event_id = is_array( $prepared ) ? $prepared[1] : ''; return isset( $GLOBALS['audit_rows'][ $event_id ] ) ? $GLOBALS['audit_rows'][ $event_id ] : null; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-behavioral-recertification.php';

function expect_true( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function expect_same( $expected, $actual, $message ) { if ( $expected !== $actual ) { fwrite( STDERR, "FAIL: {$message} expected=" . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . "\n" ); exit( 1 ); } }

$input = array(
	'provider_id' => 'jetsmartfilters',
	'capability_id' => 'filter_meta.bounded-write',
	'target_ability' => 'jetsmartfilters/update-filter-meta',
	'expected_candidate_sha' => str_repeat( 'a', 40 ),
	'expected_build_fingerprint' => str_repeat( 'b', 64 ),
	'expected_artifact_fingerprint' => str_repeat( 'c', 64 ),
	'expected_capability_contract_digest' => str_repeat( 'd', 64 ),
	'target_input' => array( 'filter_id' => 7, 'meta_key' => '_query_var', 'value' => 'probe_value', 'expected_sha256' => str_repeat( 'e', 64 ) ),
);
$context = array(
	'provider_id' => 'jetsmartfilters',
	'capability_id' => 'filter_meta.bounded-write',
	'artifact_fingerprint' => str_repeat( 'c', 64 ),
	'capability_contract_digest' => str_repeat( 'd', 64 ),
);

expect_same( true, MAD4B_SCP_Provider_Behavioral_Recertification::can_run( $input ), 'governed preflight allows exact shadow bounded probe' );
$result = MAD4B_SCP_Provider_Behavioral_Recertification::run( $input );
expect_true( is_array( $result ), 'successful probe returns evidence' );
expect_same( true, $result['behavioral_passed'], 'behavioral probe passes' );
expect_same( true, $result['rollback_verified'], 'rollback is verified' );
expect_same( 'before_value', $GLOBALS['provider_state']['value'], 'successful probe restores exact before-state' );
expect_same( true, $result['receipt_pending_approval_finalization'], 'receipt remains pending before central finalizer' );
$before = MAD4B_SCP_Provider_Behavioral_Recertification::provide_receipts( array(), $context );
expect_same( 0, count( $before ), 'executing ticket cannot surface receipt' );

$GLOBALS['ticket_status'] = 'used';
$after = MAD4B_SCP_Provider_Behavioral_Recertification::provide_receipts( array(), $context );
expect_same( 1, count( $after ), 'used ticket surfaces exactly one receipt' );
$receipt = $after[0];
$canonical = array(
	'contract' => $receipt['contract'],
	'provider_id' => $receipt['provider_id'],
	'capability_id' => $receipt['capability_id'],
	'artifact_fingerprint' => $receipt['artifact_fingerprint'],
	'capability_contract_digest' => $receipt['capability_contract_digest'],
	'verifier_id' => $receipt['verifier_id'],
	'issuer_id' => $receipt['issuer_id'],
	'issued_at' => $receipt['issued_at'],
	'expires_at' => $receipt['expires_at'],
	'observations' => $receipt['observations'],
);
$verified = MAD4B_SCP_Provider_Behavioral_Recertification::verify_receipt( $receipt, $context, $canonical );
expect_same( true, $verified['verified'], 'terminally authorized audit-bound HMAC receipt verifies' );

$replay = MAD4B_SCP_Provider_Behavioral_Recertification::run( $input );
expect_true( is_wp_error( $replay ), 'used ticket cannot replay behavioral mutation' );
expect_same( 'mad4b_provider_recertification_ticket_not_claimed', $replay->get_error_code(), 'replay fails at claimed-ticket gate' );

$event = $receipt['audit_event_id'];
$GLOBALS['audit_rows'][ $event ]['entry_hash'] = str_repeat( 'f', 64 );
$tampered = MAD4B_SCP_Provider_Behavioral_Recertification::verify_receipt( $receipt, $context, $canonical );
expect_same( false, $tampered['verified'], 'audit hash tampering invalidates receipt' );
$GLOBALS['audit_rows'][ $event ]['entry_hash'] = $receipt['audit_entry_hash'];

$GLOBALS['binding_sha'] = str_repeat( '9', 40 );
$drifted = MAD4B_SCP_Provider_Behavioral_Recertification::provide_receipts( array(), $context );
expect_same( 0, count( $drifted ), 'candidate binding drift hides stale receipt' );
$GLOBALS['binding_sha'] = str_repeat( 'a', 40 );

$GLOBALS['ticket_id'] = '33333333-3333-3333-3333-333333333333';
$GLOBALS['ticket_status'] = 'executing';
$GLOBALS['probe_mode'] = 'fail_after_side_effect';
$GLOBALS['provider_state']['value'] = 'before_failure';
$GLOBALS['opts'][ MAD4B_SCP_Provider_Behavioral_Recertification::RECEIPT_OPTION ] = array();
$failed = MAD4B_SCP_Provider_Behavioral_Recertification::run( $input );
expect_true( is_wp_error( $failed ), 'provider failure after side effect remains failed' );
expect_same( 'provider_failed_after_side_effect', $failed->get_error_code(), 'original provider error survives successful recovery rollback' );
expect_same( 'before_failure', $GLOBALS['provider_state']['value'], 'recovery rollback restores exact state after provider failure' );
$GLOBALS['ticket_status'] = 'used';
$failed_receipts = MAD4B_SCP_Provider_Behavioral_Recertification::provide_receipts( array(), $context );
expect_same( 0, count( $failed_receipts ), 'failed probe cannot manufacture behavioral receipt' );

echo "Provider behavioral recertification runtime: PASS\n";
