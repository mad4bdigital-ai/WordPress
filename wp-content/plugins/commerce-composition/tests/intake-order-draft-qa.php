<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\IntakeOrderDraft as Draft;
global $wpdb;
$pass=0;$fail=[];
$ok=static function($v,$name)use(&$pass,&$fail){$v?$pass++:$fail[]=$name;};
$t=static fn($n)=>$wpdb->prefix.$n;
$tables=['fluentform_submissions','commerce_request_batches','commerce_contact_links',
 'commerce_intake_cases','fc_subscribers','wc_orders','woocommerce_order_items',
 'woocommerce_order_itemmeta','etg_booking_links'];
$before=[];foreach($tables as $n)$before[$n]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t($n));
$beforeMax=(int)$wpdb->get_var('SELECT MAX(id) FROM '.$t('wc_orders'));
$verifiedCleanup=static function(int $requestId)use($wpdb,$t,$beforeMax):int {
    // The Woo CRUD pipeline may auto-commit/alter MySQL transactions.
    // Clean only this synthetic Request's newly issued draft, using the
    // exact private source metadata and the original empty-order contract.
    $ids=$wpdb->get_col($wpdb->prepare(
        'SELECT order_id FROM '.$t('wc_orders_meta').
        " WHERE meta_key='_commerce_intake_request_id' AND meta_value=%s",
        (string)$requestId
    ));
    $removed=0;
    foreach($ids as $id){
        $id=(int)$id;
        if($id<=$beforeMax)continue;
        $order=wc_get_order($id);
        if(!($order instanceof WC_Order)||
          (int)$order->get_meta('_commerce_intake_request_id',true)!==$requestId||
          $order->get_meta('_commerce_intake_business_state',true)!=='inquiry_only'||
          $order->get_items('line_item')!==[]||
          $order->is_paid()||(float)$order->get_total()!==0.0)
            continue;
        if($order->delete(true))$removed++;
    }
    return $removed;
};
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$old=get_current_user_id();
$requestId=0;
$contacts=$wpdb->get_col('SELECT id FROM '.$t('fc_subscribers').' ORDER BY id LIMIT 2');
$policy=static fn()=>['enabled'=>true,'default_mode'=>'order_first','channels'=>[],'services'=>[]];
$on=static fn()=>true;
$ok(!Draft::enabled(),'nonfinancial_order_writer_off_by_default');
$sid=0;$basketHash=hash('sha256',random_bytes(20));
$fixtureUrl='http://example.invalid/order-draft-test-fixture';

