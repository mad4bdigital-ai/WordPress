<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\GuestConsentProof;
global $wpdb;
$done=0;$failed=[];
$ok=static function($yes,$label)use(&$done,&$failed){$yes?$done++:$failed[]=$label;};
$t=$wpdb->prefix.'commerce_guest_consent_proofs';
$forms=$wpdb->prefix.'fluentform_submissions';
$crm=$wpdb->prefix.'fc_subscribers';
$orders=$wpdb->prefix.'wc_orders';
$original=[
 'proof'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t"),
 'form'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $forms"),
 'contacts'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $crm"),
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")
];
$allow=static fn($prior,$formId)=>true;
$deliveries=0;
$shouldSucceed=false;
$delivery=static function($prior,$payload)use(&$deliveries,&$shouldSucceed){
 $deliveries++;
 return $shouldSucceed;
};
add_filter('commerce_composition_guest_consent_transport_ready',$allow,90,2);
add_filter('commerce_composition_guest_consent_deliver',$delivery,90,2);
$wpdb->query('START TRANSACTION');
try{
 $make=static function($email)use($wpdb,$forms){
  $wpdb->insert($forms,[
   'form_id'=>3,'user_id'=>0,'status'=>'unread',
   'response'=>wp_json_encode(['email'=>$email,'marketing_optin'=>['yes']]),
   'source_url'=>'http://example.invalid/qa-retry',
   'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
  ]);
  return (int)$wpdb->insert_id;
 };
 $one=$make('qa-retry-never-deliver@example.invalid');
 $ok($one>0,'original_guest_optin_saved');
 $initial=GuestConsentProof::issue(3,$one);
 $ok(is_wp_error($initial)&&
   $initial->get_error_code()==='guest_consent_delivery_failed',
   'first_delivery_failure_is_sanitized');
 $row=$wpdb->get_row($wpdb->prepare(
  "SELECT id,state,attempts,next_retry_utc FROM $t WHERE submission_id=%d",$one
 ),ARRAY_A);
 $ok($row && $row['state']==='delivery_failed' &&
   (int)$row['attempts']===1 && !empty($row['next_retry_utc']),
   'first_failure_saved_for_retry_with_backoff');
 $notDue=GuestConsentProof::retryDue(10);
 $ok($notDue['processed']===0,'too_early_cannot_bypass_retry_delay');
 $wpdb->update($t,['next_retry_utc'=>gmdate('Y-m-d H:i:s',time()-120)],
   ['id'=>(int)$row['id']]);
 $shouldSucceed=true;
 $run=GuestConsentProof::retryDue(10);
 $ok($run['processed']===1 &&$run['delivered']===1&&
    $run['failed']===0,'retry_with_new_opaque_token_succeeds');
 $new=$wpdb->get_row($wpdb->prepare(
   "SELECT state,attempts,next_retry_utc FROM $t WHERE id=%d",(int)$row['id']
 ),ARRAY_A);
 $ok($new['state']==='pending' &&(int)$new['attempts']===2 &&
  $new['next_retry_utc']===null,'success_stops_all_further_retries');
 $after=GuestConsentProof::retryDue(10);
 $ok($after['processed']===0 &&$deliveries===2,'no_duplicate_send_after_success');
 $two=$make('qa-retry-optout@example.invalid');
 $shouldSucceed=false;
 $failedOnce=GuestConsentProof::issue(3,$two);
 $ok(is_wp_error($failedOnce),'separate_delivery_failed_fixture');
 $failedId=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT id FROM $t WHERE submission_id=%d",$two));
 $wpdb->update($t,['next_retry_utc'=>gmdate('Y-m-d H:i:s',time()-120)],
   ['id'=>$failedId]);
 $wpdb->update($forms,['response'=>wp_json_encode([
   'email'=>'qa-retry-optout@example.invalid'
 ])],['id'=>$two]);
 $shouldSucceed=true;
 $invalid=GuestConsentProof::retryDue(10);
 $ok($invalid['processed']===1&&$invalid['invalidated']===1,
   'changed_original_optin_invalidates_retry');
 $state=$wpdb->get_var($wpdb->prepare(
   "SELECT state FROM $t WHERE id=%d",$failedId));
 $ok($state==='invalidated','invalid_state_not_sent_to_email');
 $ok($deliveries===3,'invalidated_source_never_triggers_redelivery');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $crm")===$original['contacts'],
  'no_marketing_contact_mutation');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$original['orders'],
  'no_paid_order_mutation');
}finally{
 $wpdb->query('ROLLBACK');
 remove_filter('commerce_composition_guest_consent_transport_ready',$allow,90);
 remove_filter('commerce_composition_guest_consent_deliver',$delivery,90);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")===$original['proof'],
 'no_test_proofs_persist');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $forms")===$original['form'],
 'no_test_forms_persist');
echo wp_json_encode(['result'=>$failed?'FAIL':'PASS',
 'passed'=>$done,'failed'=>$failed,
 'mock_transport_only'=>true,'real_mail_sent'=>false,
 'retry_bounded'=>true,'test_rolled_back'=>true]).PHP_EOL;
if($failed)exit(2);
