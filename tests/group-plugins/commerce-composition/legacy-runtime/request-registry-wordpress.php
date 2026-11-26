<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\ClaimLedger;
use Commerce\Composition\RequestBasket;
use Commerce\Composition\RequestBasketView;
use Commerce\Composition\RequestCheckout;
use Commerce\Composition\RequestCheckoutController;
global $wpdb;
$pass=0;$failed=[];
$check=static function($yes,$name)use(&$pass,&$failed){$yes?$pass++:$failed[]=$name;};
$table=$wpdb->prefix.'commerce_request_batches';
$claims=ClaimLedger::table();
$orders=$wpdb->prefix.'wc_orders';
$session=WC()->session;$sid=$session->get_customer_id();
$originalOrders=(int)$wpdb->get_var("SELECT COUNT(*) FROM $orders");
$originalRows=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$originalClaims=(int)$wpdb->get_var("SELECT COUNT(*) FROM $claims");
$originalFluent=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions");
$fixtures=[99170001,99170002,99170003];
$sourceIds=[60645,66133,66133];
$created=[];
$make=static function($i)use($fixtures,$sourceIds){
 return [
  'schema'=>1,'mode'=>'request_only',
  'status'=>'validated_intent_not_cart_session_committed',
  'cart_authority'=>false,'checkout_authority'=>false,'form_id'=>6,
  'source_submission_id'=>$fixtures[$i],
  'source_parent_id'=>$sourceIds[$i],'source_rate_id'=>null,
  'selection_digest'=>hash('sha256','registry-fixture-'.$i),
  'composition'=>[
   'schema'=>1,'intent'=>'request',
   'status'=>'structure_and_component_evidence_only_not_checkout_authority',
   'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>$sourceIds[$i]],
   'components'=>$i===0?[
    ['source_type'=>'properties','source_id'=>36474,'group'=>'stay','role'=>'choose_one_included'],
    ['source_type'=>'transportations','source_id'=>37201,'group'=>'transfer','role'=>'fixed_included']
   ]:[],
   'price_state'=>'pending'
  ]
 ];
};
try{
 $check(RequestCheckout::ready(),'registry_table_ready');
 $columns=$wpdb->get_col("SHOW COLUMNS FROM $table",0);
 $check(!in_array('order_id',$columns,true)&&!in_array('total_amount',$columns,true) &&
   !in_array('amount',$columns,true)&&!in_array('price',$columns,true),
   'no_woocommerce_order_id_or_numeric_monetary_amount');
 $check(in_array('snapshot_json',$columns,true)&&in_array('pricing_state',$columns,true),
   'structure_and_pricing_state_fields');
 $check(!in_array('booking_request',wc_get_order_types(),true),
   'nonfinancial_hpos_order_type_no_longer_registered');
 $empty=RequestCheckout::commit();
 $check(is_wp_error($empty),'empty_session_denied');
 foreach([0,1] as $i){
  $stage=ClaimLedger::stage($make($i));
  $check(!is_wp_error($stage),'source_global_claim_staged_'.$i);
 }
 $check(count(RequestBasket::entries())===2,'two_independent_requests_in_one_session');
 $result=RequestCheckout::commit();
 $check(!is_wp_error($result)&&($result['status']??null)==='request_submitted',
   'registry_commit_succeeded');
 if(!is_wp_error($result)){
  $rid=(int)$result['request_id'];$created[]=$rid;
  $check(array_key_exists('sales_order_id',$result) &&
    $result['sales_order_id']===null &&
    ($result['pricing_state']??'')==='not_quoted',
    'no_sales_order_and_explicitly_not_quoted');
  $row=$wpdb->get_row($wpdb->prepare(
    "SELECT id,state,pricing_state,sales_order_id,snapshot_json FROM $table WHERE id=%d",
    $rid),ARRAY_A);
  $sources=json_decode((string)$row['snapshot_json'],true);
  $check($row['state']==='pending_review'&&$row['pricing_state']==='not_quoted'&&
    $row['sales_order_id']===null,'sql_row_unpriced_unlinked');
  $check(is_array($sources)&&count($sources)===2,
    'one_review_record_contains_two_parent_sources');
  $check(count($sources[0]['components'])===2 &&
    $sources[0]['components'][0]['source_id']===36474,
    'included_hotel_and_transfer_nested_in_one_package');
  $check(count($sources[1]['components'])===0,
    'standalone_service_kept_independent');
  $again=RequestCheckout::commit();
  $check(!is_wp_error($again)&&$again['status']==='already_submitted' &&
    $again['request_id']===$rid,'retry_reuses_same_request_registry_record');
  $owned=RequestCheckout::find($rid);
  $check(!is_wp_error($owned)&&count($owned['sources'])===2,
    'request_readback_restricted_to_current_session');
  $upgrade=RequestCheckout::promoteToSale($rid,[]);
  $check(is_wp_error($upgrade)&&
    $upgrade->get_error_code()==='certified_sale_transition_not_configured',
    'commercial_sale_never_implicitly_authorized');
  $snap=RequestBasketView::snapshot();
  $check(count(array_filter($snap['lines'],static fn($l)=>
    $l['request_state']==='submitted_for_review'))===2,
    'original_session_lines_marked_submitted');
 }
 $third=ClaimLedger::stage($make(2));
 $check(!is_wp_error($third),'later_new_service_claim_staged');
 $later=RequestCheckout::commit();
 $check(!is_wp_error($later)&&$later['status']==='request_submitted'&&
   $later['line_count']===1,'later_request_contains_only_new_service');
 if(!is_wp_error($later)){
  $created[]=$later['request_id'];
  $row=$wpdb->get_row($wpdb->prepare(
    "SELECT snapshot_json FROM $table WHERE id=%d",$later['request_id']),ARRAY_A);
  $parts=json_decode($row['snapshot_json'],true);
  $check(is_array($parts)&&count($parts)===1&&
    $parts[0]['source_submission_id']===$fixtures[2],
    'earlier_service_never_resubmitted');
 }
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$originalOrders,
   'hpos_order_count_never_changed');
 $check(count(WC()->cart->get_cart())===0,'commercial_woocommerce_cart_unchanged');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$originalRows+2,
   'two_distinct_nonfinancial_records');
 $view=RequestBasketView::snapshot();
 $check($view['can_pay']===false&&$view['can_checkout']===false,
    'read_only_request_view_no_sale_entitlement');
}finally{
 foreach($created as $id)$wpdb->delete($table,['id'=>(int)$id],['%d']);
 foreach($fixtures as $id)$wpdb->delete($claims,
     ['form_id'=>6,'submission_id'=>$id],['%d','%d']);
 $session->delete_session($sid);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$originalOrders,
 'hpos_untouched_after_cleanup');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$originalRows,
 'registry_fixtures_removed');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $claims")===$originalClaims,
 'source_claim_fixtures_removed');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions")===$originalFluent,
 'fluent_submission_records_unchanged');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS','checks'=>$pass,'failed'=>$failed,
 'nonfinancial_requests_created'=>count($created),'money_field'=>null,
 'hpos_unchanged'=>true,'source_fixtures_removed'=>true]).PHP_EOL;
if($failed)exit(2);
