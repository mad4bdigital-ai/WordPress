<?php
/**
 * Deprecated compatibility wrapper for zero-touch functional-gap evidence.
 *
 * The target operating model is the read-only MCP ability:
 *   mad4b/functional-gap-runtime-evidence
 *
 * This wrapper deliberately owns no discovery, hashing, routing, option or
 * evaluation logic. It delegates to the same canonical runtime used in
 * production so fallback diagnostics cannot drift from the zero-touch path.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress runtime required.\n" ); exit( 2 ); }

if ( ! class_exists( 'MAD4B_SCP_Functional_Gap_Evidence' ) ) {
	fwrite( STDERR, "MAD4B zero-touch functional-gap runtime is unavailable.\n" );
	exit( 3 );
}

$snapshot = MAD4B_SCP_Functional_Gap_Evidence::snapshot();
if ( ! is_array( $snapshot ) || 'mad4b.functional-gap-zero-touch.v1' !== ( isset( $snapshot['contract'] ) ? (string) $snapshot['contract'] : '' ) ) {
	fwrite( STDERR, "Invalid zero-touch functional-gap snapshot.\n" );
	exit( 4 );
}

$runtime = isset( $snapshot['runtime'] ) && is_array( $snapshot['runtime'] ) ? $snapshot['runtime'] : array();
if ( 'mad4b.runtime-functional-gap-evidence.v2' !== ( isset( $runtime['contract'] ) ? (string) $runtime['contract'] : '' ) ) {
	fwrite( STDERR, "Invalid zero-touch runtime evidence contract.\n" );
	exit( 5 );
}

$runtime['compatibility_wrapper'] = 'runtime-functional-gap-diagnostic.php';
$runtime['zero_touch_snapshot_identity_sha256'] = isset( $snapshot['snapshot_identity_sha256'] ) ? (string) $snapshot['snapshot_identity_sha256'] : '';
$runtime['repository_evidence_valid'] = ! empty( $snapshot['repository_evidence']['valid'] );
$runtime['promotion_authorized'] = false;

echo wp_json_encode( $runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
