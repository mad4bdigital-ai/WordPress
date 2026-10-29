<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\RequestSupplierReadiness;
global $wpdb;
$pass=0;$failed=[];$ok=static function($v,$name)use(&$pass,&$failed){
 $v?$pass++:$failed[]=$name;
};
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$old=get_current_user_id();
$names=['forms'=>'fluentform_submissions','requests'=>'commerce_request_batches',
 'review'=>'commerce_request_review_states','audit'=>'commerce_request_review_events',
 'bookings'=>'etg_booking_links','booking_proofs'=>'etg_booking_proofs',
 'booking_audit'=>'etg_booking_audit','hp'=>'wc_orders',
 'posts'=>'posts','jobs'=>'commerce_workflow_jobs',
 'board_tasks'=>'fbs_tasks'];
$before=[];foreach($names as $key=>$name)
    $before[$key]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$name");
$id=0;$original=0;$rate=61158;$source=60645;
$batch=$wpdb->prefix.'commerce_request_batches';
$state=$wpdb->prefix.'commerce_request_review_states';
$forms=$wpdb->prefix.'fluentform_submissions';
$path=static fn($id)=>'/commerce-composition/v1/request-reviews/'.$id.'/supplier-readiness';
$wpdb->query('START TRANSACTION');
try{
    wp_set_current_user($admin);
    $ownerConfig=get_option('commerce_request_supplier_owner_registry',[]);
    $ok(is_array($ownerConfig)&&
        current_user_can($ownerConfig['capability']??'do_not_allow'),
        'site_configured_supplier_operator_permission_live');
    $ok(is_array($ownerConfig)&&
        class_exists($ownerConfig['readiness_class']??'',false),
        'site_configured_native_supplier_owner_exists');
    $ok(get_post_type($source)==='tours-and-activities' &&
        get_post_type($rate)==='tour-rates','existing_real_source_and_rate');
    $wpdb->insert($forms,['form_id'=>6,'status'=>'unread','response'=>'{}','user_id'=>0,
      'source_url'=>'http://example.invalid/supplier-readiness-qa',
      'created_at'=>gmdate('Y-m-d H:i:s'),
      'updated_at'=>gmdate('Y-m-d H:i:s')]);
    $original=(int)$wpdb->insert_id;
    $lines=[['source_id'=>$source,'rate_id'=>$rate,
      'form_id'=>6,'source_submission_id'=>$original,'components'=>[]]];
    $wpdb->insert($batch,[
      'basket_sha256'=>hash('sha256','supplier-readiness-qa-'.bin2hex(random_bytes(10))),
      'session_sha256'=>hash('sha256','supplier-readiness-session'),
      'sales_order_id'=>null,'state'=>'pending_review',
      'pricing_state'=>'not_quoted','snapshot_json'=>wp_json_encode($lines),
      'created_utc'=>gmdate('Y-m-d H:i:s')
    ]);
    $id=(int)$wpdb->insert_id;
    $ok($original>0&&$id>0,'synthetic_request_in_same_transaction');
    wp_set_current_user(0);
    $guest=rest_do_request(new WP_REST_Request('GET',$path($id)));
    $ok(in_array($guest->get_status(),[401,403],true),'guest_read_denied');
    $guestPost=rest_do_request(new WP_REST_Request('POST',$path($id)));
    $ok(!in_array($guestPost->get_status(),[200,201],true),'no_supplier_post_endpoint');
    wp_set_current_user($admin);
    $noOwnerConfig=static fn($value)=>[];
    add_filter('pre_option_commerce_request_supplier_owner_registry',
        $noOwnerConfig,99);
    $unconfigured=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($unconfigured)&&
        $unconfigured->get_error_code()==='request_supplier_provider_unconfigured',
        'missing_provider_registry_fails_closed');
    remove_filter('pre_option_commerce_request_supplier_owner_registry',
        $noOwnerConfig,99);
    $notAccepted=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($notAccepted)&&
        $notAccepted->get_error_code()==='request_supplier_review_not_accepted',
        'unreviewed_request_not_supplier_ready');
    $wpdb->insert($state,['request_id'=>$id,'review_state'=>'under_review',
        'revision'=>1,'actor_id'=>$admin,
        'updated_utc'=>gmdate('Y-m-d H:i:s')]);
    $started=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($started)&&
        $started->get_error_code()==='request_supplier_review_not_accepted',
        'under_review_still_not_supplier_ready');
    $wpdb->update($state,['review_state'=>'rejected','revision'=>2],['request_id'=>$id]);
    $rejected=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($rejected)&&
        $rejected->get_error_code()==='request_supplier_review_not_accepted',
        'rejected_does_not_dispatch_to_supplier');
    $wpdb->update($state,
        ['review_state'=>'accepted_for_processing','revision'=>2],
        ['request_id'=>$id]);
    // A manager without the native supplier capability must not see
    // operational handoff detail, even with manage_options.
    $capDeny=static function($allCaps) {
        $siteOwner=get_option('commerce_request_supplier_owner_registry',[]);
        $cap=is_array($siteOwner)?($siteOwner['capability']??null):null;
        if(is_string($cap))$allCaps[$cap]=false;
        return $allCaps;
    };
    add_filter('user_has_cap',$capDeny,999);
    $withoutNativeCap=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($withoutNativeCap)&&
        $withoutNativeCap->get_error_code()==='request_supplier_operator_forbidden',
        'missing_native_booking_operator_capability_denied');
    remove_filter('user_has_cap',$capDeny,999);
    $preview=RequestSupplierReadiness::preview($id);
    $ok(!is_wp_error($preview)&&
        $preview['status']==='awaiting_woocommerce_order',
        'approved_review_still_requires_canonical_order');
    $ok(!is_wp_error($preview)&&
        $preview['line_count']===1&&
        $preview['sources'][0]['source_id']===$source&&
        $preview['sources'][0]['rate_id']===$rate,
        'real_source_and_rate_ids_retained_without_customer_pii');
    $ok(!is_wp_error($preview)&&
        $preview['requires_woocommerce_order']===true&&
        $preview['requires_woocommerce_order_item']===true&&
        $preview['native_provider_requires_woocommerce_order_item']===true&&
        $preview['can_create_supplier_booking']===false,
        'canonical_woocommerce_order_and_item_are_mandatory');
    $ok(!is_wp_error($preview)&&
        ($preview['nonfinancial_source_owner_installed']??true)===false&&
        ($preview['nonfinancial_source_writer_enabled']??true)===false&&
        ($preview['nonfinancial_event_consumers_approved']??true)===false,
        'retired_source_writer_not_available');
    $writer=static fn($was)=>true;
    $consumers=static fn($was)=>true;
    add_filter('supplier_request_registry_handoff_enabled',$writer,999);
    $singleApproved=RequestSupplierReadiness::preview($id);
    $ok(!is_wp_error($singleApproved)&&
        $singleApproved['status']==='awaiting_woocommerce_order',
        'source_switch_cannot_authorize_no_order_booking');
    add_filter('supplier_request_registry_consumers_ready',$consumers,999);
    $both=RequestSupplierReadiness::preview($id);
    $ok(!is_wp_error($both)&&
        $both['status']==='awaiting_woocommerce_order'&&
        $both['can_create_supplier_booking']===false,
        'even_forced_source_switches_cannot_skip_woo_order');
    remove_filter('supplier_request_registry_consumers_ready',$consumers,999);
    remove_filter('supplier_request_registry_handoff_enabled',$writer,999);
    $ok(!is_wp_error($preview)&&
        $preview['commercial_order_created']===false&&
        $preview['supplier_confirmed']===false&&
        $preview['payment_authorized']===false,
        'no_false_supplier_financial_commitment');
    // The WooCommerce operator Inbox must truthfully show the unavailable
    // nonfinancial provider link, rather than promising a supplier booking.
    ob_start();
    Commerce\Composition\RequestReviewAdmin::page();
    $operatorHtml=ob_get_clean();
    $ok(str_contains($operatorHtml,
        'Awaiting canonical WooCommerce Order and mapped Order Item'),
        'operator_inbox_states_order_first_rule');
    $ok(!str_contains($operatorHtml,'Create supplier booking'),
        'operator_inbox_has_no_unapproved_supplier_write_action');
    $operatorHttp=rest_do_request(new WP_REST_Request('GET',$path($id)));
    $ok($operatorHttp->get_status()===200&&
        $operatorHttp->get_data()['result']['status']==='awaiting_woocommerce_order',
        'owner_private_rest_preview');
    $operatorPost=rest_do_request(new WP_REST_Request('POST',$path($id)));
    $ok(!in_array($operatorPost->get_status(),[200,201],true),
        'even_owner_cannot_post_booking_via_preview');
    // The original multi-service Request may contain multiple legitimate
    // source types, but it can never turn into a booking by being bundled.
    $wpdb->insert($forms,[
        'form_id'=>3,'status'=>'unread','response'=>'{}','user_id'=>0,
        'source_url'=>'http://example.invalid/supplier-readiness-qa',
        'created_at'=>gmdate('Y-m-d H:i:s'),
        'updated_at'=>gmdate('Y-m-d H:i:s')
    ]);
    $otherSubmission=(int)$wpdb->insert_id;
    $second=['source_id'=>36474,'rate_id'=>null,'form_id'=>3,
        'source_submission_id'=>$otherSubmission,'components'=>[]];
    $wpdb->update($batch,['snapshot_json'=>wp_json_encode([$lines[0],$second])],['id'=>$id]);
    $multi=RequestSupplierReadiness::preview($id);
    $ok(!is_wp_error($multi)&&$multi['line_count']===2&&
        $multi['sources'][1]['source_type']==='properties'&&
        $multi['can_create_supplier_booking']===false,
        'two_real_distinct_services_still_blocked_from_native_booking');
    $wpdb->update($batch,['snapshot_json'=>wp_json_encode($lines)],['id'=>$id]);
    $wpdb->update($forms,['status'=>'trashed'],['id'=>$original]);
    $inactive=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($inactive)&&
        $inactive->get_error_code()==='request_supplier_original_inactive',
        'deleted_original_form_refused');
    $wpdb->update($forms,['status'=>'unread'],['id'=>$original]);
    $wpdb->update($batch,['snapshot_json'=>wp_json_encode([[
       'source_id'=>999999987,'rate_id'=>$rate,'form_id'=>6,
       'source_submission_id'=>$original,'components'=>[]
    ]])],['id'=>$id]);
    $missingSource=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($missingSource)&&
        $missingSource->get_error_code()==='request_supplier_product_source_missing',
        'fabricated_product_source_refused');
    $wpdb->update($batch,['snapshot_json'=>wp_json_encode([[
       'source_id'=>$source,'rate_id'=>999999987,'form_id'=>6,
       'source_submission_id'=>$original,'components'=>[]
    ]])],['id'=>$id]);
    $badRate=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($badRate)&&
        $badRate->get_error_code()==='request_supplier_rate_missing',
        'fabricated_rate_refused');
    $wpdb->update($batch,['snapshot_json'=>wp_json_encode($lines),
       'sales_order_id'=>100],['id'=>$id]);
    $fakeSale=RequestSupplierReadiness::preview($id);
    $ok(is_wp_error($fakeSale)&&
        $fakeSale->get_error_code()==='review_unpriced_source_not_found',
        'financially_converted_request_not_inspected_as_inquiry');
    $wpdb->update($batch,['sales_order_id'=>null,'snapshot_json'=>wp_json_encode($lines)],
        ['id'=>$id]);
}finally{
    $wpdb->query('ROLLBACK');
    wp_set_current_user($old);
}
foreach($names as $key=>$name)
    $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$name")===$before[$key],
        'rollback_preserves_'.$key);
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS',
 'passed'=>$pass,'failed'=>$failed,
 'provider_invoked_to_write'=>false,
 'provider_cpt_records_unchanged'=>true,
 'native_booking_unmodified'=>true,
 'no_order_or_supplier_commitment'=>true]).PHP_EOL;
if($failed)exit(2);
