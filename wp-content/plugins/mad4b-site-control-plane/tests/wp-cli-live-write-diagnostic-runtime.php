<?php
/** Read-only CLI regression: a persisted grant checkpoint is not live authority. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_get_environment_type() { return 'staging'; }
class WP_CLI {
    public static $commands = array();
    public static $lines = array();
    public static function add_command( $key, $callback ) { self::$commands[ $key ] = $callback; }
    public static function line( $value ) { self::$lines[] = $value; }
    public static function error( $message ) { throw new RuntimeException( $message ); }
}
class MAD4B_SCP_Staging_Write_Authority {
    public static $current_ready = false;
    public static function persisted_status() { return array( 'ready' => true, 'write_tool_count' => 104, 'oauth_secret' => 'MUST_NOT_LEAK' ); }
    public static function current_execution_readiness() {
        return array( 'ready' => self::$current_ready,
            'blockers' => self::$current_ready ? array() : array( 'stale_write_grants_present' ),
            'candidate_binding_match' => true,
            'next_reconciliation' => self::$current_ready ? 'none' : 'review_exact_live_write_grants' );
    }
    public static function reconciliation_plan() {
        return array( 'write_tool_count' => self::$current_ready ? 104 : 99,
            'exact_grants_missing_count' => 0, 'stale_allow_grants_count' => self::$current_ready ? 0 : 5,
            'stale_allow_grants' => self::$current_ready ? array() : array(
                array( 'ability' => 'context/materialize-brand-draft', 'id' => 'PRIVATE' ),
                array( 'ability' => 'context/reconcile-brand-materialization' ),
                array( 'ability' => 'context/recreate-drive-asset' ),
                array( 'ability' => 'context/rollback-materialized-brand-draft' ),
                array( 'ability' => 'context/update-drive-asset' ) ) );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-cli.php';
function check( $ok, $msg ) { if ( ! $ok ) { fwrite( STDERR, $msg . PHP_EOL ); exit( 1 ); } }
check( isset( WP_CLI::$commands['mad4b diagnostics write'] ), 'read-only diagnostic not registered' );
$command = WP_CLI::$commands['mad4b diagnostics write'];
call_user_func( $command, array(), array() );
$raw = end( WP_CLI::$lines );
$row = json_decode( $raw, true );
check( is_array( $row ) && $row['contract'] === 'mad4b.cli-write-diagnostic.v1', 'contract mismatch' );
check( $row['checkpoint_ready'] === true && $row['current_execution_ready'] === false, 'persisted truth incorrectly granted live write' );
check( $row['write_inventory_count'] === 99 && $row['stale_allow_grants_count'] === 5, 'active inventory drift hidden' );
check( count( $row['stale_ability_names'] ) === 5, 'exact stale ability names missing' );
check( $row['mutation_performed'] === false && $row['authorizing'] === false && $row['production_authorized'] === false, 'diagnostic became authorizing' );
check( strpos( $raw, 'PRIVATE' ) === false && strpos( $raw, 'MUST_NOT_LEAK' ) === false, 'credential or grant identifier leaked' );
MAD4B_SCP_Staging_Write_Authority::$current_ready = true;
call_user_func( $command, array(), array() );
$ready = json_decode( end( WP_CLI::$lines ), true );
check( $ready['current_execution_ready'] === true && $ready['write_inventory_count'] === 104 && count( $ready['stale_ability_names'] ) === 0, 'healthy readback drifted' );
echo "mad4b.wp-cli.live-write-diagnostic.runtime.v1: PASS\n";
