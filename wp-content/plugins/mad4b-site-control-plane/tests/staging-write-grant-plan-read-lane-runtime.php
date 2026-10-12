<?php
/* Original-authority-lane fixture. Never creates or approves grants. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['captured_abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['captured_abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) {
    $GLOBALS['captured_abilities'][ $name ] = $args;
    return true;
}
function check_lane( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-grant-reconciliation-plan.php';
$c = 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan';
$c::register_ability();
$c::register_ability();
check_lane( count( $GLOBALS['captured_abilities'] ) === 1,
    'Exact grant plan registration is idempotent.' );
$plan = $GLOBALS['captured_abilities'][ $c::ABILITY ];
$meta = $plan['meta'] ?? array();
$mcp = $meta['mcp'] ?? array();
$annotations = $meta['annotations'] ?? array();
check_lane( ( $mcp['surface'] ?? '' ) === 'read'
    && ( $mcp['readonly_authority_plan'] ?? false ) === true
    && ( $mcp['authorizes_mutation'] ?? true ) === false,
    'Read-only grant plan must have a read original lane and cannot authorize mutation.' );
check_lane( ( $annotations['readonly'] ?? false ) === true
    && ( $annotations['destructive'] ?? true ) === false
    && ( $annotations['idempotent'] ?? false ) === true,
    'Strict read annotations match the original read lane.' );
check_lane( $plan['execute_callback'] === array( $c, 'plan' )
    && $plan['permission_callback'] === array( $c, 'can_plan' ),
    'Original governed plan permission and handler remain unchanged.' );
echo "PASS: grant reconciliation planning stays on original read lane; no grant mutation\n";
