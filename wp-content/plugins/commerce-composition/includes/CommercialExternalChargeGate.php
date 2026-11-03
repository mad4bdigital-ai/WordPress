<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * QA50 — real-payment safety boundary. It never authorizes an external charge
 * from mock source receipts or identity alone. Only managed Commerce Orders are
 * gated here. Native Woo products and unrelated merchant orders are unaffected.
 *
 * Integration contract: payment provider must call authorizeBeforeExternalCharge
 * BEFORE reaching its remote authorization/capture API.
 */
final class CommercialExternalChargeGate
{
    private static function managed(\WC_Order $order):bool
    {
        return $order->get_meta('_commerce_composition_execution_gate',true)==='required';
    }

    public static function inspect(\WC_Order $order):array
    {
        $reasons=[];
        if(!self::managed($order))
            $reasons[]='order_not_registered_for_commercial_execution';
        try {
            NativeOrderItemIdentityResolver::assertOrder((int)$order->get_id());
        } catch(\Throwable $e) {
            $reasons[]='native_order_item_identity_not_certified';
        }
        $policy=CompanyCommercePolicy::registry();
        if(!is_array($policy)||($policy['status']??null)!=='active')
            $reasons[]='tenant_commerce_policy_not_active';

        $digest=$order->get_meta('_commerce_final_quote_digest',true);
        if(!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest))
            $reasons[]='immutable_final_quote_digest_missing';
        else {
            $status=CommercialFinalQuoteLifecycle::approvalStatus($digest);
            if(!is_array($status)||($status['accepted_by_both']??false)!==true)
                $reasons[]='manager_and_traveler_acceptance_not_certified';
            if(!is_array($status)||($status['payment_authorized']??false)!==true)
                $reasons[]='financial_execution_provider_not_certified';
        }
        // There is intentionally no live payment-release provider in QA50.
        $reasons[]='external_gateway_execution_not_certified';
        return [
            'contract'=>'commerce.external-charge-readiness.v1',
            'order_id'=>(int)$order->get_id(),
            'mock_receipts_not_accepted'=>true,
            'ready'=>false,
            'blockers'=>array_values(array_unique($reasons))
        ];
    }

    /** Call explicitly before making a network request to any live gateway. */
    public static function authorizeBeforeExternalCharge(\WC_Order $order):array|\WP_Error
    {
        $proof=self::inspect($order);
        return new \WP_Error('commerce_external_charge_not_certified',
            'External payment execution remains disabled',$proof);
    }

    public static function needsPayment($needs,$order,$statuses):bool
    {
        if(!$needs||!($order instanceof \WC_Order)||!self::managed($order))
            return (bool)$needs;
        return false;
    }

    public static function prePaymentComplete($orderId,$txn=''):void
    {
        $order=wc_get_order((int)$orderId);
        if(!($order instanceof \WC_Order)||!self::managed($order))return;
        throw new \WC_Data_Exception('commerce_external_charge_not_certified',
            'External payment execution remains disabled');
    }

    public static function bootstrap():void
    {
        if(!class_exists('WooCommerce'))return;
        add_filter('woocommerce_order_needs_payment',[self::class,'needsPayment'],75,3);
        add_action('woocommerce_pre_payment_complete',[self::class,'prePaymentComplete'],0,2);
    }
}
