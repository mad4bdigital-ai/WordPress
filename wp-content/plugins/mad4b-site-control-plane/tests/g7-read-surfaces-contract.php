<?php
/* G7 optional read surfaces: discovery, permissions, strict input, no mutation. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( $code, $message = '', $data = array() ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['g7_read_registry'] = array();
function wp_register_ability( $name, $spec ) { $GLOBALS['g7_read_registry'][ $name ] = $spec; }
function wp_has_ability( $name ) { return false; }
function add_action( $hook, $callback, $priority = 10 ) { if ( 'wp_abilities_api_init' === $hook ) call_user_func( $callback ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_G7_Host_Readiness {
    public static function capture() { return array( 'contract' => 'g7-test-host', 'authorizing' => false ); }
}
class MAD4B_SCP_G7_Update_Acceptance {
    public static function capture() { return array( 'contract' => 'g7-test-update', 'authorizing' => false ); }
    public static function compare( array $before, array $after ) { return array( 'contract' => 'g7-test-comparison', 'authorizing' => false, 'state' => 'APPROVAL_REQUIRED' ); }
}
class MAD4B_SCP_G7_Action_Center { const CONTRACT = 'mad4b.feature007-g7-action-center.v1'; }
class MAD4B_SCP_G7_Workload_Measurement {
    public static function status( $hours = 24 ) { return array( 'state' => 'INCOMPLETE_EVIDENCE', 'authorizing' => false ); }
}
class MAD4B_SCP_G7_Release_Acceptance_Audit {
    public static function assess( array $before, array $after ) { return array( 'state' => 'RECONCILIATION_REQUIRED', 'authorizing' => false ); }
}
class MAD4B_SCP_Operator_Control_Center {
    public static function execute() { return array( 'g7_action_center' => array( 'contract' => MAD4B_SCP_G7_Action_Center::CONTRACT, 'state' => 'NO_ACTION_OBSERVED', 'authorizing' => false ) ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-read-surfaces.php';
function g7r( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
MAD4B_SCP_G7_Read_Surfaces::boot();
g7r( count( $GLOBALS['g7_read_registry'] ) === 6, 'six read-only abilities registered' );
foreach ( $GLOBALS['g7_read_registry'] as $name => $spec ) {
    g7r( strpos( $name, 'mad4b/g7-' ) === 0, 'strict namespace' );
    g7r( $spec['category'] === 'mad4b-read' &&
        $spec['permission_callback'] === array( 'MAD4B_SCP_Policy', 'can_read' ) &&
        true === $spec['meta']['annotations']['readonly'] &&
        false === $spec['meta']['annotations']['destructive'] &&
        false === $spec['meta']['public'] &&
        false === $spec['meta']['mcp']['public'] &&
        false === $spec['meta']['show_in_rest'], 'permission and non-public read metadata' );
}
g7r( ! is_wp_error( MAD4B_SCP_G7_Read_Surfaces::host() ), 'host read available' );
g7r( ! is_wp_error( MAD4B_SCP_G7_Read_Surfaces::update() ), 'update read available' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::host( array( 'install' => true ) ) ), 'no host input' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::update( array( 'run' => true ) ) ), 'no update execution' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::compare( array( 'before' => array() ) ) ), 'complete comparison input required' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::compare( array( 'before' => array(), 'after' => array(), 'authorize' => true ) ) ), 'no injected authorization field' );
$huge = array( 'sealed_observation' => array( 'material' => array( 'unsafe' => str_repeat( 'x', 32769 ) ) ) );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::compare( array( 'before' => $huge, 'after' => array() ) ) ), 'comparison bounded before native verification' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::release( array( 'before' => array(), 'after' => $huge ) ) ), 'release input size bounded' );
$nested = array();
for ( $i = 0; $i < 12; $i++ ) $nested = array( 'level' => $nested );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::compare( array( 'before' => $nested, 'after' => array() ) ) ), 'deep evidence rejected' );
$objects = array( 'sealed_observation' => (object) array( 'material' => array() ) );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::release( array( 'before' => $objects, 'after' => array() ) ) ), 'non-JSON objects denied' );
$comparison = MAD4B_SCP_G7_Read_Surfaces::compare( array( 'before' => array(), 'after' => array() ) );
g7r( ! is_wp_error( $comparison ) && false === $comparison['authorizing'], 'comparison stays non-authorizing' );
$action = MAD4B_SCP_G7_Read_Surfaces::action();
g7r( ! is_wp_error( $action ) && false === $action['authorizing'], 'action status is read-only' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::action( array( 'approve' => true ) ) ), 'action input cannot approve' );
g7r( ! is_wp_error( MAD4B_SCP_G7_Read_Surfaces::metrics() ), 'metric projection read only' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::metrics( array( 'record' => 1 ) ) ), 'metric write injection denied' );
g7r( is_wp_error( MAD4B_SCP_G7_Read_Surfaces::release( array( 'before' => array(), 'after' => array(), 'approve' => true ) ) ), 'release grant injection denied' );
g7r( ! is_wp_error( MAD4B_SCP_G7_Read_Surfaces::release( array( 'before' => array(), 'after' => array() ) ) ), 'release projection available' );
echo "mad4b.feature007-g7-read-surfaces.v1: PASS\n";
