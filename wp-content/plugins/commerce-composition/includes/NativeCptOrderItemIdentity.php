<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Reconciles CPT Product Integration native Woo Order items for Tour Rates.
 *
 * Woo 11.2 WC_Abstract_Order::add_product() constructs WC_Order_Item_Product
 * directly via LegacyProxy rather than WC_Order_Factory, bypassing the vendor
 * CPT item factory and its custom set_product_id(). The native setter rejects
 * non-"product" post types and WC silently leaves product_id=0.
 *
 * This hook repairs only the native identity at the deprecated add_product
 * hook, using the vendor's registered CPT_Order_Item implementation.
 * It does NOT authorize checkout, issue a quote, change a price or stock,
 * or create orders or provider bookings.
 */
final class NativeCptOrderItemIdentity
{
    public static function bootstrap():void
    {
        if (!class_exists('WC_Order_Factory')
            || !class_exists(\TinySolutions\cptwooint\Models\CPTOrderItemProduct::class))
            return;
        add_action('woocommerce_order_add_product',[self::class,'onProductAdded'],15,5);
    }

    public static function onProductAdded($orderId,$itemId,$product,$quantity,$args):void
    {
        if (!($product instanceof \WC_Product))return;
        $productId=(int)$product->get_id();
        // Deliberately narrow: only Tour Rates with a valid parent Tour
        // and registered CPT support. Other CPTs need their own contracts.
        if ($productId<1 || get_post_type($productId)!=='tour-rates')return;
        $tourId=(int)get_post_meta($productId,'related_tour_id',true);
        if ($tourId<1 || get_post_type($tourId)!=='tours-and-activities'
            || !class_exists(\TinySolutions\cptwooint\Helpers\Fns::class)
            || !\TinySolutions\cptwooint\Helpers\Fns::is_supported('tour-rates'))
            throw new \WC_Data_Exception(
                'commerce_cpt_rate_relation_unverified',
                'Native Tour Rate identity could not be verified.'
            );

        $item= \WC_Order_Factory::get_order_item((int)$itemId);
        if (!($item instanceof \TinySolutions\cptwooint\Models\CPTOrderItemProduct)
            || (int)$item->get_order_id()!==(int)$orderId)
            throw new \WC_Data_Exception(
                'commerce_cpt_order_item_factory_missing',
                'Native CPT order-item factory is unavailable.'
            );

        $existing=(int)$item->get_product_id();
        if ($existing!==0 && $existing!==$productId)
            throw new \WC_Data_Exception(
                'commerce_cpt_order_item_product_conflict',
                'Order item is bound to another product.'
            );
        if ($existing===0) {
            $item->set_product_id($productId);
            $item->save();
        }
        $readback=\WC_Order_Factory::get_order_item((int)$itemId);
        if (!($readback instanceof \WC_Order_Item_Product)
            || (int)$readback->get_product_id()!==$productId
            || (int)$readback->get_order_id()!==(int)$orderId)
            throw new \WC_Data_Exception(
                'commerce_cpt_order_item_reconciliation_failed',
                'Native Tour Rate order item could not be reconciled.'
            );
    }
}
