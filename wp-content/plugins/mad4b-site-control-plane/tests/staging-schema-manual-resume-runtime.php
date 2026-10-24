<?php
/** Offline regression: historical manual resume cannot recursively schedule Schema at init. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['schema_probe'] = array( 'calls' => 0, 'scheduled' => 0 );
$GLOBALS['manual_checkpoint'] = array(
	'source' => 'self_update',
	'state' => 'pending_manual_resume',
	'resume_blocker' => 'mad4b_automation_automation_kill_switch',
);
function add_action( $name, $callback, $priority = 10 ) { return true; }
function get_option( $key, $default = false ) { return $GLOBALS['manual_checkpoint']; }
function wp_next_scheduled( $hook ) { $GLOBALS['schema_probe']['scheduled']++; return false; }
class MAD4B_SCP_Runtime_Convergence {
	const CHECKPOINT_OPTION = 'mad4b_scp_runtime_convergence_v1';
	public static function restart_grace_status() {
		$GLOBALS['schema_probe']['calls']++;
		return array( 'active' => true );
	}
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-schema-lifecycle.php';
MAD4B_SCP_Schema_Lifecycle::maybe_schedule();
if ( 0 !== $GLOBALS['schema_probe']['calls'] || 0 !== $GLOBALS['schema_probe']['scheduled'] ) {
	fwrite( STDERR, 'SCHEMA_MANUAL_PAUSE_FAIL: manual checkpoint invoked expensive Runtime graph' . PHP_EOL ); exit( 1 );
}
$GLOBALS['manual_checkpoint']['state'] = 'pending_safe_phases';
MAD4B_SCP_Schema_Lifecycle::maybe_schedule();
if ( 1 !== $GLOBALS['schema_probe']['calls'] || 0 !== $GLOBALS['schema_probe']['scheduled'] ) {
	fwrite( STDERR, 'SCHEMA_MANUAL_PAUSE_FAIL: live pending checkpoint bypassed normal Restart Grace' . PHP_EOL ); exit( 1 );
}
echo "SCHEMA_MANUAL_PAUSE: PASS\n";