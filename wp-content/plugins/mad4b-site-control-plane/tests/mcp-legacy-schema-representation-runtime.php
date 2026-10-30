<?php
/**
 * Hermetic legacy MCP mixed-runtime acceptance. This tests the representation
 * seam, not an Ability mutation, grant, or owner attestation.
 */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = 'schema_invalid', $message = 'schema invalid' ) {
        $this->code = $code; $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( (string) $v ); }
function add_filter( ...$args ) { return true; }

eval( 'namespace WP\MCP\Domain\Tools;
class McpToolValidator {
    public static $calls = 0;
    public static $last = null;
    public static $dto_calls = 0;
    public static function validate_tool_dto( $dto ) { self::$dto_calls++; return new \WP_Error( "dto_legacy_bug" ); }
    public static function validate_tool_data( array $data ) {
        self::$calls++;
        self::$last = $data;
        foreach ( array( "inputSchema", "outputSchema" ) as $key ) {
            if ( ! isset( $data[ $key ] ) ) continue;
            if ( ! is_array( $data[ $key ] ) || ! isset( $data[ $key ]["type"] )
                 || $data[ $key ]["type"] !== "object" ) return new \WP_Error( "invalid_schema_type" );
            if ( isset( $data[ $key ]["properties"] ) && ! is_array( $data[ $key ]["properties"] ) )
                return new \WP_Error( "schema_properties_invalid" );
        }
        return true;
    }
}' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-catalog-diagnostics.php';
$fail = static function ( $message ) { fwrite( STDERR, 'FAIL mixed_mcp_schema: ' . $message . PHP_EOL ); exit( 1 ); };
$dto = new class {
    public $data;
    public function __construct() {
        $this->data = array(
            'name' => 'mad4b-schema-probe',
            'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
            'outputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => true ),
        );
    }
    public function toArray() { return $this->data; }
};
$first = MAD4B_SCP_MCP_Catalog_Diagnostics::dto_failure( $dto );
if ( null !== $first ) $fail( 'valid empty JSON objects were not accepted by upstream validator' );
$validator = 'WP\MCP\Domain\Tools\McpToolValidator';
if ( 1 !== $validator::$calls || 0 !== $validator::$dto_calls ) $fail( 'did not use full upstream data validation' );
if ( ! is_array( $validator::$last['inputSchema']['properties'] )
     || ! is_array( $validator::$last['outputSchema']['properties'] ) ) $fail( 'validation copy was not converted' );
if ( ! $dto->data['inputSchema']['properties'] instanceof stdClass
     || ! $dto->data['outputSchema']['properties'] instanceof stdClass ) $fail( 'outbound wire was modified' );
$dto->data['inputSchema']['properties'] = 'invalid';
$second = MAD4B_SCP_MCP_Catalog_Diagnostics::dto_failure( $dto );
if ( ! is_array( $second ) || ( $second['error_code'] ?? '' ) !== 'schema_properties_invalid'
    || ( $second['stage'] ?? '' ) !== 'official_schema_validation' ) $fail( 'malformed property map did not fail closed' );
$dto->data['inputSchema']['properties'] = array( 'person' => array( 'type' => 'string' ) );
$dto->data['outputSchema']['properties'] = new stdClass();
$third = MAD4B_SCP_MCP_Catalog_Diagnostics::dto_failure( $dto );
if ( null !== $third || ( $validator::$last['inputSchema']['properties']['person']['type'] ?? '' ) !== 'string' )
    $fail( 'nonempty constrained properties changed' );
echo "MCP_LEGACY_SCHEMA_REPRESENTATION: PASS\n";
