<?php
/** Generic server-authoritative composite booking preview. Lab only; not a checkout price. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_composite_quote($rate_id, $audience, $occupancy, $options = []) {
    if (!is_array($options)) return new WP_Error('invalid_booking_options');
    $currency = isset($options['currency']) && is_string($options['currency']) ? strtoupper($options['currency']) : null;
    $base = shared_product_quote_snapshot($rate_id, $audience, $occupancy, $currency);
    if (is_wp_error($base)) return $base;
    $unit = shared_product_resolve_price_unit($rate_id);
    if (is_wp_error($unit)) return $unit;
    $currency = $base['display_currency'];
    $people = filter_var($options['travelers'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999]]);
    if ($people === false || $people === null) return new WP_Error('invalid_travelers');
    $child_rates = $options['optional_rate_ids'] ?? [];
    if (!is_array($child_rates) || count($child_rates) > 30) return new WP_Error('invalid_optional_rates');
    $seen = []; $lines = []; $optional_total = 0.0;
    foreach ($child_rates as $child_id) {
        if (!is_int($child_id) && !(is_string($child_id) && ctype_digit($child_id))) return new WP_Error('invalid_optional_rate_id');
        $child_id = absint($child_id);
        if (!$child_id || isset($seen[$child_id])) return new WP_Error('duplicate_or_invalid_optional_rate');
        $seen[$child_id] = true;
        $child = shared_product_optional_rate_quote($rate_id, $child_id, $audience, $occupancy, $currency);
        if (is_wp_error($child)) return $child;
        $child_unit=shared_product_resolve_price_unit($child_id);
        if(is_wp_error($child_unit))return $child_unit;
        $amount = (float) ($child['quote']['display_amount'] ?? 0);
        if ($amount <= 0 || !is_finite($amount)) return new WP_Error('invalid_optional_rate_amount');
        // Child rate represents one selected booking unit. Traveler multiples require a separate certified child policy.
        $optional_total += $amount;
        $lines[] = ['kind'=>'optional_tour_rate','rate_id'=>$child_id,'tour_id'=>$child['child_tour_id'],'price_unit'=>$child_unit,'amount'=>$amount];
    }
    $language = $options['guide_language'] ?? null;
    $transport = $options['transportation'] ?? null;
    if (($language !== null && $language !== '') || ($transport !== null && $transport !== '')) {
        $modifiers = shared_product_booking_modifiers($rate_id, $language, $transport, $people, $currency);
        if (is_wp_error($modifiers)) return $modifiers;
    } else {
        $modifiers = ['modifiers_total'=>0, 'lines'=>[]];
    }
    $main_amount = (float)$base['display_amount'];
    $modifier_amount = (float)$modifiers['modifiers_total'];
    $optional_total = round($optional_total, 2);
    return [
        'schema'=>1,'parent_rate_id'=>(int)$rate_id,'currency'=>$currency,
        'base_quote'=>$base,'base_price_unit'=>$unit,'optional_rate_lines'=>$lines,'modifier_lines'=>$modifiers['lines'],
        'base_amount'=>$main_amount,'optional_total'=>$optional_total,'modifiers_total'=>$modifier_amount,
        'preview_total'=>round($main_amount+$optional_total+$modifier_amount,2),
        'status'=>'preview_only_not_checkout_authority',
        'tax_status'=>'not_applied_until_certified', 'service_fee_status'=>'not_applied_until_certified',
    ];
}
