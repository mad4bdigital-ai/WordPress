<?php
// Standalone transport tests: large schemas, frozen pagination, deltas, resumability and isolation.
define( 'ABSPATH', __DIR__ );
class WP_Error { public $code; function __construct( $code, $message ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class MAD4B_SCP_Policy { static function can_read() { return $GLOBALS['allowed']; } }
class MAD4B_SCP_ChatGPT_Tool_Projection { static function describe_ability( $name ) { return array( 'lane' => 'read', 'readonly' => true, 'execution_eligible' => true, 'input_schema_sha256' => str_repeat( 'a', 64 ), 'classification_sha256' => str_repeat( 'b', 64 ) ); } static function current_binding() { return array( 'origin' => 'https://ci.test', 'revision' => $GLOBALS['binding'] ); } }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_current_user_id() { return $GLOBALS['user']; }
function wp_get_current_user() { return (object) array( 'allcaps' => array( 'read' => true ) ); }
function wp_salt( $v ) { return 'test-signing-key'; }
function apply_filters( $name, $v ) { return 'mad4b_scp_catalog_storage_capacity_bytes' === $name ? ( $GLOBALS['capacity'] ?? $v ) : $v; }
function set_transient( $key, $v, $ttl ) { $GLOBALS['cache'][$key] = $v; return true; }
function get_transient( $key ) { return $GLOBALS['cache'][$key] ?? false; }
function wp_get_abilities() { return $GLOBALS['abilities']; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][$name] ?? null; }
function rest_url( $path ) { return 'https://ci.test/wp-json/' . $path; }
function get_option( $key, $default = false ) { if ( isset( $GLOBALS['read_hook'] ) ) call_user_func( $GLOBALS['read_hook'], $key ); return $GLOBALS['options'][$key] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { if ( isset( $GLOBALS['options'][$key] ) ) return false; $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function wp_cache_delete( $key, $group ) { return true; }
function maybe_serialize( $v ) { return serialize( $v ); }
class FakeDB {
 public $options = 'options'; public $race = false;
 function esc_like( $value ) { return $value; }
 function get_col( $args ) { $names = array_values( array_filter( array_keys( $GLOBALS['options'] ), static function( $key ) use ( $args ) { return 0 === strpos( $key, 'mad4b_ct2_' ) && $key > $args[1]; } ) ); sort( $names, SORT_STRING ); return array_slice( $names, 0, 500 ); }
 function get_var( $args ) { $bytes = 0; foreach ( $GLOBALS['options'] as $name => $value ) if ( 0 === strpos( $name, 'mad4b_ct2_' ) ) $bytes += strlen( serialize( $value ) ); return $bytes; }
 function prepare( $query, ...$args ) { return $args; }
 function query( $args ) {
  list( $next, $key, $old ) = $args;
  if ( $this->race ) { $this->race = false; $GLOBALS['options'][$key]['concurrent'] = array( 'option' => 'other', 'expires' => time()+3600, 'bytes' => 1 ); return 0; }
  if ( serialize( $GLOBALS['options'][$key] ) !== $old ) return 0;
  $GLOBALS['options'][$key] = unserialize( $next ); return 1;
 }
}
$GLOBALS['wpdb'] = new FakeDB(); $GLOBALS['options'] = array();
class FixtureAbility {
 private $text; function __construct( $text ) { $this->text = $text; }
 function get_input_schema() { return array( 'type' => 'object', 'properties' => array( 'value' => array( 'type' => 'string', 'description' => $this->text ) ) ); }
 function get_output_schema() { return array( 'type' => 'object' ); }
 function get_label() { return 'Fixture'; }
 function get_meta() { return array( 'annotations' => array( 'readonly' => true ) ); }
 function get_category() { return 'read'; }
}
require __DIR__ . '/../includes/class-mad4b-scp-catalog-object-store.php';
require __DIR__ . '/../includes/class-mad4b-scp-ability-catalog-transport.php';
function check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function request( $input ) { return MAD4B_SCP_Ability_Catalog_Transport::handle( $input ); }
$GLOBALS['allowed'] = true; $GLOBALS['user'] = 1; $GLOBALS['binding'] = 1; $GLOBALS['cache'] = array();
$GLOBALS['abilities'] = array( 'a' => new FixtureAbility( str_repeat( 'محتوى', 20000 ) ), 'b' => new FixtureAbility( 'old' ) );
$first = request( array( 'limit' => 1 ) );
check( $first['items'][0]['schema_bytes'] > 65536 && ! empty( $first['next_cursor'] ), 'Large schema rejected or pagination absent' );
$ref = array( 'snapshot' => $first['snapshot'], 'schema_sha256' => $first['items'][0]['schema_sha256'] );
$full = request( $ref + array( 'transport_action' => 'schema' ) );
check( strlen( $full['schema']->inputSchema->properties->value->description ) > 65536, 'Schema truncated' );
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
$GLOBALS['allowed'] = true; $GLOBALS['binding'] = 1;
$caps = request( array( 'transport_action' => 'capabilities' ) ); check( in_array( 'authenticated_rest_binary', $caps['transports'], true ), 'No negotiation' );
$stable = request( array() ); $same = request( array() ); check( $same['storage_metrics']['writes'] === 0, 'Unchanged catalog rewritten' );
$GLOBALS['abilities'] = array(); $delta = request( array( 'known_snapshot' => $stable['snapshot'], 'limit' => 1 ) );
check( count( $delta['removed'] ) === 1 && ! empty( $delta['next_cursor'] ), 'Removals not paginated' );
$tail = request( array( 'cursor' => $delta['next_cursor'] ) ); check( count( $tail['removed'] ) === 1, 'Missing removal page' );
$store = new MAD4B_SCP_Catalog_Object_Store(); $store->put( 'race', 'safe', 3600 ); $GLOBALS['wpdb']->race = true; $store->flush();
check( $store->get( 'race' ) === 'safe' && isset( $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['concurrent'] ), 'CAS overwrote concurrent publication' );

$lease_store = new MAD4B_SCP_Catalog_Object_Store();
$lease_store->put( 'dependency-lease', 'shared', 3600 );
$lease_store->flush();
$GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['dependency-lease']['expires'] = time() + 2000;
$minimum_dependency_expiry = time() + 5000;
$lease_store = new MAD4B_SCP_Catalog_Object_Store();
$lease_store->put( 'dependency-lease', 'shared', 3600, $minimum_dependency_expiry );
$lease_store->flush();
check(
	$GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['dependency-lease']['expires'] >= $minimum_dependency_expiry,
	'Shared dependency lifetime was not extended to the referencing object lifetime'
);

class ObjectAbility extends FixtureAbility {
 function get_input_schema() { return (object) array( 'type' => 'object', 'properties' => new stdClass() ); }
 function get_output_schema() { return new stdClass(); }
}
class LabelAbility extends FixtureAbility {
 private $label; function __construct( $label ) { parent::__construct( 'same' ); $this->label = $label; }
 function get_label() { return $this->label; }
}
$GLOBALS['abilities'] = array( 'object' => new ObjectAbility( '' ), 'label' => new LabelAbility( 'booking' ) );
$manifest = request( array() ); $object = array_values( array_filter( $manifest['items'], static fn($v) => $v['ability_name'] === 'object' ) )[0];
$value = request( array( 'transport_action' => 'schema', 'snapshot' => $manifest['snapshot'], 'schema_sha256' => $object['schema_sha256'] ) );
check( is_object( $value['schema']->inputSchema->properties ) && is_object( $value['schema']->outputSchema ), 'Empty object became array' );
$query = request( array( 'query' => 'booking' ) ); $GLOBALS['abilities']['label'] = new LabelAbility( 'other' );
$query_delta = request( array( 'query' => 'booking', 'known_snapshot' => $query['snapshot'] ) ); check( $query_delta['removed'] === array( 'label' ), 'Query membership removal lost' );
class CycleAbility extends FixtureAbility { function get_input_schema() { $v = new stdClass(); $v->self = $v; return $v; } }
$GLOBALS['abilities']['cycle'] = new CycleAbility( '' ); $cycle = request( array() );
check( ! is_wp_error( $cycle ) && ! empty( $cycle['items'][0]['unavailable'] ), 'Invalid schema destroyed catalog' );
$directory = $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY];
$expired = reset( $directory ); $key = array_key_first( $directory ); $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY][$key]['expires'] = time()-1;
MAD4B_SCP_Catalog_Object_Store::collect_expired();
check( isset( $GLOBALS['options'][$expired['option']] ), 'Expired payload was reclaimed before reader grace' );
$GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['retired:' . $expired['option']]['expires'] = time() - 1;
MAD4B_SCP_Catalog_Object_Store::collect_expired();
check( ! isset( $GLOBALS['options'][$expired['option']] ), 'Retired payload not collected after grace' );

// A newly published schema may share older blocks. Its advertised retention must cover every block.
foreach ( $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY] as $key => $entry ) {
 if ( is_string( $GLOBALS['options'][$entry['option']] ?? null ) ) $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY][$key]['expires'] = time() + 302400 + 600;
}
$GLOBALS['abilities']['shared'] = new FixtureAbility( str_repeat( 'محتوى', 20000 ) . 'new-tail' );
$shared_manifest = request( array() ); check( ! is_wp_error( $shared_manifest ), 'Shared-block publication failed' );
$shared_item = array_values( array_filter( $shared_manifest['items'], static fn($v) => $v['ability_name'] === 'shared' ) )[0];
$descriptor_key = hash( 'sha256', ':schema:' . $shared_item['schema_sha256'] );
$directory = $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]; $shared_descriptor = $GLOBALS['options'][$directory[$descriptor_key]['option']];
foreach ( $shared_descriptor['blocks'] as $block ) check( $directory[hash( 'sha256', ':block:' . $block )]['expires'] >= $shared_descriptor['retain_until'], 'Shared block expires before advertised schema retention' );
class WireFixtureDTO { function toArray() { return array( 'name' => 'object-tool', 'inputSchema' => (object) array( 'type' => 'object', 'properties' => new stdClass() ), 'outputSchema' => (object) array( 'type' => 'object' ) ); } }
class WireFixtureBuilder { static function build( $ability ) { return array( 'tool' => new WireFixtureDTO() ); } }
class_alias( 'WireFixtureBuilder', 'WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool' );
$lazy = MAD4B_SCP_Ability_Catalog_Transport::prepare_ability( 'object' );
check( ! is_wp_error( $lazy ) && $lazy['item']['source']['sha256'] !== $lazy['item']['wire']['sha256'], 'Source and wire identities conflated' );
$wire = request( array( 'transport_action' => 'schema', 'schema_format' => 'wire', 'snapshot' => $lazy['snapshot'], 'schema_sha256' => $lazy['item']['wire']['sha256'] ) );
check( ! is_wp_error( $wire ) && is_object( $wire['schema']->inputSchema->properties ), 'Lazy official wire schema unavailable' );
$bad_format = request( array( 'transport_action' => 'schema', 'snapshot' => $lazy['snapshot'], 'schema_sha256' => $lazy['item']['wire']['sha256'] ) );
check( is_wp_error( $bad_format ), 'Wire digest admitted as source' );
echo "PASS catalog transport: large schema, chunks, frozen pages, delta, tampering, user and site isolation\n";