try{
 wp_set_current_user($admin);
 $wpdb->insert($t('fluentform_submissions'),[
  'form_id'=>6,'status'=>'unread','response'=>'{}','user_id'=>0,
  'source_url'=>$fixtureUrl,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')
 ]);
 $sid=(int)$wpdb->insert_id;
 $snapshot=wp_json_encode([['source_id'=>60645,'form_id'=>6,'source_submission_id'=>$sid,
    'rate_id'=>61158,'components'=>[]]]);
 $wpdb->insert($t('commerce_request_batches'),[
  'basket_sha256'=>$basketHash,
  'session_sha256'=>hash('sha256','draft-qa'),'sales_order_id'=>null,
  'state'=>'pending_review','pricing_state'=>'not_quoted',
  'snapshot_json'=>$snapshot,'created_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $requestId=(int)$wpdb->insert_id;
 $wpdb->insert($t('commerce_contact_links'),[
  'contact_id'=>(int)$contacts[0],'domain'=>'fluent_form','source_key'=>'inquiry',
  'source_id'=>$sid,'role'=>'requester','actor_id'=>$admin,
  'created_utc'=>gmdate('Y-m-d H:i:s')
 ]);
 $ok($sid>0&&$requestId>0,'form_and_independent_request_saved');
 $locked=Draft::ensure($requestId);
 $ok(is_wp_error($locked)&&$locked->get_error_code()==='intake_order_draft_disabled',
  'default_gate_refuses_any_order');
 add_filter('pre_option_commerce_intake_routing_policy_v1',$policy,99);
 add_filter('pre_option_commerce_intake_case_registry_enabled',$on,99);
 add_filter('pre_option_commerce_intake_nonfinancial_order_enabled',$on,99);
 $plan=Draft::preview($requestId);
 $ok(!is_wp_error($plan)&&$plan['route_mode']==='order_first'&&
   $plan['quoted_amount']===null,'order_first_has_no_approved_price');
 $ok(!is_wp_error($plan)&&
   $plan['source_product_identity_owner']==='cpt_product_integration'&&
   $plan['source_product_identity_status']==='verified_read_only'&&
   ($plan['source_product_items'][0]['woocommerce_product_id']??0)===60645,
   'source_product_is_original_cpt_product_id');
 $ok(!is_wp_error($plan)&&
   $plan['source_product_items'][0]['rate_id']===61158&&
   $plan['source_product_items'][0]['order_item_creation_authorized']===false&&
   $plan['source_product_items'][0]['quote_approved']===false,
   'native_rate_awaits_price_certification_before_item');
 $ok(!is_wp_error($plan)&&
   $plan['source_product_items'][0]['rate_relationship_verified']===true&&
   in_array('travel_date_required',
       $plan['source_product_items'][0]['rate_selection_blockers'],true)&&
   $plan['source_product_items'][0]['rate_selection_eligible']===false,
   'without_trip_date_no_rate_selection_is_approved');
 $datePlan=Draft::preview($requestId,'2026-11-15');
 $ok(!is_wp_error($datePlan)&&
   $datePlan['source_product_items'][0]['rate_selection_eligible']===true&&
   $datePlan['source_product_items'][0]['order_item_creation_authorized']===false,
   'winter_rate_date_can_be_eligible_but_never_authorizes_item');
 $ok(!is_wp_error($datePlan)&&
   $datePlan['source_product_items'][0]['selected_rate_resolution_status']==='explicit_eligible_rate'&&
   in_array(61148,$datePlan['source_product_items'][0]['eligible_alternative_rate_ids'],true)&&
   $datePlan['source_product_items'][0]['rate_id']===61158,
   'saved_rate_selected_despite_overlapping_equally_eligible_gold_rate');
 $outside=Draft::preview($requestId,'2026-09-15');
 $ok(!is_wp_error($outside)&&
   in_array('outside_rate_window',
       $outside['source_product_items'][0]['rate_selection_blockers'],true),
   'selected_day_outside_original_rate_refused');
 $route='/commerce-composition/v1/intake-cases/'.$requestId.'/order-draft';
 wp_set_current_user(0);
 $anon=rest_do_request(new WP_REST_Request('GET',$route));
 $ok(in_array($anon->get_status(),[401,403],true),'guest_cannot_read_private_order_plan');
 wp_set_current_user($admin);
 $planResponse=rest_do_request(new WP_REST_Request('GET',$route));
 $ok($planResponse->get_status()===200&&
   ($planResponse->get_data()['result']['order_status']??'')==='checkout-draft',
   'operator_can_read_order_first_preflight');
 $withDate=new WP_REST_Request('GET',$route);
 $withDate->set_param('travel_date','2026-11-15');
 $dateResponse=rest_do_request($withDate);
 $ok($dateResponse->get_status()===200&&
   ($dateResponse->get_data()['result']['source_product_items'][0]
    ['rate_selection_eligible']??false)===true&&
   ($dateResponse->get_data()['result']['source_product_items'][0]
    ['order_item_creation_authorized']??true)===false,
   'staff_rest_can_preview_valid_date_without_supplier_or_price_authority');
 $wrongDate=new WP_REST_Request('GET',$route);
 $wrongDate->set_param('travel_date','2026-02-30');
 $wrongResponse=rest_do_request($wrongDate);
 $ok($wrongResponse->get_status()!==200,
   'staff_rest_rejects_calendar_impossible_date');
 $invalidRequest=new WP_REST_Request('POST',$route);
 $invalidRequest->set_header('Content-Type','application/json');
 $invalidRequest->set_body(wp_json_encode(['contact_id'=>(int)$contacts[1],'currency'=>'USD']));
 $invalidResponse=rest_do_request($invalidRequest);
 $ok($invalidResponse->get_status()===400,'cannot_inject_customer_or_currency_into_order');
 $created=Draft::ensure($requestId);
 $oid=is_wp_error($created)?0:(int)$created['order_id'];
 if(is_wp_error($created))echo wp_json_encode(['debug_order_creation'=>$created->get_error_code()]).PHP_EOL;
 $ok($oid>0&&!is_wp_error($created),'genuine_woo_order_created');
 if($oid>0){
  $order=wc_get_order($oid);
  $ok($order instanceof WC_Order&&$order->get_status()==='checkout-draft',
    'private_woocommerce_checkout_draft_status');
  $ok($order instanceof WC_Order&&$order->get_meta('_commerce_intake_business_state',true)==='inquiry_only',
    'nonfinancial_business_state');
  $ok($order instanceof WC_Order&&!$order->needs_payment()&&!$order->is_paid()&&
    $order->get_meta('_commerce_intake_checkout_authorized',true)==='no',
    'no_payment_or_checkout_authority');
  $ok($order instanceof WC_Order&&count($order->get_items('line_item'))===0&&
    $order->get_meta('_commerce_intake_price_state',true)==='not_quoted',
    'no_fabricated_zero_price_product_item');
  $restReplay=rest_do_request(new WP_REST_Request('POST',$route));
  $ok($restReplay->get_status()===200&&
      (int)($restReplay->get_data()['result']['order_id']??0)===$oid&&
      ($restReplay->get_data()['result']['reused']??false)===true,
      'operator_rest_post_reuses_same_order');
  $replay=Draft::ensure($requestId);
  $ok(!is_wp_error($replay)&&$replay['order_id']===$oid&&$replay['reused']===true,
    'same_source_returns_same_canonical_order');
  $relation=$wpdb->get_var($wpdb->prepare(
    'SELECT woo_order_id FROM '.$t('commerce_intake_cases').' WHERE request_id=%d',$requestId));
  $ok((int)$relation===$oid,'case_and_woo_order_same_identity');
  $notBookable=ETG_Tour_Bookings::validate_link([
    'wc_order_id'=>$oid,'wc_order_item_id'=>999999988,
    'service_key'=>'unpriced:'.$requestId,'service_kind'=>'tour',
    'supplier_ref'=>'properties:36474'
  ]);
  $ok(is_wp_error($notBookable),'no_order_item_no_supplier_booking');
  $wpdb->update($t('commerce_contact_links'),['contact_id'=>(int)$contacts[1]],
    ['domain'=>'fluent_form','source_key'=>'inquiry','source_id'=>$sid,'role'=>'requester']);
  $customerConflict=Draft::ensure($requestId);
  $ok(is_wp_error($customerConflict)&&
    $customerConflict->get_error_code()==='intake_order_original_or_policy_conflict',
    'existing_order_cannot_rebind_another_customer');
 }
}finally{
 // Woo CRUD and transient invalidations use their own database hooks.
 // Avoid a long SQL transaction; explicitly remove ONLY this QA fixture.
 if($requestId>0)$verifiedCleanup($requestId);
 if($requestId>0){
   $wpdb->delete($t('commerce_intake_cases'),['request_id'=>$requestId]);
   $wpdb->delete($t('commerce_request_batches'),
     ['id'=>$requestId,'basket_sha256'=>$basketHash]);
 }
 if($sid>0){
   $wpdb->delete($t('commerce_contact_links'),
      ['domain'=>'fluent_form','source_key'=>'inquiry','source_id'=>$sid,
       'role'=>'requester','actor_id'=>$admin]);
   $wpdb->delete($t('fluentform_submissions'),
      ['id'=>$sid,'form_id'=>6,'source_url'=>$fixtureUrl]);
 }
 if($requestId>0)$verifiedCleanup($requestId);
 remove_filter('pre_option_commerce_intake_routing_policy_v1',$policy,99);
 remove_filter('pre_option_commerce_intake_case_registry_enabled',$on,99);
 remove_filter('pre_option_commerce_intake_nonfinancial_order_enabled',$on,99);
 wp_set_current_user($old);
}
foreach($tables as $n)$ok((int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t($n))===$before[$n],'fixture_cleanup_'.$n);
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','checks'=>$pass,
 'failed'=>$fail,'all_temporary_records_scoped_and_removed'=>true]).PHP_EOL;
if($fail)exit(2);
