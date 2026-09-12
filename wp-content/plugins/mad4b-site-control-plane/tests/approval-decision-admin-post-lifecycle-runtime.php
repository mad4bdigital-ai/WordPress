<?php

define( 'ABSPATH', '/srv/wordpress/' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function add_action() {}
function add_submenu_page() {}
function __( $value ) { return $value; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function wp_get_environment_type() { return 'staging'; }
function home_url() { return 'https://staging.egypttourgates.com/'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_unslash( $value ) { return $value; }
function is_admin() { return true; }

$GLOBALS['mad4b_prime_calls'] = 0;
$GLOBALS['mad4b_prime_fail'] = false;
function rest_get_server() {
	++$GLOBALS['mad4b_prime_calls'];
	if ( ! empty( $GLOBALS['mad4b_prime_fail'] ) ) throw new Exception( 'simulated prime failure' );
	return (object) array();
}

class MAD4B_SCP_MCP_Registration_Rescue {
	public static $calls = array();
	public static function reconcile( $reason ) { self::$calls[] = (string) $reason; return array( 'ready' => true ); }
}

class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array(
			'manifest_present' => true,
			'manifest_valid' => true,
			'runtime_manifest_match' => true,
			'stale' => false,
			'source_commit_sha' => str_repeat( 'b', 40 ),
			'build_fingerprint' => str_repeat( 'c', 64 ),
		);
	}
}

class MAD4B_SCP_Approval_Tickets {
	public static $decisions = 0;
	public static function get( $ticket_id ) {
		return array(
			'ticket_id' => $ticket_id,
			'status' => 'pending',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
			'ticket_class' => 'mutation',
			'server_id' => 'mad4b-write',
			'ability_name' => 'elementor/update-widget-settings',
			'provider' => 'elementor',
			'payload_sha256' => str_repeat( 'a', 64 ),
			'agent_id' => 9,
		);
	}
	public static function candidate_binding( $ticket_id ) {
		return array(
			'contract' => 'mad4b.approval-candidate-binding.v1',
			'ticket_id' => $ticket_id,
			'payload_sha256' => str_repeat( 'a', 64 ),
			'candidate_sha' => str_repeat( 'b', 40 ),
			'build_fingerprint' => str_repeat( 'c', 64 ),
			'environment' => 'staging',
			'host' => 'staging.egypttourgates.com',
		);
	}
	public static function decide_pending( $ticket_id, $decision, $payload, $meta ) {
		++self::$decisions;
		return array( 'ticket_id' => $ticket_id, 'status' => 'approve' === $decision ? 'approved' : 'revoked', 'meta' => $meta );
	}
}

class MAD4B_SCP_Agent_Registry {
	public static function get_agent_by_id( $id ) {
		return array( 'id' => $id, 'slug' => 'chatgpt-staging-write', 'status' => 'enabled', 'environment' => 'staging' );
	}
}

class MAD4B_SCP_Staging_Write_Authority {
	const AGENT_SLUG = 'chatgpt-staging-write';
	public static $ready = false;
	public static $reconcile_calls = 0;
	public static $force_block = false;
	public static function effective() { return self::$ready; }
	public static function reconcile() {
		++self::$reconcile_calls;
		if ( self::$force_block ) { self::$ready = false; return array( 'ready' => false, 'state' => 'blocked' ); }
		self::$ready = true;
		return array( 'ready' => true, 'state' => 'ready' );
	}
	public static function is_write_ability( $ability ) { return 'elementor/update-widget-settings' === $ability; }
}

class MAD4B_SCP_Servers {
	public static function provider_for_ability( $server, $ability ) {
		return 'mad4b-write' === $server && 'elementor/update-widget-settings' === $ability ? 'elementor' : null;
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-approval-decision-admin.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-plugin.php';

function mad4b_lifecycle_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function mad4b_lifecycle_error( $result, $code ) { return $result instanceof WP_Error && $code === $result->get_error_code(); }

$ticket_id = '11111111-1111-4111-8111-111111111111';
$request = array(
	'ticket_id' => $ticket_id,
	'decision' => 'approve',
	'expected_payload_sha256' => str_repeat( 'a', 64 ),
	'expected_candidate_sha' => str_repeat( 'b', 40 ),
	'expected_build_fingerprint' => str_repeat( 'c', 64 ),
);

// Reproduce the live rc.23 failure precondition: request-local authority starts pending.
MAD4B_SCP_Staging_Write_Authority::$ready = false;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $request );
mad4b_lifecycle_assert( is_array( $result ) && 'approved' === $result['status'], 'Validated admin-post decision must reconcile request-local authority and approve the exact ticket.' );
mad4b_lifecycle_assert( 1 === $GLOBALS['mad4b_prime_calls'], 'Decision lifecycle must prime REST/MCP runtime exactly once.' );
mad4b_lifecycle_assert( array( 'admin_approval_decision' ) === MAD4B_SCP_MCP_Registration_Rescue::$calls, 'Decision lifecycle must run the bounded registration rescue for the approval route.' );
mad4b_lifecycle_assert( 1 === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Decision lifecycle must reconcile write authority in the same request.' );
mad4b_lifecycle_assert( MAD4B_SCP_Staging_Write_Authority::effective(), 'Request-local authority must be ready after reconciliation.' );
mad4b_lifecycle_assert( 1 === MAD4B_SCP_Approval_Tickets::$decisions, 'Human approval must perform only the ticket transition once.' );

// Exact validation must happen before any priming/reconciliation side effect.
$bad = $request;
$bad['expected_candidate_sha'] = str_repeat( 'd', 40 );
$prime_before = $GLOBALS['mad4b_prime_calls'];
$reconcile_before = MAD4B_SCP_Staging_Write_Authority::$reconcile_calls;
$decisions_before = MAD4B_SCP_Approval_Tickets::$decisions;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $bad );
mad4b_lifecycle_assert( mad4b_lifecycle_error( $result, 'mad4b_approval_decision_candidate_mismatch' ), 'Wrong candidate must fail before authority runtime priming.' );
mad4b_lifecycle_assert( $prime_before === $GLOBALS['mad4b_prime_calls'], 'Invalid exact candidate must not prime REST/MCP runtime.' );
mad4b_lifecycle_assert( $reconcile_before === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Invalid exact candidate must not reconcile write authority.' );
mad4b_lifecycle_assert( $decisions_before === MAD4B_SCP_Approval_Tickets::$decisions, 'Invalid exact candidate must not decide a ticket.' );

// Runtime priming failure remains fail-closed and never decides the ticket.
MAD4B_SCP_Staging_Write_Authority::$ready = false;
$GLOBALS['mad4b_prime_fail'] = true;
$decisions_before = MAD4B_SCP_Approval_Tickets::$decisions;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $request );
mad4b_lifecycle_assert( mad4b_lifecycle_error( $result, 'mad4b_approval_decision_runtime_prime_failed' ), 'Runtime prime failure must fail closed.' );
mad4b_lifecycle_assert( $decisions_before === MAD4B_SCP_Approval_Tickets::$decisions, 'Runtime prime failure must not decide the ticket.' );
$GLOBALS['mad4b_prime_fail'] = false;

// Reconciliation can still block and must never degrade into approval.
MAD4B_SCP_Staging_Write_Authority::$ready = false;
MAD4B_SCP_Staging_Write_Authority::$force_block = true;
$decisions_before = MAD4B_SCP_Approval_Tickets::$decisions;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $request );
mad4b_lifecycle_assert( mad4b_lifecycle_error( $result, 'mad4b_approval_decision_write_authority_not_ready' ), 'Blocked authority after reconciliation must fail closed.' );
mad4b_lifecycle_assert( $decisions_before === MAD4B_SCP_Approval_Tickets::$decisions, 'Blocked authority must not decide the ticket.' );
MAD4B_SCP_Staging_Write_Authority::$force_block = false;

// The human decision page itself must be a recognized authority admin surface.
$_GET['page'] = 'mad4b-approval-decisions';
mad4b_lifecycle_assert( MAD4B_SCP_Plugin::is_authority_admin_surface(), 'Approval Decisions page must be recognized as an authority admin surface.' );
$prime_before = $GLOBALS['mad4b_prime_calls'];
$reconcile_before = MAD4B_SCP_Staging_Write_Authority::$reconcile_calls;
MAD4B_SCP_Plugin::prime_admin_mcp_runtime();
MAD4B_SCP_Plugin::reconcile_authority_on_mad4b_admin();
mad4b_lifecycle_assert( $prime_before + 1 === $GLOBALS['mad4b_prime_calls'], 'Approval Decisions page must prime the admin MCP runtime.' );
mad4b_lifecycle_assert( $reconcile_before + 1 === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Approval Decisions page must reconcile authority.' );

// Unrelated wp-admin requests must remain outside this lifecycle.
$_GET['page'] = 'plugins';
mad4b_lifecycle_assert( ! MAD4B_SCP_Plugin::is_authority_admin_surface(), 'Unrelated wp-admin pages must not be authority surfaces.' );
$prime_before = $GLOBALS['mad4b_prime_calls'];
$reconcile_before = MAD4B_SCP_Staging_Write_Authority::$reconcile_calls;
MAD4B_SCP_Plugin::prime_admin_mcp_runtime();
MAD4B_SCP_Plugin::reconcile_authority_on_mad4b_admin();
mad4b_lifecycle_assert( $prime_before === $GLOBALS['mad4b_prime_calls'], 'Unrelated wp-admin page must not prime MCP runtime.' );
mad4b_lifecycle_assert( $reconcile_before === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Unrelated wp-admin page must not reconcile write authority.' );

echo "mad4b.approval-decision.admin-post-lifecycle.v2: PASS\n";
