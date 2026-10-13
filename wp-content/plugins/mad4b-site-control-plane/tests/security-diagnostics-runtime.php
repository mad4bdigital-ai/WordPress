<?php
/**
 * Native PHP fixture: bounded read-only security diagnostics.
 * php tests/security-diagnostics-runtime.php
 * Uses only disposable fixture files under sys_get_temp_dir(); no WP/network.
 */
$base = sys_get_temp_dir() . '/mad4b-security-fixture-' . bin2hex( random_bytes( 5 ) );
mkdir( $base );
define( 'ABSPATH', $base . '/' );
define( 'WP_CONTENT_DIR', $base . '/wp-content' );
define( 'ARRAY_A', 'ARRAY_A' );
mkdir( WP_CONTENT_DIR );
mkdir( WP_CONTENT_DIR . '/uploads' );
mkdir( WP_CONTENT_DIR . '/plugins' );
mkdir( WP_CONTENT_DIR . '/themes' );
mkdir( WP_CONTENT_DIR . '/mu-plugins' );
file_put_contents( ABSPATH . 'index.php', '<?php // trusted fixture' );
file_put_contents( WP_CONTENT_DIR . '/uploads/suspect.php', '<?php // powershell code reference' );
file_put_contents( WP_CONTENT_DIR . '/uploads/normal.js', 'console.log("ordinary");' );
file_put_contents( WP_CONTENT_DIR . '/uploads/remote.js', "const a='browse-veriid.beer'; eth_call; document.createElement('script');" );

