<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Read-only bridge discovery: review acceptance is not a supplier booking.
 * The current native supplier writer requires an exact WC order + item.
 * A nonfinancial Request has neither; NEVER manufacture these references.
 */
final class RequestSupplierReadiness
{
    private static function providerConfig():array|\WP_Error
    {
        // Site-owned configuration, never hardcode an environment's brand,
        // provider class or privilege in shared plugin source.
        $config=get_option('commerce_request_supplier_owner_registry',[]);
        if(!is_array($config)||!in_array($config['enabled']??null,[true,1,'1'],true)||
            !is_string($config['capability']??null)||
            !preg_match('/^[a-z][a-z0-9_]{2,79}$/D',$config['capability'])||
            !is_string($config['readiness_class']??null)||
            !preg_match('/^[A-Za-z_][A-Za-z0-9_]{1,127}$/D',
                $config['readiness_class']))
            return new \WP_Error('request_supplier_provider_unconfigured');
        return $config;
    }
    public static function preview(int $requestId):array|\WP_Error
    {
        global $wpdb;
        $config=self::providerConfig();
        if(is_wp_error($config))return $config;
        if(!current_user_can('manage_options')||
            !current_user_can($config['capability'])||
            !RequestReview::enabled())
            return new \WP_Error('request_supplier_operator_forbidden');

        $review=RequestReview::read($requestId);
        if(is_wp_error($review))return $review;
        if(($review['review_state']??'')!=='accepted_for_processing')
            return new \WP_Error('request_supplier_review_not_accepted');

        // Double-check the nonfinancial source still has no commercial sale.
        $batch=$wpdb->prefix.'commerce_request_batches';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,state,pricing_state,sales_order_id,snapshot_json
             FROM $batch WHERE id=%d",$requestId),ARRAY_A);
        if(!is_array($row)||$row['state']!=='pending_review'||
            $row['pricing_state']!=='not_quoted'||$row['sales_order_id']!==null)
            return new \WP_Error('request_supplier_source_is_not_inquiry');

        $lines=json_decode((string)$row['snapshot_json'],true);
        if(!is_array($lines)||!$lines||count($lines)>100||
            count($lines)!==(int)$review['item_count'])
            return new \WP_Error('request_supplier_source_invalid');

        $submissions=$wpdb->prefix.'fluentform_submissions';
        $summaries=[];
        foreach($lines as $line){
            if(!is_array($line))return new \WP_Error('request_supplier_line_invalid');
            $formId=(int)($line['form_id']??0);
            $submissionId=(int)($line['source_submission_id']??0);
            $sourceId=(int)($line['source_id']??0);
            if(min($formId,$submissionId,$sourceId)<1)
                return new \WP_Error('request_supplier_original_missing');
            $verified=SubmissionProvenance::verify($submissionId,$formId);
            if(is_wp_error($verified))return $verified;
            $status=$wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $submissions WHERE id=%d AND form_id=%d",
                $submissionId,$formId));
            if(!is_string($status)||in_array(
                $status,['trashed','spam','deleted'],true))
                return new \WP_Error('request_supplier_original_inactive');

            $post=get_post($sourceId);
            if(!$post||in_array($post->post_status,
                ['trash','auto-draft','inherit'],true))
                return new \WP_Error('request_supplier_product_source_missing');

            $rateId=(int)($line['rate_id']??0);
            $rateType=null;
            if($rateId>0){
                $rate=get_post($rateId);
                if(!$rate||in_array($rate->post_status,
                    ['trash','auto-draft','inherit'],true))
                    return new \WP_Error('request_supplier_rate_missing');
                $rateType=$rate->post_type;
            }
            $summaries[]=[
                'source_id'=>$sourceId,'source_type'=>$post->post_type,
                'rate_id'=>$rateId?:null,'rate_type'=>$rateType,
                'form_id'=>$formId,'submission_id'=>$submissionId,
                'supplier_selected'=>false
            ];
        }

        $providerClass=$config['readiness_class'];
        $nativeInstalled=class_exists($providerClass,false);
        $nativeReady=false;
        if($nativeInstalled && is_callable([$providerClass,'readiness'])){
            // This is a source-owned read-only readiness method.
            // No class create/transition/upload methods are ever invoked.
            $state=call_user_func([$providerClass,'readiness']);
            $nativeReady=($state===true);
        }
        // Read an optional source-owned capability declaration; this is
        // DISPLAY metadata, not authorization to create. Supplier writes
        // remain exclusive to the separately gated original owner API.
        $contract=apply_filters('commerce_composition_supplier_source_bridge_state',
            ['installed'=>false,'source_writer_enabled'=>false,
             'event_consumers_approved'=>false],$requestId);
        if(!is_array($contract))$contract=[];
        $installed=($contract['installed']??false)===true;
        $ownerEnabled=($contract['source_writer_enabled']??false)===true;
        $consumerReady=($contract['event_consumers_approved']??false)===true;
        // Global invariant: Request Registry is intake provenance, not a
        // replacement for a WooCommerce Order or its real product items.
        // This preflight must NEVER advertise supplier booking readiness
        // before the canonical Woo Order/Order Item authority is validated.
        $status='awaiting_woocommerce_order';
        return [
            'request_id'=>$requestId,
            'review_state'=>'accepted_for_processing',
            'status'=>$status,
            'nonfinancial_source_owner_installed'=>$installed,
            'nonfinancial_source_writer_enabled'=>$ownerEnabled,
            'nonfinancial_event_consumers_approved'=>$consumerReady,
            'native_provider_available'=>$nativeInstalled,
            'native_provider_runtime_ready'=>$nativeReady,
            'line_count'=>count($summaries),
            'sources'=>$summaries,
            'requires_supplier_assignment'=>true,
            'requires_woocommerce_order'=>true,
            'requires_woocommerce_order_item'=>true,
            'native_provider_requires_woocommerce_order_item'=>true,
            'source_typed_route_requires_woocommerce_order_item'=>true,
            'can_create_supplier_booking'=>false,
            'supplier_confirmed'=>false,
            'commercial_order_created'=>false,
            'payment_authorized'=>false
        ];
    }
}