// The reader holds the old directory while another publisher replaces its value.
$writer = new MAD4B_SCP_Catalog_Object_Store(); $writer->put( 'reader-race', 'old', 3600 ); $writer->flush();
$old_option = $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['reader-race']['option'];
$GLOBALS['read_hook'] = static function( $name ) use ( $old_option ) {
 if ( $name !== $old_option ) return;
 unset( $GLOBALS['read_hook'] );
 $writer = new MAD4B_SCP_Catalog_Object_Store(); $writer->put( 'reader-race', 'new', 3600 ); $writer->flush();
};
$reader = new MAD4B_SCP_Catalog_Object_Store();
check( 'old' === $reader->get( 'reader-race' ), 'Concurrent publication broke the existing reader' );
check( 'new' === $reader->get( 'reader-race' ), 'Reader did not see the next published revision' );
check( isset( $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['retired:' . $old_option] ), 'Reader lease was not accounted in storage capacity' );
// Same payload lease renewal must reuse its option, even when expiry advances.
$lease = $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['reader-race'];
$writer = new MAD4B_SCP_Catalog_Object_Store(); $writer->put( 'reader-race', 'new', 3600, time() + 7200 ); $writer->flush();
check( $writer->metrics()['writes'] === 0 && $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['reader-race']['option'] === $lease['option'], 'Lease renewal copied unchanged bytes' );
// More than one complete live page must not starve an orphan on a later page.
$stamp = time() - 7200;
for ( $i = 0; $i < 600; ++$i ) {
 $name = 'mad4b_ct2_' . $stamp . '_gc' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT );
 $GLOBALS['options'][$name] = 'live';
 $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY]['gc-live-' . $i] = array( 'option' => $name, 'expires' => time() + 3600, 'bytes' => 4 );
}
$orphan = 'mad4b_ct2_' . $stamp . '_zz-orphan'; $GLOBALS['options'][$orphan] = 'orphan';
MAD4B_SCP_Catalog_Object_Store::collect_expired();
check( ! isset( $GLOBALS['options'][$orphan] ), 'GC starved an orphan after 600 live objects' );
check( isset( $GLOBALS['options']['mad4b_ct2_' . $stamp . '_gc0599'] ), 'GC removed an active payload' );
echo "PASS catalog storage: overlapping reader/writer, lease reuse, deferred GC and 600-live-page progress\n";

