<?php
/** Executable read-only regression: a 20-second quiet window does not clear a pending checkpoint. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['checkpoint'] = array();
$GLOBALS['option_reads'] = 0;
$GLOBALS['option_writes'] = 0;
function get_option( $key, $fallback = false ) {
    $GLOBALS['option_reads']++;
    return 'mad4b_scp_runtime_convergence_v1' === $key ? $GLOBALS['checkpoint'] : $fallback;
}
function update_option() { $GLOBALS['option_writes']++; throw new RuntimeException( 'Diagnostics must not mutate' ); }
function add_action( $hook, $callback, $priority = 10 ) { return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
function check_grace( $ok, $reason ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $reason . PHP_EOL ); exit( 1 ); } }
$start = time() - 120;
$GLOBALS['checkpoint'] = array(
    'source' => 'self_update', 'state' => 'pending_safe_phases',
    'created_at' => gmdate( 'c', $start ), 'updated_at' => gmdate( 'c', $start + 10 ),
    'resume_not_before' => $start + 20,
    'resume_blocker' => '', 'transient_retry_count' => 2,
);
$r = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
check_grace( $r['active'] && ! $r['quiet_period_active'], 'pending post-quiet checkpoint must remain protected' );
check_grace( $r['quiet_period_elapsed_but_pending'] && $r['retryable'], 'pending is retryable but not released' );
check_grace( $r['checkpoint_age_seconds'] >= 110 && $r['checkpoint_update_age_seconds'] >= 100, 'checkpoint age evidence' );
check_grace( 'runtime_identity_unverified' === $r['diagnostic_classification'], 'unknown identity cannot be treated as converged' );
check_grace( 2 === $r['transient_retry_count'], 'bounded retry count retained' );
$GLOBALS['checkpoint']['state'] = 'pending_manual_resume';
$GLOBALS['checkpoint']['resume_blocker'] = 'wp_cron_disabled';
$r = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
check_grace( $r['active'] && ! $r['retryable'] && $r['operator_action_required'], 'manual recovery stays gated' );
check_grace( 'operator_action_required' === $r['diagnostic_classification'] && 'wp_cron_disabled' === $r['resume_blocker'], 'manual blocker exposed' );
$GLOBALS['checkpoint']['state'] = 'blocked';
$GLOBALS['checkpoint']['last_error_code'] = 'mad4b_automation_guard_missing';
$r = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
check_grace( $r['active'] && ! $r['retryable'] && 'mad4b_automation_guard_missing' === $r['last_error_code'], 'blocked state never times out automatically' );
$GLOBALS['checkpoint']['state'] = 'complete';
$r = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
check_grace( ! $r['active'] && 'inactive' === $r['diagnostic_classification'], 'terminal checkpoint releases barrier' );
check_grace( 0 === $GLOBALS['option_writes'] && $GLOBALS['option_reads'] > 0, 'read-only evidence cannot write' );
echo "RUNTIME_RESTART_GRACE_READONLY: PASS\n";
