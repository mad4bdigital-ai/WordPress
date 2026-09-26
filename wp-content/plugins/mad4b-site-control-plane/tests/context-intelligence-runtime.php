<?php

define( 'ABSPATH', '/tmp/' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

class MAD4B_SCP_Context_Authority {
	public static $assets = array();
	public static function assets() { return self::$assets; }
	public static function asset( $id ) { return isset( self::$assets[ $id ] ) ? self::$assets[ $id ] : array(); }
}
class MAD4B_SCP_Google_Drive_Context {
	public static $content = array();
	public static function read_context_asset( $asset_id ) {
		if ( ! isset( self::$content[ $asset_id ] ) ) return new WP_Error( 'fixture_missing', 'Fixture content missing.' );
		$content = self::$content[ $asset_id ];
		return array( 'content' => $content, 'content_sha256' => hash( 'sha256', $content ) );
	}
}
class MAD4B_SCP_Context_Preflight {
	public static function validate_receipt_binding( $receipt ) {
		if ( empty( $receipt['ready'] ) || empty( $receipt['receipt_sha256'] ) ) return new WP_Error( 'fixture_receipt_invalid', 'Fixture receipt invalid.' );
		return array( 'ready' => true, 'receipt_sha256' => $receipt['receipt_sha256'] );
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-intelligence.php';

function ci_fail( $message, $data = null ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $data ) fwrite( STDERR, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}
function ci_assert( $condition, $message, $data = null ) { if ( ! $condition ) ci_fail( $message, $data ); }

$a = str_repeat( 'a', 64 );
$b = str_repeat( 'b', 64 );
$c = str_repeat( 'c', 64 );
$d = str_repeat( 'd', 64 );
$f = str_repeat( 'f', 64 );
$g = str_repeat( '7', 64 );

$base_quality = array( 'dimensions' => array( 'freshness' => 90 ) );
MAD4B_SCP_Context_Authority::$assets = array(
	$a => array(
		'asset_id' => $a, 'title' => 'Brand Strategy', 'path' => 'Brand/Strategy', 'source_mode' => 'governed',
		'category' => 'brand_strategy', 'authority_class' => 'brand_authority', 'required' => true, 'priority' => 100,
		'status' => 'ready', 'content_complete' => true, 'review_status' => 'approved', 'quality_score' => 92,
		'quality' => $base_quality, 'classification_confidence' => 1.0, 'content_excerpt' => 'Premium cultural journeys Egypt Tour Gates',
	),
	$b => array(
		'asset_id' => $b, 'title' => 'Positioning Notes', 'path' => 'Brand/Positioning', 'source_mode' => 'governed',
		'category' => 'brand_strategy', 'authority_class' => 'brand_authority', 'required' => false, 'priority' => 90,
		'status' => 'ready', 'content_complete' => true, 'review_status' => 'approved', 'quality_score' => 82,
		'quality' => $base_quality, 'classification_confidence' => 0.95, 'content_excerpt' => 'Budget mass market positioning',
	),
	$c => array(
		'asset_id' => $c, 'title' => 'Writer Reference', 'path' => 'References/Writer', 'source_mode' => 'governed',
		'category' => 'writer_reference', 'authority_class' => 'reference', 'required' => false, 'priority' => 40,
		'status' => 'ready', 'content_complete' => true, 'review_status' => 'approved', 'quality_score' => 94,
		'quality' => $base_quality, 'classification_confidence' => 1.0, 'content_excerpt' => 'Egypt culture travel narrative questions',
	),
	$d => array(
		'asset_id' => $d, 'title' => 'Campaign Brief', 'path' => 'Tasks/Campaign', 'source_mode' => 'task_attachment',
		'task_scope' => 'campaign-2026', 'category' => 'campaign_strategy', 'authority_class' => 'task_knowledge',
		'required' => false, 'priority' => 70, 'status' => 'ready', 'content_complete' => true, 'review_status' => 'unreviewed',
		'quality_score' => 88, 'quality' => $base_quality, 'classification_confidence' => 0.9, 'content_excerpt' => 'premium cultural egypt campaign',
	),
);

MAD4B_SCP_Google_Drive_Context::$content = array(
	$a => "Positioning: Premium cultural journeys\nRequired term: Egypt Tour Gates\nForbidden claim: guaranteed cheapest price",
	$b => "Positioning: Budget mass-market tours\nPreferred term: accessible travel",
	$c => "Why do some journeys stay with us? They begin with a detail, then expand into a scene. Short sentences can create pace.\n\nLonger paragraphs slow the reader down and make room for context, reflection, and a stronger transition. The purpose is structure, not imitation.",
	$d => "Campaign focus: premium cultural Egypt journeys",
);

$conflicts = MAD4B_SCP_Context_Intelligence::conflict_report();
ci_assert( ! is_wp_error( $conflicts ), 'Conflict report returned an error.', $conflicts );
ci_assert( 1 === $conflicts['conflict_count'], 'Expected one explicit positioning conflict.', $conflicts );
ci_assert( 'human_required' === $conflicts['conflicts'][0]['resolution'], 'Conflict must require human resolution.', $conflicts );
ci_assert( false === $conflicts['conflicts'][0]['auto_resolution'], 'Conflict must never auto-resolve.', $conflicts );

MAD4B_SCP_Context_Authority::$assets[ $f ] = array(
	'asset_id' => $f, 'title' => 'Unreadable Brand Strategy', 'path' => 'Brand/Unreadable', 'source_mode' => 'governed',
	'category' => 'brand_strategy', 'authority_class' => 'brand_authority', 'required' => false, 'priority' => 80,
	'status' => 'ready', 'content_complete' => true, 'review_status' => 'approved', 'quality_score' => 90,
	'quality' => $base_quality, 'classification_confidence' => 1.0, 'content_excerpt' => 'unreadable fixture',
);
$incomplete_conflicts = MAD4B_SCP_Context_Intelligence::conflict_report();
ci_assert( ! is_wp_error( $incomplete_conflicts ), 'Incomplete conflict report returned an error.', $incomplete_conflicts );
ci_assert( false === $incomplete_conflicts['ready'], 'Unreadable governed authority asset must fail conflict coverage closed.', $incomplete_conflicts );
ci_assert( false === $incomplete_conflicts['coverage_complete'], 'Unreadable governed authority asset must mark conflict coverage incomplete.', $incomplete_conflicts );
ci_assert( in_array( 'asset_unreadable:' . $f, $incomplete_conflicts['blockers'], true ), 'Unreadable governed authority asset blocker missing.', $incomplete_conflicts );
unset( MAD4B_SCP_Context_Authority::$assets[ $f ] );

$profile = MAD4B_SCP_Context_Intelligence::reference_profile( array( 'asset_id' => $c ) );
ci_assert( ! is_wp_error( $profile ), 'Reference profile returned an error.', $profile );
ci_assert( 'structural_reference_only' === $profile['usage'], 'Reference profile must be structural only.', $profile );
ci_assert( false === $profile['imitation_instruction_allowed'], 'Reference profile must deny direct imitation instruction.', $profile );
ci_assert( $profile['metrics']['word_count'] > 20, 'Reference profile did not analyze text.', $profile );
ci_assert( false === strpos( json_encode( $profile ), 'Why do some journeys stay with us' ), 'Reference profile leaked raw source content.', $profile );

MAD4B_SCP_Context_Authority::$assets[ $g ] = MAD4B_SCP_Context_Authority::$assets[ $c ];
MAD4B_SCP_Context_Authority::$assets[ $g ]['asset_id'] = $g;
MAD4B_SCP_Context_Authority::$assets[ $g ]['review_status'] = 'unreviewed';
MAD4B_SCP_Google_Drive_Context::$content[ $g ] = MAD4B_SCP_Google_Drive_Context::$content[ $c ];
$unreviewed_reference = MAD4B_SCP_Context_Intelligence::reference_profile( array( 'asset_id' => $g ) );
ci_assert( is_wp_error( $unreviewed_reference ) && 'mad4b_reference_asset_review_required' === $unreviewed_reference->get_error_code(), 'Unreviewed governed reference must be denied.', $unreviewed_reference );

MAD4B_SCP_Context_Authority::$assets[ $g ]['source_mode'] = 'task_attachment';
MAD4B_SCP_Context_Authority::$assets[ $g ]['task_scope'] = 'task-private';
$unscoped_reference = MAD4B_SCP_Context_Intelligence::reference_profile( array( 'asset_id' => $g ) );
ci_assert( is_wp_error( $unscoped_reference ) && 'mad4b_reference_task_scope_mismatch' === $unscoped_reference->get_error_code(), 'Task reference without exact scope must be denied.', $unscoped_reference );
$wrong_scope_reference = MAD4B_SCP_Context_Intelligence::reference_profile( array( 'asset_id' => $g, 'task_scope' => 'task-other' ) );
ci_assert( is_wp_error( $wrong_scope_reference ) && 'mad4b_reference_task_scope_mismatch' === $wrong_scope_reference->get_error_code(), 'Task reference with wrong scope must be denied.', $wrong_scope_reference );
$scoped_reference = MAD4B_SCP_Context_Intelligence::reference_profile( array( 'asset_id' => $g, 'task_scope' => 'task-private' ) );
ci_assert( ! is_wp_error( $scoped_reference ) && 'structural_reference_only' === $scoped_reference['usage'], 'Exact task scope must permit structural reference profiling.', $scoped_reference );
unset( MAD4B_SCP_Context_Authority::$assets[ $g ], MAD4B_SCP_Google_Drive_Context::$content[ $g ] );

$retrieval = MAD4B_SCP_Context_Intelligence::retrieve( array(
	'query' => 'premium cultural Egypt journeys',
	'task_scope' => 'campaign-2026',
	'limit' => 10,
) );
ci_assert( ! is_wp_error( $retrieval ), 'Retrieval returned an error.', $retrieval );
ci_assert( true === $retrieval['ready'], 'Retrieval should be ready.', $retrieval );
ci_assert( 1 === count( $retrieval['mandatory_assets'] ), 'Required governed asset must bypass ranking.', $retrieval );
ci_assert( $a === $retrieval['mandatory_assets'][0]['asset_id'], 'Wrong mandatory asset selected.', $retrieval );
$ranked_ids = array_map( static function ( $row ) { return $row['asset_id']; }, $retrieval['ranked_optional_assets'] );
ci_assert( in_array( $d, $ranked_ids, true ), 'Matching task-scoped source was not included.', $retrieval );

$filtered_retrieval = MAD4B_SCP_Context_Intelligence::retrieve( array(
	'query' => 'writer narrative',
	'task_scope' => 'campaign-2026',
	'category' => 'writer_reference',
	'limit' => 10,
) );
ci_assert( ! is_wp_error( $filtered_retrieval ), 'Category-filtered retrieval returned an error.', $filtered_retrieval );
ci_assert( 1 === count( $filtered_retrieval['mandatory_assets'] ), 'Category filter must never hide required governed Context.', $filtered_retrieval );
ci_assert( $a === $filtered_retrieval['mandatory_assets'][0]['asset_id'], 'Required Brand Context identity changed under category filter.', $filtered_retrieval );

MAD4B_SCP_Context_Authority::$assets[ $a ]['content_complete'] = false;
$incomplete_required = MAD4B_SCP_Context_Intelligence::retrieve( array( 'query' => 'premium cultural Egypt journeys' ) );
ci_assert( ! is_wp_error( $incomplete_required ), 'Incomplete mandatory retrieval returned an error.', $incomplete_required );
ci_assert( false === $incomplete_required['ready'], 'Incomplete required governed content must block retrieval readiness.', $incomplete_required );
ci_assert( in_array( 'mandatory_asset_not_ready:' . $a, $incomplete_required['blockers'], true ), 'Incomplete required content blocker missing.', $incomplete_required );
MAD4B_SCP_Context_Authority::$assets[ $a ]['content_complete'] = true;

$receipt = array(
	'ready' => true,
	'receipt_sha256' => str_repeat( 'e', 64 ),
	'assets_loaded' => array( array( 'asset_id' => $a ) ),
);
$bad = MAD4B_SCP_Context_Intelligence::compliance_check( array(
	'text' => 'Egypt Tour Gates offers the guaranteed cheapest price for every journey.',
	'receipt' => $receipt,
) );
ci_assert( ! is_wp_error( $bad ), 'Compliance check returned an error.', $bad );
ci_assert( 'REVISION_REQUIRED' === $bad['verdict'], 'Forbidden explicit claim must require revision.', $bad );
ci_assert( 1 === count( $bad['violations'] ), 'Expected one deterministic compliance violation.', $bad );

$good = MAD4B_SCP_Context_Intelligence::compliance_check( array(
	'text' => 'Egypt Tour Gates creates curated cultural journeys across Egypt.',
	'receipt' => $receipt,
) );
ci_assert( ! is_wp_error( $good ), 'Clean compliance check returned an error.', $good );
ci_assert( 'PASS' === $good['verdict'], 'Compliant draft should pass explicit-rule checks.', $good );
ci_assert( true === $good['coverage_limited'], 'Compliance must disclose deterministic coverage limits.', $good );
ci_assert( false === $good['semantic_model_used'], 'Compliance foundation must not claim opaque model semantics.', $good );

MAD4B_SCP_Context_Authority::$assets[ $f ] = array(
	'asset_id' => $f, 'title' => 'Large Claim Policy', 'path' => 'Brand/Claim Policy', 'source_mode' => 'governed',
	'category' => 'claim_policy', 'authority_class' => 'policy_authority', 'required' => true, 'priority' => 100,
	'status' => 'ready', 'content_complete' => true, 'review_status' => 'approved', 'quality_score' => 100,
	'quality' => $base_quality, 'classification_confidence' => 1.0, 'content_excerpt' => 'bounded policy fixture',
);
$rule_lines = array();
for ( $i = 0; $i <= MAD4B_SCP_Context_Intelligence::MAX_RULES; ++$i ) $rule_lines[] = 'Forbidden claim: forbidden-rule-' . $i;
MAD4B_SCP_Google_Drive_Context::$content[ $f ] = implode( "\n", $rule_lines );
$limited_receipt = array(
	'ready' => true,
	'receipt_sha256' => str_repeat( '9', 64 ),
	'assets_loaded' => array( array( 'asset_id' => $f ) ),
);
$limited = MAD4B_SCP_Context_Intelligence::compliance_check( array(
	'text' => 'A clean draft that does not contain the generated forbidden phrases.',
	'receipt' => $limited_receipt,
) );
ci_assert( ! is_wp_error( $limited ), 'Rule-limit compliance check returned an error.', $limited );
ci_assert( false === $limited['ready'], 'Rule-limit compliance scan must fail closed.', $limited );
ci_assert( 'BLOCKED' === $limited['verdict'], 'Rule-limit compliance scan must not return PASS or revision-only status.', $limited );
ci_assert( in_array( 'compliance_rule_limit_reached', $limited['blockers'], true ), 'Rule-limit blocker missing.', $limited );
unset( MAD4B_SCP_Context_Authority::$assets[ $f ], MAD4B_SCP_Google_Drive_Context::$content[ $f ] );

echo "mad4b.context-intelligence.runtime.v3: PASS\n";
