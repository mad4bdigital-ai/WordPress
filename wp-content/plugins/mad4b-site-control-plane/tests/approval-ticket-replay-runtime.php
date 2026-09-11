<?php

define( 'ABSPATH', '/srv/wordpress/' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function site_url() { return 'https://staging.egypttourgates.com'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_current_user_id() { return 0; }
function wp_generate_uuid4() { return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; }
function apply_filters( $tag, $value ) { return $value; }

class MAD4B_SCP_Schema {
	public static function tables() { return array( 'approvals' => 'wp_mad4b_approvals' ); }
}
class MAD4B_SCP_Audit {
	public static function record( $ability, $summary, $status = 'ok' ) { return true; }
}

final class MAD4B_Test_WPDB {
	public $ticket;
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_row( $prepared, $format ) { return $this->ticket; }
	public function query( $prepared ) {
		if ( ! is_array( $prepared ) || false === strpos( $prepared['query'], "SET status = 'used'" ) ) return 0;
		if ( 'approved' !== $this->ticket['status'] ) return 0;
		$this->ticket['status'] = 'used';
		$this->ticket['used_at'] = gmdate( 'Y-m-d H:i:s' );
		return 1;
	}
}

$wpdb = new MAD4B_Test_WPDB();
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-identity-context.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-approval-tickets.php';

function mad4b_replay_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$ticket_id = '11111111-1111-4111-8111-111111111111';
mad4b_replay_assert( MAD4B_SCP_Identity_Context::bind_approval_ticket_for_request( $ticket_id ), 'Governance-input ticket must bind to request-local identity evidence.' );
$overlay = MAD4B_SCP_Identity_Context::current();
mad4b_replay_assert( is_array( $overlay ) && $ticket_id === $overlay['approval_ticket_id'], 'Synchronous audit/finalizer reads must recover the bound ticket.' );
mad4b_replay_assert( ! MAD4B_SCP_Identity_Context::bind_approval_ticket_for_request( '22222222-2222-4222-8222-222222222222' ), 'A second different ticket must not replace the request-local binding.' );

$agent = array( 'id' => 7, 'public_id' => 'agent-staging-live-acceptance' );
$server = 'mad4b-write';
$ability = 'mad4b/content-update-post';
$provider = 'core';
$target = hash( 'sha256', 'post:123' );
$input = array( 'post_id' => 123, 'post_title' => 'Live acceptance reversible probe' );
$ticket_class = 'mutation';
$payload = MAD4B_SCP_Approval_Tickets::canonical_payload_hash( $agent['public_id'], $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_string( $payload ) && 64 === strlen( $payload ), 'Canonical payload hash must be generated.' );

$wpdb->ticket = array(
	'id' => 11,
	'ticket_id' => $ticket_id,
	'ticket_class' => $ticket_class,
	'agent_id' => 7,
	'server_id' => $server,
	'ability_name' => $ability,
	'provider' => $provider,
	'target_fingerprint' => $target,
	'payload_sha256' => $payload,
	'status' => 'approved',
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
	'used_at' => null,
);

$first = MAD4B_SCP_Approval_Tickets::consume_exact( $wpdb->ticket['ticket_id'], $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_array( $first ), 'First exact use must succeed.' );
mad4b_replay_assert( 'used' === $wpdb->ticket['status'], 'First exact use must atomically consume the ticket.' );

$second = MAD4B_SCP_Approval_Tickets::consume_exact( $wpdb->ticket['ticket_id'], $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_wp_error( $second ), 'Second exact use must be denied.' );
mad4b_replay_assert( 'mad4b_approval_replay_denied' === $second->get_error_code(), 'Sequential replay must emit the authoritative replay-denied code consumed by Live Acceptance.' );

$wpdb->ticket['status'] = 'pending';
$pending = MAD4B_SCP_Approval_Tickets::consume_exact( $wpdb->ticket['ticket_id'], $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_wp_error( $pending ) && 'mad4b_approval_not_approved' === $pending->get_error_code(), 'Pending tickets must remain distinct from replay denial.' );

echo "mad4b.approval-ticket-replay.runtime.v2: PASS\n";
