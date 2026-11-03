<?php
/** Shared Product Core: WooCommerce quote snapshot contract (Lab only). */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
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
/** Attach the snapshot only to explicit tour-rate cart intent, not ordinary WC products. */
add_filter('woocommerce_add_cart_item_data', static function($data, $product_id) {
    if (!isset($_REQUEST['shared_rate_id'], $_REQUEST['shared_audience'], $_REQUEST['shared_occupancy'])) return $data;
    $rate = absint(wp_unslash($_REQUEST['shared_rate_id']));
    $audience = sanitize_key(wp_unslash($_REQUEST['shared_audience']));
    $occupancy = sanitize_key(wp_unslash($_REQUEST['shared_occupancy']));
    $snapshot = shared_product_quote_snapshot($rate, $audience, $occupancy);
    if (is_wp_error($snapshot)) { wc_add_notice(__('The selected tour rate is unavailable.', 'woocommerce'), 'error'); return $data; }
    $data['shared_product_quote'] = $snapshot;
    $data['shared_quote_token'] = wp_generate_uuid4();
    return $data;
}, 20, 2);
/** Immutable audit details on the order item; no payment or price override in this stage. */
add_action('woocommerce_checkout_create_order_line_item', static function($item, $cart_key, $values) {
    $quote = $values['shared_product_quote'] ?? null;
    if (!is_array($quote) || ($quote['schema'] ?? null) !== 1) return;
    // Stable identity for booking-line reconciliation, distinct per cart line.
    $token = $values['shared_quote_token'] ?? null;
    if (is_string($token) && wp_is_uuid($token)) {
        $item->add_meta_data('_shared_ledger_line_id', 'rate-' . absint($quote['rate_id']) . '-' . $token, true);
        $item->add_meta_data('_shared_booking_quote_token', $token, true);
    }
    foreach (['rate_id','audience','occupancy','source_currency','source_amount','display_currency','display_amount','fx_effective','discounted','quoted_at_utc'] as $key) {
        if (array_key_exists($key, $quote)) $item->add_meta_data('_shared_quote_' . $key, $quote[$key], true);
    }
}, 20, 3);

/** Refuse invalid tour quote requests before cart insertion. */
add_filter('woocommerce_add_to_cart_validation', static function($valid, $product_id, $quantity) {
    if (!$valid || !isset($_REQUEST['shared_rate_id'])) return $valid;
    $rate = absint(wp_unslash($_REQUEST['shared_rate_id']));
    if ($rate !== (int)$product_id || get_post_type($rate) !== 'tour-rates') { wc_add_notice(__('The selected tour rate does not match the cart item.', 'woocommerce'),'error'); return false; }
    $audience = sanitize_key(wp_unslash($_REQUEST['shared_audience'] ?? ''));
    $occupancy = sanitize_key(wp_unslash($_REQUEST['shared_occupancy'] ?? ''));
    if (is_wp_error(shared_product_quote_snapshot($rate, $audience, $occupancy))) {
        wc_add_notice(__('The selected tour rate is unavailable.', 'woocommerce'), 'error');
        return false;
    }
    return $valid;
}, 20, 3);

