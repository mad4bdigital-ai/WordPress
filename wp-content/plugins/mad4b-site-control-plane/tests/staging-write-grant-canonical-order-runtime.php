<?php
/**
 * Pure, hermetic grant-evidence order parity. Does not mount grants or write.
 * PHP 7.4+; no live OAuth, WordPress host, database or network required.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'JSON_UNESCAPED_SLASHES' ) ) die( 'JSON support required' );
function wp_json_encode( $value, $options = 0 ) {
    return json_encode( $value, $options );
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-authority.php';

function parity_assert( $pass, $label ) {
    if ( ! $pass ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
function rows_sha( $rows ) {
    return hash( 'sha256', wp_json_encode(
        MAD4B_SCP_Staging_Write_Authority::canonical_plan_rows( $rows ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) );
}
$rows = array(
    array( 'ability' => 'elementor/update-widget-settings', 'provider' => 'elementor',
        'mounted' => true, 'exact_grant_present' => true, 'grant_state' => 'exact_current_environment' ),
    array( 'ability' => 'jetsmartfilters/update-filter-meta', 'provider' => 'jetsmartfilters',
        'mounted' => true, 'exact_grant_present' => false, 'grant_state' => 'missing_exact_grant' ),
    array( 'ability' => 'mad4b/sample', 'provider' => 'core',
        'mounted' => true, 'exact_grant_present' => false, 'grant_state' => 'explicit_deny' ),
);
$reversed = array_reverse( $rows );
parity_assert( rows_sha( $rows ) === rows_sha( $reversed ),
    'Admin vs MCP read-order permutation cannot change grant fingerprint' );
$canonical = MAD4B_SCP_Staging_Write_Authority::canonical_plan_rows( $rows );
parity_assert( 3 === count( $canonical ), 'No grant, deny, or missing row discarded' );
$duplicate = $rows; $duplicate[] = $rows[0];
parity_assert( 4 === count( MAD4B_SCP_Staging_Write_Authority::canonical_plan_rows( $duplicate ) ),
    'Duplicate grants retained for fail-closed detection' );
parity_assert( rows_sha( $duplicate ) !== rows_sha( $rows ), 'Added duplicate changes fingerprint' );
$changed = $rows; $changed[0]['provider'] = 'unreviewed';
parity_assert( rows_sha( $changed ) !== rows_sha( $rows ), 'Provider drift changes fingerprint' );
$changed = $rows; $changed[1]['exact_grant_present'] = true;
$changed[1]['grant_state'] = 'exact_current_environment';
parity_assert( rows_sha( $changed ) !== rows_sha( $rows ), 'Grant state changes fingerprint' );
$changed = $rows; $changed[2]['grant_state'] = 'missing_exact_grant';
parity_assert( rows_sha( $changed ) !== rows_sha( $rows ), 'Deny removal changes fingerprint' );
echo "STAGING_OWNER_GRANT_ORDER_PARITY: PASS (non-authorizing)\n";
