<?php
// Offline fail-closed UI assertions — no WordPress, network or database changes.
define('ABSPATH', '/');
define('SHARED_PRODUCT_LAB_ONLY', true);
function get_option($key, $fallback = null) {
    if ($key === '_wcml_settings') {
        return ['enable_multi_currency' => 2,
            'currency_options' => array_fill_keys(['USD', 'EUR', 'EGP', 'GBP'], [])];
    }
    return $fallback;
}
function pricing_display_currency() { return 'EUR'; }
function wp_unique_id($prefix) { return $prefix . 'test'; }
function esc_attr($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function esc_html($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function esc_html__($v, $domain = '') { return esc_html($v); }
function selected($v, $other, $echo = true) {
    $out = $v === $other ? " selected='selected'" : '';
    if ($echo) { echo $out; }
    return $out;
}
function add_shortcode($name, $callback) {}
function add_action($name, $callback) {}
function remove_query_arg($key) { return '/'; }
function add_query_arg($key, $value, $url = null) { return '/?' . $key . '=' . $value; }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/mu-plugins/pricing-header-switcher.php';
$degraded = pricing_render_currency_header(false);
$ready = pricing_render_currency_header(true);
$assert = static function ($passed, $message) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
};
$assert(strpos($degraded, 'pricing-currency-switcher') !== false, 'switcher is never hidden by FX outage');
$assert(strpos($degraded, 'Rates updating') !== false, 'degraded state is visible');
$assert(substr_count($degraded, 'disabled="disabled"') === 3, 'three non-base currencies are disabled');
$assert(strpos($degraded, 'Use USD') !== false, 'previously selected EUR offers USD recovery');
$assert(strpos($ready, 'disabled="disabled"') === false, 'all configured currencies enabled after recovery');
$assert(strpos($ready, '<option value="EUR" selected=') !== false, 'visitor currency is preserved after recovery');
$assert(strpos($ready, '\\"') === false, 'HTML attributes contain no escaped quote artifacts');
