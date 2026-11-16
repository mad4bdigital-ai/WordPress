<?php
/**
 * Source-only hermetic REST + Cron admission test.
 * Does not connect to WordPress, touch database, call a provider or mint grants.
 */
define( 'ABSPATH', __DIR__ . '/' );
class WP_REST_Server {
    const READABLE = 'GET';
    const CREATABLE = 'POST';
}
class WP_Error {
    private $code;
    private $data;
    public function __construct( $code, $message = '', $data = array() ) {
        $this->code = $code;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
$GLOBALS['mad4b_cwv_hooks'] = array();
$GLOBALS['mad4b_cwv_routes'] = array();
$GLOBALS['mad4b_cwv_is_admin'] = false;
$GLOBALS['mad4b_cwv_abilities'] = array();
function wp_has_ability( $name ) {
    return isset( $GLOBALS['mad4b_cwv_abilities'][ $name ] );
}
function wp_register_ability( $name, $config ) {
    $GLOBALS['mad4b_cwv_abilities'][ $name ] = $config;
}
function add_action( $name, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['mad4b_cwv_hooks'][] = $name;
}
function register_rest_route( $ns, $route, $args ) {
    $identity = trim( $ns, '/' ) . '/' . ltrim( $route, '/' );
    if ( isset( $GLOBALS['mad4b_cwv_routes'][$identity] ) )
        throw new RuntimeException( 'Duplicate REST route: ' . $identity );
    $GLOBALS['mad4b_cwv_routes'][$identity] = $args;
}
function current_user_can( $capability ) {
    return $capability === 'manage_options' && $GLOBALS['mad4b_cwv_is_admin'];
}
function assert_cwv( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( 'FAIL REST Cron: ' . $message );
}
require dirname( __DIR__ ) .
    '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-core-web-vitals.php';

MAD4B_SCP_Core_Web_Vitals::register_ability();
assert_cwv( array_keys( $GLOBALS['mad4b_cwv_abilities'] ) ===
    array( 'mad4b/core-web-vitals-status' ),
    'Original read-only WordPress Ability missing or extra' );
$ability = $GLOBALS['mad4b_cwv_abilities']['mad4b/core-web-vitals-status'];
assert_cwv( ( $ability['execute_callback'] ?? null ) ===
    array( 'MAD4B_SCP_Core_Web_Vitals', 'status' ),
    'Read-only Ability handler was replaced' );
assert_cwv( ( $ability['permission_callback'] ?? null ) ===
    array( 'MAD4B_SCP_Policy', 'can_read' ),
    'Original WordPress read permission callback missing' );
assert_cwv( ! empty( $ability['meta']['annotations']['readonly'] )
    && empty( $ability['meta']['annotations']['destructive'] )
    && empty( $ability['meta']['mcp']['public'] ),
    'Read-only MCP metadata or public access changed' );

$hooks = array(
    'mad4b_core_web_vitals_daily',
    'mad4b_core_web_vitals_refresh_once',
    'mad4b_core_web_vitals_device_worker',
);
foreach ( $hooks as $hook ) {
    assert_cwv( in_array( $hook, $GLOBALS['mad4b_cwv_hooks'], true ),
        'original hook not owned by Core Web Vitals: ' . $hook );
}
assert_cwv( ! in_array( 'daily', $GLOBALS['mad4b_cwv_hooks'], true ),
    'Schedule recurrence mistakenly registered as hook' );

MAD4B_SCP_Core_Web_Vitals::register_rest_routes();
$prefix = 'mad4b/v1/admin/core-web-vitals';
$routes = array(
    $prefix => array( WP_REST_Server::READABLE, 'rest_read_status' ),
    $prefix . '/lifecycle' => array( WP_REST_Server::READABLE, 'rest_read_lifecycle' ),
    $prefix . '/settings' => array( WP_REST_Server::CREATABLE, 'rest_save_settings' ),
    $prefix . '/refresh' => array( WP_REST_Server::CREATABLE, 'rest_queue_refresh' ),
);
assert_cwv( count( $GLOBALS['mad4b_cwv_routes'] ) === count( $routes ),
    'Unexpected or duplicated administrative REST routes' );
foreach ( $routes as $route => $expected ) {
    $options = isset( $GLOBALS['mad4b_cwv_routes'][$route] )
        ? $GLOBALS['mad4b_cwv_routes'][$route] : null;
    assert_cwv( is_array( $options ), 'Missing source REST route: ' . $route );
    assert_cwv( ( $options['methods'] ?? null ) === $expected[0],
        'Unexpected REST method: ' . $route );
    assert_cwv( isset( $options['permission_callback'] ) &&
        $options['permission_callback'] ===
        array( 'MAD4B_SCP_Core_Web_Vitals', 'rest_admin_permission' ),
        'Missing per-route admin admission: ' . $route );
    assert_cwv( ( $options['callback'] ?? null ) ===
        array( 'MAD4B_SCP_Core_Web_Vitals', $expected[1] ),
        'Unexpected WordPress REST handler: ' . $route );
    $GLOBALS['mad4b_cwv_is_admin'] = false;
    $denied = call_user_func( $options['permission_callback'] );
    assert_cwv( $denied instanceof WP_Error
        && $denied->get_error_code() === 'mad4b_cwv_admin_required'
        && ( $denied->get_error_data()['status'] ?? null ) === 403,
        'Guest REST admission not denied: ' . $route );
    $GLOBALS['mad4b_cwv_is_admin'] = true;
    assert_cwv( call_user_func( $options['permission_callback'] ) === true,
        'Admin REST permission unexpectedly denied: ' . $route );
}
echo "CWV REST CRON SOURCE HERMITIC: PASS 4 admin routes / 3 original hooks\n";// Audited positive exit marker; all denial checks must complete first.
echo "CWV_REST_CRON_SOURCE: PASS\n";
