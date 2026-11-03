<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\ClaimLedger;
use Commerce\Composition\RequestCheckoutController;
global $wpdb;
$n=0;$bad=[];$ok=static function($v,$label)use(&$n,&$bad){$n++;if(!$v)$bad[]=$label;};
$session=WC()->session;$id=$session->get_customer_id();
$orders=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$claims=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ClaimLedger::table());
$batch=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches");
$fid=99089201;$ordersCreated=[];
$disabled=RequestCheckoutController::token();
$ok(is_wp_error($disabled)&&$disabled->get_error_code()==='request_checkout_disabled',
 'frontend_checkout_disabled_by_default');
$ok(do_shortcode('[commerce_request_checkout]')==='',
 'frontend_form_not_displayed_without_approved_optin');
$on=static fn($prior)=>true;
add_filter('commerce_composition_enable_request_checkout',$on,90);
try {
 $noSession=RequestCheckoutController::token();
 $ok(is_wp_error($noSession),'no_arbitrary_anonymous_request_without_session');
 $intent=[
  'schema'=>1,'status'=>'validated_intent_not_cart_session_committed',
  'mode'=>'request_only','cart_authority'=>false,'checkout_authority'=>false,
  'form_id'=>6,'source_submission_id'=>$fid,
  'source_parent_id'=>60645,'source_rate_id'=>61158,
  'selection_digest'=>hash('sha256','csrf-fixture'),
  'composition'=>[
   'schema'=>1,'intent'=>'request',
   'status'=>'structure_and_component_evidence_only_not_checkout_authority',
   'parent'=>['service'=>'tours','source_id'=>60645],
   'components'=>[],'price_state'=>'pending'
  ]
 ];
 $staged=ClaimLedger::stage($intent);
 $ok(!is_wp_error($staged),'request_prepared_with_claim');
 $token=RequestCheckoutController::token();
 $ok(is_string($token)&&RequestCheckoutController::verify($token),
 'customer_session_signed_token');
 $ok(!RequestCheckoutController::verify('000.000'),'invalid_token_denied');
 $ok(!RequestCheckoutController::verify(''),'missing_token_denied');
 $html=do_shortcode('[commerce_request_checkout]');
 $ok(str_contains($html,'commerce_request_checkout_submit')&&
     str_contains($html,esc_attr($token)),
     'opted_in_shortcode_shows_session_nonce');
 $ok(str_contains($html,'without payment'),'no_payment_claim_on_form');
 $result=RequestCheckoutController::submit('bad');
 $ok(is_wp_error($result)&&$result->get_error_code()==='request_checkout_invalid_session_token',
     'csrf_invalid_cannot_submit');
 $submitted=RequestCheckoutController::submit($token);
 $ok(!is_wp_error($submitted)&&$submitted['status']==='request_submitted',
     'valid_session_nonce_can_submit_nonfinancial_request');
 if(!is_wp_error($submitted))$ordersCreated[]=$submitted['request_id'];
 $repeat=RequestCheckoutController::submit($token);
 $ok(!is_wp_error($repeat)&&$repeat['status']==='already_submitted',
     'csrf_retry_deduplicates_hpos_order');
 $after=do_shortcode('[commerce_request_checkout]');
 $ok(!str_contains($after,'commerce_request_checkout_submit'),
     'no_submit_button_after_request_is_submitted');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$orders,
     'no_woocommerce_orders_created');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")===$batch+1,
     'one_request_record_created_without_amount');
}finally {
 remove_filter('commerce_composition_enable_request_checkout',$on,90);
 foreach($ordersCreated as $oid){
  $wpdb->delete($wpdb->prefix.'commerce_request_batches',['id'=>$oid],['%d']);
 }
 $wpdb->delete(ClaimLedger::table(),['form_id'=>6,'submission_id'=>$fid],['%d','%d']);
 $session->delete_session($id);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$orders,
 'nonfinancial_request_fixture_removed');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM ".ClaimLedger::table())===$claims,
 'claim_fixture_removed');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")===$batch,
 'batch_fixture_removed');
echo wp_json_encode(['result'=>$bad?'FAIL':'PASS','checks'=>$n,'failed'=>$bad,
 'owner_optin'=>'off_by_default','csrf'=>'woocommerce_guest_session_hmac',
 'request_type'=>'separate_nonfinancial_registry','qa_fixtures_removed'=>true]).PHP_EOL;
if($bad)exit(2);
