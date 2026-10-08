<?php
/* G9 owned-reader boot contract: independent hermetic PHP process. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['g9_ability_hooks'] = array();
$GLOBALS['g9_boot_abilities'] = array();

class WP_Error {
    private $code;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10 ) {
    $GLOBALS['g9_ability_hooks'][] = array( $hook, $callback, $priority ); return true;
}
function wp_has_ability( $name ) { return isset( $GLOBALS['g9_boot_abilities'][ $name ] ); }
function wp_register_ability( $name, $schema ) {
    $GLOBALS['g9_boot_abilities'][ $name ] = $schema; return true;
}
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
}
function check_g9( $condition, $description ) {
    if ( ! $condition ) { fwrite( STDERR, "G9 boot FAIL: $description\n" ); exit( 1 ); }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-g9-read-surface.php';
check_g9( MAD4B_SCP_G9_Read_Surface::boot() === true, 'code-owned reader pinned' );
check_g9( count( $GLOBALS['g9_ability_hooks'] ) === 1, 'one bootstrap hook' );
$hook = $GLOBALS['g9_ability_hooks'][0];
check_g9( $hook[0] === 'wp_abilities_api_init' && $hook[2] === 38,
    'abilities exposed only via standard WordPress init' );
call_user_func( $hook[1] );
check_g9( count( $GLOBALS['g9_boot_abilities'] ) === 3, 'three passive abilities' );
foreach ( $GLOBALS['g9_boot_abilities'] as $name => $args ) {
    check_g9( strpos( $name, 'mad4b/g9-' ) === 0
        && $args['meta']['annotations']['readonly'] === true
        && $args['meta']['annotations']['destructive'] === false
        && $args['meta']['mcp']['public'] === false
        && $args['input_schema']['additionalProperties'] === false,
        'only private read-only G9 abilities published' );
}
check_g9( ! isset( $GLOBALS['g9_boot_abilities']['mad4b/g9-release-reserve'] ),
    'no release reservation mutation Ability exposed' );
check_g9( is_wp_error( MAD4B_SCP_Resilience_Context::register_reader(
    new MAD4B_SCP_G9_Local_Reader()
) ), 'second reader registration rejected' );
echo "G9 owned-reader bootstrap: PASS (single pinned local reader, 3 private read Abilities)\n";
