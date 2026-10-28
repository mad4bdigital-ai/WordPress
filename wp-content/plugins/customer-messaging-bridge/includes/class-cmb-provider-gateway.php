<?php
/**
 * Read-only provider gateway for ChatbotX and MAD4B Control Plane.
 * Does NOT orchestrate money: delegates entire composed requests to the
 * existing Shared Pricing Orchestrator REV65 or fails closed.
 */
defined('ABSPATH') || exit;

final class CMB_Provider_Gateway {
    public const CONTRACT = 'cmb.provider-gateway.v1';
    public const SHARED_PRICE_CONTRACT = 'shared.pricing.orchestrator.v1';
    private const PROHIBITED_FIELDS = array(
        'total','amount','price','subtotal','base_amount','sale_price','approved_total',
        'exchange_rate','tax_amount','fee_amount','manual_override','override_price',
        'unit_price','payment_amount','deposit_amount','checkout_ready','quote_authorized',
        'inventory_reserved','orders_created'
    );

    public static function shared_pricing_available(): bool {
        return function_exists('shared_pricing_orchestrate') &&
            function_exists('shared_pricing_orchestrator_enabled') &&
            shared_pricing_orchestrator_enabled();
    }

    public static function pricing_status(): array {
        $available = self::shared_pricing_available();
        $providers = $available && function_exists('shared_pricing_domain_providers')
            ? shared_pricing_domain_providers() : array();
        $kinds = array();
        foreach ($providers as $name => $entry) {
            if (!is_string($name) || !is_array($entry)) continue;
            $kinds[$name] = array(
                'source_type' => (string)($entry['source_type'] ?? ''),
                'native_callback_available' => is_callable($entry['callback'] ?? null)
            );
        }
        $legacy = class_exists('MAD4B_Pricing_Source_Registry')
            ? MAD4B_Pricing_Source_Registry::snapshot() : null;
        return array(
            'contract' => self::SHARED_PRICE_CONTRACT,
            'authoritative_entrypoint' => 'shared_pricing_orchestrate',
            'api_route' => '/shared-pricing/v1/preview',
            'provider_owned_pricing' => true,
            'deployed_and_enabled_here' => $available,
            'providers' => $kinds,
            'expected_domains' => array('tour','accommodation','transportation','visa'),
            'legacy_registry_present' => is_array($legacy),
            'legacy_registry_source_count' => is_array($legacy) ? (int)($legacy['source_count'] ?? 0) : 0,
            'legacy_registry_used_as_price_fallback' => false,
            'eligibility_certified_for_checkout' => false,
            'cart_checkout_order_authority' => false,
            'read_only' => true,
            'missing_action' => $available ? null
                : 'Deploy and separately certify existing REV65 Shared Pricing Orchestrator for this exact Lab origin; do not bypass its Checkout QA DB/environment gate.'
        );
    }

    /**
     * One read-only presentation contract across isolated QA and main Lab.
     * The native MU provider makes every eligibility decision; never quote.
     */
    public static function presentation_context($selection) {
        if (!is_array($selection) || count($selection)>7 ||
            array_diff(array_keys($selection), ['tour_id','rate_id','service','channel',
                'travel_date','occupancy','audience'])) {
            return new WP_Error('cmb_presentation_selection_invalid');
        }
        $tour=absint($selection['tour_id']??0);
        if (!$tour || get_post_type($tour)!=='tours-and-activities' ||
            get_post_status($tour)!=='publish') {
            return new WP_Error('cmb_presentation_tour_required');
        }
        $channel=$selection['channel']??'standalone';
        if (!in_array($channel,['standalone','tour_component','destination_discovery'],true)) {
            return new WP_Error('cmb_presentation_channel_invalid');
        }
        if ($channel==='destination_discovery') {
            return new WP_Error('cmb_presentation_discovery_not_a_tour');
        }
        $service=$selection['service']??'tours';
        if (!is_string($service) || !in_array($service,['tours','properties',
                'transportations','visas'],true)) {
            return new WP_Error('cmb_presentation_service_invalid');
        }
        $rate=absint($selection['rate_id']??0);
        if ($rate) {
            if (get_post_type($rate)!=='tour-rates' || get_post_status($rate)!=='publish') {
                return new WP_Error('cmb_presentation_rate_invalid');
            }
            $owner=absint(get_post_meta($rate,'related_tour_id',true));
            $english=static function($id) {
                return absint(apply_filters('wpml_object_id',$id,'tours-and-activities',false,'en') ?: $id);
            };
            if (!$owner || $english($owner)!==$english($tour)) {
                return new WP_Error('cmb_presentation_rate_tour_mismatch');
            }
        }
        if (class_exists('Shared_Display_Context_Registry',false)) {
            $native=Shared_Display_Context_Registry::resolve($selection+['service'=>$service,'channel'=>$channel]);
            if (is_wp_error($native)) return $native;
            return ['contract'=>'cmb.presentation-context.v1','native_contract'=>$native['contract'],
                'provider'=>'unified_display_context','language'=>$native['language']['code']??null,
                'currency'=>$native['currency']['presentation']??null,
                'service'=>$service,'channel'=>$channel,
                'display_eligible'=>$native['visibility']['service_allowed']??false,
                'market_status'=>$native['market']['status']??'unresolved',
                'availability_status'=>$native['availability']['status']??'not_checked',
                'price_status'=>$native['pricing']['status']??'not_quoted',
                'rate_id'=>$rate?:null, 'tour_id'=>$tour,
                'quote_authorized'=>false,'booking_authorized'=>false,
                'read_only'=>true,'mutation_performed'=>false];
        }
        if (!class_exists('Shared_Product_Presentation_Compatibility_v1',false) ||
            !function_exists('service_channel_allowed')) {
            return new WP_Error('cmb_native_presentation_unavailable');
        }
        $native=Shared_Product_Presentation_Compatibility_v1::context_for_tour($tour);
        if (empty($native['ok'])) return new WP_Error('cmb_native_presentation_invalid');
        return ['contract'=>'cmb.presentation-context.v1',
            'native_contract'=>'main_lab.presentation-compatibility.v1',
            'provider'=>'shared_product_presentation_compatibility',
            'language'=>$native['locale']??null, 'currency'=>$native['currency']??null,
            'service'=>$service,'channel'=>$channel,
            'display_eligible'=>(bool)service_channel_allowed($service,$channel),
            'market_status'=>'not_certified_by_compatibility_facade',
            'availability_status'=>'not_checked','price_status'=>'not_quoted',
            'rate_id'=>$rate?:null,'tour_id'=>$tour,
            'quote_authorized'=>false,'booking_authorized'=>false,
            'read_only'=>true,'mutation_performed'=>false];
    }

