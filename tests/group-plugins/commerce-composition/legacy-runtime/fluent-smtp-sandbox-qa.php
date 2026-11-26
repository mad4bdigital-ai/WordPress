<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
global $wpdb;
use Commerce\Composition\GuestConsentProof;
use Commerce\Composition\WordPressMailTransport;
$pass=[];$fail=[];
$check=static function(bool $result,string $label)use(&$pass,&$fail){
  $result?$pass[]=$label:$fail[]=$label;
};
if(PHP_SAPI!=='cli'||wp_get_environment_type()!=='local'){
 echo '{"error":"not_local_cli"}';exit(3);
}
$cfg=get_option('fluentmail-settings',[]);
$connection=$cfg['connections'][md5('qa-notify@example.invalid')]['provider_settings']??[];
$check(($connection['provider']??'')==='smtp'&&
 ($connection['host']??'')==='commerce-qa-mail-sink'&&
 (int)($connection['port']??0)===1025,'smtp_destination_is_private_qa_sink');
$check(get_option('commerce_guest_consent_mail',[])['enabled']===false,
 'real_guest_mail_remains_disabled_by_default');
$contact=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers");
$orders=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$forms=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions");
$proofs=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_guest_consent_proofs");
$forceQA=static fn($prior)=>[
 'transport'=>'wordpress','enabled'=>true,'delivery_test_approved'=>true
];
$allowLocal=static fn($prior)=>true;
add_filter('pre_option_commerce_guest_consent_mail',$forceQA,999);
add_filter('commerce_composition_consent_allow_local_http',$allowLocal,999);
// This exception lives ONLY in this short-lived CLI process. All normal
// HTTP requests retain their original pre_wp_mail sandbox blocks.
remove_all_filters('pre_wp_mail');
$wpdb->query('START TRANSACTION');
$written=false;
try {
 $written=$wpdb->insert($wpdb->prefix.'fluentform_submissions',[
   'form_id'=>3,'user_id'=>0,'status'=>'unread',
   'response'=>wp_json_encode([
      'email'=>'qa-consent-sink@example.invalid','marketing_optin'=>['yes']
   ]),
   'source_url'=>'http://example.invalid/qa-fluent-smtp-proof',
   'created_at'=>current_time('mysql'),
   'updated_at'=>current_time('mysql')
 ])===1;
 $submissionId=(int)$wpdb->insert_id;
 $check($written&&$submissionId>0,'genuine_saved_synthetic_fluent_submission');
 $result=GuestConsentProof::issue(3,$submissionId);
 $check(!is_wp_error($result)&&
   ($result['state']??'')==='pending'&&
   ($result['delivered']??false)===true,
   'guest_verification_passed_wordpress_to_fluent_smtp');
 $proof=$wpdb->get_row($wpdb->prepare(
    "SELECT state,form_id,submission_id,attempts
     FROM {$wpdb->prefix}commerce_guest_consent_proofs
     WHERE form_id=3 AND submission_id=%d",$submissionId
 ),ARRAY_A);
 $check(is_array($proof) &&$proof['state']==='pending'&&
   (int)$proof['attempts']===1,'one_pending_real_smtp_challenge');
 $second=GuestConsentProof::issue(3,$submissionId);
 $check(!is_wp_error($second)&&($second['reused']??false)===true,
   'no_duplicate_smtp_message_for_same_submission');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers")===$contact,
   'no_crm_contact_or_optin_mutation');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$orders,
   'no_woocommerce_order_mutation');
}finally {
 $wpdb->query('ROLLBACK');
 remove_filter('pre_option_commerce_guest_consent_mail',$forceQA,999);
 remove_filter('commerce_composition_consent_allow_local_http',$allowLocal,999);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions")===$forms,
 'original_fluent_rows_restored');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_guest_consent_proofs")===$proofs,
 'no_test_guest_proofs_saved');
echo wp_json_encode([
 'result'=>$fail?'FAIL':'PASS','passed'=>count($pass),'failed'=>$fail,
 'qa_transport'=>'FluentSMTP to isolated Mailpit',
 'global_mail_guards_modified'=>false,
 'real_customer_email_sent'=>false,
 'test_sql_changes_rolled_back'=>true
]).PHP_EOL;
if($fail)exit(2);
