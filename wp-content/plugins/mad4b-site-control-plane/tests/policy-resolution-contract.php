<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['mad4b_test_filters'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $tag, $value ) {
	return array_key_exists( $tag, $GLOBALS['mad4b_test_filters'] ) ? $GLOBALS['mad4b_test_filters'][ $tag ] : $value;
}
final class MAD4B_SCP_Site_Profile {
	public static function profile() { return array(); }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-policy-resolution.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL policy-resolution-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$base = array(
	'hard_deny' => false,
	'kill_switch_active' => false,
	'environment_allowed' => true,
	'authority_allowed' => true,
	'certification_allowed' => true,
	'quality_release_allowed' => true,
	'approval_required' => false,
	'approval_satisfied' => true,
	'grant_allowed' => true,
	'feature_enabled' => true,
	'environment' => 'staging',
	'capability' => 'mad4b/content-job-transition',
	'target_fingerprint' => str_repeat( 'a', 64 ),
	'operating_mode' => 'SINGLE_OWNER_HARDENED',
	'policy_versions' => array( 'site' => '2', 'global' => '1' ),
	'required_approvals' => array(),
);

$allow = MAD4B_SCP_Policy_Resolution::resolve( $base );
$check( ! is_wp_error( $allow ), 'base decision errored' );
$check( 'ALLOW' === $allow['decision'], 'base facts did not allow' );
$check( 'policy_allow' === $allow['reason_code'], 'allow reason drift' );
$check( false === $allow['authorizing'] && false === $allow['mutation_performed'], 'resolver became authorizing/mutating' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $allow['decision_sha256'] ), 'decision digest invalid' );
$check( 'SINGLE_OWNER_HARDENED' === $allow['operating_mode'], 'single-owner mode truthfulness lost' );

$cases = array(
	array( 'hard_deny', true, 'DENY', 'hard_deny' ),
	array( 'kill_switch_active', true, 'DENY', 'kill_switch_active' ),
	array( 'environment_allowed', false, 'DENY', 'environment_prohibited' ),
	array( 'authority_allowed', false, 'DENY', 'authority_prohibited' ),
	array( 'certification_allowed', false, 'BLOCKED', 'certification_blocked' ),
	array( 'quality_release_allowed', false, 'BLOCKED', 'quality_or_release_blocked' ),
	array( 'approval_required', true, 'REQUIRE_APPROVAL', 'approval_required', 'approval_satisfied', false ),
	array( 'grant_allowed', false, 'DENY', 'grant_missing_or_denied' ),
	array( 'feature_enabled', false, 'DEFER', 'feature_not_enabled' ),
);
foreach ( $cases as $case ) {
	$facts = $base;
	$facts[ $case[0] ] = $case[1];
	if ( isset( $case[4] ) ) $facts[ $case[4] ] = $case[5];
	$result = MAD4B_SCP_Policy_Resolution::resolve( $facts );
	$check( ! is_wp_error( $result ), 'precedence case errored: ' . $case[0] );
	$check( $case[2] === $result['decision'], 'decision mismatch for ' . $case[0] );
	$check( $case[3] === $result['reason_code'], 'reason mismatch for ' . $case[0] );
}

# Higher precedence deny must win over lower blockers/allows.
$facts = $base;
$facts['hard_deny'] = true;
$facts['kill_switch_active'] = true;
$facts['environment_allowed'] = false;
$facts['approval_required'] = true;
$facts['approval_satisfied'] = false;
$result = MAD4B_SCP_Policy_Resolution::resolve( $facts );
$check( 'DENY' === $result['decision'] && 'hard_deny' === $result['reason_code'], 'precedence order can be bypassed' );

