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
function absint( $value ) { return abs( (int) $value ); }
function add_action() {}
$GLOBALS['mad4b_removed_actions'] = array();
function remove_action( $hook, $callback, $priority = 10 ) { $GLOBALS['mad4b_removed_actions'][] = array( $hook, $callback, $priority ); return true; }
function add_submenu_page() {}
function __( $value ) { return $value; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function wp_get_environment_type() { return 'staging'; }
function home_url() { return 'https://staging.client.test/'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_unslash( $value ) { return $value; }
function is_admin() { return true; }

class MAD4B_SCP_Site_Profile {
	public static function configured() { return true; }
	public static function origin_enrolled() { return true; }
	public static function write_enabled() { return true; }
	public static function current_origin() { return 'https://staging.client.test'; }
	public static function current_host() { return 'staging.client.test'; }
	public static function site_uuid() { return '22222222-2222-4222-8222-222222222222'; }
	public static function revision() { return 7; }
	public static function profile_digest() { return str_repeat( 'd', 64 ); }
	public static function agent_slug() { return 'chatgpt-site-222222222222'; }
}
class MAD4B_SCP_Policy {
	public static function can_approve_mutations() { return true; }
}
class MAD4B_SCP_Schema { public static function critical_ready() { return true; } }

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
	const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v2';
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
			'candidate_binding_contract' => self::CANDIDATE_BINDING_CONTRACT,
			'candidate_sha' => str_repeat( 'b', 40 ),
			'build_fingerprint' => str_repeat( 'c', 64 ),
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'site_profile_revision' => MAD4B_SCP_Site_Profile::revision(),
			'site_profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
			'binding_environment' => 'staging',
			'binding_host' => 'staging.client.test',
			'bound_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}
	public static function candidate_binding_from_ticket( array $ticket ) {
		return array(
			'contract' => isset( $ticket['candidate_binding_contract'] ) ? $ticket['candidate_binding_contract'] : '',
			'ticket_id' => isset( $ticket['ticket_id'] ) ? $ticket['ticket_id'] : '',
			'payload_sha256' => isset( $ticket['payload_sha256'] ) ? $ticket['payload_sha256'] : '',
			'candidate_sha' => isset( $ticket['candidate_sha'] ) ? $ticket['candidate_sha'] : '',
			'build_fingerprint' => isset( $ticket['build_fingerprint'] ) ? $ticket['build_fingerprint'] : '',
			'site_uuid' => isset( $ticket['site_uuid'] ) ? $ticket['site_uuid'] : '',
			'profile_revision' => isset( $ticket['site_profile_revision'] ) ? (int) $ticket['site_profile_revision'] : 0,
			'profile_digest' => isset( $ticket['site_profile_digest'] ) ? $ticket['site_profile_digest'] : '',
			'environment' => isset( $ticket['binding_environment'] ) ? $ticket['binding_environment'] : '',
			'host' => isset( $ticket['binding_host'] ) ? $ticket['binding_host'] : '',
		);
	}
	public static function candidate_binding( $ticket_id ) { return self::candidate_binding_from_ticket( self::get( $ticket_id ) ); }
	public static function decide_pending( $ticket_id, $decision, $payload, $meta ) {
		++self::$decisions;
		return array( 'ticket_id' => $ticket_id, 'status' => 'approve' === $decision ? 'approved' : 'revoked', 'meta' => $meta );
	}
}

class MAD4B_SCP_Agent_Registry {
	public static function get_agent_by_id( $id ) { return array( 'id' => $id, 'slug' => MAD4B_SCP_Site_Profile::agent_slug(), 'status' => 'enabled', 'environment' => 'staging' ); }
}

class MAD4B_SCP_Staging_Write_Authority {
	public static $ready = false;
	public static $reconcile_calls = 0;
	public static $force_block = false;
	public static function effective() { return self::$ready; }
	public static function status() { return array( 'ready' => self::$ready, 'state' => self::$ready ? 'ready' : 'blocked' ); }
	public static function reconcile() {
		++self::$reconcile_calls;
		if ( self::$force_block ) { self::$ready = false; return array( 'ready' => false, 'state' => 'blocked' ); }
		self::$ready = true;
		return array( 'ready' => true, 'state' => 'ready' );
	}
	public static function is_write_ability( $ability ) { return 'elementor/update-widget-settings' === $ability; }
}

class MAD4B_SCP_Servers {
	public static function provider_for_ability( $server, $ability ) { return 'mad4b-write' === $server && 'elementor/update-widget-settings' === $ability ? 'elementor' : null; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-approval-decision-admin.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-plugin.php';

function mad4b_lifecycle_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function mad4b_lifecycle_error( $result, $code ) { return $result instanceof WP_Error && $code === $result->get_error_code(); }

$ticket_id = '11111111-1111-4111-8111-111111111111';
$request = array(
	'ticket_id' => $ticket_id,
	'decision' => 'approve',
	'expected_payload_sha256' => str_repeat( 'a', 64 ),
	'expected_candidate_sha' => str_repeat( 'b', 40 ),
	'expected_build_fingerprint' => str_repeat( 'c', 64 ),
);

// Exact validation happens first, then bounded request-local runtime preparation,
// then exactly one human transition. Authority must already be explicitly
// reconciled by the governed authority surface; approval itself is read-only
// with respect to grants/subjects/authority reconciliation.
MAD4B_SCP_Staging_Write_Authority::$ready = true;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $request );
mad4b_lifecycle_assert( is_array( $result ) && 'approved' === $result['status'], 'Validated admin-post decision must reconcile request-local authority and approve the exact ticket.' );
mad4b_lifecycle_assert( 1 === $GLOBALS['mad4b_prime_calls'], 'Decision lifecycle must prime REST/MCP runtime exactly once.' );
mad4b_lifecycle_assert( array( 'admin_approval_decision' ) === MAD4B_SCP_MCP_Registration_Rescue::$calls, 'Decision lifecycle must run bounded registration rescue.' );
mad4b_lifecycle_assert( 0 === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Human approval must not reconcile write authority or mutate grants.' );
mad4b_lifecycle_assert( MAD4B_SCP_Staging_Write_Authority::effective(), 'Previously reconciled authority must remain ready during approval.' );
mad4b_lifecycle_assert( 1 === MAD4B_SCP_Approval_Tickets::$decisions, 'Human approval must perform only the ticket transition once.' );

// Exact validation must happen before priming/reconciliation side effects.
$bad = $request; $bad['expected_candidate_sha'] = str_repeat( 'e', 40 );
$prime_before = $GLOBALS['mad4b_prime_calls'];
$reconcile_before = MAD4B_SCP_Staging_Write_Authority::$reconcile_calls;
$decisions_before = MAD4B_SCP_Approval_Tickets::$decisions;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $bad );
mad4b_lifecycle_assert( mad4b_lifecycle_error( $result, 'mad4b_approval_decision_candidate_mismatch' ), 'Wrong candidate must fail before authority runtime priming.' );
mad4b_lifecycle_assert( $prime_before === $GLOBALS['mad4b_prime_calls'], 'Invalid candidate must not prime REST/MCP runtime.' );
mad4b_lifecycle_assert( $reconcile_before === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Invalid candidate must not reconcile write authority.' );
mad4b_lifecycle_assert( $decisions_before === MAD4B_SCP_Approval_Tickets::$decisions, 'Invalid candidate must not decide a ticket.' );

// Runtime priming failure remains fail-closed even when authority was already reconciled.
MAD4B_SCP_Staging_Write_Authority::$ready = true;
$GLOBALS['mad4b_prime_fail'] = true;
$decisions_before = MAD4B_SCP_Approval_Tickets::$decisions;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $request );
mad4b_lifecycle_assert( mad4b_lifecycle_error( $result, 'mad4b_approval_decision_runtime_prime_failed' ), 'Runtime prime failure must fail closed.' );
mad4b_lifecycle_assert( $decisions_before === MAD4B_SCP_Approval_Tickets::$decisions, 'Runtime prime failure must not decide ticket.' );
$GLOBALS['mad4b_prime_fail'] = false;

MAD4B_SCP_Staging_Write_Authority::$ready = false;
$reconcile_before = MAD4B_SCP_Staging_Write_Authority::$reconcile_calls;
$decisions_before = MAD4B_SCP_Approval_Tickets::$decisions;
$result = MAD4B_SCP_Approval_Decision_Admin::decide( $request );
mad4b_lifecycle_assert( mad4b_lifecycle_error( $result, 'mad4b_approval_decision_write_authority_not_ready' ), 'Blocked persisted authority must fail closed without self-repair.' );
mad4b_lifecycle_assert( $reconcile_before === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Blocked approval must not reconcile write authority.' );
mad4b_lifecycle_assert( $decisions_before === MAD4B_SCP_Approval_Tickets::$decisions, 'Blocked authority must not decide ticket.' );

// GET Approval Console is read-only and removes runtime mutation lifecycle hooks.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = 'mad4b-approval-decisions';
mad4b_lifecycle_assert( MAD4B_SCP_Plugin::is_authority_admin_surface(), 'Approval Decisions page remains a recognized MAD4B authority route.' );
$prime_before = $GLOBALS['mad4b_prime_calls'];
$reconcile_before = MAD4B_SCP_Staging_Write_Authority::$reconcile_calls;
$GLOBALS['mad4b_removed_actions'] = array();
MAD4B_SCP_Approval_Decision_Admin::protect_read_model_hot_path();
mad4b_lifecycle_assert( $prime_before === $GLOBALS['mad4b_prime_calls'], 'Approval Decisions GET must not prime MCP runtime.' );
mad4b_lifecycle_assert( $reconcile_before === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'Approval Decisions GET must not reconcile write authority.' );
mad4b_lifecycle_assert( in_array( array( 'admin_init', array( 'MAD4B_SCP_Plugin', 'prime_admin_mcp_runtime' ), 1 ), $GLOBALS['mad4b_removed_actions'], true ), 'Approval Decisions GET must remove MCP runtime priming hook.' );
mad4b_lifecycle_assert( in_array( array( 'admin_init', array( 'MAD4B_SCP_Plugin', 'reconcile_authority_on_mad4b_admin' ), 20 ), $GLOBALS['mad4b_removed_actions'], true ), 'Approval Decisions GET must remove authority reconciliation hook.' );

$_GET['page'] = 'plugins';
mad4b_lifecycle_assert( ! MAD4B_SCP_Plugin::is_authority_admin_surface(), 'Unrelated wp-admin pages must not be authority surfaces.' );
$GLOBALS['mad4b_removed_actions'] = array();
MAD4B_SCP_Approval_Decision_Admin::protect_read_model_hot_path();
mad4b_lifecycle_assert( empty( $GLOBALS['mad4b_removed_actions'] ), 'Unrelated wp-admin GET must not remove MAD4B lifecycle hooks.' );

echo "mad4b.approval-decision.admin-post-lifecycle.v5: PASS\n";
