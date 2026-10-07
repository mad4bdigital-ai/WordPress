<?php
define( 'ABSPATH', __DIR__ . '/' );

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
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function apply_filters( $hook, $value ) { return 'mad4b_scp_g5_stored_observation_adapters' === $hook ? array( new MAD4B_G5_Test_Adapter() ) : $value; }
class MAD4B_SCP_Admin_Route_Registry {
	public static function schedule_submenu( $callback, $priority = 20 ) {}
	public static function register( $slug, $capability ) { return true; }
}
class MAD4B_SCP_Site_Profile { public static function site_uuid() { return 'site-g5'; } }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-external-providers.php';

final class MAD4B_G5_Test_Adapter implements MAD4B_SCP_G5_Stored_Observation_Adapter {
	public function descriptor() {
		$hex = str_repeat( 'a', 64 );
		return array(
			'contract' => MAD4B_SCP_G5_External_Providers::CONTRACT,
			'provider_id' => 'ga4',
			'strategy_id' => 'stored.growth-evidence.v1',
			'adapter_sha256' => hash_file( 'sha256', __FILE__ ),
			'artifact_sha256' => $hex,
			'schema_sha256' => str_repeat( 'b', 64 ),
			'generation_sha256' => str_repeat( 'c', 64 ),
			'account_ref' => str_repeat( 'd', 64 ),
			'tenant_ref' => str_repeat( 'e', 64 ),
			'certified' => true,
			'expires_at' => time() + 3600,
			'site_uuid' => 'site-g5',
			'required_scopes' => array( 'analytics.readonly' ),
			'property_refs' => array( 'property-1' ),
			'capability_ids' => array( 'analytics-report' ),
			'effects' => array( 'retained_aggregate_read' ),
			'economics' => array( 'quota_units' => 0, 'max_cost_micro' => 0, 'currency' => 'USD' ),
			'rights' => array( 'aggregate_read_allowed' => true, 'normalized_allowed' => true, 'max_retention_seconds' => 86400, 'storage_regions' => array( 'us' ), 'redistribution_allowed' => false ),
			'metrics' => array( 'sessions' => array( 'semantic_id' => 'sessions', 'unit' => 'count' ) ),
		);
	}
	public function setup_fields() { return array( 'property' => array( 'label' => 'Property', 'kind' => 'property', 'required' => true, 'max_length' => 191 ) ); }
	public function authorize_read( array $scope ) { return true; }
	public function consent_status( array $scope ) { return array(); }
	public function read_observation( $observation_id ) { return array(); }
}

function mad4b_g5_ext_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . PHP_EOL ); exit( 1 ); }
}

$inventory = MAD4B_SCP_G5_External_Providers::inventory();
mad4b_g5_ext_assert( ! is_wp_error( $inventory ), 'inventory must succeed' );
mad4b_g5_ext_assert( 1 === $inventory['registered_adapter_count'], 'exactly one fixture adapter must be visible' );
mad4b_g5_ext_assert( 'retained_read_admitted' === $inventory['providers'][0]['state'], 'pinned retained read must be admitted' );
mad4b_g5_ext_assert( false === $inventory['authorizing'] && false === $inventory['paid_execution_performed'], 'inventory cannot authorize or pay' );
mad4b_g5_ext_assert( false === $inventory['secret_values_included'] && false === $inventory['secret_handles_included'], 'inventory cannot echo credentials' );

$preview = MAD4B_SCP_G5_External_Providers::setup_preview( array( 'provider_id' => 'ga4', 'requested_scopes' => array( 'analytics.readonly' ) ) );
mad4b_g5_ext_assert( ! is_wp_error( $preview ) && false === $preview['credential_values_included'], 'setup preview must remain value-free' );
mad4b_g5_ext_assert( true === $preview['new_scopes_require_external_consent'], 'new scopes must require consent' );

$descriptor = MAD4B_SCP_G5_External_Providers::adapters()['ga4']->descriptor();
$pack = MAD4B_SCP_G5_External_Providers::validate_pack( array(
	'contract' => 'mad4b.g5-provider-configuration-pack.v1',
	'provider_id' => 'ga4',
	'strategy_id' => 'stored.growth-evidence.v1',
	'descriptor_sha256' => MAD4B_SCP_G5_External_Providers::digest( $descriptor ),
	'requested_scopes' => array( 'analytics.readonly' ),
) );
mad4b_g5_ext_assert( ! is_wp_error( $pack ) && true === $pack['compatible'], 'reviewed pack must preview as compatible' );
mad4b_g5_ext_assert( false === $pack['activation_performed'] && false === $pack['network_performed'], 'pack validation cannot activate or call network' );

$bad = MAD4B_SCP_G5_External_Providers::validate_pack( array(
	'contract' => 'mad4b.g5-provider-configuration-pack.v1',
	'provider_id' => 'ga4',
	'strategy_id' => 'generic-http',
	'descriptor_sha256' => str_repeat( 'a', 64 ),
	'requested_scopes' => array(),
) );
mad4b_g5_ext_assert( is_wp_error( $bad ) && 'mad4b_g5_configuration_pack_protocol_unavailable' === $bad->get_error_code(), 'unknown protocol must fail closed' );

echo "mad4b.feature007-g5-external-provider.v1: PASS\n";
