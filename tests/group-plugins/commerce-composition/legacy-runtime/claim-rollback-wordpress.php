<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\ClaimLedger;
global $wpdb;
$tests=0;$failed=[];
$check=static function($yes,$name)use(&$tests,&$failed){$tests++;if(!$yes)$failed[]=$name;};
$session=WC()->session;
$id=$session->get_customer_id();
$table=ClaimLedger::table();
$sesTable=$wpdb->prefix.'woocommerce_sessions';
$initial=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$orig=$wpdb->get_var($wpdb->prepare("SELECT session_value FROM $sesTable WHERE session_key=%s",$id));
$fixture=[
 'schema'=>1,'mode'=>'request_only','status'=>'validated_intent_not_cart_session_committed',
 'cart_authority'=>false,'checkout_authority'=>false,'form_id'=>6,'source_submission_id'=>99083001,
 'selection_digest'=>hash('sha256','failed-commercial-mode'),
 'composition'=>[
  'schema'=>1,'intent'=>'sale',
  'status'=>'structure_and_component_evidence_only_not_checkout_authority'
 ]
];
$r=ClaimLedger::stage($fixture);
$check(is_wp_error($r)&&$r->get_error_code()==='request_component_plan_unverified',
 'invalid_business_intent_denied');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$initial,
 'claim_unique_transaction_rolled_back');
$after=$wpdb->get_var($wpdb->prepare("SELECT session_value FROM $sesTable WHERE session_key=%s",$id));
$check($after===$orig,'no_unconfirmed_wc_session_data_committed');
$check(count(WC()->cart->get_cart())===0,'no_paid_wc_cart_line');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS','assertions'=>$tests,'failed'=>$failed,
 'transaction_rollback_confirmed'=>true]).PHP_EOL;
if($failed)exit(2);
