<?php
/* Pure read-only remote alternative discovery/refusal tests. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
$GLOBALS['mounted'] = array();
function wp_has_ability( $ability ) { return ! empty( $GLOBALS['mounted'][ $ability ] ); }
final class MAD4B_SCP_Remote_Operation_Parity {
    public static function catalog() {
        return array(
            'trusted-remote-db-probe' => array(
                'provider' => 'remote-lab', 'remote_ability' => 'remote-lab/probe',
                'capability_tags' => array( 'mariadb', 'crash-recovery' ),
                'remote_registered' => true, 'execution_eligible' => true,
                'registration_digest' => str_repeat( 'a', 64 ),
            ),
            'suspicious-shell-job' => array(
                'provider' => 'remote-shell', 'remote_ability' => 'remote-shell/exec',
                'capability_tags' => array( 'host', 'runner' ),
                'remote_registered' => true, 'execution_eligible' => true,
                'registration_digest' => str_repeat( 'b', 64 ),
            ),
        );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-recovery-alternatives.php';
function recovery_assert( $pass, $reason ) {
    if ( ! $pass ) { fwrite( STDERR, "FAIL: $reason\n" ); exit( 1 ); }
}
$routes = MAD4B_SCP_Recovery_Alternatives::routes();
recovery_assert( count( $routes ) === 4, 'four domains including optional Host-free native rehearsal' );
recovery_assert( count( $routes['wordpress_native'] ) === 2
    && count( $routes['host_runner'] ) >= 3
    && count( $routes['database_recovery'] ) >= 3
    && count( $routes['candidate_delivery'] ) >= 4,
    'multiple alternatives in each unavailable provider domain' );
$empty = MAD4B_SCP_Recovery_Alternatives::assess( array() );
recovery_assert( ! is_wp_error( $empty )
    && !$empty['all_remote_steps_execution_certified']
    && !$empty['automated_write_dispatch']
    && !$empty['grants_created'], 'empty discovery cannot grant authority' );
foreach ( $empty['domains'] as $group ) {
    recovery_assert( $group['status'] === 'ALTERNATIVES_DISCOVERED_NOT_CERTIFIED',
        'no fake green when remote executor missing' );
    foreach ( $group['candidates'] as $candidate ) {
        recovery_assert( !$candidate['execution_authorized']
            && !$candidate['release_or_rollback_certified']
            && !$candidate['automatic_fallback_allowed']
            && $candidate['separate_owner_approval_required'],
            'every route requires real evidence and approval' );
    }
}
$mounted = array();
foreach ( MAD4B_SCP_Recovery_Alternatives::required_ability_names() as $name )
    $mounted[ $name ] = true;
$all = MAD4B_SCP_Recovery_Alternatives::assess( $mounted );
foreach ( $all['domains'] as $group ) {
    foreach ( $group['candidates'] as $candidate )
        recovery_assert( !$candidate['execution_authorized']
            && !$candidate['release_or_rollback_certified'],
            'full ability registration is not certification' );
}
$fall_back = $mounted;
unset( $fall_back['mad4b/control-plane-selected-head-plan'] );
$ci = MAD4B_SCP_Recovery_Alternatives::assess( $fall_back, 'candidate_delivery' );
recovery_assert( $ci['domains']['candidate_delivery']['suggested_read_only_preflight_route']
    === 'signed_offline_staging', 'prefer existing signed CI-outage alternative if standard CI unavailable' );
unset( $fall_back['mad4b/staging-offline-attestor-enroll-plan'] );
$ci2 = MAD4B_SCP_Recovery_Alternatives::assess( $fall_back, 'candidate_delivery' );
recovery_assert( $ci2['domains']['candidate_delivery']['suggested_read_only_preflight_route']
    === 'standalone_remote_builder', 'discover standalone build runner after offline signature plan unavailable' );
$native = MAD4B_SCP_Recovery_Alternatives::assess( $mounted, 'wordpress_native' );
recovery_assert( $native['domains']['wordpress_native']['suggested_read_only_preflight_route']
    === 'native_staging_scratch', 'Host-free WordPress can be planned as first native route' );
foreach ( $native['domains']['wordpress_native']['candidates'] as $route ) {
    recovery_assert( !$route['execution_authorized']
        && !$route['release_or_rollback_certified'],
        'WordPress scratch never certifies Host, release or crash recovery' );
}
$host = MAD4B_SCP_Recovery_Alternatives::assess( $mounted, 'host_runner' );
recovery_assert( count( $host['domains'] ) === 1
    && $host['domains']['host_runner']['suggested_read_only_preflight_route']
    === 'enrolled_host_bridge', 'prefer native signed Host Bridge without bypassing proof' );
$db = MAD4B_SCP_Recovery_Alternatives::assess( $mounted, 'database_recovery' );
foreach ( $db['domains']['database_recovery']['candidates'] as $candidate ) {
    recovery_assert( $candidate['discovery_state'] === 'INTEGRATION_OR_MOUNT_GAP',
        'no existing generic Host operation can certify MariaDB interrupted-write crash evidence' );
}
recovery_assert( is_wp_error( MAD4B_SCP_Recovery_Alternatives::assess( $mounted, 'Production' ) ),
    'unsupported domains fail closed' );
$GLOBALS['mounted'] = $mounted;
$dynamic = MAD4B_SCP_Recovery_Alternatives::discover( 'all' );
recovery_assert( isset( $dynamic['other_registered_provider_suggestions'] )
    && count( $dynamic['other_registered_provider_suggestions'] ) >= 2,
    'dynamic operation catalog discovers future candidates' );
foreach ( $dynamic['other_registered_provider_suggestions'] as $other ) {
    recovery_assert( !$other['execution_authorized']
        && !$other['evidence_equivalence_certified']
        && $other['status'] === 'UNTRUSTED_EQUIVALENCE_CANDIDATE',
        'unreviewed add-on executor is never trusted or dispatched' );
}
echo "mad4b.site-control-plane.recovery-alternatives.runtime.v1: PASS\n";
