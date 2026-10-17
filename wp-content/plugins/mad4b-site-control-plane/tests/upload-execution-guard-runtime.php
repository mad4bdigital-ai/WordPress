<?php
/** Disposable no-WordPress regression fixture: no network or WordPress mutation. */
$root = sys_get_temp_dir() . '/mad4b-upload-guard-' . bin2hex( random_bytes( 6 ) );
mkdir( $root ); mkdir( $root . '/wp-content' ); mkdir( $root . '/wp-content/uploads' ); mkdir( $root . '/elsewhere' );
define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
$registry = array(); $auth = true; $profile = true;
$upload = array( 'basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'https://staging.example.test/wp-content/uploads', 'error' => false );
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
function add_action( $name, $cb, $priority ) { $GLOBALS['registered_hook'] = $name; }
function wp_has_ability( $name ) { return isset( $GLOBALS['registry'][ $name ] ); }
function wp_register_ability( $name, $definition ) { $GLOBALS['registry'][ $name ] = $definition; return (object) array(); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function current_user_can( $capability ) { return $GLOBALS['auth']; }
function get_current_user_id() { return 1; }
function wp_upload_dir( $time, $create_dir, $refresh ) {
    if ( $create_dir || $refresh ) throw new RuntimeException( 'Side-effectful upload_dir arguments' );
    return $GLOBALS['upload'];
}
function get_current_blog_id() { return 3; }
function is_multisite() { return true; }
function home_url( $path ) { return 'https://staging.example.test/'; }
class MAD4B_SCP_Policy {
    public static function can_read() { return $GLOBALS['auth']; }
    public static function can_connect_user( $id ) { return $GLOBALS['auth']; }
}
class MAD4B_SCP_Site_Profile {
    public static function configured() { return $GLOBALS['profile']; }
    public static function origin_enrolled() { return $GLOBALS['profile']; }
    public static function site_urls_match_enrollment() { return $GLOBALS['profile']; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-upload-execution-guard.php';
$n = 0;
$ok = function( $condition, $message ) use ( &$n ) {
    ++$n;
    if ( ! $condition ) throw new RuntimeException( 'FAIL ' . $n . ': ' . $message );
};
try {
    MAD4B_SCP_Upload_Execution_Guard::boot();
    $ok( $GLOBALS['registered_hook'] === 'wp_abilities_api_init', 'abilities lifecycle hook' );
    MAD4B_SCP_Upload_Execution_Guard::register_abilities();
    $ok( count( $registry ) === 2, 'two registered read abilities' );
    foreach ( $registry as $definition ) {
        $ok( $definition['meta']['annotations']['readonly'] === true, 'read-only annotation' );
        $ok( $definition['meta']['mcp']['public'] === false, 'non-public MCP' );
        $ok( $definition['permission_callback'] === array( 'MAD4B_SCP_Upload_Execution_Guard', 'can_read' ), 'governed read' );
    }
    $plan = MAD4B_SCP_Upload_Execution_Guard::policy_plan();
    $ok( $plan['proposed_route'] === 'HTACCESS_CANDIDATE_NEEDS_OVERRIDE_AND_HANDLER_VERIFICATION', 'Apache only candidate' );
    $ok( $plan['execution_authorized'] === false && $plan['config_mutation_available'] === false, 'no write authorization' );
    $res = MAD4B_SCP_Upload_Execution_Guard::discover();
    $ok( $res['uploads']['status'] === 'UNVERIFIED_NEEDS_ORIGIN_PROBE', 'no false protection' );
    $ok( $res['uploads']['wordpress_content_relative_path'] === 'uploads', 'relative path only' );
    $ok( $res['uploads']['site_id'] === 3 && $res['multisite_network_inventoried'] === false, 'multisite current site only' );
    $ok( !$res['site_protected_certified'] && !$res['direct_php_execution_verified_blocked'], 'no false attestation' );
    $ok( !$res['mutation_performed'] && !$res['network_requests_performed'], 'no effects' );
    $ok( is_wp_error( MAD4B_SCP_Upload_Execution_Guard::discover( array( 'basedir' => '/tmp' ) ) ), 'no user paths' );
    file_put_contents( WP_CONTENT_DIR . '/uploads/.htaccess', "# MAD4B-UPLOAD-EXECUTION-GUARD:v1\n" );
    $res = MAD4B_SCP_Upload_Execution_Guard::discover();
    $ok( $res['uploads']['htaccess_marker_present'] && !$res['site_protected_certified'], 'marker not enforcement' );
    $GLOBALS['upload']['baseurl'] = 'https://cdn.example.test/uploads';
    $res = MAD4B_SCP_Upload_Execution_Guard::discover();
    $ok( $res['uploads']['status'] === 'REMOTE_OR_CDN_UPLOAD_URL', 'CDN requires mapping' );
    $GLOBALS['upload']['baseurl'] = 'https://staging.example.test/wp-content/uploads';
    $GLOBALS['upload']['basedir'] = ABSPATH . 'elsewhere';
    $res = MAD4B_SCP_Upload_Execution_Guard::discover();
    $ok( $res['uploads']['status'] === 'UPLOADS_OUTSIDE_CONTENT_ROOT', 'unmapped path' );
    $GLOBALS['upload']['basedir'] = WP_CONTENT_DIR . '/uploads';
    $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26';
    $plan = MAD4B_SCP_Upload_Execution_Guard::policy_plan();
    $ok( $plan['proposed_route'] === 'NGINX_HOST_CONFIG_REQUIRED', 'Nginx does not use htaccess' );
    $GLOBALS['auth'] = false;
    $ok( is_wp_error( MAD4B_SCP_Upload_Execution_Guard::discover() ), 'permission failure' );
    $GLOBALS['auth'] = true; $GLOBALS['profile'] = false;
    $ok( is_wp_error( MAD4B_SCP_Upload_Execution_Guard::policy_plan() ), 'profile mismatch' );
    $GLOBALS['profile'] = true;
    symlink( WP_CONTENT_DIR . '/uploads', WP_CONTENT_DIR . '/linked-uploads' );
    $GLOBALS['upload']['basedir'] = WP_CONTENT_DIR . '/linked-uploads';
    $res = MAD4B_SCP_Upload_Execution_Guard::discover();
    $ok( $res['uploads']['status'] === 'SYMLINK_REQUIRES_HOST_REVIEW', 'symlink must fail closed' );
    echo 'PASS upload execution guard: ' . $n . " checks\n";
} finally {
    @unlink( WP_CONTENT_DIR . '/linked-uploads' );
    @unlink( WP_CONTENT_DIR . '/uploads/.htaccess' );
    @rmdir( WP_CONTENT_DIR . '/uploads' ); @rmdir( WP_CONTENT_DIR );
    @rmdir( ABSPATH . 'elsewhere' ); @rmdir( ABSPATH );
}
