<?php
/** Staging MU recovery scheduling regression. Pure local fixtures, no WP writes. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function check_mu_schedule( $value, $label ) {
    if ( ! $value ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
$GLOBALS['mu_sched_env'] = 'staging';
$GLOBALS['mu_sched_governed'] = true;
$GLOBALS['mu_sched_hotpath'] = false;
$GLOBALS['mu_sched_diagnostic'] = false;
$GLOBALS['mu_sched_cron'] = false;
$GLOBALS['mu_sched_events'] = array();
$GLOBALS['mu_sched_state'] = 'managed_mu_stale';
$GLOBALS['mu_sched_owned'] = true;
$GLOBALS['mu_sched_blocker'] = '';
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_doing_cron() { return $GLOBALS['mu_sched_cron']; }
function wp_next_scheduled( $hook ) {
    return isset( $GLOBALS['mu_sched_events'][ $hook ] ) ? $GLOBALS['mu_sched_events'][ $hook ] : false;
}
function wp_schedule_single_event( $time, $hook ) {
    $GLOBALS['mu_sched_events'][ $hook ] = $time;
    return true;
}
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['mu_sched_env']; }
    public static function nonproduction_governed( $capability ) {
        return $capability === 'managed_runtime' && $GLOBALS['mu_sched_governed'];
    }
}
class MAD4B_SCP_MCP_Request_Scope {
    public static function current_request_is_protocol_hotpath() { return $GLOBALS['mu_sched_hotpath']; }
    public static function current_request_is_endpoint_diagnostic_job() { return $GLOBALS['mu_sched_diagnostic']; }
}
class MAD4B_SCP_MCP_MU_Bootstrap_Refresh {
    public static function conflict_status() {
        return array(
            'state' => $GLOBALS['mu_sched_state'],
            'blocker' => $GLOBALS['mu_sched_blocker'],
            'managed' => $GLOBALS['mu_sched_owned'],
            'source_sha256' => str_repeat( 'a', 64 ),
            'destination_sha256_before' => str_repeat( 'b', 64 ),
        );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-runtime-recovery.php';
$c = 'MAD4B_SCP_MCP_Runtime_Recovery';
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( count( $GLOBALS['mu_sched_events'] ) === 1 &&
    isset( $GLOBALS['mu_sched_events'][ $c::HOOK ] ),
    'Known owned stale MU triggers exact audited recovery cron after FTP/manual ZIP upload' );
$GLOBALS['mu_sched_events'] = array();
$GLOBALS['mu_sched_state'] = 'managed_mu_absent';
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( !$GLOBALS['mu_sched_events'],
    'Absent MU file is not automatically treated as previously owned' );
$GLOBALS['mu_sched_state'] = 'managed_mu_stale';
$GLOBALS['mu_sched_owned'] = false;
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( !$GLOBALS['mu_sched_events'],
    'Unowned MU cannot be replaced by stale-owned recovery mode' );
$GLOBALS['mu_sched_owned'] = true;
$GLOBALS['mu_sched_env'] = 'production';
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( !$GLOBALS['mu_sched_events'],
    'Production cannot trigger Staging auto refresh' );
$GLOBALS['mu_sched_env'] = 'staging';
$GLOBALS['mu_sched_hotpath'] = true;
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( !$GLOBALS['mu_sched_events'],
    'No scheduling during MCP protocol hotpath' );
$GLOBALS['mu_sched_hotpath'] = false;
$GLOBALS['mu_sched_diagnostic'] = true;
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( !$GLOBALS['mu_sched_events'],
    'No scheduling during endpoint diagnostic hotpath' );
$GLOBALS['mu_sched_diagnostic'] = false;
$GLOBALS['mu_sched_governed'] = false;
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( !$GLOBALS['mu_sched_events'],
    'Disabled managed_runtime feature never auto-recovers MU' );
$GLOBALS['mu_sched_governed'] = true;
$GLOBALS['mu_sched_state'] = 'unmanaged_path_conflict';
$GLOBALS['mu_sched_blocker'] = 'unmanaged_mu_bootstrap_path_conflict';
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( count( $GLOBALS['mu_sched_events'] ) === 1,
    'Existing governed Staging conflict workflow remains available' );
$c::maybe_schedule_conflict_recovery();
check_mu_schedule( count( $GLOBALS['mu_sched_events'] ) === 1,
    'Cron scheduling idempotent while a task is already scheduled' );
echo "PASS: staging owned MU stale recovery scheduling, fail-closed unknown/absent/production and no hotpath mutation\n";
