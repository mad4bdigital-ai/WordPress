<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_check_invalid_utf8( $value ) { return $value; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][ $name ] ?? null; }
function get_current_blog_id() { return 1; }

class MAD4B_SCP_Authorization {
	public static function execution_boundary_verified( $ability ) { return true; }
}
class MAD4B_SCP_Servers {
	public static function provider_for_ability( $server, $ability ) { return 'fixture'; }
	public static function core_tools( $server ) { return array(); }
}

class InspectorFixture {
	private $name;
	private $variant;
	public function __construct( $name, $variant ) { $this->name = $name; $this->variant = $variant; }
	public function get_name() { return $this->name; }
	public function execute( $input = null ) { return array( 'ok' => true ); }
	public function get_category() { return 'fixture'; }
	public function get_label() { return 'Fixture'; }
	public function get_description() { return 'Canonical order fixture'; }
	public function get_input_schema() {
		return array( 'type' => 'object', 'properties' => array( 'value' => array( 'type' => 'number', 'default' => 0.5 ) ) );
	}
	public function get_output_schema() {
		if ( 'semantic-change' === $this->variant ) return array( 'type' => 'object', 'additionalProperties' => false );
		return 'reverse' === $this->variant
			? array( 'properties' => array(), 'type' => 'object' )
			: array( 'type' => 'object', 'properties' => array() );
	}
	public function get_meta() {
		$annotations = 'reverse' === $this->variant
			? array( 'idempotent' => true, 'readonly' => true )
			: array( 'readonly' => true, 'idempotent' => true );
		$mcp = 'reverse' === $this->variant
			? array( 'search_aliases' => array( 'قراءة', 'read' ), 'surface' => 'read' )
			: array( 'surface' => 'read', 'search_aliases' => array( 'قراءة', 'read' ) );
		return 'reverse' === $this->variant
			? array( 'mcp' => $mcp, 'annotations' => $annotations )
			: array( 'annotations' => $annotations, 'mcp' => $mcp );
	}
}

require __DIR__ . '/../includes/class-mad4b-scp-ability-contract-inspector.php';
require __DIR__ . '/../includes/class-mad4b-scp-capability-descriptor-registry.php';

$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };
$name = 'fixture/canonical';
$GLOBALS['abilities'] = array( $name => new InspectorFixture( $name, 'normal' ) );
$first = MAD4B_SCP_Ability_Contract_Inspector::inspect( $name );
$first_descriptor = MAD4B_SCP_Capability_Descriptor_Registry::describe( $name );
$check( ! is_wp_error( $first ) && ! is_wp_error( $first_descriptor ), 'Initial canonical inspection failed.' );
$check( ! class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection', false ), 'Descriptor registry unexpectedly depends on ChatGPT projection loading.' );

$GLOBALS['abilities'][ $name ] = new InspectorFixture( $name, 'reverse' );
$second = MAD4B_SCP_Ability_Contract_Inspector::inspect( $name );
$second_descriptor = MAD4B_SCP_Capability_Descriptor_Registry::describe( $name );
$check( hash_equals( $first['classification_sha256'], $second['classification_sha256'] ), 'Associative metadata order changed classification identity.' );
$check( hash_equals( $first_descriptor['descriptor_sha256'], $second_descriptor['descriptor_sha256'] ), 'Associative metadata order changed descriptor identity.' );
$check( MAD4B_SCP_Ability_Contract_Inspector::CLASSIFICATION_CONTRACT === $second['classification_contract'], 'Classification digest contract was not explicit.' );

$object_a = (object) array( 'b' => 2, 'a' => 1 );
$object_b = (object) array( 'a' => 1, 'b' => 2 );
$object_digest_a = MAD4B_SCP_Ability_Contract_Inspector::digest( 'fixture.object-order.v1', $object_a );
$object_digest_b = MAD4B_SCP_Ability_Contract_Inspector::digest( 'fixture.object-order.v1', $object_b );
$check( ! is_wp_error( $object_digest_a ) && hash_equals( $object_digest_a, $object_digest_b ), 'Object property order changed canonical identity.' );
$object_empty = MAD4B_SCP_Ability_Contract_Inspector::digest( 'fixture.object-kind.v1', new stdClass() );
$list_empty = MAD4B_SCP_Ability_Contract_Inspector::digest( 'fixture.object-kind.v1', array() );
$check( ! is_wp_error( $object_empty ) && ! is_wp_error( $list_empty ) && ! hash_equals( $object_empty, $list_empty ), 'Canonicalization collapsed empty object and list semantics.' );
$float_digest = MAD4B_SCP_Ability_Contract_Inspector::digest( 'fixture.float.v1', array( 'ratio' => 0.5 ) );
$check( ! is_wp_error( $float_digest ) && 64 === strlen( $float_digest ), 'Finite float canonicalization failed.' );

$GLOBALS['abilities'][ $name ] = new InspectorFixture( $name, 'semantic-change' );
$third = MAD4B_SCP_Ability_Contract_Inspector::inspect( $name );
$check( ! hash_equals( $second['classification_sha256'], $third['classification_sha256'] ), 'Semantic output contract change did not change classification identity.' );

echo "PASS canonical Ability inspection: projection-independent descriptor, stable array/object ordering, object/list fidelity, finite floats and semantic drift detection\n";
