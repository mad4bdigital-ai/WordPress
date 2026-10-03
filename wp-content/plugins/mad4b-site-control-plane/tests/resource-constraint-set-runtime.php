<?php
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
class WP_Error { private $code,$data; function __construct($code,$message='',$data=array()){$this->code=$code;$this->data=$data;} function get_error_code(){return $this->code;} function get_error_data(){return $this->data;} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-resource-constraint-set.php';

$fail = static function( $message, $value = null ) { fwrite( STDERR, 'FAIL resource-constraint-set: ' . $message . ( null === $value ? '' : ' ' . json_encode( $value ) ) . PHP_EOL ); exit(1); };
$check = static function( $condition, $message, $value = null ) use ( $fail ) { if ( ! $condition ) $fail( $message, $value ); };

$input = array(
	'post_id' => 42,
	'post_type' => 'page',
	'taxonomy' => 'category',
	'term_ids' => array( 7, 9 ),
	'provider_object_id' => 'provider-object-123',
	'filesystem_zone' => 'managed_artifacts',
	'path' => 'reports/2026/result.json',
	'database_table' => 'wp_mad4b_jobs',
	'database_columns' => array( 'status', 'revision' ),
	'post_title' => 'Bounded update',
);
$a = MAD4B_SCP_Resource_Constraint_Set::compile( 'mad4b/content-update-post', 'core', $input );
$b = MAD4B_SCP_Resource_Constraint_Set::compile( 'mad4b/content-update-post', 'core', array_reverse( $input, true ) );
$check( is_array( $a ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $a['resource_set_sha256'] ), 'compiled resource digest invalid', $a );
$check( is_array( $b ) && hash_equals( $a['resource_set_sha256'], $b['resource_set_sha256'] ), 'resource digest depends on object key order' );
$check( false === $a['authorizing'] && false === $a['user_declared_limits_authorizing'], 'resource set became an authority grant' );
$check( array(42) === $a['resources']['post_ids'], 'post id resource missing', $a['resources'] );
$check( array('category') === $a['resources']['taxonomies'], 'taxonomy resource missing', $a['resources'] );
$check( 'managed_artifacts' === $a['resources']['filesystem'][0]['zone'], 'filesystem zone missing', $a['resources'] );
$check( 'wp_mad4b_jobs' === $a['resources']['database'][0]['table'], 'database table missing', $a['resources'] );

$widen = $input; $widen['post_id'] = 43;
$drift = MAD4B_SCP_Resource_Constraint_Set::assert_same( $a, 'mad4b/content-update-post', 'core', $widen );
$check( is_wp_error( $drift ) && 'mad4b_resource_set_drift' === $drift->get_error_code(), 'post resource widening was not rejected', $drift );

$provider_swap = $input; $provider_swap['provider_object_id'] = 'provider-object-999';
$drift = MAD4B_SCP_Resource_Constraint_Set::assert_same( $a, 'mad4b/content-update-post', 'core', $provider_swap );
$check( is_wp_error( $drift ) && 'mad4b_resource_set_drift' === $drift->get_error_code(), 'provider object substitution was not rejected', $drift );

$wildcard = $input; $wildcard['path'] = 'reports/*/result.json';
$bad = MAD4B_SCP_Resource_Constraint_Set::compile( 'mad4b/content-update-post', 'core', $wildcard );
$check( is_wp_error( $bad ) && 'mad4b_resource_path_invalid' === $bad->get_error_code(), 'filesystem wildcard expansion was admitted', $bad );

$alias = $input; $alias['path'] = 'reports/../private/result.json';
$bad = MAD4B_SCP_Resource_Constraint_Set::compile( 'mad4b/content-update-post', 'core', $alias );
$check( is_wp_error( $bad ) && 'mad4b_resource_path_alias_denied' === $bad->get_error_code(), 'filesystem path aliasing was admitted', $bad );

$dbwild = $input; $dbwild['database_table'] = 'wp_*';
$bad = MAD4B_SCP_Resource_Constraint_Set::compile( 'mad4b/content-update-post', 'core', $dbwild );
$check( is_wp_error( $bad ) && 'mad4b_resource_database_identifier_invalid' === $bad->get_error_code(), 'database wildcard expansion was admitted', $bad );

$evidence = MAD4B_SCP_Resource_Constraint_Set::preparation_evidence( 'mad4b/content-update-post', 'core', $input );
$check( is_array( $evidence ) && hash_equals( $a['resource_set_sha256'], $evidence['resource_set_sha256'] ) && false === $evidence['authorizing'], 'resource preparation evidence is not bound/non-authorizing', $evidence );

echo "mad4b.resource-constraint-set.runtime.v1: PASS\n";