class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $cap ) { return $GLOBALS['permitted'] && $cap === 'manage_options'; }
function get_current_user_id() { return 19; }
function add_action( $name, $callback, $priority ) { $GLOBALS['hooks'][] = array( $name, $priority ); }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) {
    $GLOBALS['abilities'][ $name ] = $args;
    return (object) array( 'name' => $name );
}
function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
    if ( $create_dir ) throw new RuntimeException( 'Unsafe upload_dir creation' );
    return array( 'basedir' => WP_CONTENT_DIR . '/uploads', 'error' => false );
}
function get_option( $name, $default = null ) { return $name === 'active_plugins' ? array( 'a/a.php' ) : $default; }
function get_site_transient( $key ) { return false; }
function get_bloginfo( $key ) { return '6.9.0'; }
function wp_get_environment_type() { return 'staging'; }
function force_ssl_admin() { return true; }
class MAD4B_SCP_Policy {
    public static function can_read() { return $GLOBALS['permitted']; }
    public static function can_connect_user( $id ) { return 19 === $id && $GLOBALS['connected']; }
}
class MAD4B_SCP_Site_Profile {
    public static function configured() { return $GLOBALS['enrolled']; }
    public static function origin_enrolled() { return $GLOBALS['enrolled']; }
    public static function site_urls_match_enrollment() { return $GLOBALS['enrolled']; }
}
class FixtureDB {
    public $options = 'wp_options';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $sql = array();
    public function get_results( $sql, $format ) {
        if ( ! preg_match( '/^SELECT .* FROM wp_(options|posts|postmeta) ORDER BY .* DESC LIMIT 100$/D', $sql )
            || $format !== ARRAY_A ) throw new RuntimeException( 'Unsafe query' );
        $this->sql[] = $sql;
        return array(
            array( 'record_id' => 9, 'record_label' => 'private_widget_config',
                'value_sample' => '<script>const server="browse-veriid.beer"; var x="secret-token";</script>' ),
            array( 'record_id' => 8, 'record_label' => 'ordinary', 'value_sample' => 'harmless' ),
        );
    }
}
$wpdb = new FixtureDB();
$GLOBALS['permitted'] = true;
$GLOBALS['connected'] = true;
$GLOBALS['enrolled'] = true;
$GLOBALS['abilities'] = array();
$GLOBALS['hooks'] = array();
require __DIR__ . '/../includes/class-mad4b-scp-security-diagnostics.php';
$check = static function ( $assertion, $message ) { if ( ! $assertion ) throw new RuntimeException( $message ); };
try {
    MAD4B_SCP_Security_Diagnostics::boot();
    $check( $GLOBALS['hooks'] === array( array( 'wp_abilities_api_init', 44 ) ), 'Hook did not register' );
    MAD4B_SCP_Security_Diagnostics::register_abilities();
    $names = array( 'mad4b/security-site-posture', 'mad4b/security-file-indicators',
        'mad4b/security-file-integrity', 'mad4b/security-database-indicators' );
    foreach ( $names as $name ) {
        $check( isset( $GLOBALS['abilities'][ $name ] ), 'Ability missing: ' . $name );
        $a = $GLOBALS['abilities'][ $name ];
        $check( $a['meta']['annotations']['readonly'] && !$a['meta']['public']
            && !$a['meta']['show_in_rest'] && $a['meta']['mcp']['surface'] === 'read'
            && $a['permission_callback'] === array( 'MAD4B_SCP_Security_Diagnostics', 'can_read' ),
            'Ability grants unsafe access' );
    }
    $check( MAD4B_SCP_Security_Diagnostics::can_read(), 'Read scope unexpectedly denied' );
    $posture = MAD4B_SCP_Security_Diagnostics::posture();
    $check( is_array( $posture ) && !$posture['site_clean_certified']
        && !$posture['core_checksum_verified'] && !$posture['network_requests_performed'],
        'Posture claimed a clean site' );
    $scan = MAD4B_SCP_Security_Diagnostics::file_indicators( array(
        'scope' => 'uploads', 'indicator_host' => 'browse-veriid.beer' ) );
    $check( is_array( $scan ) && $scan['files_sampled'] === 3 && !$scan['no_findings_means_clean'],
        'File sample unbounded or claimed safety' );
    $result = json_encode( $scan );
    $check( strpos( $result, 'suspect.php' ) !== false && strpos( $result, 'remote.js' ) !== false
        && strpos( $result, 'normal.js' ) === false && strpos( $result, 'const a=' ) === false,
        'Finding classification or raw-content redaction failed' );
    $check( is_wp_error( MAD4B_SCP_Security_Diagnostics::file_indicators( array( 'scope' => '../' ) ) ),
        'Untrusted traversal scope accepted' );
    $check( is_wp_error( MAD4B_SCP_Security_Diagnostics::file_indicators(
        array( 'scope' => 'uploads', 'path' => '/etc/passwd' ) ) ), 'Arbitrary path accepted' );
    $check( is_wp_error( MAD4B_SCP_Security_Diagnostics::file_indicators(
        array( 'scope' => 'uploads', 'indicator_host' => 'http://localhost/a' ) ) ), 'Unsafe URL accepted' );
    $integrity = MAD4B_SCP_Security_Diagnostics::file_integrity( array(
        'target' => 'index.php', 'expected_sha256' => hash_file( 'sha256', ABSPATH . 'index.php' ) ) );
    $check( is_array( $integrity ) && $integrity['matches_supplied_baseline'] === true
        && $integrity['baseline_trust_verified'] === false, 'Comparison falsely certified baseline' );
    $check( is_wp_error( MAD4B_SCP_Security_Diagnostics::file_integrity( array(
        'target' => 'wp-config.php' ) ) ), 'Secret-containing target accessible' );
    $db = MAD4B_SCP_Security_Diagnostics::database_indicators( array(
        'surface' => 'options', 'indicator_host' => 'browse-veriid.beer' ) );
    $encoded = json_encode( $db );
    $check( count( $wpdb->sql ) === 1 && count( $db['findings'] ) === 1
        && strpos( $encoded, 'private_widget_config' ) === false
        && strpos( $encoded, 'secret-token' ) === false
        && strpos( $encoded, 'browse-veriid.beer' ) === false,
        'Database sample leaks raw data' );
    $GLOBALS['connected'] = false;
    $check( is_wp_error( MAD4B_SCP_Security_Diagnostics::database_indicators( array( 'surface' => 'options' ) ) )
        && count( $wpdb->sql ) === 1, 'Revoked connector still read database' );
    $GLOBALS['connected'] = true;
    $GLOBALS['permitted'] = false;
    $check( is_wp_error( MAD4B_SCP_Security_Diagnostics::posture() ),
        'Permission gate bypassed' );
    $GLOBALS['permitted'] = true;
    $GLOBALS['enrolled'] = false;
    $check( is_wp_error( MAD4B_SCP_Security_Diagnostics::file_integrity( array( 'target' => 'index.php' ) ) ),
        'Site enrollment bypassed' );
    echo "PASS security diagnostics: registered 4 read abilities; bounded file/DB samples, no leaks, strict scope, integrity comparison, revocation and enrollment\n";
} finally {
    foreach ( array( 'index.php', 'wp-content/uploads/suspect.php',
        'wp-content/uploads/normal.js', 'wp-content/uploads/remote.js' ) as $file ) @unlink( $base . '/' . $file );
    foreach ( array( 'wp-content/uploads', 'wp-content/plugins', 'wp-content/themes',
        'wp-content/mu-plugins', 'wp-content' ) as $dir ) @rmdir( $base . '/' . $dir );
    @rmdir( $base );
}
