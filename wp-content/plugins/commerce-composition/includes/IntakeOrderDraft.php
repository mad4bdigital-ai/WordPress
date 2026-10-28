<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Genuine HPOS Order for an unpriced, verified ORDER_FIRST intake.
 * No item is bookable until the canonical Product/Rate owner certifies it.
 * Distinct from checkout, quote acceptance and financial authorization.
 */
final class IntakeOrderDraft
{
    public static function enabled():bool
    {
        return in_array(get_option('commerce_intake_nonfinancial_order_enabled',false),
            [true,1,'1'],true);
    }
    public static function preview(int $requestId,?string $travelDate=null):array|\WP_Error
    {
        if(!current_user_can('manage_options')||
            !current_user_can('manage_woocommerce'))
            return new \WP_Error('intake_order_operator_forbidden');
        if(!function_exists('wc_create_order')||
           get_option('woocommerce_custom_orders_table_enabled')!=='yes')
            return new \WP_Error('intake_order_hpos_unavailable');
        $plan=IntakeCaseRegistry::preview($requestId);
        if(is_wp_error($plan))return $plan;
        if($plan['route_mode']!=='order_first')
            return new \WP_Error('intake_order_mode_not_order_first');
        // The installed CPT Product Integration owns Woo identity.
        // A raw CPT price never becomes a certified Order Item amount.
        $identity=ServiceProductIdentity::previewRequest($requestId,$travelDate);
        // A malformed operator-selected date is a request error, not a
        // 200 OK preflight with silently omitted source rate evidence.
        if($travelDate!==null&&is_wp_error($identity))return $identity;
        $identityReady=!is_wp_error($identity);
        return $plan+[
            'source_product_identity_owner'=>'cpt_product_integration',
            'source_product_identity_status'=>$identityReady?
                'verified_read_only':'not_ready',
            'source_product_identity_error'=>$identityReady?null:
                $identity->get_error_code(),
            'source_product_items'=>$identityReady?
                $identity['items']:[],
            'order_status'=>'checkout-draft',
            'quoted_amount'=>null,'price_approved'=>false,
            'order_items_certified'=>false,'checkout_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_intake_cases';
    }
    private static function checkedOrder(array $row,array $plan):array|\WP_Error
    {
        $orderId=(int)($row['woo_order_id']??0);
        if($orderId<1)return new \WP_Error('intake_order_claim_reconciliation_required');
        $order=wc_get_order($orderId);
        if(!($order instanceof \WC_Order)||
            $order->get_status()!=='checkout-draft'||
            $order->get_created_via()!=='commerce_intake_nonfinancial'||
            (int)$order->get_meta('_commerce_intake_request_id',true)!==$plan['request_id']||
            $order->get_meta('_commerce_intake_source_sha256',true)!==$plan['source_sha256']||
            $order->get_meta('_commerce_intake_policy_sha256',true)!==$plan['policy_sha256']||
            $order->get_meta('_commerce_intake_price_state',true)!=='not_quoted'||
            $order->get_meta('_commerce_intake_checkout_authorized',true)!=='no'||
            $order->get_items('line_item')!==[]||
            $order->needs_payment()||
            $order->is_paid())
            return new \WP_Error('intake_order_replay_diverged');
        return [
            'request_id'=>$plan['request_id'],
            'order_id'=>$orderId,'order_status'=>'checkout-draft',
            'source_channel'=>$plan['source_channel'],
            'quote_status'=>'not_quoted',
            'currency_or_amount_certified'=>false,
            'order_item_count'=>0,
            'supplier_booking_authorized'=>false,
            'payment_authorized'=>false,
            'customer_message_sent'=>false
        ];
    }
    public static function ensure(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if(!self::enabled()||!IntakeCaseRegistry::enabled())
            return new \WP_Error('intake_order_draft_disabled');
        if(!IntakeCaseRegistry::ready())
            return new \WP_Error('intake_order_case_schema_unavailable');
        $plan=self::preview($requestId);
        if(is_wp_error($plan))return $plan;
        $table=self::table();
        $existing=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE request_id=%d",$requestId),ARRAY_A);
        if($existing){
            if($existing['route_mode']!=='order_first'||
               (int)$existing['contact_id']!==$plan['contact_id']||
               !hash_equals($existing['source_sha256'],$plan['source_sha256'])||
               !hash_equals($existing['policy_sha256'],$plan['policy_sha256']))
                return new \WP_Error('intake_order_original_or_policy_conflict');
            $verified=self::checkedOrder($existing,$plan);
            if(is_wp_error($verified))return $verified;
            return $verified+['reused'=>true];
        }
        // Persistent exact source claim before Woo CRUD: an interrupted
        // create must be reconciled by an operator, NEVER retried into a
        // second Order from an uncertain outcome.
        $claimed=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table
             (request_id,source_sha256,policy_sha256,route_mode,source_channel,
              contact_id,woo_order_id,stage,actor_id,created_utc)
             VALUES (%d,%s,%s,'order_first',%s,%d,NULL,'creating_order_draft',%d,%s)",
            $requestId,$plan['source_sha256'],$plan['policy_sha256'],
            $plan['source_channel'],$plan['contact_id'],
            get_current_user_id(),gmdate('Y-m-d H:i:s')
        ));
        if($claimed!==1)
            return new \WP_Error('intake_order_claim_already_in_progress');

        $fresh=self::preview($requestId);
        if(is_wp_error($fresh)||
           $fresh['source_sha256']!==$plan['source_sha256']||
           $fresh['policy_sha256']!==$plan['policy_sha256']||
           $fresh['contact_id']!==$plan['contact_id'])
            return new \WP_Error('intake_order_source_changed_after_claim');

        // Woo CRUD can fail after the source claim but before its outcome
        // becomes certain. Never erase a claim or retry automatically:
        // reconcile any ambiguous Woo order through its private source meta.
        try {
        $order=wc_create_order([
            'status'=>'checkout-draft','customer_id'=>0,
            'created_via'=>'commerce_intake_nonfinancial'
        ]);
        if(is_wp_error($order)||!($order instanceof \WC_Order))
            return new \WP_Error('intake_order_wc_create_failed');

        $order->update_meta_data('_commerce_intake_request_id',$requestId);
        $order->update_meta_data('_commerce_intake_source_sha256',$plan['source_sha256']);
        $order->update_meta_data('_commerce_intake_policy_sha256',$plan['policy_sha256']);
        $order->update_meta_data('_commerce_intake_source_channel',$plan['source_channel']);
        $order->update_meta_data('_commerce_intake_contact_ref',$plan['contact_id']);
        $order->update_meta_data('_commerce_intake_business_state','inquiry_only');
        $order->update_meta_data('_commerce_intake_price_state','not_quoted');
        $order->update_meta_data('_commerce_intake_checkout_authorized','no');
        $order->update_meta_data('_commerce_intake_supplier_bookable','no');
        // No fabricated Product Item or 0-priced approved service.
        $order->save();
        $saved=$wpdb->update($table,[
            'woo_order_id'=>$order->get_id(),
            'stage'=>'awaiting_approved_business_board'
        ],['request_id'=>$requestId,'woo_order_id'=>null,
           'route_mode'=>'order_first','stage'=>'creating_order_draft']);
        if($saved!==1)return new \WP_Error('intake_order_claim_reconciliation_required');
        $verified=self::checkedOrder(['woo_order_id'=>$order->get_id()],$plan);
        if(is_wp_error($verified))return $verified;
        return $verified+['reused'=>false];
        }catch(\Throwable $e){
            return new \WP_Error('intake_order_outcome_reconciliation_required');
        }
    }
}
