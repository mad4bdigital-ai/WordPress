<?php
/**
 * Reusable, safe public-content performance probe for company Staging.
 * Tests FastCGI cache mechanics, not real WP page safety or tenant authority.
 */
defined('ABSPATH') || exit;
add_action('parse_request', static function ($wp) {
    if (wp_get_environment_type() !== 'staging') return;
    if (empty($wp->request) || trim($wp->request, '/') !== '__mad4b_public_perf_probe__') return;
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD', true, 405);
        exit;
    }
    if (is_user_logged_in()) {
        header('Cache-Control: private, no-store', true);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"logged_in_not_eligible"}';
        exit;
    }
    // This exact endpoint emits only fixed public synthetic content.
    // Never include nonce, user, pricing, WooCommerce or geolocation data.
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-MAD4B-Cache-Eligibility: public-static-v1');
    header('Cache-Control: public, max-age=60');
    echo '{"contract":"mad4b.company-performance-preset.v1","public":true,"synthetic":true}';
    exit;
}, 0);
