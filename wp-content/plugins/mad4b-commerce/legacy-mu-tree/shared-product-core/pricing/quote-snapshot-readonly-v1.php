<?php
/** Pure rate snapshot. Shared by read-only quote presentation and Woo adapters. */
if(!defined('ABSPATH'))return;
/** Generate from authoritative rate data, never client-supplied amount or currency. */
function shared_product_quote_snapshot($rate_id, $audience, $occupancy, $display_currency = null) {
    if (!function_exists('pricing_audience_quote') || !function_exists('pricing_currency_convert')) return new WP_Error('resolver_missing');
    $quote = pricing_audience_quote(absint($rate_id), sanitize_key($audience), sanitize_key($occupancy));
    if (($quote['status'] ?? '') !== 'available') return new WP_Error('quote_unavailable', $quote['reason'] ?? 'unavailable');
    $currency = strtoupper($display_currency ?: pricing_display_currency());
    $settings = get_option('_wcml_settings', []);
    if (!isset($settings['currency_options'][$currency])) return new WP_Error('unsupported_currency');
    $amount = pricing_currency_convert($quote['final'], $quote['source_currency'], $currency);
    if ($amount === null || !is_finite($amount) || $amount <= 0) return new WP_Error('fx_unavailable');
    $source_amount = (float)$quote['final'];
    $factor = $source_amount > 0 ? $amount / $source_amount : 0;
    return [
        'schema' => 1, 'rate_id' => (int)$quote['rate_id'], 'audience' => $quote['audience'],
        'occupancy' => $quote['occupancy'], 'source_currency' => $quote['source_currency'],
        'source_amount' => $source_amount, 'display_currency' => $currency,
        'display_amount' => $amount, 'fx_effective' => round($factor, 8),
        'discounted' => (bool)$quote['discounted'], 'quoted_at_utc' => gmdate('c'),
    ];
}
