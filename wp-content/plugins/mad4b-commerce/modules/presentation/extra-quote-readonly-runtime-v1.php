<?php
/**
 * Source-native Extras read-only presentation contract.
 *
 * Safe to load without the full Commerce Runtime. It does not register any
 * checkout, order, payment, supplier or inventory write handlers. It never
 * fabricates a price when the canonical pricing providers are unavailable.
 */
defined('ABSPATH') || exit;

if (!function_exists('shared_product_optional_tour_ids_for_tour')) {
    /**
     * Existing JetEngine field is the canonical allowlist. This fallback is
     * only used when the original Commerce Runtime did not register the
     * native method; it preserves the existing validation/fail-closed rules.
     */
    function shared_product_optional_tour_ids_for_tour($parent) {
        $parent = absint($parent);
        if (get_post_type($parent) !== 'tours-and-activities' ||
            get_post_status($parent) !== 'publish') {
            return new WP_Error('invalid_optional_parent_tour');
        }
        if ((string)get_post_meta($parent, 'tour_extras_status', true) !== '1') return [];
        $raw = get_post_meta($parent, 'tour_optional_extras', true);
        if ($raw === '' || $raw === null || $raw === []) return [];
        if (is_string($raw)) $raw = maybe_unserialize($raw);
        if (is_string($raw)) $raw = array_filter(array_map('trim', explode(',', $raw)));
        if (!is_array($raw) || count($raw) > 100)
            return new WP_Error('invalid_optional_tours_meta');
        $ids = [];
        foreach ($raw as $value) {
            if (!is_scalar($value) || !ctype_digit((string)$value))
                return new WP_Error('invalid_optional_tour_id');
            $id = absint($value);
            if ($id === $parent ||
                get_post_type($id) !== 'tours-and-activities' ||
                get_post_status($id) !== 'publish')
                return new WP_Error('invalid_optional_tour_reference');
            $mode = get_post_meta($id, 'tour_mode', true);
            if (!in_array($mode, ['activity','extra_trip'], true))
                return new WP_Error('invalid_optional_tour_mode');
            if ((string)get_post_meta($id,'tour_public_status',true) === '0')
                return new WP_Error('optional_tour_not_public');
            $ids[$id] = $id;
        }
        return array_values($ids);
    }
}

final class MAD4B_Extra_Readonly_Quote_Runtime_V1 {
    public const CONTRACT = 'mad4b.extra-readonly-quote-runtime.v1';

    /**
     * Status is diagnostic only: PHP declarations alone do not certify
     * published inventory, valid rates, FX policy or commercial authority.
     */
    public static function status(): array {
        $required = [
            'shared_product_optional_tour_ids_for_tour',
            'shared_product_extra_source_quote',
            'shared_product_resolve_price_sources',
            'shared_product_unit_extended_amount',
            'shared_product_strategy_quote',
            'shared_product_resolve_strategy',
            'pricing_audience_quote',
            'shared_product_quote_snapshot',
            'shared_product_booking_v2_rate_covers_date',
            'pricing_currency_convert',
        ];
        $present = [];
        foreach ($required as $function) $present[$function] = function_exists($function);
        $ready = !in_array(false, $present, true);
        return [
            'contract' => self::CONTRACT,
            'status' => $ready
                ? 'SOURCE_PRICING_FUNCTIONS_PRESENT_DATA_UNVERIFIED'
                : 'CANONICAL_QUOTE_DEPENDENCIES_UNAVAILABLE',
            'required_functions' => $present,
            'read_only_preview_dispatch_available' => $ready,
            'rate_eligibility_verified' => false,
            'commercial_pricing_approved' => false,
            'checkout_or_financial_write_enabled_by_adapter' => false,
        ];
    }

    /**
     * Dispatches only to the original pricing source resolver; no arithmetic
     * or client-provided monetary values are accepted as authoritative.
     */
    public static function quote($parentId, array $extra, $audience, $occupancy,
                                  array $quantities, $date = null, $currency = null) {
        if (!self::status()['read_only_preview_dispatch_available'])
            return new WP_Error('extra_quote_runtime_unavailable',
                'Canonical non-financial Extra pricing is not loaded');
        return shared_product_extra_source_quote(
            $parentId, $extra, $audience, $occupancy, $quantities, $date, $currency);
    }
}
