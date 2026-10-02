<?php
define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}
function sanitize_text_field( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

final class MAD4B_SCP_Site_Profile {
	public static $environment = 'staging';
	public static $write_enabled = true;
	public static function configured() { return true; }
	public static function current_environment() { return self::$environment; }
	public static function origin_enrolled() { return true; }
	public static function site_urls_match_enrollment() { return true; }
	public static function write_enabled() { return self::$write_enabled; }
}
final class MAD4B_SCP_Post_Update_Continuation {}
final class MAD4B_SCP_Runtime_Maintenance_Lease {
	public static $preflight = array();
	public static function preflight( $requester = '' ) { return self::$preflight; }
}
final class MAD4B_SCP_Staging_Write_Authority {
	public static $checkpoint = array();
	public static $presence = array();
	public static $binding = array();
	public static $effective = false;
	public static function persistence_checkpoint() { return self::$checkpoint; }
	public static function authority_presence_status() { return self::$presence; }
	public static function candidate_binding_status() { return self::$binding; }
	public static function effective() { return self::$effective; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';

function check( $ok, $message ) {
	if ( ! $ok ) throw new RuntimeException( $message );
}
function classify() {
	$method = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'post_update_continuation_policy' );
	$method->setAccessible( true );
	return $method->invoke( null );
}
function maintenance_projection() {
	$method = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'maintenance_status_projection' );
	$method->setAccessible( true );
	return $method->invoke( null );
}
function maintenance_gate() {
	$method = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'maintenance_preflight' );
	$method->setAccessible( true );
	return $method->invoke( null, 'self_update_replacement' );
}
function continuation_projection() {
	$method = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'continuation_policy_projection' );
	$method->setAccessible( true );
	return $method->invoke( null );
}

MAD4B_SCP_Site_Profile::$environment = 'staging';
MAD4B_SCP_Site_Profile::$write_enabled = true;

MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
	'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
	'exists' => false,
	'status' => array(),
);
MAD4B_SCP_Staging_Write_Authority::$presence = array(
	'contract' => 'mad4b.governed-write-authority-presence.v1',
	'checkpoint_exists' => false,
	'checkpoint_ready' => false,
	'managed_agent_present' => false,
	'managed_grant_count' => 0,
	'authority_residue_without_checkpoint' => false,
	'fresh_bootstrap_candidate' => true,
	'read_only' => true,
	'mutation_performed' => false,
);
MAD4B_SCP_Staging_Write_Authority::$binding = array( 'required' => true, 'match' => false );
MAD4B_SCP_Staging_Write_Authority::$effective = false;
$bootstrap = classify();
check( ! is_wp_error( $bootstrap ), 'fresh bootstrap must be classifiable' );
check( empty( $bootstrap['required'] ), 'fresh bootstrap must not require continuation' );
check( ! empty( $bootstrap['bootstrap_without_authority'] ), 'fresh bootstrap marker missing' );
check( 'bootstrap_no_prior_authority' === $bootstrap['mode'], 'fresh bootstrap mode mismatch' );

MAD4B_SCP_Runtime_Maintenance_Lease::$preflight = array(
	'contract' => 'mad4b.runtime-maintenance-preflight.v1',
	'classification' => 'CLEAR',
	'safe_to_acquire' => true,
	'retryable' => false,
	'operator_action_required' => false,
	'automatic_mutation_retry_allowed' => false,
	'preflight_recheck_allowed' => true,
	'active_fence_count' => 0,
	'read_only' => true,
	'mutation_performed' => false,
);
$maintenance_projection = maintenance_projection();
check( 'clear' === $maintenance_projection['classification'], 'maintenance projection classification mismatch' );
check( ! empty( $maintenance_projection['safe_to_acquire'] ), 'clear maintenance projection must allow acquire' );
$continuation_projection = continuation_projection();
check( empty( $continuation_projection['blocked'] ) && 'bootstrap_no_prior_authority' === $continuation_projection['mode'], 'continuation projection must expose bootstrap mode' );

MAD4B_SCP_Runtime_Maintenance_Lease::$preflight = array(
	'contract' => 'mad4b.runtime-maintenance-preflight.v1',
	'classification' => 'FENCE_CONFLICT',
	'safe_to_acquire' => false,
	'retryable' => false,
	'operator_action_required' => true,
	'automatic_mutation_retry_allowed' => false,
	'preflight_recheck_allowed' => true,
	'owner' => 'runtime_convergence',
	'fence_source' => 'mad4b_scp_runtime_maintenance_lock_v1',
	'active_fence_count' => 2,
	'fence_token_conflict' => true,
	'read_only' => true,
	'mutation_performed' => false,
);
$conflict_gate = maintenance_gate();
check( is_wp_error( $conflict_gate ), 'maintenance fence conflict must block self-update' );
check( 'mad4b_runtime_maintenance_fence_conflict' === $conflict_gate->get_error_code(), 'maintenance fence conflict blocker mismatch' );
$conflict_projection = maintenance_projection();
check( empty( $conflict_projection['safe_to_acquire'] ) && ! empty( $conflict_projection['operator_action_required'] ), 'conflict projection must require operator action' );

