<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\ClaimLedger;
use Commerce\Composition\RequestCheckout;
use Commerce\Composition\RequestBasket;
global $wpdb;
$pass=0;$failed=[];$ok=static function($v,$n)use(&$pass,&$failed){$v?$pass++:$failed[]=$n;};
$s=WC()->session;$sid=$s->get_customer_id();
$baseOrders=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$baseClaims=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ClaimLedger::table());
$baseBatch=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches");
$sourceIds=[60645,66133];$fixture=[99089101,99089102];
$orderIds=[];
$maker=static function($i)use($sourceIds,$fixture){
 return [
  'schema'=>1,'mode'=>'request_only',
  'status'=>'validated_intent_not_cart_session_committed',
  'cart_authority'=>false,'checkout_authority'=>false,
  'form_id'=>6,'source_submission_id'=>$fixture[$i],
  'source_parent_id'=>$sourceIds[$i],'source_rate_id'=>null,
  'selection_digest'=>hash('sha256','followup-submission-'.$i),
  'composition'=>[
   'schema'=>1,'intent'=>'request',
   'status'=>'structure_and_component_evidence_only_not_checkout_authority',
   'parent'=>['source_id'=>$sourceIds[$i],'source_type'=>'tours-and-activities','service'=>'tours'],
   'components'=>$i===0?[['source_type'=>'properties','source_id'=>36474,'group'=>'stay','role'=>'choose_one_included']]:[],
   'price_state'=>'pending'
  ]
 ];
};
try{
 $s1=ClaimLedger::stage($maker(0));
 $ok(!is_wp_error($s1),'original_request_staged');
 $r1=RequestCheckout::commit();
 if(is_wp_error($r1))echo wp_json_encode(['diagnostic'=>'first_commit','code'=>$r1->get_error_code()]).PHP_EOL;
 $ok(!is_wp_error($r1)&&$r1['status']==='request_submitted','first_hpos_request_created');
 if(!is_wp_error($r1))$orderIds[]=$r1['request_id'];
 $view=Commerce\Composition\RequestBasketView::snapshot();
 $ok(($view['lines'][0]['request_state']??'')==='submitted_for_review',
     'first_line_marked_review');
 $s2=ClaimLedger::stage($maker(1));
 $ok(!is_wp_error($s2),'different_later_service_staged');
 $entries=RequestBasket::entries();
 $ok(count($entries)===2,'old_and_new_request_in_same_wc_session');
 $ok(isset($entries['6:'.$fixture[0]]['submitted_order_id'])&&
     !isset($entries['6:'.$fixture[1]]['submitted_order_id']),
     'later_service_not_premarked_submitted');
 $r2=RequestCheckout::commit();
 if(is_wp_error($r2))echo wp_json_encode(['diagnostic'=>'second_commit','code'=>$r2->get_error_code()]).PHP_EOL;
 $ok(!is_wp_error($r2)&&$r2['status']==='request_submitted'&&$r2['line_count']===1,
     'second_hpos_only_new_service_line');
 if(!is_wp_error($r2))$orderIds[]=$r2['request_id'];
 $ok(count($orderIds)===2&&$orderIds[0]!==$orderIds[1],
     'separate_hpos_request_numbers');
 $o2=count($orderIds)>1?wc_get_order($orderIds[1]):null;
 $sources=$o2?$o2->get_meta('_request_sources_snapshot'):[];
 $ok(is_array($sources)&&count($sources)===1&&$sources[0]['source_id']===66133,
     'later_order_does_not_duplicate_first_parent');
 $r3=RequestCheckout::commit();
 $ok(!is_wp_error($r3)&&$r3['status']==='already_submitted' &&
     $r3['request_id']===$orderIds[1],'repeat_without_new_products_no_duplicate');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$baseOrders+2,
     'only_two_request_hpos_rows_created');
 $v=Commerce\Composition\RequestBasketView::snapshot();
 $ok($v['count']===2&&
     count(array_filter($v['lines'],static fn($line)=>
        $line['request_state']==='submitted_for_review'))===2,
     'both_request_lines_marked_submitted_no_payment');
}finally{
 foreach($orderIds as $oid) {
  $o=wc_get_order($oid);
  if($o)$o->delete(true);
 }
 foreach($fixture as $id)$wpdb->delete(ClaimLedger::table(),[
  'form_id'=>6,'submission_id'=>$id],['%d','%d']);
 if($orderIds){
  $in=implode(',',array_map('intval',$orderIds));
  $wpdb->query("DELETE FROM {$wpdb->prefix}commerce_request_batches WHERE order_id IN ($in)");
 }
 $s->delete_session($sid);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$baseOrders,
 'temporary_hpos_request_rows_removed');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM ".ClaimLedger::table())===$baseClaims,
 'fixture_form_claims_removed');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")===$baseBatch,
 'batch_fixture_rows_removed');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS','assertions'=>$pass,
'failed'=>$failed,'submitted_orders'=>count($orderIds),
'no_duplicate_parent_lines'=>true,'qa_fixtures_cleaned'=>true]).PHP_EOL;
if($failed)exit(2);
