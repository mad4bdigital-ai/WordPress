<?php
/**
 * Company-scoped public frontend fragments. Staging only.
 * Company selectors belong to JSON, not to the generic PHP implementation.
 */
defined('ABSPATH') || exit;

if (!function_exists('mad4b_company_fragment_preset_config')) {
 function mad4b_company_fragment_preset_config(): array {
    static $cfg = null;
    // Before WordPress init, do not cache an early false verdict.
    if (!did_action('init')) return [];
    if ($cfg !== null) return $cfg;
    $cfg = [];
    $path = __DIR__ . '/company-performance-fragment-preset.json';
    if (!is_readable($path)) return [];
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)
        || ($data['contract'] ?? '') !== 'mad4b.company-performance-fragments.v1'
        || ($data['enabled'] ?? false) !== true
        || ($data['environment'] ?? '') !== 'staging'
        || !is_array($data['targets'] ?? null)
        || !is_array($data['page_paths'] ?? null)
        || !is_array($data['cache_targets'] ?? null)
        || !in_array(($data['geo_policy'] ?? ''), ['static_base_only', 'unverified'], true)
        || ($data['geo_policy'] ?? '') === 'unverified'
        || !in_array('/', $data['page_paths'], true)
        || !is_string($data['site_host'] ?? null)
        || !preg_match('/^[a-z0-9.-]{6,100}$/D', $data['site_host'])
        || !is_string($data['language'] ?? null)
        || !preg_match('/^[a-z]{2,3}$/D', $data['language'])
        || !is_int($data['fragment_ttl_seconds'] ?? null)
        || $data['fragment_ttl_seconds'] < 5
        || $data['fragment_ttl_seconds'] > 60) return [];
    $targets = $data['targets'];
    foreach (['menu_document_id','attractions_document_id','attractions_listing_id'] as $name) {
        if (!is_int($targets[$name] ?? null) || $targets[$name] <= 0) return [];
    }
    foreach (['menu_widget_id','attractions_widget_id'] as $name) {
        if (!is_string($targets[$name] ?? null) ||
            !preg_match('/^[a-f0-9]{7,12}$/D', $targets[$name])) return [];
    }
    if (!is_string($targets['attractions_taxonomy'] ?? null)
        || !preg_match('/^[a-z0-9_-]{2,45}$/D', $targets['attractions_taxonomy'])) return [];
    return $cfg = $data;
 }
}

if (!function_exists('mad4b_company_fragment_preset_enabled')) {
 function mad4b_company_fragment_preset_enabled(string $target = ''): bool {
    if (wp_get_environment_type() !== 'staging' || PHP_SAPI === 'cli'
        || !did_action('init') || !function_exists('is_user_logged_in')) return false;
    $cfg = mad4b_company_fragment_preset_config();
    if (!$cfg || ($cfg['environment'] ?? '') !== 'staging') return false;
    if ($target && !in_array($target, $cfg['cache_targets'], true)) return false;
    // Fail closed on GeoIP-sensitive WooCommerce configuration and on
    // explicit upstream country/market context. Unknown providers require
    // company-by-company attestation; this is not general GeoIP certification.
    if (get_option('woocommerce_default_customer_address', '') !== 'base'
        || get_option('woocommerce_calc_taxes', '') !== 'no') return false;
    foreach (['HTTP_CF_IPCOUNTRY','HTTP_X_COUNTRY_CODE',
              'HTTP_X_GEOIP_COUNTRY','HTTP_X_MAD4B_MARKET_CONTEXT'] as $context_header) {
        if (!empty($_SERVER[$context_header])) return false;
    }
    if (is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
        || !empty($_SERVER['HTTP_COOKIE']) || !empty($_SERVER['HTTP_AUTHORIZATION'])
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || !empty($_SERVER['QUERY_STRING'])) return false;
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    if ($host === '' || $host !== strtolower($cfg['site_host'])
        || strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) !== $host) return false;
    $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!in_array($path, $cfg['page_paths'], true) || $path !== '/') return false;
    $current_language = (string) apply_filters('wpml_current_language', '');
    return $current_language === $cfg['language'];
 }
}
