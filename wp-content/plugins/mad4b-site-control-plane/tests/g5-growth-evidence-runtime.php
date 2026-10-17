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
function apply_filters( $hook, $value ) { return 'mad4b_scp_g5_stored_observation_adapters' === $hook ? array( $GLOBALS['g5_adapter'] ) : $value; }
class MAD4B_SCP_Admin_Route_Registry {
	public static function schedule_submenu( $callback, $priority = 20 ) {}
	public static function register( $slug, $capability ) { return true; }
}
class MAD4B_SCP_Site_Profile { public static function site_uuid() { return 'site-g5'; } }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-external-providers.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-growth-evidence.php';

final class MAD4B_G5_Growth_Test_Adapter implements MAD4B_SCP_G5_Stored_Observation_Adapter {
	public $consent = true;
	public $scope_reads = 0;
	public $payload_reads = 0;
	public $certificate_expires_at = null;
	public $expire_during_read = false;
	public $expire_first_in_batch = false;
	public $delay_second_in_batch = false;
	private $issued_expires_at;
	public function __construct() { $this->issued_expires_at = time() + 3600; }
	public function descriptor() {
		return array(
			'contract' => MAD4B_SCP_G5_External_Providers::CONTRACT,
			'provider_id' => 'ga4',
			'strategy_id' => 'stored.growth-evidence.v1',
			'adapter_sha256' => hash_file( 'sha256', __FILE__ ),
			'artifact_sha256' => str_repeat( 'a', 64 ),
			'schema_sha256' => str_repeat( 'b', 64 ),
			'generation_sha256' => str_repeat( 'c', 64 ),
			'account_ref' => str_repeat( 'd', 64 ),
			'tenant_ref' => str_repeat( 'e', 64 ),
			'certified' => true,
			'expires_at' => null === $this->certificate_expires_at ? $this->issued_expires_at : $this->certificate_expires_at,
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
	public function setup_fields() { return array(); }
	public function authorize_read( array $scope ) { return $this->consent; }
	public function consent_status( array $scope ) {
		return array(
			'expires_at' => $this->issued_expires_at,
			'granted_scopes' => array( 'analytics.readonly' ),
			'account_ref' => str_repeat( 'd', 64 ),
			'property_ref' => 'property-1',
			'tenant_ref' => str_repeat( 'e', 64 ),
			'generation_sha256' => str_repeat( 'c', 64 ),
		);
	}
	private function scope_value() { return array( 'account_ref' => str_repeat( 'd', 64 ), 'property_ref' => 'property-1', 'tenant_ref' => str_repeat( 'e', 64 ), 'site_uuid' => 'site-g5', 'capability_id' => 'analytics-report' ); }
	public function observation_scope( $observation_id ) { ++$this->scope_reads; return $this->scope_value(); }
	public function read_observation( $observation_id, array $authorized_scope ) {
		++$this->payload_reads;
		if ( $this->expire_during_read ) sleep( 3 );
		if ( $this->delay_second_in_batch && 'f' === substr( $observation_id, 0, 1 ) ) sleep( 3 );
		if ( $authorized_scope !== $this->scope_value() ) return new WP_Error( 'fixture_scope_mismatch' );
		$currency = substr( $observation_id, 0, 1 ) === 'f' ? 'EUR' : 'USD';
		return array(
			'contract' => MAD4B_SCP_G5_Growth_Evidence::CONTRACT,
			'observation_id' => $observation_id,
			'provider_id' => 'ga4',
			'generation_sha256' => str_repeat( 'c', 64 ),
			'schema_sha256' => str_repeat( 'b', 64 ),
			'privacy_class' => 'aggregate_only',
			'scope' => $this->scope_value(),
			'observed_at' => time() - 60,
			'valid_until' => $this->expire_first_in_batch && 'a' === substr( $observation_id, 0, 1 ) ? time() + 2 : time() + 600,
			'source_sha256' => str_repeat( '1', 64 ),
			'storage_region' => 'us',
			'dimensions' => array(
				'query' => 'all',
				'surface_ref' => 'site',
				'market' => 'US',
				'language' => 'en',
				'window_start' => '2026-10-01',
				'window_end' => '2026-10-07',
				'attribution_window_seconds' => 86400,
				'timezone' => 'UTC',
				'granularity' => 'day',
				'metric_currency' => $currency,
			),
			'metrics' => array( 'sessions' => 10 ),
			'sampling' => array( 'state' => 'complete', 'rate' => 1.0, 'method' => 'complete', 'thresholding' => false ),
			'cost' => array( 'state' => 'settled', 'quota_units' => 0, 'cost_micro' => 0, 'currency' => 'USD', 'receipt_sha256' => str_repeat( '2', 64 ), 'budget_state' => 'admitted' ),
			'contradictions' => array(),
		);
	}
}

function mad4b_g5_growth_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . PHP_EOL ); exit( 1 ); }
}

