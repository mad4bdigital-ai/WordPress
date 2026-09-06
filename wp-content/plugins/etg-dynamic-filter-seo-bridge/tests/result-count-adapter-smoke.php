<?php
declare( strict_types=1 );
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
class ETG_Test_JSF_Query {
    public $filtered = array( 'tax_query' => array( 'location_jet' => 'cairo' ) );
    public function get_query_from_request() { return $this->filtered; }
}
$GLOBALS['etg_jsf'] = (object) array( 'query' => new ETG_Test_JSF_Query() );
function jet_smart_filters() { return $GLOBALS['etg_jsf']; }
class ETG_Test_Query {
    public $id;
    public $query_id;
    public $props = array();
    public $setup = false;
    public function __construct( $id = 77, $queryId = 'tours_query_archive' ) { $this->id = $id; $this->query_id = $queryId; }
    public function setup_query() { $this->setup = true; }
    public function set_filtered_prop( $prop, $value ) { $this->props[ $prop ] = $value; }
    public function get_items_total_count() { return $this->setup && isset( $this->props['tax_query'] ) ? 4 : 99; }
}
class ETG_Test_Query_Manager {
    public static $instance;
    public $queries = array();
    public static function instance() { return self::$instance; }
    public function get_queries() { return $this->queries; }
}
class_alias( 'ETG_Test_Query_Manager', 'Jet_Engine\\Query_Builder\\Manager' );
$manager = new ETG_Test_Query_Manager();
$manager->queries = array( new ETG_Test_Query( 77, 'tours_query_archive' ) );
ETG_Test_Query_Manager::$instance = $manager;
function expect_same( $expected, $actual, string $message ): void { if ( $expected !== $actual ) { fwrite( STDERR, "FAILED: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/../includes/JetEngine/QueryIdentityResolver.php';
require_once __DIR__ . '/../includes/SEO/JetEngineResultCountAdapter.php';
$adapter = new ETG\DynamicFilterSEOBridge\SEO\JetEngineResultCountAdapter();
$context = array( 'provider'=>'jet-engine','query_id'=>'tours_query_archive','provider_observation_matches_url'=>true );
$result = $adapter->resolve( $context );
expect_same( 4, $result['count'], 'adapter returns filtered Query Builder total' );
expect_same( true, $result['authoritative'], 'built-in adapter is authoritative only after filtered count' );
expect_same( 'jet_engine_query_builder', $result['source'], 'adapter source contract' );
expect_same( 'tours_query_archive', $result['custom_query_id'], 'custom Query ID remains route authority' );
expect_same( '77', $result['internal_query_id'], 'internal Query Builder ID is evidence only' );
expect_same( 'jet_engine_query_builder_manager_get_queries', $result['query_identity_source'], 'adapter resolves through exact Query Builder inventory' );
$GLOBALS['etg_jsf']->query->filtered = array();
$result = $adapter->resolve( $context );
expect_same( null, $result['count'], 'empty filtered request never falls back to unfiltered count' );
expect_same( false, $result['authoritative'], 'empty filtered request is unavailable' );
$context['provider_observation_matches_url'] = false;
$result = $adapter->resolve( $context );
expect_same( null, $result['count'], 'provider/query mismatch cannot produce authority' );
fwrite( STDOUT, "JetEngine result-count adapter smoke tests passed.\n" );
