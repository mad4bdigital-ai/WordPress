<?php
/**
 * Regression: native WC country source works in a guest admin-AJAX request
 * even when MAD4B deliberately skips loading its control plane.
 * This is a hermetic unit test; full WP browser tests remain separate.
 */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('COOKIEPATH', '/');
define('WP_PLUGIN_DIR', '/__wpml_not_installed__');
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($name,$value,...$args) {return $value;}
function add_shortcode(...$args) {}
function home_url($path = '/') { return 'http://local.test' . $path; }
function wp_salt($scheme = 'auth') { return 'unit-test-only-key'; }
function wp_json_encode($value) { return json_encode($value); }
function is_ssl() { return false; }
function nocache_headers() {}
function plugins_url($path, $from = '') { return '/plugins/' . $path; }
function wp_send_json_success($data, $status = 200) {
    $GLOBALS['test_ajax_payload'] = $data;
    $GLOBALS['test_ajax_status'] = $status;
}
class WC_Countries {
    public function get_countries() { return ['EG' => 'Egypt', 'SA' => 'Saudi Arabia', 'US' => 'United States']; }
}
class WC_Fake {
    public $countries;
    public $session = null;
    public function __construct() {$this->countries = new WC_Countries();}
}
function WC() { static $wc; return $wc ??= new WC_Fake(); }
require (getenv('SWITCHER_PLUGIN_FILE') ?: __DIR__ . '/../../wp-content/plugins/etg-traveller-nationality-switcher/etg-traveller-nationality-switcher.php');
$n = 'Travel_Booking_Traveller_Nationality_Switcher';
$assert = static function ($what, $ok) {
    if (!$ok) { fwrite(STDERR, "$what=FAIL\n"); exit(1); }
    echo "$what=PASS\n";
};
$assert('MAD4B_SKIPPED', !class_exists('MAD4B_SCP_Switcher_Sources'));
$assert('WOOCOMMERCE_NATIVE_EG', $n::valid('EG') === 'EG');
$assert('SAUDI_NATIONALITY_NOT_MARKET_RESTRICTED', $n::valid('SA') === 'SA');
$assert('INVALID_COUNTRY_REJECTED', $n::valid('ZZ') === '');
$record = ['contract' => $n::CONTRACT, 'site_uuid' => hash('sha256', home_url('/')), 'country' => 'EG', 'saved_at' => time()];
$payload = rtrim(strtr(base64_encode(json_encode($record)), '+/', '-_'), '=');
$_COOKIE[$n::COOKIE] = $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth'));
$assert('SIGNED_COOKIE_GUEST_RESTORED', $n::selected() === 'EG');
$n::ajax_state();
$assert('AJAX_RETURNS_EG_NO_CORE', ($GLOBALS['test_ajax_payload']['country'] ?? '') === 'EG' && $GLOBALS['test_ajax_status'] === 200);
$_COOKIE[$n::COOKIE] = $payload . '.invalid';
$assert('TAMPERED_COOKIE_REJECTED', $n::selected() === '');
echo "AJAX_FALLBACK_FAILURES=0\n";
