<?php
/**
 * Plugin Name: MAD4B Visitor Read-Only State Lean Bootstrap
 * Version: 0.2.0
 * Description: Company-opted, scoped WordPress plugin bootstrap for read-only
 * visitor state batch. A company-local opt-in is required for public use.
 */
defined('ABSPATH') || exit;
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
    || ($_GET['action'] ?? '') !== 'mad4b_visitor_preference_state'
    || !defined('DOING_AJAX') || !DOING_AJAX
) return;

// Production requires a local option. No public query/header may opt in.
if (get_option('mad4b_visitor_preference_lean_bootstrap_v1','off') !== 'on') return;
// Authenticated REST/AJAX principals preserve full WordPress capabilities.
// Never omit login/security plugins on an authenticated request.
if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_PHP_AUTH_USER']))return;
foreach (array_keys($_COOKIE ?? []) as $cookie_name) {
    if (preg_match('/^wordpress_(?:logged_in_|sec_|[a-f0-9]{16,})/iD',(string)$cookie_name))return;
}

// Required plugin dependencies of the read-only state contract.
// Always preserve their existing activation order in active_plugins.
$essential = [
    'sitepress-multilingual-cms/sitepress.php',
    'wpml-string-translation/plugin.php',
    'woocommerce/woocommerce.php',
    'woocommerce-multilingual/wpml-woocommerce.php',
    'mad4b-site-control-plane/mad4b-site-control-plane.php',
    'mad4b-country-switcher/mad4b-country-switcher.php',
    'etg-traveller-nationality-switcher/etg-traveller-nationality-switcher.php',
];
add_filter('option_active_plugins', static function($plugins) use ($essential) {
    return is_array($plugins) ? array_values(array_intersect($plugins, $essential)) : $plugins;
}, PHP_INT_MAX);
// Diagnostic receipt only, never cache/private state values in public headers.
if (!headers_sent())header('X-MAD4B-Visitor-Bootstrap: lean-v2',true);
