<?php
define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

final class MAD4B_SCP_Site_Profile {
	public static $environment = 'staging';
	public static $write_enabled = true;
	public static function current_environment() { return self::$environment; }
	public static function write_enabled() { return self::$write_enabled; }
}
final class MAD4B_SCP_Post_Update_Continuation {}
final class MAD4B_SCP_Staging_Write_Authority {
	public static $checkpoint = array();
	public static $binding = array();
	public static $effective = false;
	public static function persistence_checkpoint() { return self::$checkpoint; }
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

MAD4B_SCP_Site_Profile::$environment = 'staging';
MAD4B_SCP_Site_Profile::$write_enabled = true;

MAD4B_SCP_Staging_Write_Authority::$checkpoint = array(
	'contract' => 'mad4b.governed-write-authority-persistence-checkpoint.v1',
	'exists' => false,
	'status' => array(),
);
MAD4B_SCP_Staging_Write_Authority::$binding = array( 'required' => true, 'match' => false );
MAD4B_SCP_Staging_Write_Authority::$effective = false;
$bootstrap = classify();
check( ! is_wp_error( $bootstrap ), 'fresh bootstrap must be classifiable' );
check( empty( $bootstrap['required'] ), 'fresh bootstrap must not require continuation' );
check( ! empty( $bootstrap['bootstrap_without_authority'] ), 'fresh bootstrap marker missing' );
check( 'bootstrap_no_prior_authority' === $bootstrap['mode'], 'fresh bootstrap mode mismatch' );

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

MAD4B_SCP_Site_Profile::$write_enabled = false;
$disabled = classify();
check( ! is_wp_error( $disabled ) && empty( $disabled['required'] ), 'write-disabled profile must not require continuation' );

MAD4B_SCP_Site_Profile::$write_enabled = true;
MAD4B_SCP_Site_Profile::$environment = 'production';
$production = classify();
check( ! is_wp_error( $production ) && empty( $production['required'] ), 'Production must not use Staging continuation policy' );
check( empty( $production['production_mutation_allowed'] ), 'Production mutation must remain false' );

echo "mad4b.self-update-continuation-policy.v1: PASS\n";