    public static function booking_status(): array {
        return array(
            'contract' => 'cmb.booking-dependencies.v1',
            'authoritative_pricing' => self::pricing_status(),
            'booking_configuration_v2' => function_exists('shared_product_booking_v2_preview'),
            'form6_native_preview_present' => function_exists('shared_presentation_form6_price_quote'),
            'form6_shared_orchestrator_parity_certified' => false,
            'accommodation_stay_overlay' => function_exists('BookingExperience\\tour_stay_overlay_quote'),
            'booking_context_resolution' => function_exists('BookingContext\\resolve'),
            'commerce_operations_bridge' => class_exists('Commerce_Operations_Bridge'),
            'travel_bridge' => class_exists('ETG_WooCommerce_Bridge'),
            'inventory_reservation_certified' => false,
            'composite_booking_certified' => false,
            'checkout_and_order_issuance_enabled' => false,
            'requires' => array('composed_quote_and_cross_product_eligibility',
                'dated_inventory_and_stop_sale','verified_customer_context',
                'exact_governed_booking_ability','risk_based_approval',
                'idempotent_write_receipt_and_readback'),
            'read_only' => true
        );
    }

    public static function discovery(): array {
        $bit = array(
            'provider_available' => false,'execution_enabled' => false,
            'mcp_execute_ability' => 'bitflows/run-flow',
            'direct_run_from_chatbotx' => false
        );
        if (class_exists('MAD4B_SCP_Adapter_Registry')) {
            $adapter = MAD4B_SCP_Adapter_Registry::instance()->get('bitflows');
            if ($adapter && $adapter->is_available()) {
                $st = $adapter->status();
                $bit = array(
                    'provider_available' => true,
                    'execution_enabled' => !empty($st['execution_enabled']),
                    'flow_policy_default' => $st['flow_policy_default'] ?? 'deny',
                    'mcp_execute_ability' => 'bitflows/run-flow',
                    'direct_run_from_chatbotx' => false
                );
            }
        }
        $contact = 'Commerce\\Composition\\ContactBridge';
        $sources = class_exists($contact) ? $contact::sources() : array();
        $source_keys = array_values(array_filter(array_keys(is_array($sources) ? $sources : array()),'is_string'));
        return array(
            'contract' => self::CONTRACT,
            'read_only' => true,
            'mutations_performed' => false,
            'presentation' => array('native_source' => class_exists('Shared_Display_Context_Registry', false) ? 'unified_display_context' : (class_exists('Shared_Product_Presentation_Compatibility_v1',false) ? 'main_lab_presentation_compatibility' : 'unavailable'), 'context_lookup' => 'CMB_Provider_Gateway::presentation_context', 'form6_shared_quote_parity_certified'=>false, 'read_only'=>true),
            'pricing' => self::pricing_status(),
            'booking' => self::booking_status(),
            'commerce_operations' => array(
                'installed' => class_exists('Commerce_Operations_Bridge'),
                'event_plan_api' => 'Commerce_Operations_Bridge::route_plan',
                'event_emission_enabled' => false,
            ),
            'commerce_composition' => array(
                'installed' => class_exists($contact),
                'contact_links_ready' => class_exists($contact) && $contact::ready(),
                'source_names' => $source_keys,
                'direct_contact_write' => false
            ),
            'bitflows' => $bit,
            'fluentcrm' => array('installed' => function_exists('FluentCrmApi'),'direct_contact_write'=>false),
            'fluent_support' => array('installed' => class_exists('FluentSupport\\App\\Models\\Ticket'),'direct_ticket_write'=>false),
            'fluent_forms' => array('installed' => function_exists('fluentFormApi'),'direct_submission_write'=>false)
        );
    }

