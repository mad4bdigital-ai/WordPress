<?php
/** Registration integration with the real Servers class; no WordPress site is contacted. */
define( 'ABSPATH', __DIR__ . '/' );
function add_action( $hook, $callback, $priority = 10 ) {}
function add_filter( $hook, $callback, $priority = 10 ) {}
$GLOBALS['g3_registered'] = array();
$GLOBALS['g3_admin'] = false;
function wp_has_ability( $name ) { return isset( $GLOBALS['g3_registered'][ $name ] ); }
function wp_register_ability( $name, $definition ) { $GLOBALS['g3_registered'][ $name ] = $definition; }
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['g3_admin']; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-declarative-adapter-manifest.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-shadow-read.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-certification-pack-registry.php';

$register = static function () {
    MAD4B_SCP_Declarative_Adapter_Manifest::register_abilities();
    MAD4B_SCP_Provider_Shadow_Read::register_ability();
    MAD4B_SCP_Certification_Pack_Registry::register_abilities();
};
$register();
if ( count( $GLOBALS['g3_registered'] ) !== 5 ) throw new RuntimeException( 'G3 registration count drifted.' );
foreach ( $GLOBALS['g3_registered'] as $name => $definition ) {
    if ( 'mad4b-admin' !== $definition['category'] || false !== $definition['meta']['public'] || false !== $definition['meta']['show_in_rest'] || false !== $definition['meta']['mcp']['public'] || 'admin' !== $definition['meta']['mcp']['surface'] ) throw new RuntimeException( 'G3 registration escaped private admin scope: ' . $name );
    if ( false !== call_user_func( $definition['permission_callback'], array() ) ) throw new RuntimeException( 'Non-admin gained G3 access.' );
    $GLOBALS['g3_admin'] = true;
    if ( true !== call_user_func( $definition['permission_callback'], array() ) ) throw new RuntimeException( 'Admin could not inspect G3.' );
    $GLOBALS['g3_admin'] = false;
}
echo "mad4b.competitive-g3-registration.v1: PASS\n";
