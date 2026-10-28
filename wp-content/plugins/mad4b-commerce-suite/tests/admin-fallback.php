<?php
/** WordPress-free diagnostics and menu-capability regression. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
$GLOBALS['suite_admin'] = true;
$GLOBALS['suite_menus'] = [];
function add_action($hook, $callback, $priority = 10) {}
function current_user_can($capability) {
    return $GLOBALS['suite_admin'] && $capability === 'manage_options';
}
function add_options_page(...$args) {
    $GLOBALS['suite_menus'][] = ['parent' => 'settings', 'capability' => $args[2]];
}
function add_submenu_page(...$args) {
    $GLOBALS['suite_menus'][] = ['parent' => $args[0], 'capability' => $args[3]];
}
function get_option($key, $default = []) { return $default; }
function wp_get_environment_type() { return 'staging'; }
require __DIR__ . '/../mad4b-commerce-suite.php';
function add_fixture_woocommerce_class() { class WooCommerce {} }
function suite_test($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
suite_test(MAD4B_Commerce_Suite::allowed(), 'admin allowed without Woo');
MAD4B_Commerce_Suite::admin_menu();
suite_test($GLOBALS['suite_menus'][0]['parent'] === 'settings', 'fallback settings menu');
suite_test($GLOBALS['suite_menus'][0]['capability'] === 'manage_options', 'admin capability');
$s = MAD4B_Commerce_Suite::status();
suite_test($s['mode'] === 'source-only-inventory', 'no commercial authority');
suite_test($s['tenant_readiness']['activation_executed'] === false, 'no activation');
$GLOBALS['suite_admin'] = false;
suite_test(!MAD4B_Commerce_Suite::allowed(), 'non-admin denied');
$GLOBALS['suite_admin'] = true;
add_fixture_woocommerce_class();
MAD4B_Commerce_Suite::admin_menu();
suite_test($GLOBALS['suite_menus'][1]['parent'] === 'woocommerce', 'Woo menu when active');
echo "COMMERCE_ADMIN_FALLBACK_PASS 7/7\n";
