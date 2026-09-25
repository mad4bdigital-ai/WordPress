<?php

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10 ) {}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-intent-registry.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL intent-registry-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$base = static function ( $id, $intent, $content, $role, $confidence = 0.8, $signals = array() ) {
	return array(
		'relation_id' => $id,
		'intent_id' => $intent,
		'content_id' => $content,
		'site' => 'https://example.test',
		'locale' => 'en',
		'market' => 'eg',
		'role' => $role,
		'confidence' => $confidence,
		'evidence_refs' => array( 'artifact:evidence-1' ),
		'valid_from' => '2026-09-25T00:00:00Z',
		'valid_to' => '',
		'source' => 'operator',
		'analysis_signals' => $signals,
	);
};

$healthy = MAD4B_SCP_Intent_Registry::normalize_relations(
	array(
		$base( 'rel-primary', 'intent:visa', 'post:10', 'PRIMARY_OWNER', 0.95 ),
		$base( 'rel-support', 'intent:visa', 'post:11', 'SUPPORTING', 0.82 ),
	),
	array()
);
$check( is_array( $healthy ) && 2 === count( $healthy ), 'healthy many-to-many relations did not normalize' );
$analysis = MAD4B_SCP_Intent_Registry::analyze_relations( $healthy );
$check( 1 === count( $analysis ), 'healthy relation group count mismatch' );
$check( 'HEALTHY_SUPPORT' === $analysis[0]['outcome'], 'primary+supporting relation was not healthy support' );

$overlap = MAD4B_SCP_Intent_Registry::normalize_relations(
	array(
		$base( 'rel-owner-a', 'intent:hotel', 'post:20', 'PRIMARY_OWNER', 0.91 ),
		$base( 'rel-owner-b', 'intent:hotel', 'post:21', 'INFORMATIONAL_OWNER', 0.88 ),
	),
	array()
);
$analysis = MAD4B_SCP_Intent_Registry::analyze_relations( $overlap );
$check( 'POSSIBLE_OVERLAP' === $analysis[0]['outcome'], 'multiple owners without evidence became cannibalization' );

$signals = array(
	'serp_overlap' => true,
	'same_page_purpose' => true,
	'indexable' => true,
	'canonical_competes' => false,
	'performance_overlap' => false,
);
$risk = MAD4B_SCP_Intent_Registry::normalize_relations(
	array(
		$base( 'rel-risk-a', 'intent:flight', 'post:30', 'PRIMARY_OWNER', 0.91, $signals ),
		$base( 'rel-risk-b', 'intent:flight', 'post:31', 'TRANSACTIONAL_OWNER', 0.89, $signals ),
	),
	array()
);
$analysis = MAD4B_SCP_Intent_Registry::analyze_relations( $risk );
$check( 'CANNIBALIZATION_RISK' === $analysis[0]['outcome'], 'explicit high-confidence competition signals did not produce risk' );
$check( false !== strpos( $analysis[0]['reason_codes'][0], 'explicit_competition_signals' ), 'risk omitted evidence-based reason' );

$historical = $base( 'rel-old', 'intent:flight', 'post:32', 'HISTORICAL_RETIRED', 0.99, $signals );
$with_history = MAD4B_SCP_Intent_Registry::normalize_relations(
	array_merge( $risk, array( $historical ) ),
	array()
);
$analysis = MAD4B_SCP_Intent_Registry::analyze_relations( $with_history );
$check( 2 === $analysis[0]['relation_count'], 'historical relation affected current conflict analysis' );

$duplicate = MAD4B_SCP_Intent_Registry::normalize_relations(
	array(
		$base( 'rel-dup', 'intent:x', 'post:1', 'PRIMARY_OWNER' ),
		$base( 'rel-dup', 'intent:x', 'post:2', 'SUPPORTING' ),
	),
	array()
);
$check( is_wp_error( $duplicate ) && 'mad4b_intent_relation_duplicate' === $duplicate->get_error_code(), 'duplicate relation id did not fail closed' );

$previous = $healthy;
$previous[0]['revision'] = 7;
$previous[1]['revision'] = 3;
$same = MAD4B_SCP_Intent_Registry::normalize_relations(
	array(
		$base( 'rel-primary', 'intent:visa', 'post:10', 'PRIMARY_OWNER', 0.95 ),
		$base( 'rel-support', 'intent:visa', 'post:11', 'SUPPORTING', 0.82 ),
	),
	$previous
);
$by_id = array();
foreach ( $same as $row ) $by_id[ $row['relation_id'] ] = $row;
$check( 7 === $by_id['rel-primary']['revision'], 'unchanged relation revision advanced' );
$check( 3 === $by_id['rel-support']['revision'], 'unchanged support relation revision advanced' );

$changed_relations = array(
	$base( 'rel-primary', 'intent:visa', 'post:10', 'PRIMARY_OWNER', 0.96 ),
	$base( 'rel-support', 'intent:visa', 'post:11', 'SUPPORTING', 0.82 ),
);
$changed = MAD4B_SCP_Intent_Registry::normalize_relations( $changed_relations, $previous );
$by_id = array();
foreach ( $changed as $row ) $by_id[ $row['relation_id'] ] = $row;
$check( 8 === $by_id['rel-primary']['revision'], 'changed relation revision did not increment' );
$check( 3 === $by_id['rel-support']['revision'], 'unrelated relation revision drifted' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-intent-registry.php' );
$artifacts = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-artifacts.php' );
$servers = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );

foreach ( array(
	"'mad4b/intent-registry-current'",
	"'mad4b/intent-conflicts-analyze'",
	"'mad4b/intent-registry-reconcile'",
	"'many_to_many' => true",
	"'cannibalization_is_derived' => true",
	"'overlap_alone_is_conflict' => false",
	"'artifact_type' => 'intent_registry'",
	"'expected_registry_artifact_id'",
	"'expected_registry_sha256'",
) as $marker ) {
	$check( false !== strpos( $source, $marker ), 'Intent Registry marker missing: ' . $marker );
}
$check( false !== strpos( $artifacts, "'intent_registry'" ), 'Intent Registry artifact type is not registered' );
$check( false !== strpos( $main, 'class-mad4b-scp-intent-registry.php' ), 'Intent Registry runtime is not loaded' );
$check( false !== strpos( $servers, "'mad4b/intent-registry-current'" ), 'Intent Registry current read is not mounted' );
$check( false !== strpos( $servers, "'mad4b/intent-conflicts-analyze'" ), 'Intent conflict analysis read is not mounted' );
$check( false !== strpos( $servers, "'mad4b/intent-registry-reconcile'" ), 'Intent Registry reconcile is not governed write candidate' );

foreach ( array(
	'wp_insert_post(',
	'wp_update_post(',
	'wp_remote_get(',
	'wp_remote_post(',
	'shell_exec(',
	'proc_open(',
	'database-raw-query',
) as $forbidden ) {
	$check( false === strpos( $source, $forbidden ), 'Intent Registry contains forbidden side effect primitive: ' . $forbidden );
}

echo "mad4b.intent-ownership.v1: PASS\n";
