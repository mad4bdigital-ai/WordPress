<?php
/**
 * Plugin Name: WooCommerce Lab SEO AJAX Edge Attestation
 * Description: Lab-only declaration backed by tested Nginx endpoint rate limiting. Do not ship to production.
 */
if (!defined('ABSPATH')) { exit; }
// Nginx path-specific protection tested on the isolated loopback-only lab.
if (!defined('DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED')) {
    define('DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED', true);
}
