<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\ClaimLedger;
use Commerce\Composition\RequestBasket;
use Commerce\Composition\RequestCheckout;
use Commerce\Composition\RequestOrder;

global $wpdb;
$pass=0;$fail=[];$ok=static function($v,$label)use(&$pass,&$fail){$v?$pass++:$fail[]=$label;};
$table=$wpdb->prefix.'commerce_request_batches';
$claims=ClaimLedger::table();$sessions=$wpdb->prefix.'woocommerce_sessions';
$s=WC()->session;$sid=$s->get_customer_id();
$baseline=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$beforeBatch=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$beforeClaims=(int)$wpdb->get_var("SELECT COUNT(*) FROM $claims");
$beforeFluent=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions");
$ids=[99089001,99089002];$requestIds=[];$sources=[60645,66133];
$make=function($index)use($ids,$sources){
 $post=$sources[$index];
 $components=$index===0?[
  ['source_type'=>'properties','source_id'=>36474,'group'=>'stay','role'=>'choose_one_included'],
  ['source_type'=>'transportations','source_id'=>37201,'group'=>'transfer','role'=>'fixed_included']
 ]:[];
 return ['schema'=>1,'status'=>'validated_intent_not_cart_session_committed',
 'mode'=>'request_only','cart_authority'=>false,'checkout_authority'=>false,
 'form_id'=>6,'source_submission_id'=>$ids[$index],
 'source_parent_id'=>$post,'source_rate_id'=>$index===0?61158:66139,
 'selection_digest'=>hash('sha256','nonfinancial-request-'.$index),'policy_revision'=>1,
 'composition'=>[
  'schema'=>1,'intent'=>'request',
  'status'=>'structure_and_component_evidence_only_not_checkout_authority',
  'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>$post],
  'components'=>$components,'price_state'=>'pending'
 ]];
};
try{
 $empty=RequestCheckout::commit();
 $ok(is_wp_error($empty) && in_array($empty->get_error_code(),
  ['booking_request_session_unavailable','booking_request_empty_or_oversized'],true),
  'empty_session_cannot_create_request_hpos');
 $a=ClaimLedger::stage($make(0));
 $ok(!is_wp_error($a),'package_parent_staged_from_claim');
 $b=ClaimLedger::stage($make(1));
 $ok(!is_wp_error($b),'independent_second_parent_staged');
 $ok(count(RequestBasket::entries())===2,'two_independent_parent_records');

 $converted=RequestCheckout::commit();
 $ok(!is_wp_error($converted),'request_hpos_conversion_succeeded');
 if(!is_wp_error($converted)){
  $orderId=(int)$converted['request_id'];$requestIds[]=$orderId;
  $ok($converted['status']==='request_submitted'&&$converted['line_count']===2,
     'one_request_order_contains_two_source_parent_lines');
  $order=wc_get_order($orderId);
  $ok($order instanceof RequestOrder,'request_subtype_restored_from_hpos');
  $ok($order->get_status()==='request-review','special_review_status_not_pending_payment');
  $ok($order->get_meta('_request_nonfinancial')==='yes' &&
    $order->get_meta('_request_price_state')==='pending_approval',
    'nonfinancial_price_pending_flags');
  $ok($order->get_meta('_request_payment_permitted')==='no',
    'payment_not_permitted');
  $meta=$order->get_meta('_request_sources_snapshot');
  $ok(is_array($meta)&&count($meta)===2,'both_parent_sources_recorded_on_hpos');
  $ok(count($meta[0]['components'])===2 &&
      (int)$meta[0]['components'][0]['source_id']===36474,
      'one_package_with_internal_stay_and_transfer');
  $ok(count($meta[1]['components'])===0,
     'second_standalone_source_retains_own_identity');
  $ok($order->get_items('line_item')===[],'no_fake_paid_wc_order_items');
  $ok(count(WC()->cart->get_cart())===0,'existing_paid_wc_cart_untouched');
  $ok($order->get_payment_method()==='' && $order->get_transaction_id()==='',
     'no_payment_method_or_transaction');
  $ok($order->needs_payment()===false && $order->is_paid()===false,
     'custom_booking_request_cannot_be_marked_payable');
  $ok($order->payment_complete('fake-payment-reference')===false &&
      $order->get_status()==='request-review',
     'payment_complete_cannot_change_request_status');
  $ok(!in_array('booking_request',wc_get_order_types('order-count'),true) &&
      !in_array('booking_request',wc_get_order_types('sales-reports'),true) &&
      !in_array('booking_request',wc_get_order_types('view-orders'),true) &&
      !in_array('booking_request',wc_get_order_types('order-webhooks'),true),
     'nonfinancial_type_excluded_from_commercial_views_and_webhooks');
  $raw=$wpdb->get_row($wpdb->prepare(
    "SELECT type,status FROM {$wpdb->prefix}wc_orders WHERE id=%d",$orderId),ARRAY_A);
  $ok($raw['type']==='booking_request' && $raw['status']==='wc-request-review',
     'actual_hpos_type_and_status');
  $batch=$wpdb->get_row($wpdb->prepare(
    "SELECT order_id,state FROM $table WHERE order_id=%d",$orderId),ARRAY_A);
  $ok((int)$batch['order_id']===$orderId&&$batch['state']==='staged',
    'persistent_unique_batch_record');
  $retry=RequestCheckout::commit();
  $ok(!is_wp_error($retry)&&$retry['status']==='already_submitted'&&
       $retry['request_id']===$orderId,'same_basket_retry_same_order_id');
  $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$baseline+1,
      'only_one_new_nonfinancial_hpos_row');
 }
}finally{
 foreach($requestIds as $oid){
  $order=wc_get_order($oid);
  if($order)$order->delete(true);
 }
 foreach($ids as $submission){
  $wpdb->delete($claims,['form_id'=>6,'submission_id'=>$submission],['%d','%d']);
 }
 $wpdb->query("DELETE FROM $table WHERE order_id IN (".(count($requestIds)?implode(',',array_map('intval',$requestIds)):'0').")");
 $s->delete_session($sid);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$baseline,
 'temporary_nonfinancial_hpos_deleted');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $claims")===$beforeClaims,
 'original_claim_count_restored');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$beforeBatch,
 'batch_records_cleaned');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions")===$beforeFluent,
 'original_fluent_records_untouched');
$ok($wpdb->get_var($wpdb->prepare(
 "SELECT session_key FROM $sessions WHERE session_key=%s",$sid))===null,
 'test_guest_session_removed');
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','checks'=>$pass,'failed'=>$fail,
 'hpos_baseline'=>$baseline,'separate_order_type'=>'booking_request',
 'payment_processed'=>false,'fixture_deleted'=>true]).PHP_EOL;
if($fail)exit(2);