$GLOBALS['g5_adapter'] = new MAD4B_G5_Growth_Test_Adapter();
$one = MAD4B_SCP_G5_Growth_Evidence::preview( array( 'observation_refs' => array(
	array( 'provider_id' => 'ga4', 'observation_id' => str_repeat( 'a', 64 ) ),
) ) );
mad4b_g5_growth_assert( ! is_wp_error( $one ), 'single retained observation must resolve' );
mad4b_g5_growth_assert( true === $one['comparison']['comparable'], 'same exact dimensions must be comparable' );
mad4b_g5_growth_assert( false === $one['proposal']['direct_content_mutation'] && false === $one['authorizing'], 'growth evidence cannot mutate or authorize' );

$mixed = MAD4B_SCP_G5_Growth_Evidence::preview( array( 'observation_refs' => array(
	array( 'provider_id' => 'ga4', 'observation_id' => str_repeat( 'a', 64 ) ),
	array( 'provider_id' => 'ga4', 'observation_id' => str_repeat( 'f', 64 ) ),
) ) );
mad4b_g5_growth_assert( ! is_wp_error( $mixed ) && false === $mixed['comparison']['comparable'], 'mixed currency/window semantics must fail comparison' );
mad4b_g5_growth_assert( in_array( 'mixed_dimensions_or_metric_semantics', $mixed['comparison']['blockers'], true ), 'mixed dimensions blocker must be explicit' );

$GLOBALS['g5_adapter']->consent = false;
$before_denied_payload_reads = $GLOBALS['g5_adapter']->payload_reads;
$before_denied_scope_reads = $GLOBALS['g5_adapter']->scope_reads;
$denied = MAD4B_SCP_G5_Growth_Evidence::preview( array( 'observation_refs' => array(
	array( 'provider_id' => 'ga4', 'observation_id' => str_repeat( 'a', 64 ) ),
) ) );
mad4b_g5_growth_assert( is_wp_error( $denied ) && 'mad4b_g5_observation_access_denied' === $denied->get_error_code(), 'missing provider consent must fail closed' );
mad4b_g5_growth_assert( $GLOBALS['g5_adapter']->scope_reads > $before_denied_scope_reads, 'authorization must use metadata-only scope lookup' );
mad4b_g5_growth_assert( $GLOBALS['g5_adapter']->payload_reads === $before_denied_payload_reads, 'denied consent must prevent retained payload access' );

// A descriptor with the exact same bytes must not remain eligible after its
// expiration passes during a retained read. This checks current eligibility,
// not merely descriptor-hash equality.
$GLOBALS['g5_adapter']->consent = true;
$GLOBALS['g5_adapter']->certificate_expires_at = time() + 2;
$GLOBALS['g5_adapter']->expire_during_read = true;
$expired_during_read = MAD4B_SCP_G5_Growth_Evidence::preview( array( 'observation_refs' => array(
	array( 'provider_id' => 'ga4', 'observation_id' => str_repeat( 'a', 64 ) ),
) ) );
mad4b_g5_growth_assert(
	is_wp_error( $expired_during_read ) && 'mad4b_g5_descriptor_stale_or_foreign' === $expired_during_read->get_error_code(),
	'expired unchanged descriptor must fail closed after retained payload access'
);
$GLOBALS['g5_adapter']->expire_during_read = false;
$GLOBALS['g5_adapter']->certificate_expires_at = null;

// The first observation may expire while a later retained read runs. Per-row
// normalization alone must not let an expired row escape inside a valid batch.
$GLOBALS['g5_adapter']->expire_first_in_batch = true;
$GLOBALS['g5_adapter']->delay_second_in_batch = true;
$expired_in_batch = MAD4B_SCP_G5_Growth_Evidence::preview( array( 'observation_refs' => array(
	array( 'provider_id' => 'ga4', 'observation_id' => str_repeat( 'a', 64 ) ),
	array( 'provider_id' => 'ga4', 'observation_id' => str_repeat( 'f', 64 ) ),
) ) );
mad4b_g5_growth_assert(
	is_wp_error( $expired_in_batch ) && 'mad4b_g5_observation_stale_or_rights_denied' === $expired_in_batch->get_error_code(),
	'expired earlier evidence must not escape a multi-observation preview'
);
$GLOBALS['g5_adapter']->expire_first_in_batch = false;
$GLOBALS['g5_adapter']->delay_second_in_batch = false;

echo "mad4b.feature007-g5-growth-observation.v1: PASS\n";
