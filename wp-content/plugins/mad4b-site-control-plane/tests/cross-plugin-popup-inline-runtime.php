<?php
/** Hermetic AJAX preview contract: read-only, nonce/role/profile-gated. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/mad4b-ajax-fixture/' );
$GLOBALS['capable'] = true;
$GLOBALS['nonce_ok'] = true;
$GLOBALS['enrolled'] = true;
$GLOBALS['site_environment'] = 'staging';
$GLOBALS['wp_environment'] = 'staging';
$GLOBALS['mode'] = 'pending';
$GLOBALS['plan_calls'] = 0;
$GLOBALS['hooks'] = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    'source_id' => 'etg-dynamic-filter-seo-bridge',
    'ref_type' => 'commit', 'reference' => str_repeat( 'a', 40 ),
    'reason' => 'Verify exact reviewed source'
);
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $obj ) { return $obj instanceof WP_Error; }
function current_user_can( $cap ) { return $GLOBALS['capable'] && 'manage_options' === $cap; }
function wp_get_environment_type() { return $GLOBALS['wp_environment']; }
function check_ajax_referer( $action, $field, $die = true ) {
    return 'mad4b_cross_plugin_preview' === $action && '_wpnonce' === $field && ! $die && $GLOBALS['nonce_ok'];
}
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_send_json_error( $data, $code = null ) { $GLOBALS['response'] = array( 'success' => false, 'data' => $data, 'code' => $code ); }
function wp_send_json_success( $data, $code = null ) { $GLOBALS['response'] = array( 'success' => true, 'data' => $data, 'code' => $code ); }
function add_action( $name, $callable ) { $GLOBALS['hooks'][$name] = $callable; }
class MAD4B_SCP_Admin_Route_Registry {
    public static function register( $page, $capability ) {}
    public static function schedule_submenu( $callback, $priority ) {}
}
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['site_environment']; }
    public static function origin_enrolled() { return $GLOBALS['enrolled']; }
}
class MAD4B_SCP_Cross_Plugin_Deployment {
    public static function plan( $input ) {
        $GLOBALS['plan_calls']++;
        $GLOBALS['last_plan_input'] = $input;
        if ( 'error' === $GLOBALS['mode'] ) return new WP_Error( 'untrusted_source' );
        return array(
            'eligible' => 'eligible' === $GLOBALS['mode'],
            'operation' => 'install',
            'resolved_sha' => str_repeat( 'a', 40 ),
            'plugin_file' => 'etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php',
            'blockers' => 'eligible' === $GLOBALS['mode'] ? array() : array( 'exact_zip_artifact_not_certified' ),
            'native_apply_input' => array( 'forbidden' => 'do not expose' )
        );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-cross-plugin-admin.php';
function ensure( $pass, $why ) {
    if ( ! $pass ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); }
}
function run_preview() {
    $GLOBALS['response'] = null;
    MAD4B_SCP_Cross_Plugin_Admin::handle_ajax_preview();
    return $GLOBALS['response'];
}
MAD4B_SCP_Cross_Plugin_Admin::boot();
ensure( isset( $GLOBALS['hooks']['wp_ajax_mad4b_cross_plugin_preview_ajax'] ), 'AJAX hook was not registered' );
ensure( ! isset( $GLOBALS['hooks']['wp_ajax_nopriv_mad4b_cross_plugin_preview_ajax'] ), 'unprivileged AJAX hook exposed' );
$result = run_preview();
ensure( $result['success'] && 'NEEDS_REVIEW' === $result['data']['state'], 'pending certificate shown eligible' );
ensure( $result['data']['blockers'][0] === 'exact_zip_artifact_not_certified', 'pending certificate blockers omitted' );
ensure( false === $result['data']['mutation_performed'] && false === $result['data']['authorizing'], 'read preview authorized mutation' );
ensure( ! isset( $result['data']['native_apply_input'] ), 'executor input leaked into AJAX response' );
$GLOBALS['mode'] = 'eligible'; $result = run_preview();
ensure( $result['success'] && 'PLAN_ELIGIBLE_NOT_AUTHORIZED' === $result['data']['state'], 'eligible plan treated as approval' );
$before = $GLOBALS['plan_calls'];
$GLOBALS['capable'] = false; $result = run_preview();
ensure( ! $result['success'] && 403 === $result['code'] && $GLOBALS['plan_calls'] === $before, 'missing role reached planner' );
$GLOBALS['capable'] = true; $GLOBALS['nonce_ok'] = false; $result = run_preview();
ensure( ! $result['success'] && 403 === $result['code'] && $GLOBALS['plan_calls'] === $before, 'missing nonce reached planner' );
$GLOBALS['nonce_ok'] = true; $GLOBALS['site_environment'] = 'production'; $result = run_preview();
ensure( ! $result['success'] && 403 === $result['code'] && $GLOBALS['plan_calls'] === $before, 'profile mismatch reached planner' );
$GLOBALS['site_environment'] = 'staging'; $GLOBALS['enrolled'] = false; $result = run_preview();
ensure( ! $result['success'] && 403 === $result['code'] && $GLOBALS['plan_calls'] === $before, 'unenrolled origin reached planner' );
$GLOBALS['enrolled'] = true; $_POST['reason'] = 'no'; $result = run_preview();
ensure( ! $result['success'] && 400 === $result['code'] && $GLOBALS['plan_calls'] === $before, 'short audit reason reached planner' );
$_POST['reason'] = 'Verify exact reviewed source'; $GLOBALS['mode'] = 'error'; $result = run_preview();
ensure( ! $result['success'] && 400 === $result['code'] && $result['data']['blockers'][0] === 'untrusted_source', 'planner error not bounded' );
echo "CROSS_PLUGIN_POPUP_INLINE: PASS\n";
