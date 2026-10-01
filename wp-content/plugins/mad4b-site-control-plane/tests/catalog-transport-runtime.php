<?php
// Standalone transport tests: large schemas, frozen pagination, deltas, resumability and isolation.
define( 'ABSPATH', __DIR__ );
class WP_Error { public $code; function __construct( $code, $message ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class MAD4B_SCP_Policy { static function can_read() { return $GLOBALS['allowed']; } }
class MAD4B_SCP_ChatGPT_Tool_Projection { static function current_binding() { return array( 'origin' => 'https://ci.test', 'revision' => $GLOBALS['binding'] ); } }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_current_user_id() { return $GLOBALS['user']; }
function wp_get_current_user() { return (object) array( 'allcaps' => array( 'read' => true ) ); }
function wp_salt( $v ) { return 'test-signing-key'; }
function apply_filters( $name, $v ) { return $v; }
function set_transient( $key, $v, $ttl ) { $GLOBALS['cache'][$key] = $v; return true; }
function get_transient( $key ) { return $GLOBALS['cache'][$key] ?? false; }
function wp_get_abilities() { return $GLOBALS['abilities']; }
class FixtureAbility {
 private $text; function __construct( $text ) { $this->text = $text; }
 function get_input_schema() { return array( 'type' => 'object', 'properties' => array( 'value' => array( 'type' => 'string', 'description' => $this->text ) ) ); }
 function get_output_schema() { return array( 'type' => 'object' ); }
 function get_label() { return 'Fixture'; }
 function get_meta() { return array( 'annotations' => array( 'readonly' => true ) ); }
 function get_category() { return 'read'; }
}
require __DIR__ . '/../includes/class-mad4b-scp-ability-catalog-transport.php';
function check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function request( $input ) { return MAD4B_SCP_Ability_Catalog_Transport::handle( $input ); }
$GLOBALS['allowed'] = true; $GLOBALS['user'] = 1; $GLOBALS['binding'] = 1; $GLOBALS['cache'] = array();
$GLOBALS['abilities'] = array( 'a' => new FixtureAbility( str_repeat( 'محتوى', 20000 ) ), 'b' => new FixtureAbility( 'old' ) );
$first = request( array( 'limit' => 1 ) );
check( $first['items'][0]['schema_bytes'] > 65536 && ! empty( $first['next_cursor'] ), 'Large schema rejected or pagination absent' );
$ref = array( 'snapshot' => $first['snapshot'], 'schema_sha256' => $first['items'][0]['schema_sha256'] );
$full = request( $ref + array( 'transport_action' => 'schema' ) );
check( strlen( $full['schema']['inputSchema']['properties']['value']['description'] ) > 65536, 'Schema truncated' );
$assembled = ''; $index = 0;
do {
 $chunk = request( $ref + array( 'transport_action' => 'chunk', 'chunk_bytes' => 1024, 'chunk_index' => $index ) );
 $raw = base64_decode( $chunk['data'], true ); check( hash( 'sha256', $raw ) === $chunk['chunk_sha256'], 'Chunk integrity failed' ); $assembled .= $raw; $index = $chunk['next_chunk_index'];
} while ( null !== $index );
check( hash( 'sha256', $assembled ) === $ref['schema_sha256'], 'Reassembled schema differs' );
$GLOBALS['abilities'] = array( 'a' => new FixtureAbility( 'new' ), 'c' => new FixtureAbility( 'added' ) );
$second = request( array( 'cursor' => $first['next_cursor'] ) );
check( 'b' === $second['items'][0]['ability_name'] && $second['snapshot'] === $first['snapshot'], 'Pagination mixed revisions' );
$delta = request( array( 'known_snapshot' => $first['snapshot'] ) );
check( $delta['delta'] && array( 'b' ) === $delta['removed'] && 2 === count( $delta['items'] ), 'Delta incorrect' );
check( is_wp_error( request( array( 'cursor' => $first['next_cursor'] . 'x' ) ) ), 'Tampered cursor accepted' );
$GLOBALS['user'] = 2; check( is_wp_error( request( $ref + array( 'transport_action' => 'schema' ) ) ), 'Cross-user cache leak' );
$GLOBALS['user'] = 1; $GLOBALS['binding'] = 2; check( is_wp_error( request( array( 'cursor' => $first['next_cursor'] ) ) ), 'Cross-binding cursor accepted' );
$GLOBALS['allowed'] = false; check( is_wp_error( request( array() ) ), 'Read permission bypass' );
echo "PASS catalog transport: large schema, chunks, frozen pages, delta, tampering, user and site isolation\n";
