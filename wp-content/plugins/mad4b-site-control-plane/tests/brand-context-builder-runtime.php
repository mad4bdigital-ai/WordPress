<?php
/**
 * Brand Context Builder semantic runtime regression.
 *
 * Exercises deterministic quality/freshness primitives with synthetic evidence
 * and no provider/network mutation.
 */
$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-brand-context-builder.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL brand-context-builder-runtime: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) {
	if ( ! $condition ) $fail( $message );
};
$invoke = static function ( $name, array $args = array() ) {
	$method = new ReflectionMethod( 'MAD4B_SCP_Brand_Context_Builder', $name );
	$method->setAccessible( true );
	return $method->invokeArgs( null, $args );
};

$good = array();
for ( $i = 0; $i < 16; ++$i ) {
	$lang = 0 === $i % 2 ? 'en' : 'ar';
	$type = 0 === $i % 3 ? 'page' : ( 1 === $i % 3 ? 'product' : 'tours-and-activities' );
	$good[] = array(
		'content_id' => 'post:' . ( $i + 1 ),
		'post_type' => $type,
		'language' => $lang,
		'text' => str_repeat( 'brand expression ', 12 ),
		'seo' => $i < 4 ? array( 'title' => 'SEO' ) : array(),
	);
}
$quality = $invoke( 'evidence_quality', array( $good, array( 'ar', 'en' ), 1 ) );
$check( ! empty( $quality['quality_gate_pass'] ), 'strong multilingual evidence did not pass quality gate' );
$check( 16 === (int) $quality['nonempty_count'], 'nonempty count drifted' );
$check( (int) $quality['primary_expression_count'] >= 8, 'primary expression threshold not met' );
$check( (int) $quality['core_content_sample_count'] >= 6, 'core content threshold not met' );
$check( 4 === (int) $quality['seo_sample_count'], 'SEO sample count drifted' );
$check( empty( $quality['language_coverage']['unavailable'] ), 'configured locale unexpectedly unavailable' );

$bad = array();
for ( $i = 0; $i < 24; ++$i ) {
	$bad[] = array(
		'content_id' => 'bad:' . $i,
		'post_type' => $i < 14 ? 'tour-rates' : 'elementor_library',
		'language' => 'en',
		'text' => $i < 10 ? '' : 'thin',
		'seo' => $i === 23 ? array( 'title' => 'one' ) : array(),
	);
}
$bad_quality = $invoke( 'evidence_quality', array( $bad, array( 'ar', 'en' ), 1 ) );
$check( empty( $bad_quality['quality_gate_pass'] ), 'utility-heavy/empty evidence incorrectly passed' );
$check( in_array( 'empty_sample_ratio_above_maximum', $bad_quality['blockers'], true ), 'empty ratio blocker missing' );
$check( in_array( 'configured_language_coverage_incomplete', $bad_quality['blockers'], true ), 'language coverage blocker missing' );

$authority_a = array(
	array( 'asset_id' => 'strategy', 'category' => 'brand_strategy', 'content_hash' => hash( 'sha256', 'strategy-a' ), 'reviewed_content_hash' => hash( 'sha256', 'strategy-a' ) ),
	array( 'asset_id' => 'voice', 'category' => 'tone_of_voice', 'content_hash' => hash( 'sha256', 'voice-a' ), 'reviewed_content_hash' => hash( 'sha256', 'voice-a' ) ),
);
$authority_b = $authority_a;
$authority_b[1]['content_hash'] = hash( 'sha256', 'voice-b' );
$authority_b[1]['reviewed_content_hash'] = $authority_b[1]['content_hash'];
$live_identity = array( array( 'content_id' => 'post:1', 'post_type' => 'page', 'content_hash' => hash( 'sha256', 'live' ), 'language' => 'en' ) );
$structure = array( 'menus' => array(), 'taxonomies' => array(), 'locale' => 'en_US' );
$rendered = array( 'enabled' => false, 'available' => false, 'semantic_hash' => '' );

$tov_a = $invoke( 'generation_evidence_digest', array( 'tone_of_voice', $authority_a, $live_identity, $structure, $rendered ) );
$tov_b = $invoke( 'generation_evidence_digest', array( 'tone_of_voice', $authority_b, $live_identity, $structure, $rendered ) );
$editorial_a = $invoke( 'generation_evidence_digest', array( 'editorial_guidelines', $authority_a, $live_identity, $structure, $rendered ) );
$editorial_b = $invoke( 'generation_evidence_digest', array( 'editorial_guidelines', $authority_b, $live_identity, $structure, $rendered ) );
$check( hash_equals( $tov_a, $tov_b ), 'Tone of Voice digest self-depends on Tone of Voice authority' );
$check( ! hash_equals( $editorial_a, $editorial_b ), 'Editorial digest did not depend on approved Tone of Voice' );

$tov_sections = $invoke( 'draft_required_sections', array( 'tone_of_voice' ) );
$editorial_sections = $invoke( 'draft_required_sections', array( 'editorial_guidelines' ) );
$check( 10 === count( $tov_sections ), 'Tone of Voice structural template count drifted' );
$check( 13 === count( $editorial_sections ), 'Editorial structural template count drifted' );

echo "mad4b.brand-context-builder.runtime.v1: PASS\n";
