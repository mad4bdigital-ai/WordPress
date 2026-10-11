<?php
/** Native denial: WordPress Abilities API returns null when registration fails. */
define( 'ABSPATH', '/fixture/' );
class WP_Error { public function __construct( $code = '' ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( $hook, $cb, $priority = 10 ) {}
$GLOBALS['abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) {
    $GLOBALS['abilities'][ $name ] = $args;
    if ( 'cso/form-validate' === $name ) return null;
    return new stdClass();
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-cso01-read-foundation.php';
MAD4B_SCP_CSO01_Read_Foundation::boot();
MAD4B_SCP_CSO01_Read_Foundation::register_abilities();
if ( array() !== MAD4B_SCP_CSO01_Read_Foundation::read_ability_names() ) {
    fwrite( STDERR, "FAIL: failed WordPress Ability registration exposed MCP tools\n" );
    exit( 1 );
}
if ( MAD4B_SCP_CSO01_Read_Foundation::can_read() ) {
    fwrite( STDERR, "FAIL: surviving partially registered callback remained executable\n" );
    exit( 1 );
}
echo "mad4b.cso01.registration-null-fail-closed.v1: PASS\n";
