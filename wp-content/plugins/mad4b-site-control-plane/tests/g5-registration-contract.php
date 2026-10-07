<?php
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( $code, $message = '', $data = array() ) {}
}
$GLOBALS['g5_registered'] = array();
$GLOBALS['g5_admin'] = false;
function add_action( $hook, $callback, $priority = 10 ) {}
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['g5_admin']; }
function wp_has_ability( $name ) { return isset( $GLOBALS['g5_registered'][ $name ] ); }
function wp_register_ability( $name, $definition ) { $GLOBALS['g5_registered'][ $name ] = $definition; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
class MAD4B_SCP_Admin_Route_Registry {
	public static function schedule_submenu( $callback, $priority = 20 ) {}
	public static function register( $slug, $capability ) { return true; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-external-providers.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-growth-evidence.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-seo-provider-families.php';

MAD4B_SCP_G5_External_Providers::register_abilities();
MAD4B_SCP_G5_Growth_Evidence::register_abilities();
MAD4B_SCP_G5_SEO_Provider_Families::register_abilities();

if ( 5 !== count( $GLOBALS['g5_registered'] ) ) throw new RuntimeException( 'G5 registration count drifted.' );
foreach ( $GLOBALS['g5_registered'] as $name => $definition ) {
	if ( 'mad4b-admin' !== $definition['category'] || false !== $definition['meta']['public'] || false !== $definition['meta']['show_in_rest'] || false !== $definition['meta']['mcp']['public'] || 'admin' !== $definition['meta']['mcp']['surface'] ) throw new RuntimeException( 'G5 registration escaped private admin scope: ' . $name );
	if ( false !== call_user_func( $definition['permission_callback'], array() ) ) throw new RuntimeException( 'Non-admin gained G5 access.' );
	$GLOBALS['g5_admin'] = true;
	if ( true !== call_user_func( $definition['permission_callback'], array() ) ) throw new RuntimeException( 'Admin could not inspect G5.' );
	$GLOBALS['g5_admin'] = false;
}
echo "mad4b.competitive-g5-registration.v1: PASS\n";
