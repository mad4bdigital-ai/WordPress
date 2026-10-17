<?php
/* Exact G7 partial delivery evidence: no task DONE or live acceptance assertions. */
$root = dirname( __DIR__, 4 );
$base = $root . '/specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/';
$json = $base . 'g7-delivery.json';
$markdown = $base . 'g7-delivery.md';
if ( ! is_file( $json ) || ! is_file( $markdown ) ) { fwrite( STDERR, "FAIL: G7 delivery missing\n" ); exit( 1 ); }
$raw = file_get_contents( $json );
$delivery = is_string( $raw ) ? json_decode( $raw, true ) : null;
function g7_spec_assert( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $label . PHP_EOL ); exit( 1 ); }
}
g7_spec_assert( is_array( $delivery ) && 'mad4b.competitive-g7-delivery.v1' === ( $delivery['contract'] ?? null ), 'delivery contract' );
g7_spec_assert( 'REPOSITORY_G7_SAFETY_FOUNDATION_IMPLEMENTED_EXTERNAL_ACCEPTANCE_PENDING' === $delivery['status'], 'partial G7 status' );
g7_spec_assert( 286 === $delivery['implementation_pr'] && 258 === $delivery['integration_pr'], 'PR routing' );
g7_spec_assert( 'supplied_by_ci_not_embedded_in_commit' === $delivery['exact_head_binding'], 'no self-attested head' );
$tasks = array_merge( range( 4036, 4055 ), range( 4061, 4065 ) );
$expected = array_map( static function( $num ) { return 'T' . $num; }, $tasks );
g7_spec_assert( $delivery['task_scope'] === $expected, 'all twenty-five G7 task ids' );
$seen = array();
foreach ( $delivery['group_progress'] as $row ) {
    g7_spec_assert( $row['status'] === 'PARTIAL' && ! empty( $row['evidence_paths'] ) &&
        ! empty( $row['remaining_acceptance'] ), 'partial group and remaining acceptance' );
    foreach ( $row['evidence_paths'] as $path ) {
        g7_spec_assert( strpos( $path, 'wp-content/plugins/mad4b-site-control-plane/' ) === 0 &&
            strpos( $path, '..' ) === false && is_file( $root . '/' . $path ), 'repository evidence exists: ' . $path );
    }
    foreach ( $row['task_ids'] as $id ) $seen[] = $id;
}
g7_spec_assert( $seen === $expected, 'each task has exactly one group owner' );
foreach ( $delivery['global_evidence'] as $path ) {
    g7_spec_assert( strpos( $path, '..' ) === false && is_file( $root . '/' . $path ), 'global evidence exists: ' . $path );
}
foreach ( $delivery['claim_limits'] as $field => $value ) {
    g7_spec_assert( false === $value, 'no unauthorized claim: ' . $field );
}
g7_spec_assert( ! empty( $delivery['pending_validation'] ) && strlen( file_get_contents( $markdown ) ) > 400, 'human handoff exists' );
echo "mad4b.feature007-g7-spec-evidence.v1: PASS\n";