MAD4B_SCP_Staging_Write_Authority::$presence['managed_agent_present'] = true;
MAD4B_SCP_Staging_Write_Authority::$presence['authority_residue_without_checkpoint'] = true;
MAD4B_SCP_Staging_Write_Authority::$presence['fresh_bootstrap_candidate'] = false;
$residue = classify();
check( is_wp_error( $residue ), 'orphaned managed authority must fail closed' );
check( 'mad4b_self_update_continuation_bootstrap_authority_residue' === $residue->get_error_code(), 'authority residue blocker mismatch' );
MAD4B_SCP_Staging_Write_Authority::$presence['managed_agent_present'] = false;
MAD4B_SCP_Staging_Write_Authority::$presence['authority_residue_without_checkpoint'] = false;
MAD4B_SCP_Staging_Write_Authority::$presence['fresh_bootstrap_candidate'] = true;

$ready_status = array(
	'ready' => true,
	'state' => 'ready',
	'blocker' => '',
	'write_inventory_fingerprint' => str_repeat( 'a', 64 ),
);
MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
	'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
	'exists' => true,
	'status' => $ready_status,
);
MAD4B_SCP_Staging_Write_Authority::$binding = array( 'required' => true, 'match' => true );
MAD4B_SCP_Staging_Write_Authority::$effective = true;
$carry = classify();
check( ! is_wp_error( $carry ), 'effective prior authority must be classifiable' );
check( ! empty( $carry['required'] ), 'effective prior authority must require continuation' );
check( 'carry_forward_effective_authority' === $carry['mode'], 'carry-forward mode mismatch' );
foreach ( array( null, array(), array( 'required' => true ), array( 'required' => 'false', 'match' => true ), array( 'required' => true, 'match' => 1 ) ) as $bad_binding ) {
	MAD4B_SCP_Staging_Write_Authority::$binding = $bad_binding;
	$invalid = classify();
	check( is_wp_error( $invalid ) && 'mad4b_self_update_continuation_authority_state_unavailable' === $invalid->get_error_code(), 'Malformed binding must not authorize carry-forward' );
}
MAD4B_SCP_Staging_Write_Authority::$binding = array( 'required' => true, 'match' => true );
MAD4B_SCP_Staging_Write_Authority::$effective = new WP_Error( 'unavailable' );
check( is_wp_error( classify() ), 'Authority error must not be cast into effective authority' );
MAD4B_SCP_Staging_Write_Authority::$effective = true;

$malformed_ready = $ready_status;
$malformed_ready['write_inventory_fingerprint'] = 'not-a-sha256';
MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
	'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
	'exists' => true,
	'status' => $malformed_ready,
);
$malformed = classify();
check( is_wp_error( $malformed ), 'malformed persisted write fingerprint must fail closed' );
check( 'mad4b_self_update_continuation_prior_authority_not_effective' === $malformed->get_error_code(), 'malformed fingerprint blocker mismatch' );

MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
	'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
	'exists' => true,
	'status' => $ready_status,
);

MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
	'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
	'exists' => true,
	'status' => array( 'ready' => false, 'state' => 'blocked', 'blocker' => 'stale', 'write_inventory_fingerprint' => '' ),
);
MAD4B_SCP_Staging_Write_Authority::$effective = false;
$partial = classify();
check( is_wp_error( $partial ), 'partial persisted authority must fail closed' );
check( 'mad4b_self_update_continuation_prior_authority_not_effective' === $partial->get_error_code(), 'partial authority blocker mismatch' );

MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
	'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
	'exists' => true,
	'status' => $ready_status,
);
MAD4B_SCP_Staging_Write_Authority::$binding = array( 'required' => true, 'match' => false );
MAD4B_SCP_Staging_Write_Authority::$effective = false;
$drift = classify();
check( is_wp_error( $drift ), 'stale candidate authority must fail closed' );
check( 'mad4b_self_update_continuation_prior_authority_drift' === $drift->get_error_code(), 'stale candidate blocker mismatch' );

check( false === $drift->get_error_data()['candidate_binding_match'] && false === $drift->get_error_data()['prior_authority_effective'], 'drift evidence must identify both blockers' );
$projection = continuation_projection();
check( 'reconcile_staging_write_authority' === $projection['operator_action'] && false === $projection['automatic_mutation_retry_allowed'], 'blocked update must expose a safe recovery action' );
MAD4B_SCP_Staging_Write_Authority::$effective = true;
check( is_wp_error( classify() ), 'effective authority must not bypass mismatched binding' );
MAD4B_SCP_Staging_Write_Authority::$binding['match'] = true;
MAD4B_SCP_Staging_Write_Authority::$effective = false;
check( is_wp_error( classify() ), 'matching binding must not bypass ineffective authority' );

MAD4B_SCP_Site_Profile::$write_enabled = false;
$disabled = classify();
check( ! is_wp_error( $disabled ) && empty( $disabled['required'] ), 'write-disabled profile must not require continuation' );

MAD4B_SCP_Site_Profile::$write_enabled = true;
MAD4B_SCP_Site_Profile::$environment = 'production';
$production = classify();
check( ! is_wp_error( $production ) && empty( $production['required'] ), 'Production must not use Staging continuation policy' );
check( empty( $production['production_mutation_allowed'] ), 'Production mutation must remain false' );

echo "mad4b.self-update-continuation-policy.v1: PASS\n";