// Capacity must include crash drafts absent from the published directory, and
// failed publication must neither leak new options nor replace existing state.
$before_directory = $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY];
$crash = 'mad4b_ct2_' . ( time() - 7200 ) . '_zz-crash-capacity';
$GLOBALS['options'][$crash] = str_repeat( 'x', 1048576 ); $GLOBALS['capacity'] = 1048576;
$before_names = array_keys( $GLOBALS['options'] );
$writer = new MAD4B_SCP_Catalog_Object_Store(); $writer->put( 'must-not-publish', 'new', 3600 );
try { $writer->flush(); throw new RuntimeException( 'Capacity overflow was accepted' ); }
catch ( RuntimeException $error ) { check( 'catalog_storage_capacity_exhausted' === $error->getMessage(), 'Unexpected capacity failure' ); }
check( $before_names === array_keys( $GLOBALS['options'] ), 'Failed capacity publication leaked drafts' );
check( $before_directory === $GLOBALS['options'][MAD4B_SCP_Catalog_Object_Store::DIRECTORY], 'Capacity failure published partial state' );
MAD4B_SCP_Catalog_Object_Store::collect_expired();
check( ! isset( $GLOBALS['options'][$crash] ), 'Overflow prevented GC from recovering crash drafts' );
unset( $GLOBALS['capacity'] );
echo "PASS physical capacity: crash drafts counted, failed publication atomic and GC recovery allowed\n";
