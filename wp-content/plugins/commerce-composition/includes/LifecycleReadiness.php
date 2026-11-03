<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Single read-only lifecycle gate projection. Domain owners keep control of
 * Forms, Rates, Woo Order, FluentBoards, supplier inventory and accounting.
 * Readiness is NOT approval or mutation authority.
 */
final class LifecycleReadiness
{
    private static function gate(string $state,string $owner,string $next,array $reasons=[]):array
    {
        return ['state'=>$state,'owner'=>$owner,'next_action'=>$next,
            'reasons'=>array_values(array_unique($reasons))];
    }

    public static function inspect(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if($requestId<1||!RequestReview::authorized()||!RequestReview::enabled())
            return new \WP_Error('lifecycle_access_denied');
        $review=RequestReview::read($requestId);
        if(is_wp_error($review))return $review;
        $requests=$wpdb->prefix.'commerce_request_batches';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT snapshot_json,pricing_state,sales_order_id FROM $requests WHERE id=%d",
            $requestId),ARRAY_A);
        if(!is_array($row))return new \WP_Error('lifecycle_source_missing');
        $lines=json_decode((string)$row['snapshot_json'],true);
        if(!is_array($lines)||count($lines)<1||count($lines)>100||
           count($lines)!==(int)$review['item_count'])
            return new \WP_Error('lifecycle_source_invalid');

        $settings=get_option('cptwooint_settings',[]);
        $types=is_array($settings)&&is_array($settings['selected_post_types']??null)
            ?$settings['selected_post_types']:[];
        $sourceLines=[];$sourceIssues=[];$identityIssues=[];$rateIssues=[];
        foreach($lines as $index=>$line){
            if(!is_array($line))return new \WP_Error('lifecycle_line_invalid');
            $form=(int)($line['form_id']??0);
            $submission=(int)($line['source_submission_id']??0);
            $source=(int)($line['source_id']??0);
            $rate=(int)($line['rate_id']??0);
            $expected=$review['sources'][$index]??null;
            if(!$expected||$expected['form_id']!==$form||
               $expected['submission_id']!==$submission||
               $expected['source_id']!==$source)
                return new \WP_Error('lifecycle_source_mismatch');
            $proof=SubmissionProvenance::verify($submission,$form);
            $status=$wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fluentform_submissions
                 WHERE id=%d AND form_id=%d",$submission,$form));
            if(is_wp_error($proof)||!is_string($status)||
               in_array($status,['trashed','spam','deleted'],true))
                $sourceIssues[]='original_submission_unverified';
            $post=get_post($source);
            $kind=$post instanceof \WP_Post?$post->post_type:null;
            $published=$post instanceof \WP_Post&&$post->post_status==='publish';
            if(!$published)$sourceIssues[]='service_not_published';
            $product=$published&&isset($types[$kind])&&function_exists('wc_get_product')
                ?wc_get_product($source):false;
            $productMapped=$product instanceof \WC_Product
                &&(int)$product->get_id()===$source;
            if(!$productMapped)$identityIssues[]='cpt_woocommerce_product_identity_unverified';
            if($kind==='tours-and-activities'&&
                (string)get_post_meta($source,'tour_public_status',true)!=='1')
                $sourceIssues[]='tour_not_activated';
            $rateState=$rate>0?'requires_domain_certification':'not_selected';
            if($rate>0){
                $ratePost=get_post($rate);
                if(!($ratePost instanceof \WP_Post)||$ratePost->post_status!=='publish'){
                    $rateState='source_unavailable';
                    $rateIssues[]='rate_not_published';
                }elseif(current_user_can('manage_options')&&current_user_can('manage_woocommerce')
                    &&$kind==='tours-and-activities'&&$ratePost->post_type==='tour-rates'){
                    $evidence=TourRateEligibility::inspect($source,$rate,null);
                    if(is_wp_error($evidence)){
                        $rateState='requires_review';
                        $rateIssues[]=$evidence->get_error_code();
                    }else{
                        // A date-less rate check is never eligible for a sale.
                        $rateState='requires_travel_date_and_commercial_quote';
                        foreach(($evidence['blockers']??[]) as $reason)$rateIssues[]=(string)$reason;
                    }
                }else $rateIssues[]='rate_owner_verification_required';
            } else $rateIssues[]='rate_not_selected_or_direct_pricing_unverified';
            $sourceLines[]=['line'=>$index,'form_id'=>$form,'submission_id'=>$submission,
                'service_id'=>$source,'service_type'=>$kind,'rate_id'=>$rate?:null,
                'source_published'=>$published,'product_identity_readable'=>$productMapped,
                'rate_evidence'=>$rateState];
        }

        $task=FluentBoardsTaskBridge::inspect($requestId);
        $taskId=(!is_wp_error($task)&&($task['linked']??false))
            ?(int)($task['task_id']??0):0;
        $taskIssues=[];
        if($taskId<1)$taskIssues[]='native_task_not_linked';
        else {
            $tasks=$wpdb->prefix.'fbs_tasks';
            $existing=$wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM $tasks WHERE id=%d",$taskId),ARRAY_A);
            if(!is_array($existing))$taskIssues[]='native_task_not_found';
            else {
                $relations=$wpdb->prefix.'fbs_relations';
                $assigned=(int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $relations
                     WHERE object_type=%s AND object_id=%d",'task_user',$taskId));
                if($assigned<1)$taskIssues[]='task_not_assigned';
            }
        }

        $eventCfg=get_option('commerce_event_bridge_v2',[]);
        $bound=is_array($eventCfg)&&isset($eventCfg['bound_site_hash'])
            &&is_string($eventCfg['bound_site_hash'])
            &&hash_equals($eventCfg['bound_site_hash'],
                hash('sha256',untrailingslashit((string)get_option('siteurl'))));
        $payments=0;
        if(function_exists('WC')&&WC()->payment_gateways()){
            foreach(WC()->payment_gateways()->payment_gateways() as $gateway){
                if(($gateway->enabled??null)==='yes')$payments++;
            }
        }
        $quoteState=(string)$row['pricing_state'];
        $canReview=empty($sourceIssues);
        $reviewState=$review['review_state'];
        $gates=[
            'source'=>self::gate($canReview?'verified':'blocked','fluent_forms_and_service_cpt',
                $canReview?'qualify_line_items':'repair_original_source',$sourceIssues),
            'product_identity'=>self::gate($identityIssues?'blocked':'readable_only',
                'cpt_woo_integration','verify_sellable_order_item_mapping',$identityIssues),
            'rate'=>self::gate('requires_review','domain_rate_engine',
                'collect_date_occupancy_and_full_rate_receipts',$rateIssues),
            'operator_review'=>self::gate($reviewState==='accepted_for_processing'?
                'accepted_for_processing':'requires_review','request_review',
                'complete_operator_review',[$reviewState]),
            'operations_task'=>self::gate($taskIssues?'blocked':'linked_and_assigned',
                'fluentboards','assign_native_task_or_reconcile_link',$taskIssues),
            'commercial_quote'=>self::gate('not_certified','domain_pricing_engine',
                'certify_full_price_cost_age_tax_fx_inventory',
                [$quoteState==='not_quoted'?'source_not_quoted':'quote_authority_unverified']),
            'manager_quote_approval'=>self::gate('not_certified','commercial_manager',
                'approve_exact_final_quote_digest',['manager_commercial_approval_unavailable']),
            'traveler_acceptance'=>self::gate('not_certified','first_party_traveler',
                'collect_independent_acceptance_for_approved_quote',
                ['traveler_acceptance_contract_unavailable']),
            'woo_order'=>self::gate('not_authorized','woocommerce_hpos',
                'certify_quote_acceptance_and_order_item_policy',
                ['no_certified_sale_transition','payment_gateway_ready_'.($payments>0?'unverified':'no')]),
            'supplier_booking'=>self::gate('not_authorized','supplier_booking_owner',
                'certify_order_item_and_supplier_confirmation',
                ['supplier_provider_not_certified']),
            'event_delivery'=>self::gate($bound?'configuration_bound_only':'blocked',
                'commerce_event_bridge','reconcile_site_identity_and_hmac_binding',
                $bound?['end_to_end_event_not_certified']:['event_bridge_site_identity_mismatch']),
            'accounting'=>self::gate('not_certified','accounting_provider',
                'certify_financial_mapping_and_reconciliation',
                ['payment_and_ledger_mapping_unavailable'])
        ];
        // Optional site-specific diagnostic providers: additions only, never
        // financial or booking authorization, no PII, no arbitrary keys.
        $extensions=apply_filters('commerce_composition_lifecycle_observations',[],$requestId);
        $observations=[];
        if(is_array($extensions)){
            foreach(array_slice($extensions,0,12) as $key=>$item){
                if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{2,60}$/D',$key)
                   ||!is_array($item))continue;
                $state=$item['state']??'unknown';
                if(!in_array($state,['ready_for_review','blocked','unknown'],true))continue;
                $observations[$key]=['state'=>$state,'non_authorizing'=>true];
            }
        }
        $next=$sourceIssues?'repair_original_source':
            ($reviewState==='pending_review'?'start_review':
             ($reviewState==='needs_information'?'collect_information':
              ($taskIssues?'assign_followup_task':'certify_commercial_quote')));
        return [
            'schema'=>'commerce.lifecycle-readiness.v1',
            'request_id'=>$requestId,'review_state'=>$reviewState,
            'review_revision'=>(int)$review['revision'],
            'lines'=>$sourceLines,'gates'=>$gates,
            'observations'=>$observations,
            'next_action'=>$next,'next_owner'=>$sourceIssues?'content':
                (in_array($next,['start_review','collect_information','assign_followup_task'],true)
                    ?'operations':'commercial_pricing'),
            'quote_approved'=>false,
            'traveler_accepted'=>false,
            'checkout_authorized'=>false,
            'payment_authorized'=>false,
            'supplier_booking_authorized'=>false,
            'accounting_posting_authorized'=>false
        ];
    }

    public static function routes():void
    {
        register_rest_route('commerce-composition/v1',
            '/request-reviews/(?P<request_id>[1-9][0-9]*)/lifecycle',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>static fn()=>RequestReview::authorized()&&RequestReview::enabled(),
            'callback'=>static function(\WP_REST_Request $request){
                $result=self::inspect((int)$request['request_id']);
                if(is_wp_error($result))
                    return new \WP_Error($result->get_error_code(),
                        'Lifecycle inspection unavailable',['status'=>422]);
                $response=new \WP_REST_Response($result,200);
                $response->header('Cache-Control','private,no-store');
                return $response;
            }
        ]);
    }
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
}
