<?php
/* Partial-runtime G7 release guard: missing optional dependency must not fatal. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( $code, $message = '', $data = array() ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class MAD4B_SCP_Adaptive_Operations_Context {
    public static function sha( $v ) { return is_string( $v ) &&
        1 === preg_match( '/^[a-f0-9]{64}$/D', $v ); }
}
class MAD4B_SCP_G7_Update_Acceptance {
    public static $state = 'APPROVAL_REQUIRED';
    public static function compare( array $before, array $after ) {
        return array(
            'contract' => 'mad4b.feature007-g7-update-comparison.v1',
            'comparison_sha256' => str_repeat( 'a', 64 ),
            'state' => self::$state,
            'graph_changed' => false
        );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-release-acceptance-audit.php';
if ( class_exists( 'MAD4B_SCP_Post_Update_Continuation', false ) ) {
    fwrite( STDERR, "FAIL: optional native continuation accidentally present\n" );
    exit( 1 );
}
$r = MAD4B_SCP_G7_Release_Acceptance_Audit::assess( array(), array() );
if ( ! is_array( $r ) || 'RECONCILIATION_REQUIRED' !== ( $r['state'] ?? '' ) ||
    true !== in_array( 'native_continuation_status_unavailable', $r['blocking_evidence'] ?? array(), true ) ||
    false !== ( $r['native_continuation_status_observed'] ?? true ) ||
    false !== ( $r['release_acceptance_receipt_issued'] ?? true ) ) {
    fwrite( STDERR, "FAIL: absent native continuation must fail closed\n" ); exit( 1 );
}
MAD4B_SCP_G7_Update_Acceptance::$state = 'NO_UPDATE_OBSERVED';
$r = MAD4B_SCP_G7_Release_Acceptance_Audit::assess( array(), array() );
if ( ! is_array( $r ) || 'RECONCILIATION_REQUIRED' !== ( $r['state'] ?? '' ) ||
    'NO_UPDATE_OBSERVED' !== ( $r['comparison_state'] ?? '' ) ||
    false !== ( $r['native_continuation_status_observed'] ?? true ) ||
    false !== ( $r['release_acceptance_receipt_issued'] ?? true ) ) {
    fwrite( STDERR, "FAIL: no-update observation cannot mask absent native continuation provider\n" );
    exit( 1 );
}
echo "mad4b.feature007-g7-missing-release-dependency.v1: PASS\n";
