<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\ClaimLedger;
use Commerce\Composition\RequestBasket;

global $wpdb;
$tests=0;$fail=[];
$check=static function($value,$label)use(&$tests,&$fail){$tests++;if(!$value)$fail[]=$label;};
$table=ClaimLedger::table();
$sessionTable=$wpdb->prefix.'woocommerce_sessions';
$fixtureForm=6;
$fixtureId=99080001;
$nextId=99080002;
$initialOrders=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$initialClaims=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$session=WC()->session;
$sessionId=$session->get_customer_id();
$intended=['schema'=>1,'mode'=>'request_only',
 'status'=>'validated_intent_not_cart_session_committed',
 'cart_authority'=>false,'checkout_authority'=>false,
 'form_id'=>$fixtureForm,'source_submission_id'=>$fixtureId,
 'source_parent_id'=>60645,'source_rate_id'=>61158,
 'selection_digest'=>hash('sha256','global-fixture-01'),
 'policy_revision'=>1,
 'composition'=>[
   'schema'=>1,'intent'=>'request',
   'status'=>'structure_and_component_evidence_only_not_checkout_authority',
   'parent'=>['service'=>'tours','source_type'=>'tours-and-activities',
      'source_id'=>60645,'rate_id'=>61158],
   'components'=>[['source_type'=>'properties','source_id'=>36474,
     'group'=>'stay','role'=>'choose_one_included']],
   'price_state'=>'price_pending'
 ]
];
try {
 $check(ClaimLedger::ready(),'claim_ledger_innodb');
 $check(!$session->has_session(),'fresh_guest_for_test');
 $first=ClaimLedger::stage($intended);
 $check(!is_wp_error($first),'initial_claim_staged');
 $check(!is_wp_error($first)&&$first['status']==='staged_request','first_staged_result');
 $r=$wpdb->get_row($wpdb->prepare(
  "SELECT state,selection_sha256,session_sha256 FROM $table WHERE form_id=%d AND submission_id=%d",
  $fixtureForm,$fixtureId),ARRAY_A);
 $check(is_array($r)&&$r['state']==='staged','unique_claim_committed');
 $check(strlen($r['session_sha256'])===64&&$r['session_sha256']!==$sessionId,'only_hashed_session_id_stored');
 $data=$wpdb->get_var($wpdb->prepare(
  "SELECT session_value FROM $sessionTable WHERE session_key=%s",$sessionId));
 $check(is_string($data)&&str_contains($data,'commerce_composition_request_basket_v1'),'wc_session_persisted');
 $again=ClaimLedger::stage($intended);
 $check(!is_wp_error($again)&&$again['status']==='already_present','same_session_global_duplicate');
 $changed=$intended;$changed['selection_digest']=hash('sha256','tampered');
 $conflict=ClaimLedger::stage($changed);
 $check(is_wp_error($conflict)&&$conflict->get_error_code()==='request_claim_payload_conflict',
  'same_submission_different_payload_denied');
 $second=$intended;
 $second['source_submission_id']=$nextId;
 $second['selection_digest']=hash('sha256','global-fixture-02');
 $second['source_parent_id']=66133;
 $second['composition']['parent']['source_id']=66133;
 $created=ClaimLedger::stage($second);
 $check(!is_wp_error($created)&&$created['basket_count']===2,'second_independent_parent_staged');
 $check(count(RequestBasket::entries())===2,'both_parents_in_customer_session');
 $check(count(WC()->cart->get_cart())===0,'no_payable_wc_cart_lines');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE form_id=$fixtureForm AND submission_id IN ($fixtureId,$nextId)")===2,
  'two_unique_source_claims');

 // Simulate a different customer's session for the exact same persisted form submission.
 $otherSession=new WC_Session_Handler();
 $otherSession->init();
 $otherId=$otherSession->get_customer_id();
 $check($otherId!==$sessionId,'distinct_guest_identity_generated');
 WC()->session=$otherSession;
 $other=ClaimLedger::stage($intended);
 $check(is_wp_error($other)&&$other->get_error_code()==='request_claim_owned_by_other_session',
  'cross_session_replay_blocked');
 WC()->session=$session;
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE form_id=$fixtureForm AND submission_id=$fixtureId")===1,
  'cross_session_no_duplicate_claim');
} finally {
 WC()->session=$session;
 // Test fixture IDs exist solely in isolated QA and are never real Fluent entries.
 $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE form_id=%d AND submission_id IN (%d,%d)",
  $fixtureForm,$fixtureId,$nextId));
 $session->delete_session($sessionId);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$initialClaims,'fixture_claims_removed');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$initialOrders,'hpos_unchanged');
$check($wpdb->get_var($wpdb->prepare(
 "SELECT session_key FROM $sessionTable WHERE session_key=%s",$sessionId))===null,
 'fixture_woo_session_removed');

echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','assertions'=>$tests,'failed'=>$fail,
 'scope'=>'isolated_checkout_qa','global_uniqueness'=>'source_form_id_plus_submission_id',
 'native_wc_cart_lines'=>0,'hpos_unchanged'=>true,'fixtures_cleaned'=>true]).PHP_EOL;
if($fail)exit(2);
