<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'LSCWP_V', '7.9.1' );
$GLOBALS['litespeed_getter_mounted'] = false;
$GLOBALS['litespeed_filter_calls'] = 0;
$GLOBALS['litespeed_write_calls'] = 0;
$GLOBALS['litespeed_bad_option'] = false;
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function has_filter( $hook ) {
    return $hook === 'litespeed_conf' && $GLOBALS['litespeed_getter_mounted'];
}
function apply_filters( $hook, $slug ) {
    if ( $hook !== 'litespeed_conf' ) throw new Exception('Unexpected vendor hook');
    ++$GLOBALS['litespeed_filter_calls'];
    if ( $GLOBALS['litespeed_bad_option'] && $slug === 'optm-js_exc' )
        return array( new stdClass() );
    if ( $slug === 'optm-js_defer' ) return 2;
    if ( $slug === 'optm-js_exc' ) return array( 'wp-includes/js/wp-hooks' );
    if ( substr( $slug, 0, 5 ) === 'optm-' && substr( $slug, -4 ) === '_exc' )
        return array();
    if ( $slug === 'optm-js_delay_inc' || $slug === 'optm-gm_js_exc' ) return array();
    return false;
}
function do_action( $hook, $input = null ) {
    if ( strpos( $hook, 'litespeed_' ) === 0 ) ++$GLOBALS['litespeed_write_calls'];
}
function assert_case( $passed, $name ) {
    if ( ! $passed ) { fwrite( STDERR, 'FAIL ' . $name . "\n" ); exit( 1 ); }
}
abstract class MAD4B_SCP_Adapter_Base {
    abstract public function id();
    abstract public function label();
    abstract public function is_available();
    abstract public function ability_names();
    abstract public function register_abilities();
    public function runtime_version() { return defined( 'LSCWP_V' ) ? LSCWP_V : ''; }
    protected function unavailable_error() { return new WP_Error( 'unavailable', 'not available' ); }
}
require dirname(__DIR__) . '/includes/adapters/class-mad4b-scp-litespeed-adapter.php';
$adapter = new MAD4B_SCP_LiteSpeed_Adapter();
$unbound = $adapter->get_optimization_settings();
assert_case( $unbound['state'] === 'CAPABILITY_GAP'
    && ! $unbound['setting_values_read'], 'unbound vendor API cannot be faked' );
$GLOBALS['litespeed_getter_mounted'] = true;
$observed = $adapter->get_optimization_settings();
assert_case( $observed['state'] === 'NATIVE_SETTINGS_READ_OBSERVED'
    && $observed['setting_values_read'], 'documented getter used' );
assert_case( count( $observed['settings'] ) === 10 && $observed['settings']['js_defer']['value'] === 2,
    'bounded actual value read' );
assert_case( preg_match('/^[a-f0-9]{64}$/', $observed['snapshot_sha256']) === 1,
    'readback CAS fingerprint' );
assert_case( $GLOBALS['litespeed_filter_calls'] === 10
    && $GLOBALS['litespeed_write_calls'] === 0, 'no mutation or vendor update' );
$GLOBALS['litespeed_bad_option'] = true;
$bad = $adapter->get_optimization_settings();
assert_case( is_wp_error( $bad ) && $bad->get_error_code() === 'mad4b_litespeed_setting_invalid',
    'invalid option shape blocked without partial snapshot' );
echo "LITESPEED_OPTIMIZATION_HOOK_RUNTIME_PASS\n";
