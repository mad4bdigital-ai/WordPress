<?php

define( 'ABSPATH', '/tmp/mad4b-context-quality/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_quality_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

function mad4b_quality_metadata( $category, $days_old = 30 ) {
	return array(
		'category' => $category,
		'classification_confidence' => 0.92,
		'file_id' => 'file-' . $category,
		'mimeType' => 'text/plain',
		'modifiedTime' => gmdate( 'c', time() - ( $days_old * DAY_IN_SECONDS ) ),
		'webViewLink' => 'https://drive.google.test/file/' . $category,
	);
}

$brand_text = str_repeat(
	"Brand promise: trusted Egypt travel expertise. Use precise terminology and approved claims.\n\n" .
	"Voice principles:\n- Clear and useful\n- Specific and evidence-aware\n- Consistent across channels\n\n",
	8
);
$brand = MAD4B_SCP_Context_Authority::score_asset( mad4b_quality_metadata( 'tone_of_voice' ), $brand_text );
mad4b_quality_assert( 'mad4b.context-quality-score.v2' === $brand['contract'], 'Quality scoring contract must be v2.', $brand );
mad4b_quality_assert( 'brand_policy' === $brand['profile'], 'Tone of Voice must use brand_policy profile.', $brand );
mad4b_quality_assert( 'content_heuristic_v2' === $brand['mode'] && empty( $brand['provisional'] ), 'Normalized text must use content heuristic v2.', $brand );
mad4b_quality_assert( $brand['overall_score'] >= 0 && $brand['overall_score'] <= 100, 'Overall score must be bounded 0-100.', $brand );
mad4b_quality_assert( $brand['confidence'] >= 0.6 && $brand['confidence'] <= 1.0, 'Content score confidence must be bounded and meaningful.', $brand );
foreach ( array( 'freshness', 'completeness', 'structure', 'specificity', 'source_quality', 'language_quality', 'retrieval_quality', 'extractability' ) as $dimension ) {
	mad4b_quality_assert( array_key_exists( $dimension, $brand['dimensions'] ), 'Missing dimension: ' . $dimension, $brand );
}
mad4b_quality_assert( abs( array_sum( $brand['weights'] ) - 1.0 ) < 0.0001, 'Brand quality weights must sum to 1.', $brand['weights'] );

$research = MAD4B_SCP_Context_Authority::score_asset(
	mad4b_quality_metadata( 'market_research', 20 ),
	str_repeat( "2026 market sample: conversion 12.4%, bookings 1,240, source [1].\n\n", 40 )
);
mad4b_quality_assert( 'market_research' === $research['profile'], 'Market research must use market_research profile.', $research );
mad4b_quality_assert( $research['weights']['freshness'] > $brand['weights']['freshness'], 'Market research must weight freshness above brand policy.', array( $research['weights'], $brand['weights'] ) );
mad4b_quality_assert( $research['weights']['specificity'] > $brand['weights']['specificity'], 'Market research must emphasize specificity.', $research['weights'] );

$writer = MAD4B_SCP_Context_Authority::score_asset(
	mad4b_quality_metadata( 'writer_reference', 500 ),
	str_repeat( "A measured opening sentence. A second sentence develops the argument with deliberate rhythm.\n\n", 30 )
);
mad4b_quality_assert( 'writer_reference' === $writer['profile'], 'Writer reference must use writer_reference profile.', $writer );
mad4b_quality_assert( $writer['weights']['language_quality'] >= 0.25, 'Writer reference must emphasize language quality.', $writer['weights'] );

$knowledge = MAD4B_SCP_Context_Authority::score_asset(
	mad4b_quality_metadata( 'product_knowledge', 60 ),
	str_repeat( "Service detail: transfer window 24 hours. Includes support, exclusions, and booking conditions.\n\n", 24 )
);
mad4b_quality_assert( 'knowledge' === $knowledge['profile'], 'Product knowledge must use knowledge profile.', $knowledge );

$metadata_only = MAD4B_SCP_Context_Authority::score_asset( mad4b_quality_metadata( 'brand_strategy', 100 ), '' );
mad4b_quality_assert( 'metadata_provisional' === $metadata_only['mode'] && ! empty( $metadata_only['provisional'] ), 'Missing normalized text must remain explicitly provisional.', $metadata_only );
mad4b_quality_assert( 0.45 === $metadata_only['confidence'], 'Metadata-only confidence must remain low and explicit.', $metadata_only );
mad4b_quality_assert( null === $metadata_only['dimensions']['completeness'], 'Metadata-only score must not fabricate completeness.', $metadata_only );

echo "mad4b.site-control-plane.context-quality-scoring.runtime.v1: PASS\n";