# Tightening extension may never widen an existing deny/block requirement.
$locked = $base;
$locked['hard_deny'] = true;
$locked['environment_allowed'] = false;
$locked['grant_allowed'] = false;
$locked['approval_required'] = true;
$tightened = MAD4B_SCP_Policy_Resolution::tighten(
	$locked,
	array(
		'hard_deny' => false,
		'environment_allowed' => true,
		'grant_allowed' => true,
		'approval_required' => false,
		'kill_switch_active' => true,
		'certification_allowed' => false,
		'unknown_widening_key' => true,
	)
);
$check( true === $tightened['hard_deny'], 'tightening disabled a hard deny' );
$check( false === $tightened['environment_allowed'], 'tightening widened environment policy' );
$check( false === $tightened['grant_allowed'], 'tightening widened grant policy' );
$check( true === $tightened['approval_required'], 'tightening removed approval requirement' );
$check( true === $tightened['kill_switch_active'], 'tightening could not activate kill switch' );
$check( false === $tightened['certification_allowed'], 'tightening could not block certification' );

# Digest must be stable under map/approval ordering.
$ordered_a = $base;
$ordered_a['approval_required'] = true;
$ordered_a['approval_satisfied'] = true;
$ordered_a['policy_versions'] = array( 'z' => '9', 'a' => '1' );
$ordered_a['required_approvals'] = array( 'role:owner', 'ticket:mutation' );
$ordered_b = $ordered_a;
$ordered_b['policy_versions'] = array( 'a' => '1', 'z' => '9' );
$ordered_b['required_approvals'] = array( 'ticket:mutation', 'role:owner' );
$a = MAD4B_SCP_Policy_Resolution::resolve( $ordered_a );
$b = MAD4B_SCP_Policy_Resolution::resolve( $ordered_b );
$check( $a['decision_sha256'] === $b['decision_sha256'], 'decision digest depends on input ordering' );

# Explicit versioned operating-mode policy is the source of the platform default.
$mode = MAD4B_SCP_Policy_Resolution::current_operating_mode();
$check( 'SINGLE_OWNER_HARDENED' === $mode, 'versioned default operating mode drifted' );
$check( MAD4B_SCP_Policy_Resolution::environment_allowed( 'staging' ) === true, 'staging environment unexpectedly blocked' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', MAD4B_SCP_Policy_Resolution::config_digest() ), 'policy config digest invalid' );

# Explicit runtime override is accepted only when it names a contracted mode.
$GLOBALS['mad4b_test_filters']['mad4b_scp_policy_operating_mode'] = 'ENTERPRISE_MULTI_OPERATOR';
$mode = MAD4B_SCP_Policy_Resolution::current_operating_mode();
$check( 'ENTERPRISE_MULTI_OPERATOR' === $mode, 'explicit enterprise mode override failed' );
$GLOBALS['mad4b_test_filters']['mad4b_scp_policy_operating_mode'] = 'INVENTED_MODE';
$mode = MAD4B_SCP_Policy_Resolution::current_operating_mode();
$check( is_wp_error( $mode ) && 'mad4b_policy_operating_mode_invalid' === $mode->get_error_code(), 'invalid mode did not fail closed' );
$GLOBALS['mad4b_test_filters'] = array();

# Integration guard: Authorization must consume the reducer and keep exact decision evidence.
$auth_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php' );
foreach ( array(
	'MAD4B_SCP_Policy_Resolution::current_operating_mode',
	'MAD4B_SCP_Policy_Resolution::tighten',
	'MAD4B_SCP_Policy_Resolution::resolve',
	'mad4b_scp_policy_resolution_tightening',
	"'policy_decision_sha256' =>",
	"'policy_resolution' =>",
) as $marker ) {
	$check( false !== strpos( $auth_source, $marker ), 'Authorization integration marker missing: ' . $marker );
}

$main_source = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$policy_pos = strpos( $main_source, "class-mad4b-scp-policy-resolution.php" );
$auth_pos = strpos( $main_source, "class-mad4b-scp-authorization.php" );
$check( false !== $policy_pos && false !== $auth_pos && $policy_pos < $auth_pos, 'policy resolver is not loaded before Authorization' );

echo "mad4b.policy-resolution.v1: PASS\n";
