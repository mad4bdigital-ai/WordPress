<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'RANK_MATH_VERSION', '1.0.250' );

class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function add_action( $hook, $callback, $priority = 10 ) {}
function wp_has_ability( $name ) { return false; }
function wp_register_ability( $name, $args ) {}
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-seo-provider-families.php';

function mad4b_g5_seo_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . PHP_EOL ); exit( 1 ); }
}

$catalog = MAD4B_SCP_G5_SEO_Provider_Families::catalog();
mad4b_g5_seo_assert( MAD4B_SCP_G5_SEO_Provider_Families::CONTRACT === $catalog['contract'], 'SEO catalog contract must be exact' );
mad4b_g5_seo_assert( 6 === count( $catalog['providers'] ), 'exact six reviewed SEO providers are required' );
foreach ( array( 'rank-math', 'yoast', 'aioseo', 'seopress', 'slim-seo', 'the-seo-framework' ) as $provider ) {
	mad4b_g5_seo_assert( isset( $catalog['providers'][ $provider ] ), 'missing SEO provider ' . $provider );
}
mad4b_g5_seo_assert( false === $catalog['authorizing'] && false === $catalog['signal_direct_mutation_allowed'], 'SEO catalog cannot authorize signal-driven writes' );

$rank = MAD4B_SCP_G5_SEO_Provider_Families::readiness( array( 'provider_id' => 'rank-math' ) );
mad4b_g5_seo_assert( ! is_wp_error( $rank ) && true === $rank['providers'][0]['installed'], 'Rank Math fixture must be observed' );
mad4b_g5_seo_assert( false === $rank['providers'][0]['execution_admitted'], 'readiness cannot admit execution' );
mad4b_g5_seo_assert( false === $rank['providers'][0]['provider_native_apply_certified'], 'repository presence cannot certify native apply' );

$absent = MAD4B_SCP_G5_SEO_Provider_Families::readiness( array( 'provider_id' => 'slim-seo' ) );
mad4b_g5_seo_assert( ! is_wp_error( $absent ) && 'provider_absent' === $absent['providers'][0]['readiness_state'], 'absent provider must remain absent' );

$plan = MAD4B_SCP_G5_SEO_Provider_Families::plan( array(
	'provider_id' => 'rank-math',
	'surface_kind' => 'post',
	'field_ids' => array( 'title', 'canonical', 'robots' ),
	'language' => 'en',
	'rendered_surface_ref' => '/example/',
) );
mad4b_g5_seo_assert( ! is_wp_error( $plan ), 'reviewed SEO plan must be inspectable' );
mad4b_g5_seo_assert( false === $plan['execution_supported'] && false === $plan['content_mutation_performed'], 'SEO planning cannot execute mutation' );
mad4b_g5_seo_assert( in_array( 'exact_rendered_readback', $plan['required_gates'], true ), 'rendered readback gate is required' );
mad4b_g5_seo_assert( in_array( 'coexistence_conflict_preservation', $plan['required_gates'], true ), 'provider coexistence gate is required' );
mad4b_g5_seo_assert( in_array( 'no_signal_direct_mutation', $plan['required_gates'], true ), 'SEO signals cannot become authority' );

$unknown = MAD4B_SCP_G5_SEO_Provider_Families::readiness( array( 'provider_id' => 'made-up-seo' ) );
mad4b_g5_seo_assert( is_wp_error( $unknown ) && 'mad4b_g5_seo_provider_unknown' === $unknown->get_error_code(), 'unknown SEO provider must fail closed' );

// Unsupported caller shapes must return a typed denial rather than invoke
// WordPress sanitizers on arrays or silently canonicalize foreign field IDs.
foreach ( array(
	array( 'method' => 'readiness', 'input' => array( 'provider_id' => array( 'rank-math' ) ), 'code' => 'mad4b_g5_seo_input_invalid' ),
	array( 'method' => 'plan', 'input' => array( 'provider_id' => array( 'rank-math' ), 'surface_kind' => 'post', 'field_ids' => array( 'title' ) ), 'code' => 'mad4b_g5_seo_input_invalid' ),
	array( 'method' => 'plan', 'input' => array( 'provider_id' => 'rank-math', 'surface_kind' => array( 'post' ), 'field_ids' => array( 'title' ) ), 'code' => 'mad4b_g5_seo_input_invalid' ),
	array( 'method' => 'plan', 'input' => array( 'provider_id' => 'rank-math', 'surface_kind' => 'post', 'field_ids' => array( array( 'title' ) ) ), 'code' => 'mad4b_g5_seo_field_scope_invalid' ),
	array( 'method' => 'plan', 'input' => array( 'provider_id' => 'rank-math', 'surface_kind' => 'post', 'field_ids' => array( 'title', 'title' ) ), 'code' => 'mad4b_g5_seo_field_scope_invalid' ),
	array( 'method' => 'plan', 'input' => array( 'provider_id' => 'rank-math', 'surface_kind' => 'post', 'field_ids' => array( 'TITLE' ) ), 'code' => 'mad4b_g5_seo_field_scope_invalid' ),
	array( 'method' => 'plan', 'input' => array( 'provider_id' => 'rank-math', 'surface_kind' => 'post', 'field_ids' => array( 'title' ), 'language' => array( 'en' ) ), 'code' => 'mad4b_g5_seo_input_invalid' ),
	array( 'method' => 'plan', 'input' => array( 'provider_id' => 'rank-math', 'surface_kind' => 'post', 'field_ids' => array( 'title' ), 'rendered_surface_ref' => array( '/post/' ) ), 'code' => 'mad4b_g5_seo_input_invalid' ),
) as $case ) {
	$denied = MAD4B_SCP_G5_SEO_Provider_Families::{$case['method']}( $case['input'] );
	mad4b_g5_seo_assert( is_wp_error( $denied ) && $case['code'] === $denied->get_error_code(), 'invalid SEO caller shape must be denied: ' . $case['code'] );
}

echo "mad4b.feature007-g5-seo-provider-family.v1: PASS\n";
