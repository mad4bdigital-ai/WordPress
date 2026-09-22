<?php
define( 'ABSPATH', '/tmp/mad4b-qm-test/' );
define( 'QM_VERSION', '4.0.7' );

$root = sys_get_temp_dir() . '/mad4b-qm-attribution-' . getmypid();
$content = $root . '/wp-content';
$plugins = $content . '/plugins';
$source_dir = $plugins . '/query-monitor/wp-content';
@mkdir( $source_dir, 0777, true );
file_put_contents( $source_dir . '/db.php', "<?php\n/* Query Monitor */\nclass QM_DB {}\n" );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_PLUGIN_DIR', $plugins );

$GLOBALS['mad4b_qm_actions'] = array();
$GLOBALS['mad4b_qm_options'] = array();
$GLOBALS['mad4b_qm_env'] = 'staging';
$GLOBALS['mad4b_qm_admin'] = true;

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['mad4b_qm_actions'][$hook][$priority][] = $callback; }
function remove_action() { return true; }
function trailingslashit( $v ) { return rtrim( (string) $v, "/\\" ) . '/'; }
function wp_normalize_path( $v ) { return str_replace( '\\', '/', (string) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function wp_get_environment_type() { return $GLOBALS['mad4b_qm_env']; }
function is_admin() { return ! empty( $GLOBALS['mad4b_qm_admin'] ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_qm_options'] ) ? $GLOBALS['mad4b_qm_options'][$key] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['mad4b_qm_options'][$key] = $value; return true; }

final class MAD4B_SCP_Site_Profile {
	public static function current_environment() { return $GLOBALS['mad4b_qm_env']; }
}
final class MAD4B_SCP_Live_Acceptance_Observer {
	const TELEMETRY_OPTION = 'unused';
	const QUERY_MONITOR_CONTRACT = 'unused';
	const MAX_EVENTS = 32;
	public static function staging_capture_allowed() { return false; }
	public static function observe_doing_it_wrong() {}
	public static function observe_deprecated_function() {}
	public static function observe_deprecated_argument() {}
	public static function observe_deprecated_hook() {}
	public static function observe_deprecated_class() {}
	public static function flush_observation() {}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-query-monitor-evidence-bridge.php';

function mad4b_qm_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$status = MAD4B_SCP_Query_Monitor_Evidence_Bridge::db_attribution_status();
mad4b_qm_assert( 'enablement_available' === $status['state'], 'fresh Staging install must report safe enablement available' );
mad4b_qm_assert( ! empty( $status['safe_to_enable'] ), 'fresh Staging install must be safe to enable' );

$enabled = MAD4B_SCP_Query_Monitor_Evidence_Bridge::maybe_enable_db_attribution();
mad4b_qm_assert( is_link( WP_CONTENT_DIR . '/db.php' ), 'Query Monitor db.php symlink was not created' );
mad4b_qm_assert( ! empty( $enabled['dropin_owned_by_query_monitor'] ), 'created drop-in must be recognized as Query Monitor owned' );
mad4b_qm_assert( 'query_monitor_dropin_reload_required' === $enabled['state'], 'newly created drop-in must require request reload before QM_DB can be active' );
mad4b_qm_assert( ! empty( $enabled['bootstrap']['created'] ), 'bootstrap evidence must record creation' );
mad4b_qm_assert( empty( $enabled['production_changed'] ), 'Staging attribution bootstrap must never claim Production mutation' );

@unlink( WP_CONTENT_DIR . '/db.php' );
file_put_contents( WP_CONTENT_DIR . '/db.php', "<?php\n/* foreign database drop-in */\n" );
$foreign_before = hash_file( 'sha256', WP_CONTENT_DIR . '/db.php' );
$conflict = MAD4B_SCP_Query_Monitor_Evidence_Bridge::db_attribution_status();
mad4b_qm_assert( 'conflicting_db_dropin' === $conflict['state'], 'foreign db.php must be detected as a conflict' );
mad4b_qm_assert( ! empty( $conflict['dropin_conflict'] ), 'foreign drop-in conflict flag missing' );
$after = MAD4B_SCP_Query_Monitor_Evidence_Bridge::maybe_enable_db_attribution();
mad4b_qm_assert( $foreign_before === hash_file( 'sha256', WP_CONTENT_DIR . '/db.php' ), 'foreign db.php must never be overwritten' );
mad4b_qm_assert( 'conflicting_db_dropin' === $after['state'], 'conflicting drop-in must remain fail-closed' );

$GLOBALS['mad4b_qm_env'] = 'production';
@unlink( WP_CONTENT_DIR . '/db.php' );
$production = MAD4B_SCP_Query_Monitor_Evidence_Bridge::maybe_enable_db_attribution();
mad4b_qm_assert( ! is_link( WP_CONTENT_DIR . '/db.php' ) && ! file_exists( WP_CONTENT_DIR . '/db.php' ), 'Production must never create the Query Monitor db.php drop-in' );
mad4b_qm_assert( empty( $production['production_changed'] ), 'Production status must report unchanged' );

@unlink( WP_CONTENT_DIR . '/db.php' );
@unlink( $source_dir . '/db.php' );
@rmdir( $source_dir );
@rmdir( dirname( $source_dir ) );
@rmdir( $plugins );
@rmdir( $content );
@rmdir( $root );

echo "mad4b.query-monitor-db-attribution.runtime.v1: PASS\n";
