<?php
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function add_action( $hook, $callback, $priority = 10 ) { return true; }
function wp_has_ability( $name ) { return false; }
function wp_register_ability( $name, $args ) { return true; }
function get_bloginfo( $show ) { return '7.1.2'; }

class MAD4B_SCP_Provider_Contracts {
	public static function get( $provider ) {
		if ( in_array( $provider, array( 'fluentforms', 'woocommerce', 'elementor', 'wpml', 'wpforms', 'w3-total-cache' ), true ) ) return array( 'provider' => $provider );
		return array();
	}
	public static function installed_version( $provider ) {
		$versions = array( 'fluentforms' => '6.1.0', 'woocommerce' => '10.2.1', 'elementor' => '3.31.0', 'wpml' => '4.8.0', 'w3-total-cache' => '2.8.0' );
		return isset( $versions[ $provider ] ) ? $versions[ $provider ] : '';
	}
	public static function certified_versions( $provider ) {
		if ( 'wpforms' === $provider ) return array( '1.9.0' );
		if ( 'w3-total-cache' === $provider ) return array( '2.7.0' );
		$version = self::installed_version( $provider );
		return '' === $version ? array() : array( $version );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g4-provider-families.php';

function mad4b_g4_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: " . $message . PHP_EOL );
		exit( 1 );
	}
}

$catalog = MAD4B_SCP_G4_Provider_Families::catalog();
mad4b_g4_assert( 'mad4b.g4-provider-family-catalog.v1' === $catalog['contract'], 'catalog contract must be exact' );
mad4b_g4_assert( 5 === count( $catalog['families'] ), 'G4 must expose exactly five owned workstream families' );
foreach ( array( 'forms', 'commerce', 'builders', 'site-operations', 'wordpress-breadth' ) as $family ) {
	mad4b_g4_assert( isset( $catalog['families'][ $family ] ), 'missing G4 family ' . $family );
}
mad4b_g4_assert( false === $catalog['authorizing'], 'catalog cannot authorize' );
mad4b_g4_assert( false === $catalog['global_boundaries']['production_authorized'], 'Production must remain unauthorized' );
mad4b_g4_assert( false === $catalog['global_boundaries']['generic_outbound_http_allowed'], 'generic outbound HTTP must remain denied' );

$forms_delete = $catalog['families']['forms']['operations']['submission_delete_plan'];
mad4b_g4_assert( 'high_risk_explicit_gate' === $forms_delete['risk'], 'submission deletion must be high risk' );
mad4b_g4_assert( true === $forms_delete['irreversible'], 'submission deletion must never claim undo' );
mad4b_g4_assert( in_array( 'separate_pii_authority', $forms_delete['requires'], true ), 'submission access requires separate PII authority' );
mad4b_g4_assert( in_array( 'no_undo_claim', $forms_delete['requires'], true ), 'submission deletion must disclose no undo claim' );

$refund = $catalog['families']['commerce']['operations']['refund_plan'];
mad4b_g4_assert( 'irreversible_external_effect' === $refund['risk'], 'refunds must be modeled as irreversible external effects' );
mad4b_g4_assert( true === $refund['external_effect'], 'refund must retain external effect classification' );
mad4b_g4_assert( true === $refund['irreversible'], 'refund must not claim rollback' );

$restore = $catalog['families']['site-operations']['operations']['restore_readiness'];
mad4b_g4_assert( in_array( 'no_automatic_restore', $restore['requires'], true ), 'restore must never auto-execute' );
mad4b_g4_assert( in_array( 'current_authority_rebind', $restore['requires'], true ), 'restore must rebind current authority' );
mad4b_g4_assert( in_array( 'no_time_travel_authority', $restore['requires'], true ), 'restore must reject time-travel authority' );

$private_messages = $catalog['families']['wordpress-breadth']['operations']['private_message_read'];
mad4b_g4_assert( 'sensitive_read' === $private_messages['risk'], 'private messages must be sensitive reads' );
mad4b_g4_assert( in_array( 'separate_private_authority', $private_messages['requires'], true ), 'private messages require separate authority' );

