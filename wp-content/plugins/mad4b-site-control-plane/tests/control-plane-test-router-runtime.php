<?php
/**
 * Hermetic, non-authorizing regression for the certified Control Plane test
 * router. No WordPress mutation, provider invocation, installer or approval.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['mad4b_router_admin'] = false;
$GLOBALS['mad4b_router_policy'] = false;
function current_user_can( $cap ) {
    return 'manage_options' === $cap && ! empty( $GLOBALS['mad4b_router_admin'] );
}
final class MAD4B_SCP_Policy {
    public static function can_read() { return ! empty( $GLOBALS['mad4b_router_policy'] ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-control-plane-test-router.php';
function router_assert( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL control-plane-test-router: ' . $message . PHP_EOL ); exit( 1 ); }
}
$r = 'MAD4B_SCP_Control_Plane_Test_Router';
router_assert( ! $r::can_read(), 'anonymous reader received admin access' );
$GLOBALS['mad4b_router_admin'] = true;
router_assert( ! $r::can_read(), 'admin without governed read policy admitted' );
$GLOBALS['mad4b_router_policy'] = true;
router_assert( $r::can_read(), 'authenticated governed admin cannot inspect' );
$sha = str_repeat( 'a', 40 );
$native = array(
    'eligible' => true, 'blockers' => array(),
    'target' => array(
        'published_from_master' => true,
        'release_root_trust_verified' => true,
        'release_verdict_success' => true,
        'source_commit_sha' => $sha,
        'archive_sha256' => str_repeat( 'b', 64 ),
    ),
);
$selected = array(
    'eligible' => true, 'blockers' => array(),
    'source' => array( 'resolved_sha' => $sha ),
    'package_identity' => array( 'source_commit_sha' => $sha ),
);
$check = static function ( $out, $case ) {
    router_assert( is_array( $out ) && ! empty( $out['read_only'] )
        && empty( $out['automatic_mutation_allowed'] )
        && empty( $out['production_allowed'] )
        && empty( $out['mutation_performed'] )
        && empty( $out['permission_reuse_allowed'] )
        && ! empty( $out['independent_owner_approval_required'] )
        && ! empty( $out['separate_exact_plan_required'] ),
        $case . ': a plan became authorizing or installable' );
};
$eligible = $r::reduce( $native, $selected, 'staging' );
$check( $eligible, 'certified_staging' );
router_assert( 'eligible_for_separate_governed_review' === $eligible['state']
    && 'certified_selected_head' === $eligible['selected_route_id']
    && $sha === $eligible['certified_source_sha'], 'certified Selected HEAD not preferred' );
$production = $r::reduce( $native, $selected, 'production' );
$check( $production, 'production' );
router_assert( 'environment_blocked' === $production['state']
    && null === $production['selected_route_id'], 'Production selected an install route' );
$invalid = $native;
$invalid['target']['archive_sha256'] = '';
$unverified = $r::reduce( $invalid, $selected, 'staging' );
$check( $unverified, 'unverified_archive' );
router_assert( 'certified_source_unavailable' === $unverified['state']
    && null === $unverified['selected_route_id'],
    'missing signed artifact identity was accepted' );
$mismatch = $selected;
$mismatch['source']['resolved_sha'] = str_repeat( 'c', 40 );
$fallback = $r::reduce( $native, $mismatch, 'staging' );
$check( $fallback, 'mismatched_selected_head' );
router_assert( 'governed_native_release' === $fallback['selected_route_id'],
    'mismatched Selected HEAD displaced exact signed native route' );
$blocked_native = $native;
$blocked_native['blockers'] = array( 'owner_review_required' );
$blocked_selected = $selected;
$blocked_selected['blockers'] = array( 'selected_head_opt_in_disabled' );
$blocked = $r::reduce( $blocked_native, $blocked_selected, 'staging' );
$check( $blocked, 'blocked_preflight' );
router_assert( 'preflight_blocked' === $blocked['state']
    && null === $blocked['selected_route_id']
    && 'mad4b/wordpress-native-candidate-opt-in-plan' === $blocked['next_read_ability'],
    'original blockers were bypassed or auto-applied' );
router_assert( ! empty( $blocked['original_blockers_preserved'] )
    && ! empty( $blocked['routes'][0]['requires_exact_owner_approval'] ),
    'route metadata omitted original blockers or owner review' );
echo "CONTROL_PLANE_TEST_ROUTER: PASS (isolated reducer, no live certification)\n";
