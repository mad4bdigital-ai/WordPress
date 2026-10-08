<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( $code, $message = '', $data = array() ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class MAD4B_SCP_Adaptive_Operations_Context {
    public static function sha( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $v ); }
}
class MAD4B_SCP_G7_Update_Acceptance {
    public static $response;
    public static function compare( array $before, array $after ) { return self::$response; }
}
class MAD4B_SCP_Post_Update_Continuation {
    const CONTRACT = 'mad4b.post-update-continuation.v1';
    public static function status() { return array( 'contract' => self::CONTRACT, 'state' => 'absent' ); }
}
class MAD4B_SCP_Runtime_Metrics {
    const CONTRACT = 'mad4b.dynamic-runtime-metrics.v1';
    public static $metrics = array();
    public static function summary( array $names, $hours ) {
        return array( 'contract' => self::CONTRACT, 'hours' => $hours, 'metrics' => self::$metrics );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-release-acceptance-audit.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-workload-measurement.php';
function g7m( $yes, $why ) { if ( ! $yes ) { fwrite( STDERR, "FAIL: $why\n" ); exit( 1 ); } }
MAD4B_SCP_G7_Update_Acceptance::$response = array(
    'contract' => 'mad4b.feature007-g7-update-comparison.v1',
    'comparison_sha256' => str_repeat( 'a', 64 ), 'state' => 'APPROVAL_REQUIRED',
    'graph_changed' => false
);
$r = MAD4B_SCP_G7_Release_Acceptance_Audit::assess( array(), array() );
g7m( $r['state'] === 'RECONCILIATION_REQUIRED' && ! $r['release_acceptance_receipt_issued'] &&
    ! $r['automatic_rollback_allowed'] && in_array( 'current_catalog_and_skills_recertification_required', $r['blocking_evidence'], true ),
    'release report preserves mandatory cert gates' );
MAD4B_SCP_G7_Update_Acceptance::$response['graph_changed'] = true;
$r = MAD4B_SCP_G7_Release_Acceptance_Audit::assess( array(), array() );
g7m( in_array( 'capability_graph_drift_requires_impact_review', $r['blocking_evidence'], true ), 'drift blocks acceptance' );
MAD4B_SCP_G7_Update_Acceptance::$response['state'] = 'NO_UPDATE_OBSERVED';
MAD4B_SCP_G7_Update_Acceptance::$response['graph_changed'] = false;
$r = MAD4B_SCP_G7_Release_Acceptance_Audit::assess( array(), array() );
g7m( 'NO_UPDATE_OBSERVED' === $r['state'] && ! $r['release_acceptance_receipt_issued'], 'no update does not certify release' );
MAD4B_SCP_Runtime_Metrics::$metrics = array(
    array( 'name' => 'g7.eligible_workload', 'count' => 3, 'sum' => 10 ),
    array( 'name' => 'g7.verified_automatic_repair', 'count' => 2, 'sum' => 9 )
);
$metric = MAD4B_SCP_G7_Workload_Measurement::status( 24 );
g7m( $metric['state'] === 'INCOMPLETE_EVIDENCE' &&
    $metric['bucket_counts_unverified']['eligible_workload'] === 10 &&
    $metric['automatic_repair_rate_percent'] === null &&
    $metric['mttr_seconds'] === null && ! $metric['target_90_95_percent_achieved'],
    '9/10 unverified counter values are not a certified 90 percent automation rate' );
MAD4B_SCP_Runtime_Metrics::$metrics[] = array( 'name' => 'g7.eligible_workload', 'count' => 1, 'sum' => 2 );
g7m( is_wp_error( MAD4B_SCP_G7_Workload_Measurement::status( 24 ) ), 'duplicate counters rejected' );
g7m( is_wp_error( MAD4B_SCP_G7_Workload_Measurement::status( 1000 ) ), 'unbounded window denied' );
echo "mad4b.feature007-g7-release-metrics.v1: PASS\n";
