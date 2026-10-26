<?php
/** Hermetic market context tests; no WordPress, database, IP lookup or billing writes. */
define('ABSPATH', __DIR__);
$GLOBALS['mock_session'] = [];
$GLOBALS['sent_payload'] = [];
$GLOBALS['market_hints'] = ['US', 'EG'];
function WC() { return $GLOBALS['wc_mock']; }
function get_locale() { return 'en_US'; }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$v)); }
function nocache_headers() { $GLOBALS['no_cache'] = true; }
function wp_send_json_success($payload, $status = 200) {
    $GLOBALS['sent_payload'] = [$payload, $status];
}
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
class Mock_Countries {
    public function get_countries() { return ['US' => 'United States', 'EG' => 'Egypt', 'SA' => 'Saudi Arabia']; }
}
class Mock_Session {
    public function get($key, $default = []) { return $GLOBALS['mock_session'][$key] ?? $default; }
    public function set($key, $val) { $GLOBALS['mock_session'][$key] = $val; }
    public function __unset($key) { unset($GLOBALS['mock_session'][$key]); }
    public function set_customer_session_cookie($force) {}
}
$GLOBALS['wc_mock'] = (object)['countries' => new Mock_Countries(),
    'session' => new Mock_Session(), 'integrations' => null];
class MAD4B_SCP_Market_Profiles {
    public static function enabled_languages() { return ['en' => 'English']; }
    public static function site_uuid() { return 'test-site'; }
    public static function approved($id) {
        if ($id !== 'us-en') return [];
        return ['profile_id' => 'us-en', 'language' => 'en', 'country' => 'US',
            'site_uuid' => 'test-site', 'revision' => 4,
            'label' => 'US English', 'private_metadata' => 'must never leak'];
    }
    public static function by_country($country, $language = '') {
        return $country === 'US' && $language === 'en' ? self::approved('us-en') : [];
    }
    public static function approved_list() { return [self::approved('us-en')]; }
}
require __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-market-context.php';
$class = 'MAD4B_SCP_Market_Context';
$fail = 0;
$check = static function ($name, $ok) use (&$fail) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$ok) $fail++;
};
$check('UNAPPROVED_MARKET_NOT_SELECTED', $class::evaluate('unknown', 'en', 'US')['selected_market'] === null);
$check('GEO_SUGGESTION_NOT_AUTO_APPROVED', $class::evaluate('', 'en', 'US')['auto_approved'] === false);
$check('GEO_COUNTRY_HINT_NOT_PERMISSION', $class::evaluate('', 'en', 'SA')['selected_market'] === null);
$check('INACTIVE_LANGUAGE_FAIL_CLOSED', $class::evaluate('us-en', 'de', 'US')['selected_market'] === null);
$check('INVALID_COUNTRY_REFUSED', $class::country('BOGUS') === '');
$check('VALID_SELECTION_BOUND', !is_wp_error($class::choose('us-en')));
$stored = $GLOBALS['mock_session'][$class::SESSION_KEY] ?? [];
$check('SESSION_SITE_AND_REVISION_BOUND', ($stored['site_uuid'] ?? '') === 'test-site' && ($stored['revision'] ?? null) === 4);
$check('CURRENT_EXPLICIT_SELECTION_READ', $class::current()['selected_market']['profile_id'] === 'us-en');
$GLOBALS['mock_session'][$class::SESSION_KEY]['revision'] = 3;
$check('STALE_SESSION_REVISION_REJECTED', $class::current()['selected_market'] === null);
$GLOBALS['mock_session'][$class::SESSION_KEY] = $stored;
$class::ajax_read();
$public = $GLOBALS['sent_payload'][0] ?? [];
$selected = $public['selected_market'] ?? [];
$check('READ_ONLY_JSON_200_NO_CACHE', ($GLOBALS['no_cache'] ?? false) && ($GLOBALS['sent_payload'][1] ?? null) === 200);
$check('SENSITIVE_FIELDS_REDACTED', !isset($selected['site_uuid']) && !isset($selected['revision']) && !isset($selected['private_metadata']));
$check('READ_ONLY_NO_PRICE_OR_IDENTITY', !isset($public['ip']) && !isset($public['billing_country']) && !isset($public['price']));
$class::clear();
$check('SESSION_CLEAR', !isset($GLOBALS['mock_session'][$class::SESSION_KEY]));
echo 'SUMMARY checks=13 failures=' . $fail . PHP_EOL;
exit($fail ? 1 : 0);
