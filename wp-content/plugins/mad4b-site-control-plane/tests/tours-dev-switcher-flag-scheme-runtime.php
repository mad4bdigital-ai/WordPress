<?php
/** Hermetic, no-network country flag URL and fallback source-preservation test. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
$dir = sys_get_temp_dir() . '/mad4b-switcher-flag-' . bin2hex( random_bytes( 5 ) );
define( 'WP_PLUGIN_DIR', $dir );
$GLOBALS['home_scheme'] = 'https';
class WC_Countries {
    public function get_countries() { return array( 'EG' => 'Egypt', 'US' => 'United States', 'DE' => 'Germany' ); }
}
function plugins_url( $p, $file ) { return 'http://fixture.example.test/wp-content/plugins/sitepress-multilingual-cms/' . $p; }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function home_url( $path = '/' ) { return $GLOBALS['home_scheme'] . '://site.example.test' . $path; }
function set_url_scheme( $url, $scheme = null ) {
    if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) throw new RuntimeException( 'Invalid scheme' );
    return preg_replace( '#^https?://#', $scheme . '://', $url );
}
function esc_attr( $val ) { return htmlspecialchars( (string) $val, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $val ) { return htmlspecialchars( (string) $val, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $val ) { return htmlspecialchars( (string) $val, ENT_QUOTES, 'UTF-8' ); }
function check_it( $yes, $description ) { if ( ! $yes ) { fwrite( STDERR, 'FAIL: ' . $description . "\n" ); exit( 1 ); } }
function cleanup_dir( $root ) {
    foreach (array('eg.country.svg', 'us.svg') as $file) @unlink( $root . '/sitepress-multilingual-cms/res/flags/' . $file );
    @rmdir( $root . '/sitepress-multilingual-cms/res/flags' );
    @rmdir( $root . '/sitepress-multilingual-cms/res' );
    @rmdir( $root . '/sitepress-multilingual-cms' );
    @rmdir( $root );
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-switcher-sources.php';
register_shutdown_function( 'cleanup_dir', $dir );
check_it( '' === MAD4B_SCP_Switcher_Sources::country_flag('EG'), 'No WPML flag assets: no synthetic URL' );
$flags = WP_PLUGIN_DIR . '/sitepress-multilingual-cms/res/flags';
check_it( mkdir( $flags, 0700, true ), 'Create restricted isolated fixture' );
check_it( false !== file_put_contents( $flags . '/eg.country.svg', '<svg/>' ), 'Create local WPML country SVG fixture' );
check_it( false !== file_put_contents( $flags . '/us.svg', '<svg/>' ), 'Create alternate WPML SVG fixture' );
$eg = MAD4B_SCP_Switcher_Sources::country_flag( 'EG' );
check_it( $eg === 'https://fixture.example.test/wp-content/plugins/sitepress-multilingual-cms/res/flags/eg.country.svg', 'HTTPS home must force HTTPS flag URL' );
check_it( false === strpos( $eg, 'http://' ), 'No insecure flag URL on HTTPS site' );
check_it( str_ends_with( MAD4B_SCP_Switcher_Sources::country_flag( 'us' ), '/res/flags/us.svg' ), 'Fallback WPML flag filename and case-insensitive ISO' );
$markup = MAD4B_SCP_Switcher_Sources::flag_html( 'EG', 100 );
check_it( false !== strpos( $markup, 'src="https://' ) && false !== strpos( $markup, 'width="48"' ), 'Escaped HTML image with clamped size' );
$GLOBALS['home_scheme'] = 'http';
check_it( str_starts_with( MAD4B_SCP_Switcher_Sources::country_flag( 'EG' ), 'http://' ), 'HTTP site preserves HTTP scheme' );
check_it( '' === MAD4B_SCP_Switcher_Sources::country_flag( 'ZZ' ), 'Reject unknown country ISO' );
check_it( '' === MAD4B_SCP_Switcher_Sources::country_flag( 'EG/../../etc' ), 'Reject invalid ISO/path traversal' );
check_it( '' === MAD4B_SCP_Switcher_Sources::country_flag( 'DE' ), 'Known country lacking native asset must not invent URL' );
check_it( false !== strpos( MAD4B_SCP_Switcher_Sources::flag_html( 'DE' ), 'mad4b-country-flag--fallback' ), 'Missing flag gets localized textual fallback' );
check_it( false === strpos( MAD4B_SCP_Switcher_Sources::flag_html( 'DE' ), '<img' ), 'Missing flag does not fetch remote image' );
echo "PASS: Tours.dev WPML flag URL scheme and missing-asset fallback (11 checks)\n";
