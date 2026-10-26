<?php
/** Hermetic runtime assertions for market choice, signed preferences and PHP 7.4. */
define('ABSPATH', __DIR__);
$GLOBALS['session_data'] = [];
$GLOBALS['mock_options'] = [
    'woocommerce_currency' => 'USD',
    '_wcml_settings' => ['currency_options' => ['USD' => [], 'EUR' => []]],
];
$GLOBALS['mock_exclusions'] = ['countries' => ['SA'], 'languages' => []];
function get_option($key, $fallback = []) {
    return $GLOBALS['mock_options'][$key] ?? $fallback;
}
function sanitize_key($s) {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$s));
}
function wp_salt($key = 'auth') {
    return 'hermetic-signed-preferences-salt';
}
function wp_json_encode($v) { return json_encode($v); }
function WC() {
    return $GLOBALS['mock_wc'];
}
class WP_Error {
    private $code;
    public function __construct($code = 'error', $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
class Local_Session {
    public function get($key, $default = null) { return $GLOBALS['session_data'][$key] ?? $default; }
    public function set($key, $value) { $GLOBALS['session_data'][$key] = $value; }
    public function __unset($key) { unset($GLOBALS['session_data'][$key]); }
    public function set_customer_session_cookie($force = false) {}
}
$GLOBALS['mock_wc'] = (object)['session' => new Local_Session()];
class MAD4B_SCP_Market_Profiles {
    public static function site_uuid() { return 'test-site-uuid'; }
    public static function enabled_languages() { return ['en' => 'English', 'es' => 'Spanish']; }
    public static function targeted_languages() { return self::enabled_languages(); }
    public static function is_targetable($country, $language) {
        return isset(self::enabled_languages()[$language])
            && !in_array($country, $GLOBALS['mock_exclusions']['countries'], true)
            && !in_array($language, $GLOBALS['mock_exclusions']['languages'], true);
    }
    public static function approved($id) {
        if ($id !== 'us-en') return [];
        return ['profile_id' => 'us-en', 'site_uuid' => self::site_uuid(),
            'language' => 'en', 'country' => 'US', 'revision' => 4];
    }
    public static function by_country($country, $lang = '') {
        return $country === 'US' && $lang === 'en' ? self::approved('us-en') : [];
    }
}
class MAD4B_SCP_Market_Context {
    const SESSION_KEY = 'mock-selected-market';
    const CONTRACT = 'market-context.test';
    public static function country($iso) { return in_array($iso, ['US', 'SA', 'ES'], true) ? $iso : ''; }
    public static function woo_geo_hint() { return 'US'; }
    public static function language() { return 'en'; }
}
require __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-market-preferences.php';
$class = 'MAD4B_SCP_Market_Preferences';
$failures = 0;
$check = static function ($name, $ok) use (&$failures) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$ok) $failures++;
};
$record = ['contract' => $class::CONTRACT, 'site_uuid' => 'test-site-uuid',
    'language' => 'en', 'currency' => 'USD', 'market_profile_id' => 'us-en', 'revision' => 4];
$payload = rtrim(strtr(base64_encode(json_encode($record)), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $payload, wp_salt('auth'));
$_COOKIE[$class::COOKIE] = $payload . '.' . $signature;
$check('VALID_SIGNED_COOKIE_RESTORED', $class::saved()['market_profile_id'] === 'us-en');
$_COOKIE[$class::COOKIE] = $payload . '.' . str_repeat('0', 64);
$check('TAMPERED_SIGNATURE_REJECTED', $class::saved() === []);
$_COOKIE[$class::COOKIE] = $payload;
$check('UNSIGNED_COOKIE_REJECTED_ON_PHP74', $class::saved() === []);
$record['site_uuid'] = 'different-site';
$payload = rtrim(strtr(base64_encode(json_encode($record)), '+/', '-_'), '=');
$_COOKIE[$class::COOKIE] = $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth'));
$check('OTHER_SITE_COOKIE_REJECTED', $class::saved() === []);
unset($_COOKIE[$class::COOKIE]);
$check('GEO_HINT_NOT_AUTO_APPROVAL', $class::defaults('SA')['market_approved'] === false);
$check('NO_CURRENCY_NOT_OFFERED', !in_array('SAR', $class::currencies(), true));
$check('PROFILES_REQUIRE_ACTIVE_LANGUAGE', $class::defaults('US')['market_approved'] === true);
$check('UNSUPPORTED_CURRENCY_REJECTED', is_wp_error($class::set('en', 'SAR', 'us-en')));
$check('UNAPPROVED_MARKET_REJECTED', is_wp_error($class::set('en', 'USD', 'other')));
exit($failures ? 1 : 0);
