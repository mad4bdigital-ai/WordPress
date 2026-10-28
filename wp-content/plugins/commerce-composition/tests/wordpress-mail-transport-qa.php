<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\WordPressMailTransport;
use Commerce\Composition\GuestConsentProof;
global $wpdb;
$pass=0;$fail=[];
$ok=static function($condition,$label)use(&$pass,&$fail){
 if($condition)$pass++;else $fail[]=$label;
};
$table=$wpdb->prefix.'commerce_guest_consent_proofs';
$forms=$wpdb->prefix.'fluentform_submissions';
$crm=$wpdb->prefix.'fc_subscribers';
$orders=$wpdb->prefix.'wc_orders';
$before=[
 'proofs'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table"),
 'forms'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $forms"),
 'contacts'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $crm"),
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")
];
$ok(WordPressMailTransport::ready(false,3)===false,'default_qa_transport_off');
$ok(WordPressMailTransport::send(false,['form_id'=>3])===false,'missing_payload_no_email');
$ok(WordPressMailTransport::ready(true,3)===true,'existing_verified_provider_allowed');
$ok(WordPressMailTransport::send(true,['form_id'=>3])===true,'prior_provider_does_not_double_send');
$setting=static fn($v)=>[
 'enabled'=>true,'transport'=>'wordpress','delivery_test_approved'=>true
];
$allowLocal=static fn($v)=>true;
$emails=[];
$catchMail=static function($previous,$args)use(&$emails){
 $emails[]=$args;
 return true;
};
add_filter('pre_option_commerce_guest_consent_mail',$setting,999);
add_filter('commerce_composition_consent_allow_local_http',$allowLocal,999);
add_filter('pre_wp_mail',$catchMail,PHP_INT_MAX,2);
try {
 $ok(WordPressMailTransport::ready(false,3)===true,
  'approved_wordpress_mail_transport_resolves');
 $ok(WordPressMailTransport::send(false,[
  'email'=>'invalid','token'=>str_repeat('0',64),'form_id'=>3
 ])===false,'malformed_recipient_rejected');
 $ok(WordPressMailTransport::send(false,[
  'email'=>'qa-not-real@example.invalid','token'=>'bad-token','form_id'=>3
 ])===false,'nonverified_challenge_rejected');
 $wpdb->query('START TRANSACTION');
 try{
  $created=$wpdb->insert($forms,[
   'form_id'=>3,'user_id'=>0,'status'=>'unread',
   'response'=>wp_json_encode([
      'email'=>'qa-never-send@example.invalid',
      'marketing_optin'=>['yes']
   ]),
   'source_url'=>'http://example.invalid/qa-mail-transport',
   'created_at'=>current_time('mysql'),
   'updated_at'=>current_time('mysql')
  ]);
  $sid=(int)$wpdb->insert_id;
  $ok($created===1&&$sid>0,'saved_original_guest_test_form');
  $result=GuestConsentProof::issue(3,$sid);
  $ok(!is_wp_error($result)&&$result['state']==='pending',
   'approved_transport_can_queue_verified_challenge');
  $ok(count($emails)===1,'one_wordpress_mail_invocation_intercepted');
  $mail=$emails[0]??[];
  $ok(($mail['to']??null)==='qa-never-send@example.invalid',
   'only_original_source_email_recipient');
  $ok(str_contains((string)($mail['message']??''),'commerce_consent_confirm='),
   'confirmation_mail_links_to_no_get_mutation_page');
  $ok(str_contains((string)($mail['message']??''),'expires after 24 hours'),
   'mail_explains_expiration');
  $proof=$wpdb->get_row($wpdb->prepare(
   "SELECT state,token_hmac,email_hmac FROM $table
    WHERE submission_id=%d AND form_id=3",$sid
  ),ARRAY_A);
  $ok(($proof['state']??null)==='pending'&&
    !str_contains((string)($mail['message']??''),(string)$proof['token_hmac']),
    'mail_proof_uses_hmac_not_raw_token_column');
  $again=GuestConsentProof::issue(3,$sid);
  $ok(!is_wp_error($again)&&$again['reused']===true &&count($emails)===1,
    'source_replay_no_duplicate_email');
  $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $crm")===$before['contacts'],
    'crm_unchanged_no_subscription');
  $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders")===$before['orders'],
    'woocommerce_orders_unchanged');
 }finally {
  $wpdb->query('ROLLBACK');
 }
}finally {
 remove_filter('pre_option_commerce_guest_consent_mail',$setting,999);
 remove_filter('commerce_composition_consent_allow_local_http',$allowLocal,999);
 remove_filter('pre_wp_mail',$catchMail,PHP_INT_MAX);
}
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$before['proofs'],
 'proof_fixture_transaction_rolled_back');
$ok((int)$wpdb->get_var("SELECT COUNT(*) FROM $forms")===$before['forms'],
 'fluent_form_fixture_transaction_rolled_back');
$ok(WordPressMailTransport::ready(false,3)===false,'transport_defaults_off_after_test');
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','passed'=>$pass,
 'failed'=>$fail,'wordpress_mail'=>'intercepted',
 'real_external_mail'=>false,'crm_subscribed'=>false]).PHP_EOL;
if($fail)exit(2);
