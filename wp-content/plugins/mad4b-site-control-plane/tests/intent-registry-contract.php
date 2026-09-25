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

$base = static function ( $intent, $content, $role, $confidence = 0.8, $signals = array() ) {
	return array(
		'intent_id' => $intent,
		'content_id' => $content,
		'locale' => 'en',
		'market' => 'eg',
		'role' => $role,
		'confidence' => $confidence,
		'evidence_refs' => array( 'artifact:evidence-1' ),
		'valid_from' => '',
		'valid_to' => '',
		'source' => 'operator',
		'analysis_signals' => $signals,
	);
};

$healthy = MAD4B_SCP_Intent_Registry::normalize_analysis_relations(
	array(
		$base( 'intent:visa', 'post:10', 'PRIMARY_OWNER', 0.95 ),
		$base( 'intent:visa', 'post:11', 'SUPPORTING', 0.82 ),
	)
);
$check( is_array( $healthy ) && 2 === count( $healthy ), 'healthy many-to-many relations did not normalize' );
$analysis = MAD4B_SCP_Intent_Registry::analyze_relations( $healthy );
$check( 1 === count( $analysis ), 'healthy relation group count mismatch' );
$check( 'HEALTHY_SUPPORT' === $analysis[0]['outcome'], 'primary+supporting relation was not healthy support' );

$overlap = MAD4B_SCP_Intent_Registry::normalize_analysis_relations(
	array(
		$base( 'intent:hotel', 'post:20', 'PRIMARY_OWNER', 0.91 ),
		$base( 'intent:hotel', 'post:21', 'INFORMATIONAL_OWNER', 0.88 ),
	)
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
$risk = MAD4B_SCP_Intent_Registry::normalize_analysis_relations(
	array(
		$base( 'intent:flight', 'post:30', 'PRIMARY_OWNER', 0.91, $signals ),
		$base( 'intent:flight', 'post:31', 'TRANSACTIONAL_OWNER', 0.89, $signals ),
	)
);
$analysis = MAD4B_SCP_Intent_Registry::analyze_relations( $risk );
$check( 'CANNIBALIZATION_RISK' === $analysis[0]['outcome'], 'explicit high-confidence competition signals did not produce risk' );
$check( false !== strpos( $analysis[0]['reason_codes'][0], 'explicit_competition_signals' ), 'risk omitted evidence-based reason' );

$historical = $base( 'intent:flight', 'post:32', 'HISTORICAL_RETIRED', 0.99, $signals );
$with_history = MAD4B_SCP_Intent_Registry::normalize_analysis_relations(
	array_merge( $risk, array( $historical ) )
);
$analysis = MAD4B_SCP_Intent_Registry::analyze_relations( $with_history );
$check( 2 === $analysis[0]['relation_count'], 'historical relation affected current conflict analysis' );

$duplicate = MAD4B_SCP_Intent_Registry::normalize_analysis_relations(
	array(
		$base( 'intent:x', 'post:1', 'PRIMARY_OWNER' ),
		$base( 'intent:x', 'post:1', 'SUPPORTING' ),
	)
);
$check( is_wp_error( $duplicate ) && 'mad4b_intent_relation_duplicate' === $duplicate->get_error_code(), 'duplicate intent/content relation did not fail closed' );

$invalid_confidence = MAD4B_SCP_Intent_Registry::normalize_analysis_relations(
	array( $base( 'intent:x', 'post:9', 'PRIMARY_OWNER', 1.1 ) )
);
$check( is_wp_error( $invalid_confidence ) && 'mad4b_intent_confidence_invalid' === $invalid_confidence->get_error_code(), 'out-of-range confidence did not fail closed' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-intent-registry.php' );
$schema = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-schema.php' );
$servers = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );

foreach ( array(
	"'mad4b/intent-registry-current'",
	"'mad4b/intent-conflicts-analyze'",
	"'mad4b/intent-registry-reconcile'",
	"'many_to_many' => true",
	"'cannibalization_is_derived' => true",
	"'overlap_alone_is_conflict' => false",
	"'expected_scope_sha256'",
	'intent_scope_stale',
	'current_relation_key',
	'SELECT MAX(revision)',
	'analysis_signals_json',
) as $marker ) {
	$check( false !== strpos( $source, $marker ), 'Intent Registry marker missing: ' . $marker );
}
foreach ( array(
	"const VERSION = 11;",
	"'intent_relations' =>",
	"UNIQUE KEY relation_revision (relation_id,revision)",
	"UNIQUE KEY current_relation_key (current_relation_key)",
	"KEY owner_scope_key (owner_scope_key)",
) as $marker ) {
	$check( false !== strpos( $schema, $marker ), 'Intent Registry schema marker missing: ' . $marker );
}
$check( false === strpos( $schema, 'UNIQUE KEY current_owner_scope' ), 'Intent schema reintroduced false single-owner exclusivity' );
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
