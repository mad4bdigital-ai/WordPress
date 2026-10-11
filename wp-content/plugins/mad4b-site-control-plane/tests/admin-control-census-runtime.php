<?php
/** Exact read-only control census regression. PHP 7.4+, no WordPress bootstrap. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['controls_admin'] = true;
function current_user_can( $cap ) { return $GLOBALS['controls_admin'] && 'manage_options' === $cap; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_get_abilities() {
    $abilities = array();
    for ( $i = 0; $i < 230; $i++ ) {
        $abilities[ 'mad4b/control-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ) ] = new stdClass();
    }
    $abilities['secret/private_token'] = new stdClass();
    return $abilities;
}
class MAD4B_SCP_Admin_Workspace {
    public static function inventory() { return array(
        'mad4b-control-plane' => array( 'title' => 'Control Plane', 'url' => 'https://example.test/wp-admin/admin.php?page=mad4b-control-plane' ),
        'mad4b-control-plane-context' => array( 'title' => 'Brand Context', 'url' => 'https://example.test/wp-admin/admin.php?page=mad4b-control-plane-context' ),
    ); }
}
class MAD4B_SCP_Operation_Registry {
    public static function status() {
        return array( 'operations' => array(
            array( 'id' => 'wordpress.preview.tour', 'planner_registered' => true, 'executor_registered' => false ),
        ) );
    }
}
function get_post_types( $args, $output ) { return array( 'post' => (object)array(), 'tours' => (object)array() ); }
function get_taxonomies( $args, $output ) { return array( 'category' => (object)array() ); }
class WP_Block_Type_Registry {
    public static function get_instance() { return new self(); }
    public function get_all_registered() { return array( 'core/paragraph' => new stdClass() ); }
}
class Control_Test_REST_Server {
    public function get_routes() { return array( '/wp/v2/posts' => array( array( 'methods' => 'GET' ) ) ); }
}
function _get_cron_array() {
    return array( 10 => array( 'mad4b_cleanup_job' => array( 'secret-args' => array( 'secret' => 'not-exposed' ) ) ) );
}
$GLOBALS['wp_rest_server'] = new Control_Test_REST_Server();
$GLOBALS['menu'] = array( array( 'MAD4B', 'manage_options', 'mad4b-control-plane' ),
    array( 'Restricted', 'delete_users', 'restricted-admin' ) );
$GLOBALS['submenu'] = array();
$GLOBALS['wp_registered_settings'] = array( 'blogname'=>array(), 'smtp_api_key'=>array() );
$GLOBALS['wp_filter'] = array( 'admin_post_submit_review'=>new stdClass(),
    'wp_ajax_live_search'=>new stdClass(),
    'wp_ajax_nopriv_exposed'=>new stdClass() );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-surface-coverage.php';
function control_check( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$class = 'MAD4B_SCP_Admin_Surface_Coverage';
$seen = array(); $offset = 0; $pages = 0;
do {
    $r = $class::control_census( array( 'kind' => 'abilities', 'offset' => $offset, 'limit' => 96 ) );
    control_check( is_array( $r ) && $r['registry_ready'] === true
        && $r['total_count'] === 231 && $r['returned_count'] <= 96,
        'Ability census must count full catalog despite paginated response' );
    foreach ( $r['items'] as $item ) {
        control_check( !isset( $seen[ $item['name'] ] ), 'Pages cannot repeat a Control' );
        $seen[ $item['name'] ] = true;
        control_check( !$item['write_verified'] && !$item['mcp_transport_verified']
            && $item['wp_admin_ui_binding'] === 'not_individually_certified',
            'Registered Ability must not be mistaken for executed or mapped UI Control' );
    }
    $pages++;
    $offset = $r['next_offset'];
} while ( null !== $offset && $pages < 10 );
control_check( $pages === 3 && count( $seen ) === 231, 'No silent 96/512 catalog cutoff; every Ability is eventually enumerated' );
control_check( ! isset( $seen['secret/private_token'] ), 'Credential-like Control identifiers must be redacted' );
control_check( count( array_filter( array_keys( $seen ), static function( $name ) {
    return 0 === strpos( $name, 'redacted:' );
} ) ) === 1, 'Sensitive Control key is present only as redacted digest' );
$pages_response = $class::control_census( array( 'kind' => 'pages', 'offset' => 0, 'limit' => 96 ) );
control_check( $pages_response['total_count'] === 2 && $pages_response['items'][0]['wp_admin_ui_binding'] === 'route_registered_not_browser_certified',
    'Registered admin pages are navigable, but per-screen browser parity remains unverified' );
$actions = $class::control_census( array( 'kind' => 'admin_post' ) );
$ajax = $class::control_census( array( 'kind' => 'ajax' ) );
control_check( $actions['total_count'] === 1 && $ajax['total_count'] === 1,
    'Administrative hooks are separately registered and no-privilege AJAX is not promoted' );
$governed = $class::control_census( array( 'kind' => 'governed_operations' ) );
$blocks = $class::control_census( array( 'kind' => 'blocks' ) );
$content_types = $class::control_census( array( 'kind' => 'content_types' ) );
$taxonomies = $class::control_census( array( 'kind' => 'taxonomies' ) );
$cron = $class::control_census( array( 'kind' => 'cron_hooks' ) );
$rest = $class::control_census( array( 'kind' => 'rest_routes' ) );
$menus = $class::control_census( array( 'kind' => 'menus' ) );
control_check( $governed['total_count'] === 1 && $governed['items'][0]['executor_registered'] === false,
    'Governed operation has planner but missing executor, never fake UI execution parity' );
control_check( $blocks['total_count'] === 1 && $content_types['total_count'] === 2
    && $taxonomies['total_count'] === 1 && $rest['total_count'] === 1
    && $cron['total_count'] === 1,
    'Every WordPress control family must be observable through bounded pagination' );
control_check( $menus['total_count'] === 1 && $cron['items'][0]['name'] === 'mad4b_cleanup_job'
    && ! isset( $cron['items'][0]['args'] ),
    'Never enumerate inaccessible admin menu or scheduled job arguments' );
$settings = $class::control_census( array( 'kind' => 'settings' ) );
control_check( $settings['total_count'] === 2 && $settings['items'][1]['name'] !== 'smtp_api_key',
    'Registered settings are discoverable without leaking credential names/values' );
control_check( is_wp_error( $class::control_census( array( 'kind' => 'all_and_execute' ) ) )
    && is_wp_error( $class::control_census( array( 'limit' => 5000 ) ) )
    && is_wp_error( $class::control_census( array( 'offset' => -1 ) ) )
    && is_wp_error( $class::control_census( array( 'limit' => '96' ) ) )
    && is_wp_error( $class::control_census( array( 'arbitrary' => true ) ) ),
    'Unknown modes, non-integer and unbounded pages are denied' );
$GLOBALS['controls_admin'] = false;
control_check( is_wp_error( $class::control_census() ), 'Non-admin users cannot enumerate privileged Controls' );
$plugin = dirname( __DIR__ );
$bootstrap = file_get_contents( $plugin . '/mad4b-site-control-plane.php' );
control_check( is_string( $bootstrap )
    && false !== strpos( $bootstrap, "includes/class-mad4b-scp-admin-surface-coverage.php" )
    && false !== strpos( $bootstrap, 'MAD4B_SCP_Admin_Surface_Coverage::boot()' ),
    'Real main-plugin bootstrap must load the Control census Ability/registry before rendering the UI' );
$ui = file_get_contents( $plugin . '/includes/class-mad4b-scp-admin-ui.php' );
control_check( is_string( $ui ) && false !== strpos( $ui, "'controls' => __( 'Controls Coverage'" )
    && false !== strpos( $ui, 'MAD4B_SCP_Admin_Surface_Coverage::control_census(' ),
    'Real WordPress admin must expose the paginated registered-Control directory' );
echo "PASS: dynamic paginated Control census discovers all registered Abilities/pages/admin hooks/settings without implying executable UI parity\n";
