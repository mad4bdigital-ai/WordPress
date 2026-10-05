<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function remove_filter( $hook, $callback, $priority = 10 ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function get_current_blog_id() { return 7; }

final class MAD4B_SCP_Ability_Contract_Inspector {
	const CONTRACT = 'mad4b.ability-contract-inspector.v1';
	const CLASSIFICATION_CONTRACT = 'mad4b.ability-classification.v2';
	public static $generation = 'generation-a';

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
	public static function digest( $contract, $value ) {
		$json = json_encode( self::sort_value( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', 'mad4b:' . (string) $contract . "\n" . $json );
	}
	public static function site_binding() {
		return array( 'site_uuid' => '33333333-3333-4333-8333-333333333333', 'origin' => 'https://staging.example.test', 'environment' => 'staging', 'profile_revision' => 4 );
	}
	public static function inspect( $name ) {
		$name = (string) $name;
		$lane = false !== strpos( $name, 'read' ) || false !== strpos( $name, 'get-' ) || false !== strpos( $name, 'list-' ) || false !== strpos( $name, 'status' ) ? 'read' : 'write';
		return array(
			'inspector_contract' => self::CONTRACT,
			'classification_contract' => self::CLASSIFICATION_CONTRACT,
			'ability_name' => $name,
			'input_schema_sha256' => hash( 'sha256', 'schema:' . $name . ':' . self::$generation ),
			'classification_sha256' => hash( 'sha256', 'classification:' . $name . ':' . $lane . ':' . self::$generation ),
			'classification' => $lane,
			'known' => true,
			'lane' => $lane,
			'readonly' => 'read' === $lane,
			'readonly_declared' => true,
			'conservative_mutation' => false,
			'breakglass' => false,
			'category' => 'ci',
			'execution_boundary' => 'write' === $lane ? 'mad4b.authorization-execution-boundary.v1' : '',
			'execution_provider' => 'write' === $lane ? 'core' : '',
			'execution_boundary_verified' => true,
			'execution_blocker' => '',
			'label' => $name,
			'description' => 'CI descriptor consumer fixture',
			'projection_eligible' => true,
			'execution_eligible' => true,
			'execution_lane' => $lane,
			'projection_blockers' => array(),
		);
	}
}

final class MAD4B_SCP_Provider_Compatibility_Certification {
	public static function capability_certification( $input = array() ) {
		$capability = isset( $input['capability_id'] ) ? (string) $input['capability_id'] : '';
		return array(
			'contract' => 'mad4b.provider-capability-certification-result.v1',
			'capabilities' => array(
				$capability => array(
					'certification_level' => 'READ_COMPATIBLE',
					'activation_stage' => 'active',
					'read_eligible' => true,
					'write_eligible' => true,
					'canary_eligible' => true,
				),
			),
			'match_count' => 1,
			'authorizing' => false,
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-descriptor-registry.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operation-registry.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-traits.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php';

$fail = static function ( $message, $value = null ) {
	$detail = null === $value ? '' : ( is_wp_error( $value ) ? ' ' . json_encode( array( 'code' => $value->get_error_code(), 'message' => $value->get_error_message(), 'data' => $value->get_error_data() ) ) : ' ' . json_encode( $value ) );
	fwrite( STDERR, 'FAIL canonical-capability-descriptor-consumers: ' . $message . $detail . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message, $value = null ) use ( $fail ) { if ( ! $condition ) $fail( $message, $value ); };

$catalog = json_decode( file_get_contents( dirname( __DIR__ ) . '/config/operation-registry.json' ), true );
$operation = reset( $catalog['operations'] );
$operation_id = (string) $operation['id'];

MAD4B_SCP_Ability_Contract_Inspector::$generation = 'generation-a';
$op_a = MAD4B_SCP_Operation_Registry::operation( $operation_id );
$check( is_array( $op_a ) && ! empty( $op_a['descriptor_binding_ready'] ), 'Operation Registry did not bind descriptors.', $op_a );
$planner_a = $op_a['capability_descriptor_bindings']['planner'];
$check( empty( $planner_a['authorizing'] ), 'Operation Registry descriptor became authorizing.' );

$auth_a = MAD4B_SCP_Authorization::capability_descriptor_binding( (string) $operation['planner'] );
$check( is_array( $auth_a ) && empty( $auth_a['authorizing'] ), 'Authorization descriptor binding unavailable or authorizing.', $auth_a );

$servers_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
$check( is_string( $servers_source ) && false !== strpos( $servers_source, 'MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence' ), 'Servers no longer delegates descriptor evidence to the canonical service.' );
$server_a = MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence( 'mad4b-ci', array( (string) $operation['planner'] ) );
$check( ! empty( $server_a['ready'] ) && empty( $server_a['authorizing'] ), 'Server descriptor evidence unavailable or authorizing.', $server_a );

$provider_catalog = json_decode( file_get_contents( dirname( __DIR__ ) . '/config/provider-capability-contracts.json' ), true );
$provider_id = (string) array_key_first( $provider_catalog['providers'] );
$capability_id = (string) array_key_first( $provider_catalog['providers'][ $provider_id ]['capabilities'] );
$traits_a = MAD4B_SCP_Capability_Traits::profile( $provider_id, $capability_id );
$check( ! empty( $traits_a['descriptor_binding_ready'] ) && 'none' === $traits_a['descriptor_authority_effect'], 'Trait profile did not consume descriptor roots.', $traits_a );

MAD4B_SCP_Ability_Contract_Inspector::$generation = 'generation-b';
$op_b = MAD4B_SCP_Operation_Registry::operation( $operation_id );
$auth_b = MAD4B_SCP_Authorization::capability_descriptor_binding( (string) $operation['planner'] );
$server_b = MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence( 'mad4b-ci', array( (string) $operation['planner'] ) );
$traits_b = MAD4B_SCP_Capability_Traits::profile( $provider_id, $capability_id );

$check( ! hash_equals( (string) $planner_a['descriptor_sha256'], (string) $op_b['capability_descriptor_bindings']['planner']['descriptor_sha256'] ), 'Operation Registry ignored descriptor generation drift.' );
$check( ! hash_equals( (string) $auth_a['descriptor_sha256'], (string) $auth_b['descriptor_sha256'] ), 'Authorization ignored descriptor generation drift.' );
$check( ! hash_equals( (string) $server_a['bindings'][ (string) $operation['planner'] ]['descriptor_sha256'], (string) $server_b['bindings'][ (string) $operation['planner'] ]['descriptor_sha256'] ), 'Servers ignored descriptor generation drift.' );
$check( ! hash_equals( (string) $traits_a['descriptor_generation_sha256'], (string) $traits_b['descriptor_generation_sha256'] ), 'Capability Traits ignored descriptor generation drift.' );

$stale = MAD4B_SCP_Capability_Descriptor_Registry::assert_binding( (string) $operation['planner'], $auth_a, 'authorization' );
$check( is_wp_error( $stale ) && in_array( $stale->get_error_code(), array( 'mad4b_capability_descriptor_binding_drift', 'mad4b_capability_descriptor_generation_drift' ), true ), 'Stale descriptor binding was not rejected.', $stale );

foreach ( array( $op_b['capability_descriptor_bindings']['planner'], $auth_b, $server_b['bindings'][ (string) $operation['planner'] ] ) as $binding ) {
	$check( isset( $binding['authorizing'] ) && false === $binding['authorizing'], 'Descriptor consumer binding widened authority.', $binding );
}

echo "mad4b.canonical-capability-descriptor-consumers.runtime.v1: PASS\n";