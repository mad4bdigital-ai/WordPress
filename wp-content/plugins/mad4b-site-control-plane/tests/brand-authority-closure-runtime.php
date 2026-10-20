<?php
/* Isolated, zero-I/O Brand operational closure reducer regression. */
define( 'ABSPATH', __DIR__ );
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-authority-closure.php';
function assert_closure( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'BRAND_CLOSURE: ' . $message . "\n" ); exit( 1 ); }
}
$good = array(
    'exact_staging' => true,
    'google' => array( 'connected' => true, 'read_available' => true, 'refresh_failed' => false, 'reconnect_required' => false ),
    'write' => array( 'current_ready' => true, 'effective_ready' => true, 'write_tool_count' => 101,
        'exact_grants_existing' => 101, 'stale_allow_grants_count' => 0, 'exact_grants_missing_count' => 0 ),
    'context' => array( 'ready' => true ),
    'brand' => array( 'ready' => true, 'coverage' => array(
        'brand_strategy' => array( 'ready' => true ), 'tone_of_voice' => array( 'ready' => true ),
        'editorial_guidelines' => array( 'ready' => true ) ) ),
);
$r = MAD4B_SCP_Brand_Authority_Closure::summarize( $good );
assert_closure( empty( $r['blockers'] ), 'fully healthy fixture emitted blockers' );
assert_closure( $r['google']['state'] === 'ready' && $r['write']['current_ready'] === true, 'healthy classification lost' );
assert_closure( $r['ready_for_publication'] === false && $r['source_scan_readback_certified'] === false
    && $r['skills_build_checkpoint_certified'] === false && $r['production_authorized'] === false
    && $r['approval_issued'] === false && $r['mutation_performed'] === false, 'read-only preview minted acceptance' );
$etg = $good;
$etg['brand']['ready'] = false;
$etg['brand']['coverage']['tone_of_voice']['ready'] = false;
$etg['brand']['coverage']['editorial_guidelines']['ready'] = false;
$e = MAD4B_SCP_Brand_Authority_Closure::summarize( $etg );
assert_closure( $e['brand']['missing_categories'] === array( 'tone_of_voice', 'editorial_guidelines' ), 'ETG category gap lost' );
assert_closure( in_array( 'scan_and_review_exact_brand_assets', $e['next_actions'], true ), 'ETG review handoff missing' );
$are = $good;
$are['google'] = array( 'connected' => true, 'read_available' => false, 'refresh_failed' => true,
    'refresh_failure_provider_code' => 'invalid_grant', 'reconnect_required' => true );
$are['write']['current_ready'] = false;
$are['write']['stale_allow_grants_count'] = 5;
$are['write']['exact_grants_existing'] = 96;
$are['context']['ready'] = false;
$are['brand']['ready'] = false;
foreach ( array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' ) as $cat )
    $are['brand']['coverage'][ $cat ]['ready'] = false;
$a = MAD4B_SCP_Brand_Authority_Closure::summarize( $are );
assert_closure( $a['google']['state'] === 'owner_reconsent_required' && $a['google']['provider_code'] === 'invalid_grant'
    && $a['google']['network_refresh_retry_allowed'] === false, 'ARE invalid_grant would be blindly retried' );
assert_closure( $a['write']['stale_grants'] === 5 && $a['write']['reconcile_without_new_exact_approval'] === false
    && in_array( 'review_fresh_exact_write_convergence_plan', $a['next_actions'], true ), 'ARE stale grant policy broadened' );
assert_closure( count( $a['brand']['missing_categories'] ) === 3 && ! $a['brand']['owner_approval_inferred'],
    'ARE operational workbooks silently approved' );
$off = $good;
$off['exact_staging'] = false;
$o = MAD4B_SCP_Brand_Authority_Closure::summarize( $off );
assert_closure( in_array( 'exact_staging_required', $o['blockers'], true )
    && $o['production_authorized'] === false, 'Production or foreign scope widened' );
echo "BRAND_AUTHORITY_CLOSURE: PASS\n";