$ready = MAD4B_SCP_G4_Provider_Families::readiness( array( 'family_id' => 'forms', 'provider_id' => 'fluentforms' ) );
mad4b_g4_assert( ! is_wp_error( $ready ), 'known provider readiness must succeed' );
mad4b_g4_assert( 1 === $ready['provider_count'], 'exact provider filter must return one row' );
mad4b_g4_assert( true === $ready['providers'][0]['installed'], 'stubbed Fluent Forms must be observed installed' );
mad4b_g4_assert( 'certified_exact_version' === $ready['providers'][0]['certification_state'], 'exact certified version must be explicit' );
mad4b_g4_assert( false === $ready['providers'][0]['execution_admitted'], 'readiness cannot admit execution' );

$unknown = MAD4B_SCP_G4_Provider_Families::readiness( array( 'family_id' => 'forms', 'provider_id' => 'unknown-provider' ) );
mad4b_g4_assert( is_wp_error( $unknown ) && 'mad4b_g4_provider_unknown_for_family' === $unknown->get_error_code(), 'unknown provider must fail closed' );

$plan = MAD4B_SCP_G4_Provider_Families::plan( array(
	'family_id' => 'commerce',
	'provider_id' => 'woocommerce',
	'operation_id' => 'refund_plan',
) );
mad4b_g4_assert( ! is_wp_error( $plan ), 'known exact plan must succeed' );
mad4b_g4_assert( false === $plan['execution_ready'], 'G4 plan cannot claim execution readiness' );
mad4b_g4_assert( false === $plan['provider_execution_performed'], 'planning cannot execute provider code' );
mad4b_g4_assert( false === $plan['financial_effect_performed'], 'planning cannot perform financial effects' );
mad4b_g4_assert( false === $plan['authorizing'], 'planning cannot authorize' );
mad4b_g4_assert( in_array( 'explicit_impact_approval', $plan['required_gates'], true ), 'high-risk plan requires explicit impact approval' );
mad4b_g4_assert( in_array( 'irreversible_effect_disclosure', $plan['required_gates'], true ), 'irreversible plan requires disclosure' );
mad4b_g4_assert( in_array( 'exact_plan_apply_readback', $plan['required_gates'], true ), 'mutation plan requires exact plan/apply/readback' );

$unprofiled = MAD4B_SCP_G4_Provider_Families::plan( array(
	'family_id' => 'site-operations',
	'provider_id' => 'w3-total-cache',
	'operation_id' => 'cache_purge_plan',
) );
mad4b_g4_assert( ! is_wp_error( $unprofiled ), 'installed unprofiled provider must produce a bounded non-executing plan' );
mad4b_g4_assert( in_array( 'provider_profile_certification', $unprofiled['required_gates'], true ), 'unprofiled installed provider requires profile certification' );
mad4b_g4_assert( in_array( 'provider_exact_version_certification', $unprofiled['required_gates'], true ), 'unprofiled installed version requires exact-version certification' );
mad4b_g4_assert( false === $unprofiled['execution_ready'], 'unprofiled provider plan cannot become execution ready' );

$missing_runtime = MAD4B_SCP_G4_Provider_Families::plan( array(
	'family_id' => 'forms',
	'provider_id' => 'wpforms',
	'operation_id' => 'schema_read',
) );
mad4b_g4_assert( ! is_wp_error( $missing_runtime ), 'profiled but absent provider must produce a bounded non-executing plan' );
mad4b_g4_assert( in_array( 'provider_profile_certification', $missing_runtime['required_gates'], true ), 'absent provider cannot inherit certification readiness' );
mad4b_g4_assert( in_array( 'provider_runtime_presence', $missing_runtime['required_gates'], true ), 'absent provider requires runtime presence' );
mad4b_g4_assert( false === $missing_runtime['execution_ready'], 'absent provider plan cannot become execution ready' );

$bad_plan = MAD4B_SCP_G4_Provider_Families::plan( array(
	'family_id' => 'builders',
	'provider_id' => 'elementor',
	'operation_id' => 'raw_php_execute',
) );
mad4b_g4_assert( is_wp_error( $bad_plan ) && 'mad4b_g4_plan_operation_invalid' === $bad_plan->get_error_code(), 'unreviewed operation must fail closed' );

echo "mad4b.g4-provider-family-catalog.v1: PASS\n";
