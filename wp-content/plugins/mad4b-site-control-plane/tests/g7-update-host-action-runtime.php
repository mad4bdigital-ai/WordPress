<?php
/* G7 isolated host, update and operator action safety contracts (PHP 7.4/8.3). */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function site_uuid() { return 'site-test-7'; }
    public static function current_environment() { return 'staging'; }
    public static function profile_digest() { return str_repeat( 'a', 64 ); }
    public static function current_origin() { return 'https://example.test'; }
    public static function deployment_binding_proof( $purpose, $digest ) {
        return hash_hmac( 'sha256', $purpose . ':' . $digest, 'g7-isolated-key' );
    }
    public static function verify_deployment_binding_proof( $purpose, $digest, $proof ) {
        return is_string( $proof ) && hash_equals( self::deployment_binding_proof( $purpose, $digest ), $proof );
    }
}
class MAD4B_SCP_Runtime_Generation_Fence {
    public static $sha;
    public static function capture() { return array( 'generation_sha256' => self::$sha ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static $sha;
    public static function build_provenance_status() {
        return array( 'runtime_manifest_match' => true, 'package_manifest_digest' => self::$sha );
    }
}
class MAD4B_SCP_Restore_Epoch {
    public static $epoch = 1;
    public static function status( $a = false, $b = false ) {
        return array( 'ready' => true, 'epoch' => self::$epoch, 'external_record_sha256' => str_repeat( 'e', 64 ) );
    }
}
class MAD4B_SCP_Developer_Host_Capabilities {
    const CONTRACT = 'mad4b.developer-host-capabilities.v1';
    public static $snapshot;
    public static function snapshot() { return self::$snapshot; }
}
class MAD4B_SCP_Runtime_Evidence_Graph {
    const CONTRACT = 'mad4b.runtime-evidence-graph.v2';
    public static $generation;
    public static $complete = true;
    public static $side_effect_observed = false;
    public static function snapshot( $input = array() ) {
        return array(
            'contract' => self::CONTRACT, 'generation_sha256' => self::$generation,
            'complete_for_absence' => self::$complete,
            'edge_status' => array( 'trustworthy_for_impact' => self::$complete ),
            'discovery' => array(
                'callbacks_executed' => false, 'unknown_plugin_code_executed' => false,
                'unknown_endpoints_invoked' => false, 'secret_values_read' => false,
                'writes_performed' => self::$side_effect_observed
            )
        );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-host-readiness.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-update-acceptance.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-action-center.php';

function g7_check( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $label . PHP_EOL ); exit( 1 ); }
}
function g7_denied( $value, $label ) { g7_check( is_wp_error( $value ), $label ); }
MAD4B_SCP_Runtime_Generation_Fence::$sha = str_repeat( 'c', 64 );
MAD4B_SCP_Live_Acceptance_Observer::$sha = str_repeat( 'd', 64 );
MAD4B_SCP_Runtime_Evidence_Graph::$generation = str_repeat( '1', 64 );
MAD4B_SCP_Developer_Host_Capabilities::$snapshot = array(
    'contract' => MAD4B_SCP_Developer_Host_Capabilities::CONTRACT,
    'capability_fingerprint' => str_repeat( 'f', 64 ),
    'resource_limiter_binary_present' => true, 'resource_limit_backend' => 'prlimit',
    'network_sandbox_binary_present' => true, 'network_isolation_backend_certified' => true,
    'network_isolation_backend' => 'bubblewrap', 'proc_open_available' => true,
    'non_root_verified' => true
);
$host = MAD4B_SCP_G7_Host_Readiness::capture();
g7_check( ! is_wp_error( $host ), 'host observed and site sealed' );
$host_status = MAD4B_SCP_G7_Host_Readiness::verify( $host );
g7_check( ! is_wp_error( $host_status ) && true === $host_status['candidate_prerequisites_present'] &&
    false === $host_status['execution_eligible'] && 'EXTERNAL_ACTION_REQUIRED' === $host_status['state'], 'binary presence cannot certify sandbox operational safety' );
MAD4B_SCP_Developer_Host_Capabilities::$snapshot['network_sandbox_binary_present'] = false;
MAD4B_SCP_Developer_Host_Capabilities::$snapshot['capability_fingerprint'] = str_repeat( '0', 64 );
g7_denied( MAD4B_SCP_G7_Host_Readiness::verify( $host ), 'host drift denied' );
$missing = MAD4B_SCP_G7_Host_Readiness::assess_snapshot( MAD4B_SCP_Developer_Host_Capabilities::$snapshot );
g7_check( in_array( 'network_sandbox_required', $missing['blockers'], true ) &&
    false === $missing['execution_eligible'], 'missing network isolation remains external action' );
MAD4B_SCP_Developer_Host_Capabilities::$snapshot['capability_fingerprint'] = str_repeat( 'f', 64 );
MAD4B_SCP_Developer_Host_Capabilities::$snapshot['network_sandbox_binary_present'] = true;
$host_bad_type = $host; $host_bad_type['sealed_observation'] = 'invalid-object';
g7_denied( MAD4B_SCP_G7_Host_Readiness::verify( $host_bad_type ), 'malformed host proof fail closed without TypeError' );
$host_wrong_proof_type = $host; $host_wrong_proof_type['sealed_observation']['proof'] = array( 'not-a-signature' );
g7_denied( MAD4B_SCP_G7_Host_Readiness::verify( $host_wrong_proof_type ), 'array proof rejected before PHP typed verifier' );
$host_tampered = $host; $host_tampered['sealed_observation']['sha256'] = str_repeat( '0', 64 );
g7_denied( MAD4B_SCP_G7_Host_Readiness::verify( $host_tampered ), 'forged host envelope denied' );
$material = $host['sealed_observation']['material'];
$material['observed_at'] = time() - 200; $material['expires_at'] = $material['observed_at'] + 120;
$old_host = array( 'sealed_observation' => MAD4B_SCP_Adaptive_Operations_Context::seal( MAD4B_SCP_G7_Host_Readiness::CONTRACT, $material ) );
g7_denied( MAD4B_SCP_G7_Host_Readiness::verify( $old_host ), 'expired host observation denied' );

$before = MAD4B_SCP_G7_Update_Acceptance::capture();
g7_check( ! is_wp_error( $before ), 'before observation captured' );
$none = MAD4B_SCP_G7_Update_Acceptance::compare( $before, $before );
g7_check( ! is_wp_error( $none ) && 'NO_UPDATE_OBSERVED' === $none['state'] &&
    false === $none['acceptance_receipt_issued'], 'unchanged observations do not claim release acceptance' );
MAD4B_SCP_Runtime_Generation_Fence::$sha = str_repeat( '9', 64 );
MAD4B_SCP_Live_Acceptance_Observer::$sha = str_repeat( '8', 64 );
$after = MAD4B_SCP_G7_Update_Acceptance::capture();
$changed = MAD4B_SCP_G7_Update_Acceptance::compare( $before, $after );
g7_check( ! is_wp_error( $changed ) && 'APPROVAL_REQUIRED' === $changed['state'] &&
    $changed['skills_recertification_required'] && ! $changed['automatic_rollback_allowed'], 'runtime replacement requires recertification and review' );
MAD4B_SCP_Runtime_Evidence_Graph::$complete = false;
g7_denied( MAD4B_SCP_G7_Update_Acceptance::compare( $before, $after ), 'fresh graph completeness downgrade with same hash denied' );
MAD4B_SCP_Runtime_Evidence_Graph::$complete = true;
MAD4B_SCP_Runtime_Evidence_Graph::$side_effect_observed = true;
g7_denied( MAD4B_SCP_G7_Update_Acceptance::compare( $before, $after ), 'fresh side effect with unchanged hash denied' );
MAD4B_SCP_Runtime_Evidence_Graph::$side_effect_observed = false;
MAD4B_SCP_Runtime_Evidence_Graph::$generation = str_repeat( '2', 64 );
$after_graph = MAD4B_SCP_G7_Update_Acceptance::capture();
$graph_diff = MAD4B_SCP_G7_Update_Acceptance::compare( $before, $after_graph );
g7_check( ! is_wp_error( $graph_diff ) && 'RECONCILIATION_REQUIRED' === $graph_diff['state'], 'graph drift cannot silently promote' );
MAD4B_SCP_Runtime_Evidence_Graph::$generation = str_repeat( '3', 64 );
g7_denied( MAD4B_SCP_G7_Update_Acceptance::compare( $before, $after_graph ), 'post-observation graph drift denied' );
MAD4B_SCP_Runtime_Evidence_Graph::$generation = str_repeat( '2', 64 );
MAD4B_SCP_Runtime_Evidence_Graph::$complete = false;
$incomplete = MAD4B_SCP_G7_Update_Acceptance::capture();
$incomplete_result = MAD4B_SCP_G7_Update_Acceptance::compare( $before, $incomplete );
g7_check( ! is_wp_error( $incomplete_result ) && 'RECONCILIATION_REQUIRED' === $incomplete_result['state'], 'truncated graph requires reconciliation' );
MAD4B_SCP_Runtime_Evidence_Graph::$complete = true;
MAD4B_SCP_Restore_Epoch::$epoch = 2;
g7_denied( MAD4B_SCP_G7_Update_Acceptance::compare( $before, $after_graph ), 'restore epoch drift denied' );
MAD4B_SCP_Restore_Epoch::$epoch = 1;
$bad_update_type = $after_graph; $bad_update_type['sealed_observation'] = 'invalid-object';
g7_denied( MAD4B_SCP_G7_Update_Acceptance::compare( $before, $bad_update_type ), 'malformed update proof fail closed without TypeError' );
$fake = $after_graph; $fake['sealed_observation']['sha256'] = str_repeat( '0', 64 );
g7_denied( MAD4B_SCP_G7_Update_Acceptance::compare( $before, $fake ), 'tampered update evidence denied' );
$stale_material = $before['sealed_observation']['material'];
$stale_material['observed_at'] = time() - MAD4B_SCP_G7_Update_Acceptance::MAX_AGE - 1;
$stale = array( 'sealed_observation' => MAD4B_SCP_Adaptive_Operations_Context::seal( MAD4B_SCP_G7_Update_Acceptance::CONTRACT, $stale_material ) );
g7_denied( MAD4B_SCP_G7_Update_Acceptance::compare( $stale, $after_graph ), 'stale pre-update evidence denied' );

$view = MAD4B_SCP_G7_Action_Center::from_operator_snapshot( array(
    'contract' => 'mad4b.operator-control-center.v1', 'state' => 'BLOCKED',
    'authorizing' => false, 'mutation_performed' => false, 'production_authorized' => false,
    'reasons' => array( 'write_authority_not_current' ), 'next_actions' => array( 'reconcile_exact_staging_write_authority' )
) );
g7_check( 'APPROVAL_REQUIRED' === $view['state'] && true === $view['manual_governance_required'] &&
    0 === $view['automatic_repaired_count'] && null === $view['automation_rate_percent'] &&
    false === $view['dispatch_performed'], 'Action Center never disguises manual review as repair' );
$recovery = MAD4B_SCP_G7_Action_Center::from_operator_snapshot( array(
    'contract' => 'mad4b.operator-control-center.v1', 'state' => 'RECOVERY_REQUIRED',
    'authorizing' => false, 'mutation_performed' => false, 'production_authorized' => false,
    'reasons' => array( 'mutation_state_uncertain' ), 'next_actions' => array()
) );
g7_check( 'RECONCILIATION_REQUIRED' === $recovery['state'], 'uncertain effect blocks autonomous retry' );
$bad_operator = MAD4B_SCP_G7_Action_Center::from_operator_snapshot( array(
    'contract' => 'mad4b.operator-control-center.v1', 'state' => 'HEALTHY',
    'authorizing' => true, 'mutation_performed' => false, 'production_authorized' => false
) );
g7_check( 'RECONCILIATION_REQUIRED' === $bad_operator['state'], 'claimed authority is never accepted from projection' );
$injected_actions = MAD4B_SCP_G7_Action_Center::from_operator_snapshot( array(
    'contract' => 'mad4b.operator-control-center.v1', 'state' => 'HEALTHY',
    'authorizing' => false, 'mutation_performed' => false, 'production_authorized' => false,
    'reasons' => array(), 'next_actions' => array( 'safe_status', array( 'unexpected_callback' ) )
) );
g7_check( 'RECONCILIATION_REQUIRED' === $injected_actions['state'] &&
    false === $injected_actions['trustworthy_operator_projection'], 'malformed actions deny operator trust' );
$healthy = MAD4B_SCP_G7_Action_Center::from_operator_snapshot( array(
    'contract' => 'mad4b.operator-control-center.v1', 'state' => 'HEALTHY',
    'authorizing' => false, 'mutation_performed' => false, 'production_authorized' => false,
    'reasons' => array(), 'next_actions' => array()
) );
g7_check( 'NO_ACTION_OBSERVED' === $healthy['state'] && 6 === count( $healthy['autonomy_levels'] ) &&
    false === $healthy['authorizing'], 'healthy view is still L0 read-only' );
echo "mad4b.feature007-g7-update-host-action.v1: PASS\n";