    public static function event_plan($event) {
        if (!class_exists('Commerce_Operations_Bridge'))
            return new WP_Error('cmb_commerce_operations_missing','Commerce Operations Bridge is unavailable');
        if (!is_array($event) || count($event) > 5)
            return new WP_Error('cmb_event_payload_invalid','Invalid event contract');
        $plan = Commerce_Operations_Bridge::route_plan($event);
        if (is_wp_error($plan)) return $plan;
        if (!is_array($plan) || !empty($plan['mutations_authorized']) ||
            !empty($plan['financial_posting_authorized']) ||
            !empty($plan['marketing_subscription_authorized']))
            return new WP_Error('cmb_event_route_not_safe','Route plan is not read only');
        return array('contract'=>self::CONTRACT,'read_only'=>true,
            'plan'=>$plan,'event_emitted'=>false,'workflow_execution_requested'=>false);
    }

    private static function contains_client_money($data, int $depth = 0): bool {
        if ($depth > 7 || !is_array($data) || count($data) > 80) return true;
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key),self::PROHIBITED_FIELDS,true)) return true;
            if (is_array($value) && self::contains_client_money($value,$depth+1)) return true;
        }
        return false;
    }

    /**
     * One composed request to the existing orchestrator, never per-domain
     * dispatch. The existing orchestrator owns rate selection, FX, rounding
     * and all provider receipts. Staff-only until consumer certification.
     */
    public static function pricing_preview($request) {
        if (!current_user_can('manage_woocommerce'))
            return new WP_Error('cmb_pricing_staff_required','Pricing context permission required');
        if (!is_array($request) || array_is_list($request) ||
            array_diff(array_keys($request),array('schema_version','context','currency','components')) ||
            self::contains_client_money($request) ||
            ($request['schema_version'] ?? null) !== 1 ||
            !in_array($request['context'] ?? '',array('tour_component','standalone'),true) ||
            !isset($request['components']) || !is_array($request['components']) ||
            !array_is_list($request['components']) ||
            count($request['components']) < 1 || count($request['components']) > 12) {
            return new WP_Error('cmb_shared_pricing_request_invalid','Only Shared Pricing Orchestrator v1 input is supported');
        }
        if (!self::shared_pricing_available())
            return new WP_Error('cmb_shared_pricing_orchestrator_not_deployed',
                'Shared Pricing Orchestrator must be deployed and certified on this exact site; no fallback quote');
        $result = shared_pricing_orchestrate($request);
        if (is_wp_error($result)) return $result;
        if (!is_array($result) || ($result['status'] ?? '') !== 'unified_preview_only' ||
            ($result['checkout_ready'] ?? null) !== false ||
            ($result['quote_authorized'] ?? null) !== false ||
            ($result['inventory_reserved'] ?? null) !== false ||
            ($result['orders_created'] ?? null) !== false ||
            ($result['cross_product_eligibility'] ?? '') !== 'not_certified' ||
            !is_numeric($result['total'] ?? null) ||
            !is_string($result['currency'] ?? null) ||
            !preg_match('/^[A-Z]{3}$/D',$result['currency']) ||
            !is_string($result['receipt_sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D',$result['receipt_sha256'])) {
            return new WP_Error('cmb_shared_pricing_receipt_invalid','Native preview authority not verified');
        }
        $lines = array();
        foreach ($result['components'] ?? array() as $line) {
            if (!is_array($line) || !is_string($line['domain'] ?? null) ||
                !is_numeric($line['amount'] ?? null)) {
                return new WP_Error('cmb_shared_pricing_line_invalid','Invalid native quote line');
            }
            $lines[] = array(
                'domain' => $line['domain'],
                'source_type' => (string)($line['source_type'] ?? ''),
                'amount' => (float)$line['amount'],
                'currency' => (string)($line['currency'] ?? ''),
                'source_receipt_sha256' => (string)($line['source_receipt_sha256'] ?? '')
            );
        }
        if (count($lines) !== (int)($result['component_count'] ?? -1))
            return new WP_Error('cmb_shared_pricing_line_count_mismatch','Invalid receipt count');
        return array(
            'contract'=>'cmb.shared-pricing-preview.v1',
            'pricing_authority'=>'shared_pricing_orchestrate',
            'state'=>'unified_preview_only',
            'currency'=>$result['currency'],
            'total'=>(float)$result['total'],
            'component_count'=>count($lines),
            'components'=>$lines,
            'receipt_sha256'=>$result['receipt_sha256'],
            'cross_product_eligibility'=>'not_certified',
            'checkout_ready'=>false,'booking_authorized'=>false,
            'inventory_reserved'=>false,'orders_created'=>false,
            'mutation_performed'=>false,'read_only'=>true
        );
    }
}
