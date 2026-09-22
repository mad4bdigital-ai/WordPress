<?php

define( 'ABSPATH', '/srv/wordpress/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.31' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class MAD4B_SCP_Approval_Tickets { const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v2'; }

function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function add_action() {}
function add_submenu_page() {}
function __( $value ) { return $value; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-approval-decision-admin.php';

function mad4b_decision_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function mad4b_decision_error( $result, $code ) { return $result instanceof WP_Error && $code === $result->get_error_code(); }

$now = 1789130000;
$ticket_id = '11111111-1111-4111-8111-111111111111';
$payload = str_repeat( 'a', 64 );
$candidate_sha = str_repeat( 'b', 40 );
$build = str_repeat( 'c', 64 );
$site_uuid = '22222222-2222-4222-8222-222222222222';
$profile_digest = str_repeat( 'd', 64 );
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
	'contract' => 'mad4b.approval-candidate-binding.v2',
	'ticket_id' => $ticket_id,
	'payload_sha256' => $payload,
	'candidate_sha' => $candidate_sha,
	'build_fingerprint' => $build,
	'site_uuid' => $site_uuid,
	'profile_revision' => 7,
	'profile_digest' => $profile_digest,
	'environment' => 'staging',
	'host' => 'staging.client.test',
);
$candidate = array(
	'ready' => true,
	'profile_ready' => true,
	'source_commit_sha' => $candidate_sha,
	'build_fingerprint' => $build,
	'site_uuid' => $site_uuid,
	'site_profile_revision' => 7,
	'site_profile_digest' => $profile_digest,
	'environment' => 'staging',
	'host' => 'staging.client.test',
	'agent_slug' => 'chatgpt-site-222222222222',
);
$agent = array(
	'status' => 'enabled',
	'environment' => 'staging',
	'slug' => 'chatgpt-site-222222222222',
);

$validate = static function ( $r, $t, $b, $c, $a, $approver = true, $env = 'staging', $host = 'staging.client.test' ) use ( $now ) {
	return MAD4B_SCP_Approval_Decision_Admin::validate_decision_for_test( $r, $t, $b, $c, $a, $approver, $env, $host, $now );
};

mad4b_decision_assert( true === $validate( $request, $ticket, $binding, $candidate, $agent ), 'Valid exact tenant/build human approval must validate.' );
$reject = $request; $reject['decision'] = 'reject';
mad4b_decision_assert( true === $validate( $reject, $ticket, $binding, $candidate, $agent ), 'Valid exact human rejection must validate.' );

$bad = $request; $bad['ticket_id'] = '33333333-3333-4333-8333-333333333333';
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_ticket_mismatch' ), 'Wrong ticket must fail closed.' );
$bad = $request; $bad['expected_payload_sha256'] = str_repeat( 'e', 64 );
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_payload_mismatch' ), 'Wrong payload hash must fail closed.' );
$bad = $request; $bad['expected_candidate_sha'] = str_repeat( 'f', 40 );
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_candidate_mismatch' ), 'Wrong candidate SHA must fail closed.' );
$bad = $request; $bad['expected_build_fingerprint'] = str_repeat( '1', 64 );
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_build_mismatch' ), 'Wrong build fingerprint must fail closed.' );

$expired = $ticket; $expired['expires_at'] = gmdate( 'Y-m-d H:i:s', $now - 1 );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $expired, $binding, $candidate, $agent ), 'mad4b_approval_decision_expired' ), 'Expired ticket must fail closed.' );
$used = $ticket; $used['status'] = 'used';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $used, $binding, $candidate, $agent ), 'mad4b_approval_decision_not_pending' ), 'Already-used ticket must fail closed.' );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $agent, false ), 'mad4b_approval_decision_admin_required' ), 'Non-approver must fail closed.' );

mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $agent, true, 'production', 'client.test' ), 'mad4b_approval_decision_site_mismatch' ), 'Different environment/origin must fail closed.' );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $agent, true, 'staging', 'clone.client.test' ), 'mad4b_approval_decision_site_mismatch' ), 'Clone host must fail closed.' );

