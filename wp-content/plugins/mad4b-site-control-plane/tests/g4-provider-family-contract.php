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
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
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
	public static function runtime_status( $provider, $available = null ) {
		$actual = self::installed_version( $provider );
		$certified = self::certified_versions( $provider );
		if ( '' === $actual ) $status = 'unavailable';
		elseif ( in_array( $actual, $certified, true ) ) $status = 'certified';
		else $status = 'version_drift';
		return array(
			'provider' => $provider,
			'status' => $status,
			'installed_version' => $actual,
			'certified_version' => isset( $certified[0] ) ? $certified[0] : '',
			'runtime_contract_ok' => 'certified' === $status,
		);
	}
}

final class MAD4B_G4_Test_Adapter {
	private $id;
	private $available;
	private $read;
	private $reversible;
	private $version;
	public function __construct( $id, $available, array $read, array $reversible = array(), $version = '' ) {
		$this->id = $id;
		$this->available = (bool) $available;
		$this->read = $read;
		$this->reversible = $reversible;
		$this->version = $version;
	}
	public function is_available() { return $this->available; }
	public function ability_names() { return array( 'read' => $this->read, 'content' => array_keys( $this->reversible ), 'admin' => array() ); }
	public function reversible_contracts() { return $this->reversible; }
	public function status() {
		return array(
			'id' => $this->id,
			'available' => $this->available,
			'version' => $this->version,
			'abilities' => $this->ability_names(),
			'reversible_contracts' => $this->reversible,
			'provider_certification' => array(
				'provider' => $this->id,
				'status' => 'uncertified_provider',
				'installed_version' => $this->version,
				'certified_version' => '',
				'runtime_contract_ok' => false,
			),
		);
	}
}

final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	private $items;
	private function __construct() {
		$this->items = array(
			'fluentforms' => new MAD4B_G4_Test_Adapter( 'fluentforms', true, array( 'fluentforms/status', 'fluentforms/list-forms', 'fluentforms/get-form' ), array(), '6.1.0' ),
			'woocommerce' => new MAD4B_G4_Test_Adapter( 'woocommerce', true, array( 'woocommerce/status', 'woocommerce/get-product' ), array( 'woocommerce/update-product' => 'mad4b.rollback.woocommerce-product.v1' ), '10.2.1' ),
			'elementor' => new MAD4B_G4_Test_Adapter( 'elementor', true, array( 'elementor/status', 'elementor/get-document' ), array( 'elementor/update-widget-settings' => 'mad4b.rollback.elementor-widget-settings.v1' ), '3.31.0' ),
			'litespeed' => new MAD4B_G4_Test_Adapter( 'litespeed', true, array( 'litespeed/status' ), array(), '7.4.0' ),
			'polylang' => new MAD4B_G4_Test_Adapter( 'polylang', true, array( 'polylang/status', 'polylang/list-languages' ), array( 'polylang/set-post-language' => 'mad4b.rollback.polylang-post-language.v1' ), '3.7.3' ),
		);
	}
	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}
	public function register_defaults() { return true; }
	public function get( $id ) { return isset( $this->items[ $id ] ) ? $this->items[ $id ] : null; }
}

