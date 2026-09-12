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

// Regression for the live Staging undo-plan target mismatch. Operator reason is
// audit metadata and must not alter the approval target or payload identity.
class MAD4B_SCP_Staging_Write_Authority {
	public static function eligible() { return true; }
}
class MAD4B_SCP_Mutation_Manager {
	public static $records = array();
	public static function get( $mutation_id ) {
		return isset( self::$records[ $mutation_id ] ) ? self::$records[ $mutation_id ] : null;
	}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-planning-guard.php';

$undo_id = '50ac11a9-82d3-44de-abee-9d24fcd3fecd';
$undo_id_other = '60ac11a9-82d3-44de-abee-9d24fcd3fecd';
MAD4B_SCP_Mutation_Manager::$records[ $undo_id ] = array(
	'ability_name' => 'elementor/update-widget-settings',
	'provider' => 'elementor',
	'target_type' => 'elementor-widget-settings',
	'target_id' => '37924:b417678',
	'after_sha256' => 'd39fd9282368117a0030f8e9896c97c9990174360d507514f1dd26393b914850',
	'rollback_payload_sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
	'reversible' => 1,
	'status' => 'verified',
	'undo_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
);
MAD4B_SCP_Mutation_Manager::$records[ $undo_id_other ] = array_merge(
	MAD4B_SCP_Mutation_Manager::$records[ $undo_id ],
	array(
		'target_id' => '37924:b417679',
		'after_sha256' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
	)
);

$plan_undo_input = array( 'mutation_id' => $undo_id, 'reason' => 'Reason reviewed during planning.' );
$execute_undo_input = array( 'mutation_id' => $undo_id, 'reason' => 'Different operator wording at execution.' );
$canonical_plan_undo = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_undo_authorization_input( $plan_undo_input );
$canonical_execute_undo = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_undo_authorization_input( $execute_undo_input );
mad4b_replay_assert( is_array( $canonical_plan_undo ) && is_array( $canonical_execute_undo ), 'Undo authorization inputs must canonicalize.' );
mad4b_replay_assert( MAD4B_SCP_Staging_Write_Planning_Guard::UNDO_AUTHORIZATION_REASON === $canonical_plan_undo['reason'], 'Planning reason must be normalized out of authorization identity.' );
mad4b_replay_assert( $canonical_plan_undo === $canonical_execute_undo, 'Plan and execution authorization inputs must match despite operator reason drift.' );

$undo_target_plan = MAD4B_SCP_Staging_Write_Planning_Guard::undo_target_fingerprint( '', 'mad4b/mutation-undo', 'core', $canonical_plan_undo );
$undo_target_execute = MAD4B_SCP_Staging_Write_Planning_Guard::undo_target_fingerprint( '', 'mad4b/mutation-undo', 'core', $canonical_execute_undo );
mad4b_replay_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', $undo_target_plan ), 'Undo target fingerprint must be a deterministic SHA-256.' );
mad4b_replay_assert( hash_equals( $undo_target_plan, $undo_target_execute ), 'Undo planning and execution target fingerprints must match.' );

$undo_payload_plan = MAD4B_SCP_Approval_Tickets::canonical_payload_hash( $agent['public_id'], 'mad4b-write', 'mad4b/mutation-undo', 'core', $undo_target_plan, $canonical_plan_undo, 'mutation' );
$undo_payload_execute = MAD4B_SCP_Approval_Tickets::canonical_payload_hash( $agent['public_id'], 'mad4b-write', 'mad4b/mutation-undo', 'core', $undo_target_execute, $canonical_execute_undo, 'mutation' );
mad4b_replay_assert( hash_equals( $undo_payload_plan, $undo_payload_execute ), 'Undo plan and execution payload hashes must match after canonicalization.' );

$other_undo = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_undo_authorization_input( array( 'mutation_id' => $undo_id_other, 'reason' => 'Any reason.' ) );
$other_target = MAD4B_SCP_Staging_Write_Planning_Guard::undo_target_fingerprint( '', 'mad4b/mutation-undo', 'core', $other_undo );
mad4b_replay_assert( ! hash_equals( $undo_target_plan, $other_target ), 'Changing the mutation id/immutable target must change the undo target fingerprint.' );

$original_target = $undo_target_plan;
MAD4B_SCP_Mutation_Manager::$records[ $undo_id ]['after_sha256'] = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
$drifted_target = MAD4B_SCP_Staging_Write_Planning_Guard::undo_target_fingerprint( '', 'mad4b/mutation-undo', 'core', $canonical_plan_undo );
mad4b_replay_assert( ! hash_equals( $original_target, $drifted_target ), 'Changing immutable after-state evidence must change the undo target fingerprint.' );
MAD4B_SCP_Mutation_Manager::$records[ $undo_id ]['after_sha256'] = 'd39fd9282368117a0030f8e9896c97c9990174360d507514f1dd26393b914850';

MAD4B_SCP_Staging_Write_Planning_Guard::remember_undo_request_reason( $execute_undo_input );
$restored_undo = MAD4B_SCP_Staging_Write_Planning_Guard::restore_undo_execution_input( $canonical_execute_undo );
mad4b_replay_assert( 'Different operator wording at execution.' === $restored_undo['reason'], 'Actual undo callback must receive the operator reason for audit evidence.' );
MAD4B_SCP_Staging_Write_Planning_Guard::clear_undo_request_reason();

$non_undo = MAD4B_SCP_Staging_Write_Planning_Guard::undo_target_fingerprint( 'existing-fingerprint', 'elementor/update-widget-settings', 'elementor', array() );
mad4b_replay_assert( 'existing-fingerprint' === $non_undo, 'Undo target override must not alter any other mutation ability.' );

echo "mad4b.approval-ticket-replay.runtime.v5: PASS\n";