/** Bind the existing CPT-backed WC cart item to the server-quoted unit price. */
function shared_product_bind_cart_prices($cart) {
    if (!$cart instanceof WC_Cart) return;
    foreach ($cart->get_cart() as $key => $line) {
        // A cart line must have exactly one legacy repricing authority.
        if (!empty($line['shared_product_quote']) && !empty($line['shared_booking_configuration'])) {
            wc_add_notice(__('Conflicting quote and booking configuration: reselect this booking.', 'woocommerce'), 'error');
            continue;
        }
        $q = $line['shared_product_quote'] ?? null;
        if (!is_array($q) || ($q['schema'] ?? null) !== 1) continue;
        $product = $line['data'] ?? null;
        $id = (int)($line['product_id'] ?? 0);
        if (!$product instanceof WC_Product || get_post_type($id) !== 'tour-rates' || (int)($q['rate_id'] ?? 0) !== $id) {
            wc_add_notice(__('Tour quote and cart item do not match.', 'woocommerce'), 'error');
            continue;
        }
        $current = function_exists('pricing_display_currency') ? pricing_display_currency() : get_woocommerce_currency();
        if ($current !== ($q['display_currency'] ?? '')) {
            wc_add_notice(__('Currency changed. Please reselect the tour rate.', 'woocommerce'), 'error');
            continue;
        }
        $fresh = shared_product_quote_snapshot($id, $q['audience'] ?? '', $q['occupancy'] ?? '', $current);
        if (is_wp_error($fresh) || abs((float)($fresh['display_amount'] ?? -1)-(float)($q['display_amount'] ?? -2)) > 0.009) {
            wc_add_notice(__('Tour rate changed. Please refresh your booking.', 'woocommerce'), 'error');
            continue;
        }
        $product->set_price(wc_format_decimal($q['display_amount']));
        $product->set_tax_status('none'); // Lab: do not infer taxation for travel services.
    }
}
add_action('woocommerce_before_calculate_totals','shared_product_bind_cart_prices',99999);
/** Explicitly prevent checkout when snapshot is inconsistent or missing. */
add_action('woocommerce_check_cart_items', static function() {
    if (!WC()->cart) return;
    foreach (WC()->cart->get_cart() as $line) {
        if (get_post_type((int)($line['product_id'] ?? 0)) !== 'tour-rates') continue;
        $legacy = !empty($line['shared_product_quote']);
        $composite = !empty($line['shared_booking_configuration']);
        if ($legacy && $composite) {
            wc_add_notice(__('Conflicting booking pricing authorities: reselect this booking.', 'woocommerce'), 'error');
            continue;
        }
        // Composite bookings are re-quoted by the priority-30 validator.
        if ($composite) continue;
        $q = $line['shared_product_quote'] ?? null;
        if (!is_array($q) || (int)($q['rate_id'] ?? 0) !== (int)$line['product_id']) {
            wc_add_notice(__('Tour rate quote is required.', 'woocommerce'), 'error');
            continue;
        }
        if (empty($line['shared_quote_token']) || !wp_is_uuid($line['shared_quote_token'])) wc_add_notice(__('Tour booking identity is missing. Please reselect the tour.', 'woocommerce'), 'error');
        if ((int)($line['quantity'] ?? 0) < 1) wc_add_notice(__('Invalid tour booking quantity.', 'woocommerce'), 'error');
        if (($q['display_currency'] ?? '') !== pricing_display_currency()) wc_add_notice(__('Tour currency must be refreshed.', 'woocommerce'), 'error');
        $fresh = shared_product_quote_snapshot((int)$line['product_id'], $q['audience'] ?? '', $q['occupancy'] ?? '', $q['display_currency'] ?? '');
        if (is_wp_error($fresh) || abs((float)($fresh['display_amount'] ?? -1) - (float)($q['display_amount'] ?? -2)) > .009) wc_add_notice(__('Tour quote is no longer valid.', 'woocommerce'), 'error');
    }
}, 20);

/** Store API adapter: persist server-generated booking choices, not a browser-provided price. */
add_filter('woocommerce_store_api_add_to_cart_data', static function($data, $request) {
    $id = absint($data['id'] ?? 0);
    if (get_post_type($id) !== 'tour-rates') return $data;
    $choices = $request->get_param('extensions');
    $choices = is_array($choices) && isset($choices['shared-product-core']) && is_array($choices['shared-product-core'])
        ? $choices['shared-product-core'] : [];
    $audience = sanitize_key($choices['audience'] ?? '');
    $occupancy = sanitize_key($choices['occupancy'] ?? '');
    $quote = shared_product_quote_snapshot($id, $audience, $occupancy);
    if (is_wp_error($quote)) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'shared_product_invalid_quote', __('The selected tour rate or pricing audience is unavailable.', 'woocommerce'), 400
        );
    }
    $data['cart_item_data']['shared_product_quote'] = $quote;
    $data['cart_item_data']['shared_quote_token'] = wp_generate_uuid4();
    return $data;
}, 20, 2);
