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
function get_option( $name, $default = false ) { return $default; }

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
		if ( ! is_array( $prepared ) ) return 0;
		$query = $prepared['query'];
		if ( false !== strpos( $query, "SET status = 'executing'" ) ) {
			if ( 'approved' !== $this->ticket['status'] ) return 0;
			$this->ticket['status'] = 'executing';
			return 1;
		}
		if ( false !== strpos( $query, "SET status = 'used'" ) ) {
			if ( 'executing' !== $this->ticket['status'] ) return 0;
			$this->ticket['status'] = 'used';
			$this->ticket['used_at'] = gmdate( 'Y-m-d H:i:s' );
			return 1;
		}
		if ( false !== strpos( $query, "SET status = 'failed'" ) ) {
			if ( 'executing' !== $this->ticket['status'] ) return 0;
			$this->ticket['status'] = 'failed';
			return 1;
		}
		return 0;
	}
}

$wpdb = new MAD4B_Test_WPDB();
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-identity-context.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php';
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
$input = array( 'post_id' => 123, 'post_title' => 'Live acceptance reversible probe', 'expected_modified_gmt' => '2026-09-11 12:00:00' );
$planning_fp = MAD4B_SCP_Authorization::target_fingerprint( $ability, $provider, $input, array(), array( 'planning' => true ) );
$runtime_fp = MAD4B_SCP_Authorization::target_fingerprint( $ability, $provider, $input, $agent, $overlay );
mad4b_replay_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', $planning_fp ), 'Fallback target fingerprint must be a deterministic SHA-256.' );
mad4b_replay_assert( hash_equals( $planning_fp, $runtime_fp ), 'Planning and execution target fingerprints must not depend on identity or ticket context.' );
$changed_input = $input;
$changed_input['post_title'] = 'Different exact operation';
$changed_fp = MAD4B_SCP_Authorization::target_fingerprint( $ability, $provider, $changed_input, $agent, $overlay );
mad4b_replay_assert( ! hash_equals( $planning_fp, $changed_fp ), 'Changing exact mutation input must change the fallback target fingerprint.' );
$target = $planning_fp;
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

// Models the MCP Adapter pre-check followed by WP_Ability::execute()'s own
// permission check. Both validations must be pure and leave the ticket approved.
$precheck_one = MAD4B_SCP_Approval_Tickets::validate_exact( $ticket_id, $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_array( $precheck_one ), 'First permission preflight must pass.' );
mad4b_replay_assert( 'approved' === $wpdb->ticket['status'], 'First permission preflight must not consume or claim the ticket.' );
$precheck_two = MAD4B_SCP_Approval_Tickets::validate_exact( $ticket_id, $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_array( $precheck_two ), 'Second permission preflight must also pass.' );
mad4b_replay_assert( 'approved' === $wpdb->ticket['status'], 'Second permission preflight must also be side-effect free.' );

$claim = MAD4B_SCP_Approval_Tickets::claim_exact( $ticket_id, $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_array( $claim ), 'Execution-boundary claim must succeed once.' );
mad4b_replay_assert( 'executing' === $wpdb->ticket['status'], 'Execution-boundary claim must transition approved to executing, not used.' );

$executing_replay = MAD4B_SCP_Approval_Tickets::validate_exact( $ticket_id, $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_wp_error( $executing_replay ) && 'mad4b_approval_replay_denied' === $executing_replay->get_error_code(), 'A claimed/executing ticket must deny replay.' );

$final = MAD4B_SCP_Approval_Tickets::finalize_claim( $ticket_id, 'used' );
mad4b_replay_assert( is_array( $final ), 'Successful execution must finalize the claim.' );
mad4b_replay_assert( 'used' === $wpdb->ticket['status'], 'Successful execution must transition executing to used.' );

$used_replay = MAD4B_SCP_Approval_Tickets::validate_exact( $ticket_id, $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_wp_error( $used_replay ) && 'mad4b_approval_replay_denied' === $used_replay->get_error_code(), 'Used ticket replay must be denied.' );

$wpdb->ticket['status'] = 'executing';
$failed_final = MAD4B_SCP_Approval_Tickets::finalize_claim( $ticket_id, 'failed' );
mad4b_replay_assert( is_array( $failed_final ) && 'failed' === $wpdb->ticket['status'], 'Execution failure must transition executing to terminal failed.' );
$failed_replay = MAD4B_SCP_Approval_Tickets::validate_exact( $ticket_id, $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_wp_error( $failed_replay ) && 'mad4b_approval_replay_denied' === $failed_replay->get_error_code(), 'Failed ticket replay must be denied.' );

$wpdb->ticket['status'] = 'pending';
$pending = MAD4B_SCP_Approval_Tickets::validate_exact( $ticket_id, $agent, $server, $ability, $provider, $target, $input, $ticket_class );
mad4b_replay_assert( is_wp_error( $pending ) && 'mad4b_approval_not_approved' === $pending->get_error_code(), 'Pending tickets must remain distinct from replay denial.' );

echo "mad4b.approval-ticket-replay.runtime.v4: PASS\n";
