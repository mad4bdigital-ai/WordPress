<?php

define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.27' );
$GLOBALS['mad4b_options'] = array();
$GLOBALS['mad4b_bearer'] = true;
$GLOBALS['mad4b_live_token'] = 'sha256:' . str_repeat( 'a', 64 );

function add_filter() { return true; }
function add_action() { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['mad4b_options'][ $name ] = $value; return true; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_options'] ) ? $GLOBALS['mad4b_options'][ $name ] : $default; }

class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}

class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array(
			'runtime_manifest_match' => true,
			'source_commit_sha' => str_repeat( '1', 40 ),
			'build_fingerprint' => str_repeat( '2', 64 ),
		);
	}
	public static function external_handshake_attestation_status() {
		return array(
			'verified' => true,
			'real_external_session' => true,
			'client_id' => 'https://chatgpt.com/oauth/client.json',
			'server_id' => 'mad4b-chatgpt',
			'session_fingerprint_present' => true,
			'external_tool_inventory_fingerprint' => str_repeat( '3', 64 ),
			'external_write_inventory_fingerprint' => str_repeat( '4', 64 ),
		);
	}
	public static function snapshot_verify( $input = array() ) {
		$token = isset( $input['client_snapshot_token'] ) ? (string) $input['client_snapshot_token'] : '';
		return array(
			'contract' => 'mad4b.snapshot-verify.v1',
			'live_snapshot_token' => $GLOBALS['mad4b_live_token'],
			'client_snapshot_token' => $token,
			'exact_match' => hash_equals( $GLOBALS['mad4b_live_token'], $token ),
		);
	}
}

class MAD4B_SCP_Skill_Snapshot_Identity {
	public static function build() { return array( 'identity_token' => $GLOBALS['mad4b_live_token'] ); }
}

class MAD4B_SCP_OAuth_Resource_Bridge {
	public static function verified_bearer_active() { return ! empty( $GLOBALS['mad4b_bearer'] ); }
}

class MAD4B_SCP_Identity_Context {
	public static function current() {
		return array( 'authenticated' => true, 'auth_method' => 'oauth2_bearer', 'token_scopes' => array( 'mad4b:read' ) );
	}
}

