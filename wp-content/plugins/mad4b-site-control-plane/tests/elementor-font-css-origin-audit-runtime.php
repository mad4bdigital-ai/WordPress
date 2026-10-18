<?php
/** Hermetic bounded CSS-origin diagnostics, never a font URL replacement. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class MAD4B_SCP_Adapter_Base {}
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_key( $s ) { return (string) $s; }
function current_user_can( $cap ) { return $GLOBALS['owner'] && 'manage_options' === $cap; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['uploads'] ); }
class MAD4B_SCP_Staging_Write_Owner_Intent {
    public static function owner() { return $GLOBALS['owner']; }
}
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function current_environment() { return $GLOBALS['environment']; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function current_origin() { return 'https://staging.example.org'; }
}
function font_assert( $pass, $label ) {
    if ( ! $pass ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
$GLOBALS['owner'] = true;
$GLOBALS['environment'] = 'staging';
$GLOBALS['uploads'] = sys_get_temp_dir() . '/mad4b-css-font-audit-' . getmypid();
$dir = $GLOBALS['uploads'] . '/elementor/css';
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) die( 'Unable to set up fixture' );
$file = $dir . '/post-22.css';
$css = '@font-face{src:url("https://production.example.org/assets/real.woff2")} '
    . '@font-face{src:url("https://staging.example.org/assets/local.woff")}';
file_put_contents( $file, $css );
$before = hash_file( 'sha256', $file );
// Regression: the real ETG issue lives in elementor/google-fonts/css,
// NOT only in elementor/css.
$font_dir = $GLOBALS['uploads'] . '/elementor/google-fonts/css';
if ( ! mkdir( $font_dir, 0700, true ) ) die( 'Unable to set up font CSS fixture' );
$font_file = $font_dir . '/cairo.css';
file_put_contents( $font_file, '@font-face{src:url("https://production.example.org/wp-content/uploads/elementor/google-fonts/fonts/cairo-real.woff2")} '
    . '@font-face{src:url(https://staging.example.org/assets/other.woff2)}' );
$font_before = hash_file( 'sha256', $font_file );
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-elementor-adapter.php';
$a = new MAD4B_SCP_Elementor_Adapter();
$read = $a->font_css_origin_audit( array( 'production_host' => 'production.example.org' ) );
font_assert( is_array( $read ) && $read['scan_complete'] === true
    && $read['production_font_references'] === 2 && $read['font_references'] === 4
    && $read['files_scanned'] === 2
    && in_array( 'google-fonts/css', $read['directories_scanned'], true ),
    'Both Elementor CSS roots scanned without counting Staging font origins' );
font_assert( $read['read_only'] === true && $read['mutation_performed'] === false
    && $read['regeneration_authorized'] === false, 'Scan does not authorize repair' );
font_assert( hash_file( 'sha256', $file ) === $before
    && hash_file( 'sha256', $font_file ) === $font_before,
    'Both generated CSS files unchanged' );
$bad = $a->font_css_origin_audit( array( 'production_host' => 'staging.example.org' ) );
font_assert( is_wp_error( $bad ) && $bad->get_error_code() === 'mad4b_elementor_font_css_origin_invalid',
    'Production and Staging origin must differ' );
$bad = $a->font_css_origin_audit( array( 'production_host' => 'https://production.example.org/path' ) );
font_assert( is_wp_error( $bad ), 'No URL or path accepted as a host' );
$GLOBALS['owner'] = false;
font_assert( is_wp_error( $a->font_css_origin_audit( array( 'production_host' => 'production.example.org' ) ) ),
    'Non-owner denied' );
$GLOBALS['owner'] = true;
$GLOBALS['environment'] = 'production';
font_assert( is_wp_error( $a->font_css_origin_audit( array( 'production_host' => 'production.example.org' ) ) ),
    'Production scan route denied' );
$GLOBALS['environment'] = 'staging';
file_put_contents( $file, str_repeat( 'x', 262145 ) );
$limited = $a->font_css_origin_audit( array( 'production_host' => 'production.example.org' ) );
font_assert( $limited['scan_complete'] === false
    && $limited['state'] === 'INCOMPLETE_SCAN', 'Oversized CSS scan does not claim clean state' );
unlink( $file );
$nested_only = $a->font_css_origin_audit( array( 'production_host' => 'production.example.org' ) );
font_assert( $nested_only['scan_complete'] === true
    && $nested_only['production_font_references'] === 1
    && $nested_only['files_scanned'] === 1,
    'Google Fonts CSS alone must be audited when regular Elementor CSS is empty' );
unlink( $font_file );
rmdir( $font_dir );
rmdir( dirname( $font_dir ) );
rmdir( $dir );
rmdir( dirname( $dir ) );
rmdir( $GLOBALS['uploads'] );
echo "ELEMENTOR_FONT_CSS_ORIGIN_AUDIT: PASS (read only)\n";
