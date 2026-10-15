<?php
/* G9 Abilities namespace-collision: no partial or foreign registration. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['g9_foreign'] = array( 'mad4b/g9-site-observation'=>true );
$GLOBALS['g9_local'] = array();
$GLOBALS['g9_actions'] = array();
class WP_Error {
    private $code;
    public function __construct( $code, $message, $data = null ) { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $name, $handler, $priority = 10 ) {
    $GLOBALS['g9_actions'][] = $handler; return true;
}
function wp_has_ability( $name ) {
    return isset( $GLOBALS['g9_foreign'][$name] ) || isset( $GLOBALS['g9_local'][$name] );
}
function wp_register_ability( $name, $spec ) {
    if ( wp_has_ability( $name ) ) return new WP_Error( 'collision', 'collision' );
    $GLOBALS['g9_local'][$name] = $spec; return true;
}
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
require_once __DIR__ . '/../includes/class-mad4b-scp-g9-read-surface.php';
$booted = MAD4B_SCP_G9_Read_Surface::boot();
if ( true !== $booted || count( $GLOBALS['g9_actions'] ) !== 1 )
    { fwrite(STDERR, "G9 collision FAIL: expected owned-reader boot hook\n"); exit(1); }
$result = MAD4B_SCP_G9_Read_Surface::register_abilities();
if ( ! is_wp_error( $result )
    || $result->get_error_code() !== 'mad4b_g9_ability_namespace_collision'
    || ! empty( $GLOBALS['g9_local'] ) )
    { fwrite(STDERR, "G9 collision FAIL: foreign ability was not rejected atomically\n"); exit(1); }
$again = MAD4B_SCP_G9_Read_Surface::register_abilities();
if ( ! is_wp_error( $again )
    || ! empty( $GLOBALS['g9_local'] )
    || count( $GLOBALS['g9_foreign'] ) !== 1 )
    { fwrite(STDERR, "G9 collision FAIL: bypassed namespace guard\n"); exit(1); }
echo "G9 ability namespace collision: PASS (no partial local registration)\n";