final class MAD4B_SCP_Plugin_Discovery {
	private static $coverage_calls = 0;
	public static function reset_calls() { self::$coverage_calls = 0; }
	public static function coverage_calls() { return self::$coverage_calls; }
	public static function coverage() {
		++self::$coverage_calls;
		return array(
			'plugins' => array(
				array( 'plugin_file' => 'fluentform/fluentform.php', 'slug' => 'fluentform', 'name' => 'Fluent Forms', 'version' => '6.1.0', 'active' => true, 'network_active' => false, 'adapter_id' => 'fluentforms', 'family' => 'fluentforms', 'coverage_state' => 'read_only_supported', 'functional_coverage' => array( 'state' => 'read_ready_write_blocked' ) ),
				array( 'plugin_file' => 'woocommerce/woocommerce.php', 'slug' => 'woocommerce', 'name' => 'WooCommerce', 'version' => '10.2.1', 'active' => true, 'network_active' => false, 'adapter_id' => 'woocommerce', 'family' => 'woocommerce', 'coverage_state' => 'supported_reversible', 'functional_coverage' => array( 'state' => 'functional_ready' ) ),
				array( 'plugin_file' => 'elementor/elementor.php', 'slug' => 'elementor', 'name' => 'Elementor', 'version' => '3.31.0', 'active' => true, 'network_active' => false, 'adapter_id' => 'elementor', 'family' => 'elementor', 'coverage_state' => 'supported_reversible', 'functional_coverage' => array( 'state' => 'functional_ready' ) ),
				array( 'plugin_file' => 'gravityforms/gravityforms.php', 'slug' => 'gravityforms', 'name' => 'Gravity Forms', 'version' => '2.9.0', 'active' => true, 'network_active' => false, 'adapter_id' => '', 'family' => 'unknown', 'coverage_state' => 'adapter_required', 'functional_coverage' => array( 'state' => 'adapter_missing' ) ),
				array( 'plugin_file' => 'w3-total-cache/w3-total-cache.php', 'slug' => 'w3-total-cache', 'name' => 'W3 Total Cache', 'version' => '2.8.0', 'active' => true, 'network_active' => false, 'adapter_id' => '', 'family' => 'unknown', 'coverage_state' => 'adapter_required', 'functional_coverage' => array( 'state' => 'adapter_missing' ) ),
				array( 'plugin_file' => 'litespeed-cache/litespeed-cache.php', 'slug' => 'litespeed-cache', 'name' => 'LiteSpeed Cache', 'version' => '7.4.0', 'active' => true, 'network_active' => false, 'adapter_id' => 'litespeed', 'family' => 'litespeed', 'coverage_state' => 'supported_governed', 'functional_coverage' => array( 'state' => 'read_ready_write_blocked' ) ),
				array( 'plugin_file' => 'polylang/polylang.php', 'slug' => 'polylang', 'name' => 'Polylang', 'version' => '3.7.3', 'active' => true, 'network_active' => false, 'adapter_id' => 'polylang', 'family' => 'polylang', 'coverage_state' => 'supported_reversible', 'functional_coverage' => array( 'state' => 'functional_ready' ) ),
			),
		);
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

MAD4B_SCP_Plugin_Discovery::reset_calls();
$all_forms = MAD4B_SCP_G4_Provider_Families::readiness( array( 'family_id' => 'forms' ) );
mad4b_g4_assert( ! is_wp_error( $all_forms ) && 5 === $all_forms['provider_count'], 'forms readiness must cover the five reviewed providers' );
mad4b_g4_assert( 1 === MAD4B_SCP_Plugin_Discovery::coverage_calls(), 'plugin discovery must be snapshotted once per readiness request, not once per provider' );

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
mad4b_g4_assert( true === $ready['providers'][0]['adapter_registered'], 'Fluent Forms adapter must be discovered from the runtime registry' );
mad4b_g4_assert( true === $ready['providers'][0]['read_surface_ready'], 'Fluent Forms read surface must be projected as ready' );
mad4b_g4_assert( 3 === $ready['providers'][0]['adapter_read_ability_count'], 'Fluent Forms read ability inventory must remain exact in the test fixture' );
mad4b_g4_assert( 1 === count( $ready['providers'][0]['observed_plugin_identities'] ), 'Fluent Forms plugin identity must come from plugin discovery' );


$gravity = MAD4B_SCP_G4_Provider_Families::readiness( array( 'family_id' => 'forms', 'provider_id' => 'gravityforms' ) );
mad4b_g4_assert( ! is_wp_error( $gravity ), 'installed Gravity Forms discovery must succeed' );
mad4b_g4_assert( true === $gravity['providers'][0]['installed'], 'Gravity Forms must be observed from plugin discovery' );
mad4b_g4_assert( 'installed_adapter_missing' === $gravity['providers'][0]['certification_state'], 'installed provider without adapter must be explicit' );
mad4b_g4_assert( false === $gravity['providers'][0]['read_surface_ready'], 'missing adapter cannot claim read readiness' );

$litespeed = MAD4B_SCP_G4_Provider_Families::readiness( array( 'family_id' => 'site-operations', 'provider_id' => 'litespeed' ) );
mad4b_g4_assert( ! is_wp_error( $litespeed ), 'LiteSpeed runtime adapter readiness must succeed' );
mad4b_g4_assert( 'adapter_read_ready_uncertified' === $litespeed['providers'][0]['certification_state'], 'read-ready adapter without exact provider profile must remain uncertified' );
mad4b_g4_assert( true === $litespeed['providers'][0]['read_surface_ready'], 'LiteSpeed read status must be projected from the existing adapter' );
mad4b_g4_assert( false === $litespeed['providers'][0]['execution_admitted'], 'existing adapter discovery still cannot admit execution' );

$polylang = MAD4B_SCP_G4_Provider_Families::readiness( array( 'family_id' => 'wordpress-breadth', 'provider_id' => 'polylang' ) );
mad4b_g4_assert( ! is_wp_error( $polylang ) && true === $polylang['providers'][0]['read_surface_ready'], 'generic language coverage must reuse the existing Polylang adapter' );

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
mad4b_g4_assert( in_array( 'provider_native_executor_required', $unprofiled['required_gates'], true ), 'mutation plan without adapter requires provider-native executor' );
mad4b_g4_assert( in_array( 'reversible_contract_required', $unprofiled['required_gates'], true ), 'reversible mutation plan without rollback contract must remain blocked' );


$missing_runtime = MAD4B_SCP_G4_Provider_Families::plan( array(
	'family_id' => 'forms',
	'provider_id' => 'wpforms',
	'operation_id' => 'schema_read',
) );
mad4b_g4_assert( ! is_wp_error( $missing_runtime ), 'profiled but absent provider must produce a bounded non-executing plan' );
mad4b_g4_assert( in_array( 'provider_profile_certification', $missing_runtime['required_gates'], true ), 'absent provider cannot inherit certification readiness' );
mad4b_g4_assert( in_array( 'provider_runtime_presence', $missing_runtime['required_gates'], true ), 'absent provider requires runtime presence' );
mad4b_g4_assert( in_array( 'provider_read_adapter_required', $missing_runtime['required_gates'], true ), 'absent read provider also requires an exact read adapter' );

mad4b_g4_assert( false === $missing_runtime['execution_ready'], 'absent provider plan cannot become execution ready' );

$bad_plan = MAD4B_SCP_G4_Provider_Families::plan( array(
	'family_id' => 'builders',
	'provider_id' => 'elementor',
	'operation_id' => 'raw_php_execute',
) );
mad4b_g4_assert( is_wp_error( $bad_plan ) && 'mad4b_g4_plan_operation_invalid' === $bad_plan->get_error_code(), 'unreviewed operation must fail closed' );

echo "mad4b.g4-provider-family-catalog.v1: PASS\n";