$breakglass = $ticket; $breakglass['ticket_class'] = 'breakglass';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $breakglass, $binding, $candidate, $agent ), 'mad4b_approval_decision_class_denied' ), 'Breakglass ticket must fail closed on normal console.' );
$recovery = $ticket; $recovery['ticket_class'] = 'recovery';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $recovery, $binding, $candidate, $agent ), 'mad4b_approval_decision_class_denied' ), 'Recovery ticket must fail closed on normal console.' );
$wrong_server = $ticket; $wrong_server['server_id'] = 'mad4b-breakglass';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $wrong_server, $binding, $candidate, $agent ), 'mad4b_approval_decision_server_denied' ), 'Breakglass server must fail closed.' );
$raw = $ticket; $raw['ability_name'] = 'mad4b/database-raw-query';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $raw, $binding, $candidate, $agent ), 'mad4b_approval_decision_target_denied' ), 'Raw SQL target must fail closed.' );

mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, array(), $candidate, $agent ), 'mad4b_approval_decision_binding_missing' ), 'Unbound/legacy ticket must fail closed.' );
$legacy = $binding; $legacy['contract'] = 'mad4b.approval-candidate-binding.v1';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $legacy, $candidate, $agent ), 'mad4b_approval_decision_binding_missing' ), 'v1 candidate binding cannot be silently upgraded.' );
$stale_binding = $binding; $stale_binding['candidate_sha'] = str_repeat( '2', 40 );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $stale_binding, $candidate, $agent ), 'mad4b_approval_decision_binding_mismatch' ), 'Ticket bound to another candidate must fail closed.' );
$stale_binding = $binding; $stale_binding['profile_revision'] = 6;
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $stale_binding, $candidate, $agent ), 'mad4b_approval_decision_binding_mismatch' ), 'Profile revision change must invalidate pending approval.' );
$stale_binding = $binding; $stale_binding['profile_digest'] = str_repeat( '3', 64 );
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $stale_binding, $candidate, $agent ), 'mad4b_approval_decision_binding_mismatch' ), 'Profile digest change must invalidate pending approval.' );
$stale_binding = $binding; $stale_binding['site_uuid'] = '44444444-4444-4444-8444-444444444444';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $stale_binding, $candidate, $agent ), 'mad4b_approval_decision_binding_mismatch' ), 'Different tenant/site UUID must invalidate pending approval.' );

$bad_agent = $agent; $bad_agent['status'] = 'disabled';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $bad_agent ), 'mad4b_approval_decision_agent_denied' ), 'Disabled agent must fail closed.' );
$bad_agent = $agent; $bad_agent['slug'] = 'other-agent';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $bad_agent ), 'mad4b_approval_decision_agent_denied' ), 'Wrong tenant agent must fail closed.' );
$bad_agent = $agent; $bad_agent['environment'] = 'production';
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $candidate, $bad_agent ), 'mad4b_approval_decision_agent_denied' ), 'Agent environment drift must fail closed.' );

$bad_candidate = $candidate; $bad_candidate['ready'] = false;
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $bad_candidate, $agent ), 'mad4b_approval_decision_candidate_unavailable' ), 'Unready live provenance must fail closed.' );
$bad_candidate = $candidate; $bad_candidate['profile_ready'] = false;
mad4b_decision_assert( mad4b_decision_error( $validate( $request, $ticket, $binding, $bad_candidate, $agent ), 'mad4b_approval_decision_candidate_unavailable' ), 'Unready Site Profile must fail closed.' );

// Production is supported only when the live candidate itself is an exact,
// write-enabled Production Site Profile; the console no longer hardcodes Staging.
$prod_candidate = $candidate;
$prod_candidate['environment'] = 'production';
$prod_candidate['host'] = 'client.test';
$prod_candidate['agent_slug'] = 'chatgpt-site-222222222222';
$prod_binding = $binding;
$prod_binding['environment'] = 'production';
$prod_binding['host'] = 'client.test';
$prod_agent = $agent;
$prod_agent['environment'] = 'production';
mad4b_decision_assert( true === $validate( $request, $ticket, $prod_binding, $prod_candidate, $prod_agent, true, 'production', 'client.test' ), 'Exact explicitly governed Production candidate may use the same bounded approval lifecycle.' );

$bad = $request; $bad['decision'] = 'execute';
mad4b_decision_assert( mad4b_decision_error( $validate( $bad, $ticket, $binding, $candidate, $agent ), 'mad4b_approval_decision_invalid' ), 'Decision surface must not accept an execute action.' );

echo "mad4b.approval-decision.runtime.v2: PASS\n";
