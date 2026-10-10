<?php
/** IMP04 isolated read-only Meta identity reconciliation. */
function get_post_type_object( $name ) {
    return 'tour_rate' === $name ? (object) array( 'name' => $name ) : null;
}
function get_posts( $args ) {
    if ( $args['post_type'] !== 'tour_rate' ||
        $args['meta_key'] !== 'source_external_id' ||
        $args['fields'] !== 'ids' || $args['numberposts'] !== 2 )
        throw new RuntimeException( 'Unscoped or unbounded WordPress identity query' );
    if ( $args['meta_value'] === '1' ) return array( 101 );
    if ( $args['meta_value'] === '2' ) return array( 102, 103 );
    return array();
}
function get_post_meta( $post_id, $key, $single = false ) {
    if ( $post_id !== 101 || $key !== 'single_price' )
        throw new RuntimeException( 'Unexpected unsanctioned field lookup' );
    return '100';
}
require __DIR__ . '/imp01-import-preview-runtime.php';
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-reconciliation.php';
$review = MAD4B_SCP_Activity_Import_Review::review(
    array( 'profile_slug' => 'pricing' ) );
ck( ! is_wp_error( $review ), 'Approved Staging snapshot not available' );
$base = array( 'profile_slug' => 'pricing',
    'snapshot_sha256' => $review['snapshot_sha256'] );
$plan = MAD4B_SCP_Activity_Import_Reconciliation::plan( $base );
ck( ! is_wp_error( $plan ) &&
    $plan['counts']['matched'] === 1 &&
    $plan['counts']['ambiguous'] === 1 &&
    !$plan['ready_for_automatic_import'],
    'Import reconciliation did not catch stable-ID duplicate collision' );
$page = $base;
$page['page_size'] = 1;
$one = MAD4B_SCP_Activity_Import_Reconciliation::plan( $page );
ck( ! is_wp_error( $one ) && $one['next_index'] === 1 &&
    $one['counts']['matched'] === 1, 'First identity page incorrect' );
$page['start_index'] = 1;
$two = MAD4B_SCP_Activity_Import_Reconciliation::plan( $page );
ck( ! is_wp_error( $two ) && $two['next_index'] === null &&
    $two['counts']['ambiguous'] === 1, 'Last duplicate ID page incorrect' );
$GLOBALS['imp04_missing_identity_binding'] = true;
$unbound = MAD4B_SCP_Activity_Import_Reconciliation::plan( $base );
ck( is_wp_error( $unbound ) &&
    $unbound->get_error_code() === 'mad4b_import_identity_registry_not_configured',
    'Engine guessed post ID from source ID without explicit identity Meta key' );
unset( $GLOBALS['imp04_missing_identity_binding'] );
echo "PASS IMP04 stable external identifier, duplicate WordPress post identity collision, pagination and fail-closed unbound identity (isolated PHP only)\n";
