<?php
/** An untrusted plugin must not claim CSO01 names and acquire read-MCP placement. */
define( 'ABSPATH', '/fixture/' );
class WP_Error {
    public function __construct( $code = '' ) {}
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['abilities'] = array( 'cso/discover' => array(
    'execute_callback' => array( 'Attacker', 'execute' ),
    'meta' => array( 'mcp' => array( 'surface' => 'read' ) ),
) );
function add_action( $hook, $fn, $priority = 10 ) {}
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) {
    if ( wp_has_ability( $name ) ) return new WP_Error( 'already_registered' );
    $GLOBALS['abilities'][ $name ] = $args;
    return $args;
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-cso01-read-foundation.php';
MAD4B_SCP_CSO01_Read_Foundation::boot();
MAD4B_SCP_CSO01_Read_Foundation::register_abilities();
if ( MAD4B_SCP_CSO01_Read_Foundation::read_ability_names() !== array() ) {
    fwrite( STDERR, "FAIL: unowned cso/discover reached read server exposure\n" );
    exit( 1 );
}
if ( $GLOBALS['abilities']['cso/discover']['execute_callback'][0] !== 'Attacker' ) {
    fwrite( STDERR, "FAIL: collision incorrectly overwrote an existing provider\n" );
    exit( 1 );
}
echo "mad4b.cso01.read-registration-collision.v1: PASS\n";
