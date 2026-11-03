<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\ClaimLedger;
global $wpdb;
$checks=0;$fail=[];
$check=static function($value,$label)use(&$checks,&$fail){$checks++;if(!$value)$fail[]=$label;};
$customer=WC()->session->get_customer_id();
$sessionTable=$wpdb->prefix.'woocommerce_sessions';
$claimTable=ClaimLedger::table();
$insertCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM $claimTable");
$fixtureId=99082001;
$intent=[
 'schema'=>1,'mode'=>'request_only','status'=>'validated_intent_not_cart_session_committed',
 'cart_authority'=>false,'checkout_authority'=>false,'form_id'=>6,
 'source_submission_id'=>$fixtureId,'source_parent_id'=>60645,'source_rate_id'=>61158,
 'selection_digest'=>hash('sha256','stale-simulation-fixture'),
 'composition'=>[
  'schema'=>1,'intent'=>'request',
  'status'=>'structure_and_component_evidence_only_not_checkout_authority',
  'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>60645],
  'components'=>[]
 ]
];
$check(WC()->session->get('commerce_composition_request_basket_v1',[])===[],
 'test_wc_request_session_initially_empty');
try {
 // Stand in for a second request having already written a new basket line.
 $databaseBasket=['6:99082000'=>[
  'schema'=>1,'selection_digest'=>hash('sha256','previous-concurrent-transaction')
 ]];
 $serialized=maybe_serialize(['commerce_composition_request_basket_v1'=>maybe_serialize($databaseBasket)]);
 $wpdb->insert($sessionTable,[
  'session_key'=>$customer,'session_value'=>$serialized,
  'session_expiry'=>time()+DAY_IN_SECONDS
 ],['%s','%s','%d']);
 $r=ClaimLedger::stage($intent);
 $check(is_wp_error($r)&&$r->get_error_code()==='request_claim_concurrent_session_change_retry',
  'stale_request_basket_fail_closed');
 $db=$wpdb->get_var($wpdb->prepare(
  "SELECT session_value FROM $sessionTable WHERE session_key=%s",$customer
 ));
 $check($db===$serialized,'concurrent_newer_session_not_overwritten');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $claimTable")===$insertCount,
  'failed_concurrent_request_created_no_claim');
 // Do the same for a changed native WC cart, with no request-basket drift.
 $serialized2=maybe_serialize(['cart'=>maybe_serialize(['other-cart-item'=>['quantity'=>1]])]);
 $wpdb->update($sessionTable,['session_value'=>$serialized2],['session_key'=>$customer]);
 $r=ClaimLedger::stage($intent);
 $check(is_wp_error($r)&&$r->get_error_code()==='request_claim_concurrent_cart_change_retry',
  'native_wc_cart_stale_state_denied');
 $actual=$wpdb->get_var($wpdb->prepare(
  "SELECT session_value FROM $sessionTable WHERE session_key=%s",$customer
 ));
 $check($actual===$serialized2,'newer_wc_cart_not_overwritten');
} finally {
 $wpdb->delete($sessionTable,['session_key'=>$customer],['%s']);
 $wpdb->delete($claimTable,['form_id'=>6,'submission_id'=>$fixtureId],['%d','%d']);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $claimTable")===$insertCount,
 'no_residual_claim');
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','assertions'=>$checks,'failed'=>$fail,
 'stale_sessions_blocked'=>true,'fixtures_cleaned'=>true]).PHP_EOL;
if($fail)exit(2);
