<?php
/**
 * Company-scoped, read-only runtime capability inventory.
 *
 * Execution authority belongs to the installed Booking Fulfillment, WooCommerce,
 * Master Admin and signed Host policies. This contract never grants any of it.
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Company_Runtime_Capability_Contract_V1', false)) {
final class MAD4B_Company_Runtime_Capability_Contract_V1 {
    public const BOOKING_OPTION = 'etg-dynamic-pricing-controls_booking_fulfillment_mode';

    public static function snapshot(): array {
        $commerce = defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true;
        $presentation = defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true;
        // An invalid dual activation must not be interpreted as extra authority.
        $mode = $commerce && $presentation ? 'conflict_hold' : ($commerce ? 'commerce' : ($presentation ? 'presentation' : 'disabled'));
        $active = in_array($mode, ['commerce', 'presentation'], true);
        $policy = get_option(self::BOOKING_OPTION, 'inquiry_only');
        if (!is_string($policy) || !in_array($policy, ['inquiry_only', 'request_to_book', 'instant_booking'], true)) {
            $policy = 'inquiry_only';
        }
        $read = $active && function_exists('tour_component_structured_price_value')
            && function_exists('tour_component_price_evidence');
        $preview = $active && function_exists('shared_lab_tour_price_resolve');
        $requestCandidate = $active && in_array($policy, ['request_to_book', 'instant_booking'], true);
        $instantCandidate = $active && $policy === 'instant_booking';

        return [
            'contract' => 'mad4b.company-runtime-capability.readonly.v1',
            'runtime_mode' => $mode,
            'booking_policy_source' => self::BOOKING_OPTION,
            'booking_policy' => $policy,
            'readiness' => [
                'native_price_display' => $read,
                'public_indicative_quote' => $preview,
                'booking_v2_preview' => $active && function_exists('shared_product_booking_v2_preview'),
                'pricing_rules_v2' => $active && function_exists('shared_product_pricing_quote_contract_v2'),
                'rate_generation_provider' => $active && class_exists('Shared_Product_Rate_Generation', false),
                'price_cache_recovery' => $active && function_exists('mad4b_commerce_native_card_price_cache_reconcile_v1'),
            ],
            // Global options identify possible intent only. Every actual Tour/Rate
            // still requires native per-product authorization and readiness.
            'intents' => [
                'inquiry_preview' => $preview,
                'quote_preview' => $preview,
                'request_candidate' => $requestCandidate,
                'instant_candidate' => $instantCandidate,
                'request_authorized' => false,
                'instant_authorized' => false,
            ],
            'execution_authority' => [
                'granted_by_this_contract' => false,
                'order_write' => 'delegate_to_native_booking_and_woocommerce',
                'payment' => 'delegate_to_gateway_and_native_readiness',
                'refund' => 'delegate_to_native_financial_authority',
                'production_deployment' => 'delegate_to_original313_release_policy',
            ],
            'price_data_written' => false,
            'booking_created' => false,
        ];
    }
}
}