class MAD4B_SCP_Audit {
	public static function verify_chain() { return true; }
	public static function tail( $limit = 50 ) {
		$before = str_repeat( 'a', 64 );
		$after = str_repeat( 'b', 64 );
		return array(
			array( 'sequence' => 10, 'entry_hash' => str_repeat( 'c', 64 ), 'time' => gmdate( 'c', time() - 20 ), 'ability' => 'mad4b/reversible-adapter-verified', 'status' => 'ok', 'summary' => array( 'mutation_id' => '11111111-1111-4111-8111-111111111111', 'before_sha256' => $before, 'after_sha256' => $after ) ),
			array( 'sequence' => 11, 'entry_hash' => str_repeat( 'd', 64 ), 'time' => gmdate( 'c', time() - 15 ), 'ability' => 'mad4b/authorization:elementor/update-widget-settings', 'status' => 'denied', 'summary' => array( 'reason_code' => 'mad4b_approval_replay_denied', 'approval_ticket_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ) ),
			array( 'sequence' => 12, 'entry_hash' => str_repeat( 'e', 64 ), 'time' => gmdate( 'c', time() - 10 ), 'ability' => 'mad4b/mutation-undo', 'status' => 'ok', 'summary' => array( 'mutation_id' => '11111111-1111-4111-8111-111111111111', 'recovery_mutation_id' => '22222222-2222-4222-8222-222222222222', 'restored_sha256' => $before, 'approval_ticket_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) ),
		);
	}
}

class MAD4B_SCP_Mutation_Manager {
	public static function get( $id ) {
		$before = str_repeat( 'a', 64 );
		$after = str_repeat( 'b', 64 );
		if ( '11111111-1111-4111-8111-111111111111' === $id ) return array(
			'mutation_id' => $id, 'reversible' => 1, 'status' => 'undone',
			'approval_ticket_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			'ability_name' => 'elementor/update-widget-settings', 'provider' => 'elementor',
			'target_type' => 'elementor_widget', 'target_id' => '37924:b417678',
			'before_sha256' => $before, 'after_sha256' => $after,
		);
		if ( '22222222-2222-4222-8222-222222222222' === $id ) return array(
			'mutation_id' => $id, 'parent_mutation_id' => '11111111-1111-4111-8111-111111111111',
			'status' => 'undone', 'approval_ticket_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
			'before_sha256' => $after, 'after_sha256' => $before, 'verification_code' => 'adapter_restore_readback_match',
		);
		return null;
	}
}

class MAD4B_SCP_Approval_Tickets {
	public static function get( $id ) {
		$ability = 0 === strpos( $id, 'aaaaaaaa' ) ? 'elementor/update-widget-settings' : 'mad4b/mutation-undo';
		return array( 'ticket_id' => $id, 'status' => 'used', 'ability_name' => $ability, 'approved_by' => 1, 'approved_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'used_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ) );
	}
	public static function candidate_binding( $id ) {
		return array( 'candidate_sha' => str_repeat( '1', 40 ), 'build_fingerprint' => str_repeat( '2', 64 ), 'environment' => 'staging', 'host' => 'staging.egypttourgates.com' );
	}
}

class MAD4B_SCP_Live_Acceptance_Finalizer {
	public static function mutation_acceptance_status() { return array( 'ready' => false, 'state' => 'pending_external_evidence', 'fresh' => false, 'blockers' => array( 'complete_execute_replay_undo_receipt_required' ) ); }
	public static function evaluate_mutation_receipt( array $r, array $candidate ) {
		$ok = 'mad4b.mutation-acceptance-receipt.v1' === $r['contract']
			&& 'undone' === $r['mutation_status']
			&& 'used' === $r['approval_status']
			&& ! empty( $r['execution_verified'] ) && ! empty( $r['replay_denied'] ) && ! empty( $r['undo_verified'] )
			&& $r['execution_sequence'] < $r['replay_sequence'] && $r['replay_sequence'] < $r['undo_sequence']
			&& hash_equals( $r['before_sha256'], $r['restored_sha256'] )
			&& hash_equals( $r['after_sha256'], $r['recovery_before_sha256'] )
			&& hash_equals( $r['before_sha256'], $r['recovery_after_sha256'] );
		return array( 'ready' => $ok, 'state' => $ok ? 'ready' : 'invalid', 'fresh' => $ok, 'blockers' => $ok ? array() : array( 'invalid' ) );
	}
	public static function live_acceptance_status( $input = array() ) { return array( 'gates' => array( 'production_unchanged' => array( 'ready' => false ) ), 'ready' => false ); }
	public static function aggregate_ready( array $gates ) { foreach ( $gates as $gate ) if ( empty( $gate['ready'] ) ) return false; return ! empty( $gates ); }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-live-acceptance-reconciler.php';

$mutation = MAD4B_SCP_Live_Acceptance_Reconciler::mutation_acceptance_status();
if ( empty( $mutation['ready'] ) || 'durable_authoritative_reconstruction' !== $mutation['evidence_source'] ) {
	fwrite( STDERR, "durable mutation reconstruction failed\n" ); exit( 1 );
}
if ( '11111111-1111-4111-8111-111111111111' !== $mutation['mutation_id'] || 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' !== $mutation['undo_approval_ticket_id'] ) {
	fwrite( STDERR, "mutation/undo binding was not preserved\n" ); exit( 1 );
}

$snapshot = MAD4B_SCP_Live_Acceptance_Reconciler::snapshot_verify( array( 'client_snapshot_token' => $GLOBALS['mad4b_live_token'] ) );
if ( empty( $snapshot['exact_match'] ) || empty( $snapshot['trusted_external_context'] ) || empty( $snapshot['attestation_recorded'] ) ) {
	fwrite( STDERR, "trusted external snapshot attestation failed\n" ); exit( 1 );
}
$status = MAD4B_SCP_Live_Acceptance_Reconciler::external_snapshot_status();
if ( empty( $status['ready'] ) ) { fwrite( STDERR, "stored external snapshot attestation is not ready\n" ); exit( 1 ); }

$GLOBALS['mad4b_bearer'] = false;
$untrusted = MAD4B_SCP_Live_Acceptance_Reconciler::snapshot_verify( array( 'client_snapshot_token' => $GLOBALS['mad4b_live_token'] ) );
if ( ! empty( $untrusted['attestation_recorded'] ) || ! empty( $untrusted['trusted_external_context'] ) ) {
	fwrite( STDERR, "untrusted snapshot verification was allowed to self-certify\n" ); exit( 1 );
}

echo "mad4b.live-acceptance-reconciler-runtime.v1: PASS\n";
