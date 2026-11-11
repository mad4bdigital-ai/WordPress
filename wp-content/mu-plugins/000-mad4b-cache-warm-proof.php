<?php
/**
 * Plugin Name: MAD4B — Object Cache Warm Request Proof
 * Description: Read-only public-page warm receipt for authorized local jobs; no user sessions or HTML caching.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
defined('MAD4B_CACHE_WARM_PROOF_VERSION') || define('MAD4B_CACHE_WARM_PROOF_VERSION', '1.0.0');

/* The receipt goes only into the site's existing object cache. */
add_action('template_redirect', static function (): void {
    if (!wp_using_ext_object_cache() || is_admin() || is_user_logged_in()
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || !empty($_SERVER['QUERY_STRING'])
        || ($_SERVER['HTTP_X_MAD4B_CACHE_WARM'] ?? '') !== '1'
        || empty($_COOKIE['mad4b_origin_cache_warm'])) {
        return;
    }
    $nonce = (string) ($_SERVER['HTTP_X_MAD4B_WARM_NONCE'] ?? '');
    $mac   = (string) ($_SERVER['HTTP_X_MAD4B_WARM_MAC'] ?? '');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $nonce)
        || !preg_match('/\A[a-f0-9]{64}\z/D', $mac)) {
        return;
    }
    $expected = hash_hmac('sha256', $nonce, wp_salt('auth'));
    if (!hash_equals($expected, $mac)) {
        return;
    }
    // Home/blog or a published public page (including WPML's separate ES homepage).
    if (!(is_front_page() || is_home() || (is_page() && get_post_status() === 'publish'))) {
        return;
    }
    wp_cache_set('receipt_' . $nonce, 'reached_wp', 'mad4b_cache_warm_proof', 120);
}, 999);
