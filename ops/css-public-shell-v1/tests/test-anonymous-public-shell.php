<?php
/** Hermetic PHP contract, without WP bootstrap or signed preference mutation. */
declare(strict_types=1);
define('ABSPATH', '/');
define('LSCWP_V', 'test');
function add_action(...$arguments) {}
function add_filter(...$arguments) {}
function home_url($path = '/') { return 'https://tours.dev.mad4b.com' . $path; }
function wp_parse_url($url,$component=null) { return parse_url($url,$component); }
function wp_get_environment_type() { return 'staging'; }
function get_option($key,$default='') {
    if ($key === 'mad4b_private_html_css_bundle_v1') return 'on';
    if ($key === 'litespeed.conf.optm-css_comb' || $key === 'litespeed.conf.optm-css_min') return '1';
    return $default;
}
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_user_logged_in() { return false; }
function is_preview() { return false; }
function is_feed() { return false; }
function is_search() { return false; }
function is_404() { return false; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_checkout() { return false; }
function is_cart() { return false; }
function is_account_page() { return false; }
function is_wc_endpoint_url() { return false; }
function assert_false(string $label): void {
    if (MAD4B_Private_HTML_CSS_Bundler_V1::eligible()) {
        throw new RuntimeException($label . ' unexpectedly admitted private CSS bundler');
    }
    echo 'PASS: '.$label."\n";
}
$_SERVER = ['REQUEST_METHOD'=>'GET', 'QUERY_STRING'=>'', 'HTTP_COOKIE'=>''];
require $argv[1] ?? throw new RuntimeException('tested original PHP file required');
assert_false('anonymous public archive uses public shell, never private CSS bundler');
$_SERVER['HTTP_COOKIE'] = 'arbitrary_untrusted_cookie=1';
assert_false('unknown preference cannot authorize private CSS response');
$_SERVER['HTTP_COOKIE']=''; $_SERVER['REQUEST_METHOD']='POST';
assert_false('POST cannot authorize CSS bundler');
echo 'PASS: original source CSS-only fallback respects anonymous/public boundary' . "\n";
