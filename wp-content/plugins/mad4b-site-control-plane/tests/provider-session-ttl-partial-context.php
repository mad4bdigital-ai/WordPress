<?php
/** Partial provider class must never get an invented OAuth lifetime. */
define( 'ABSPATH', '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
if ( in_array( '--valid-provider', $argv, true ) ) {
    class MAD4B_SCP_Google_Drive_Context {
        public static function authorization_session_ttl_seconds() { return 600; }
    }
} else {
    class MAD4B_SCP_Google_Drive_Context { // Older/lazy provider, no TTL method.
        public static function auth_mode() { throw new RuntimeException('OAuth consent must not be started'); }
    }
}
function add_action() {}
$GLOBALS['captured_provider_routes'] = array();
function register_rest_route( $namespace, $route, $args ) {
    if ( 'mad4b/v1' !== $namespace || ! is_array( $args )
        || ! isset( $args['permission_callback'], $args['callback'] ) ) {
        throw new RuntimeException('Invalid or unguarded provider route');
    }
    $GLOBALS['captured_provider_routes'][ $route ] = $args;
}
// All existing REST routes must remain permission-gated even if the
// connected provider is a partial class. This fixture has no authenticated
// WordPress session, token or enrolled owner, so every route denies.
function current_user_can( $cap ) { return false; }
class MAD4B_SCP_Policy { public static function can_read() { return false; } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-connection-center.php';
$ttl = new ReflectionMethod('MAD4B_SCP_Provider_Connection_Center','native_oauth_session_ttl_seconds');
if ( PHP_VERSION_ID < 80100 ) $ttl->setAccessible( true );
$expect = in_array( '--valid-provider', $argv, true ) ? 600 : 0;
if ( $expect !== $ttl->invoke(null) ) {
    fwrite(STDERR,"FAIL: Provider OAuth TTL not preserved or absent consent became eligible\n");
    exit(1);
}
$controller = 'MAD4B_SCP_Provider_Connection_Center';
$controller::register_rest_routes();
$routes = array(
    '/provider-connections' => 'rest_read_permission',
    '/provider-connections/(?P<provider>[a-z][a-z0-9_-]{0,63})/plan' => 'rest_read_permission',
    '/provider-connections/(?P<provider>[a-z][a-z0-9_-]{0,63})/refresh' => 'rest_refresh_permission',
    '/provider-connections/(?P<provider>[a-z][a-z0-9_-]{0,63})/reconnect' => 'rest_reconnect_permission',
    '/provider-connections/(?P<provider>[a-z][a-z0-9_-]{0,63})/reconnect/complete' => 'rest_reconnect_complete_permission',
);
if ( array_keys( $routes ) !== array_keys( $GLOBALS['captured_provider_routes'] ) ) {
    fwrite( STDERR, "FAIL: Provider route set changed without independent contract review\n" );
    exit(1);
}
foreach ( $routes as $route => $permission ) {
    $args = $GLOBALS['captured_provider_routes'][ $route ];
    if ( $args['permission_callback'] !== array( $controller, $permission )
        || !in_array( $args['methods'], array( 'GET','POST' ), true ) ) {
        fwrite( STDERR, "FAIL: Provider route permission binding changed: $route\n" );
        exit(1);
    }
}
echo "PROVIDER_REST_FIVE_PERMISSION_CALLBACKS_PINNED: PASS\n";
foreach ( array(
    'rest_read_permission',
    'rest_refresh_permission',
    'rest_reconnect_permission',
    'rest_reconnect_complete_permission',
) as $method ) {
    $denied = $controller::$method( null );
    if ( ! ( $denied instanceof WP_Error ) ) {
        fwrite( STDERR, "FAIL: unauthenticated REST provider route admitted: $method\n" );
        exit( 1 );
    }
}
echo "PROVIDER_REST_ANONYMOUS_ACCESS_DENIED: PASS\n";
echo $expect ? "PROVIDER_CERTIFIED_TTL_PRESERVED: PASS\n" : "PROVIDER_PARTIAL_OAUTH_TTL_DENIED: PASS\n";
