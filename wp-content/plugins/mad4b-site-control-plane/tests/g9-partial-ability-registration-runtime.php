<?php
/* G9 second Ability registration fails: the first must not become callable. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['g9_read_abilities'] = array();
$GLOBALS['g9_registration_attempts'] = 0;
class WP_Error {
    private $code;
    public function __construct( $code, $message, $data = null ) { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10 ) { return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['g9_read_abilities'][ $name ] ); }
function wp_register_ability( $name, $schema ) {
    ++$GLOBALS['g9_registration_attempts'];
    if ( 2 === $GLOBALS['g9_registration_attempts'] ) {
        $mode = isset( $GLOBALS['argv'][1] ) ? (string) $GLOBALS['argv'][1] : 'error';
        if ( 'pretend' === $mode ) return true; // API lies: name not persisted.
        if ( 'null' === $mode ) return null;  // Adapter forgets return.
        return new WP_Error( 'g9_simulated_registry_error', 'The second Ability cannot be registered.' );
    }
    $GLOBALS['g9_read_abilities'][ $name ] = $schema;
    return true;
}
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
require_once __DIR__ . '/../includes/class-mad4b-scp-g9-read-surface.php';
function g9_partial_assert( $predicate, $message ) {
    if ( ! $predicate ) { fwrite( STDERR, "G9 partial registration FAIL: " . $message . "\n" ); exit( 1 ); }
}
g9_partial_assert( MAD4B_SCP_G9_Read_Surface::boot() === true, 'code-owned reader pin must succeed' );
$registered = MAD4B_SCP_G9_Read_Surface::register_abilities();
g9_partial_assert( is_wp_error( $registered )
    && $registered->get_error_code() === 'mad4b_g9_ability_registration_failed',
    'mid-batch WordPress error must propagate' );
g9_partial_assert( count( $GLOBALS['g9_read_abilities'] ) === 1,
    'one partially registered read tool is expected under injected failure' );
$first = reset( $GLOBALS['g9_read_abilities'] );
g9_partial_assert( isset( $first['permission_callback'] )
    && call_user_func( $first['permission_callback'], array() ) === false,
    'registered partial Ability must be permission-denied, despite admin policy' );
g9_partial_assert( MAD4B_SCP_G9_Read_Surface::can_read_ability() === false,
    'code-owned group remains disabled until registration completes' );
$retry = MAD4B_SCP_G9_Read_Surface::register_abilities();
g9_partial_assert( is_wp_error( $retry )
    && $retry->get_error_code() === 'mad4b_g9_ability_namespace_collision',
    'partial registry cannot be accidentally interpreted as an owned completed trio' );
echo "G9 partial registry: PASS (mid-registration failure leaves no callable partial Ability)\n";
