<?php

define( 'ABSPATH', '/srv/wordpress/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.22' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class MAD4B_SCP_Staging_Write_Authority { const AGENT_SLUG = 'chatgpt-staging-write'; }

function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function add_action() {}
function add_submenu_page() {}
function __( $value ) { return $value; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-approval-decision-admin.php';

function mad4b_decision_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function mad4b_decision_error( $result, $code ) {
	return $result instanceof WP_Error && $code === $result->get_error_code();
}

$now = 1789130000;
$ticket_id = '11111111-1111-4111-8111-111111111111';
$payload = str_repeat( 'a', 64 );
$candidate_sha = str_repeat( 'b', 40 );
$build = str_repeat( 'c', 64 );
$request = array(
	'ticket_id' => $ticket_id,
	'decision' => 'approve',
	'expected_payload_sha256' => $payload,
	'expected_candidate_sha' => $candidate_sha,
	'expected_build_fingerprint' => $build,
);
$ticket = array(
	'ticket_id' => $ticket_id,
	'status' => 'pending',
	'expires_at' => gmdate( 'Y-m-d H:i:s', $now + 600 ),
	'ticket_class' => 'mutation',
	'server_id' => 'mad4b-write',
	'ability_name' => 'elementor/update-widget-settings',
	'provider' => 'elementor',
	'payload_sha256' => $payload,
);
$binding = array(
	'contract' => 'mad4b.approval-candidate-binding.v1',
	'ticket_id' => $ticket_id,
	'payload_sha256' => $payload,
	'candidate_sha' => $candidate_sha,
	'build_fingerprint' => $build,
	'environment' => 'staging',
	'host' => 'staging.egypttourgates.com',
);
$candidate = array(
	'ready' => true,
	'source_commit_sha' => $candidate_sha,
	'build_fingerprint' => $build,
);
$agent = array(
	'status' => 'enabled',
	'environment' => 'staging',
	'slug' => 'chatgpt-staging-write',
);

$validate = static function ( $r, $t, $b, $c, $a, $admin = true, $env = 'staging', $host = 'staging.egypttourgates.com' ) use ( $now ) {
	return MAD4B_SCP_Approval_Decision_Admin::validate_decision_for_test( $r, $t, $b, $c, $a, $admin, $env, $host, $now );
};

mad4b_decision_assert( true === $validate( $request, $ticket, $binding, $candidate, $agent ), 'Valid exact human approval must validate.' );
$reject = $request; $reject['decision'] = 'reject';
mad4b_decision_assert( true === $validate( $reject, $ticket, $binding, $candidate, $agent ), 'Valid exact human rejection must validate.' );

$bad = $request; $bad['ticket_id'] = '22222222-2222-4222-8222-222222222222';
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_ticket_mismatch' ), 'Wrong ticket must fail closed.' );

$bad = $request; $bad['expected_payload_sha256'] = str_repeat( 'd', 64 );
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_payload_mismatch' ), 'Wrong payload hash must fail closed.' );

$bad = $request; $bad['expected_candidate_sha'] = str_repeat( 'e', 40 );
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_candidate_mismatch' ), 'Wrong candidate SHA must fail closed.' );

$bad = $request; $bad['expected_build_fingerprint'] = str_repeat( 'f', 64 );
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_build_mismatch' ), 'Wrong build fingerprint must fail closed.' );

$expired = $ticket; $expired['expires_at'] = gmdate( 'Y-m-d H:i:s', $now - 1 );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $expired, $binding, $candidate, $agent ), 'mad4b_approval_decision_expired' ), 'Expired ticket must fail closed.' );

$used = $ticket; $used['status'] = 'used';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $used, $binding, $candidate, $agent ), 'mad4b_approval_decision_not_pending' ), 'Already-used ticket must fail closed.' );

mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $agent, false ), 'mad4b_approval_decision_admin_required' ), 'Non-admin approver must fail closed.' );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $agent, true, 'production', 'egypttourgates.com' ), 'mad4b_approval_decision_staging_only' ), 'Production approval attempt must fail closed.' );

$breakglass = $ticket; $breakglass['ticket_class'] = 'breakglass';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $breakglass, $binding, $candidate, $agent ), 'mad4b_approval_decision_class_denied' ), 'Breakglass ticket must fail closed.' );
$recovery = $ticket; $recovery['ticket_class'] = 'recovery';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $recovery, $binding, $candidate, $agent ), 'mad4b_approval_decision_class_denied' ), 'Recovery ticket must fail closed.' );

$wrong_server = $ticket; $wrong_server['server_id'] = 'mad4b-breakglass';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $wrong_server, $binding, $candidate, $agent ), 'mad4b_approval_decision_server_denied' ), 'Breakglass server must fail closed.' );
$raw = $ticket; $raw['ability_name'] = 'mad4b/database-raw-query';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $raw, $binding, $candidate, $agent ), 'mad4b_approval_decision_target_denied' ), 'Raw SQL target must fail closed.' );

mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, array(), $candidate, $agent ), 'mad4b_approval_decision_binding_missing' ), 'Unbound pre-patch ticket must fail closed.' );
$stale_binding = $binding; $stale_binding['candidate_sha'] = str_repeat( '1', 40 );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $stale_binding, $candidate, $agent ), 'mad4b_approval_decision_binding_mismatch' ), 'Ticket bound to another candidate must fail closed.' );

$bad_agent = $agent; $bad_agent['status'] = 'disabled';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $bad_agent ), 'mad4b_approval_decision_agent_denied' ), 'Disabled agent must fail closed.' );
$bad_agent = $agent; $bad_agent['slug'] = 'other-agent';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $bad_agent ), 'mad4b_approval_decision_agent_denied' ), 'Non-canonical Staging agent must fail closed.' );

$bad_candidate = $candidate; $bad_candidate['ready'] = false;
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $bad_candidate, $agent ), 'mad4b_approval_decision_candidate_unavailable' ), 'Unready live provenance must fail closed.' );

$bad = $request; $bad['decision'] = 'execute';
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_invalid' ), 'Decision surface must not accept an execute action.' );

echo "mad4b.approval-decision.runtime.v1: PASS\n";
