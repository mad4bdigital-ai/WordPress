<?php
/**
 * MAD4B DFSB edge limiter attestation, 2026-11-07.
 * Nginx protects both /wp-json/{dfsb,etg-dfsb}/v1/ajax-presentation
 * and the WordPress ?rest_route= equivalents, by verified real visitor IP.
 * Limit: 6 requests/sec per address, burst=12; 429 if exceeded.
 * Remove this file if the Nginx edge guard is removed or fails verification.
 * This establishes the external WAF branch only; no content/SEO authorization.
 */
if (!defined('ABSPATH')) {
    return;
}
if (!defined('DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED')) {
    define('DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED', true);
}
