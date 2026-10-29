<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\MarketingConsentField;
global $wpdb;
$passed=0;$failed=[];$assert=static function($yes,$label)use(&$passed,&$failed){
 if($yes)$passed++;else $failed[]=$label;
};
$policy=get_option('etg_crm_consent_forms',[]);
$before=[];
foreach(['fluentform_submissions','fc_subscribers','commerce_workflow_jobs',
 'commerce_request_batches','wc_orders','etg_crm_consent'] as $name){
 $before[$name]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$name");
}
$registered=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_form_meta WHERE meta_key='fluentcrm_feeds'");
foreach([3,6] as $id){
 $form=$wpdb->get_row($wpdb->prepare(
  "SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$id
 ),ARRAY_A);
 $fields=json_decode($form['form_fields'],true);
 $matching=MarketingConsentField::inspect((array)$fields,'marketing_optin');
 $assert(count($matching)===1,'form_'.$id.'_has_one_native_checkbox');
 $f=$matching[0]??[];
 $assert(($f['element']??null)==='input_checkbox' &&
  ($f['attributes']['value']??null)===[],
  'form_'.$id.'_checkbox_unchecked_by_default');
 $assert(($f['settings']['validation_rules']['required']['value']??true)===false,
  'form_'.$id.'_optional_not_required');
 $assert(($f['settings']['advanced_options'][0]['value']??null)==='yes',
  'form_'.$id.'_explicit_yes_only');
 $assert(($policy[$id]['field']??null)==='marketing_optin' &&
  ($policy[$id]['accepted_value']??null)==='yes' &&
  ($policy[$id]['purpose']??null)==='marketing_updates',
  'form_'.$id.'_consent_version_registered');
 $assert(hash_equals((string)($policy[$id]['form_sha256']??''),
  hash('sha256',$form['form_fields'])),
  'form_'.$id.'_signed_exact_text_and_schema');
 $markup=do_shortcode('[fluentform id="'.$id.'"]');
 $assert(is_string($markup)&&str_contains($markup,'marketing_optin'),
  'form_'.$id.'_checkbox_rendered_in_original_flientform_shortcode');
 $assert(str_contains((string)$markup,
  'I agree to receive occasional travel offers'),
  'form_'.$id.'_marketing_purpose_visible');
}
$policyStill=get_option('etg_crm_consent_forms',[]);
$assert(isset($policyStill[14])&&
 $policyStill[14]['purpose']==='destination_news',
 'preexisting_form14_consent_policy_preserved');
$assert((int)$registered===0,'native_fluentcrm_feed_kept_off');
$beforeConsent=$before['etg_crm_consent'];
$entry=(object)[
 'id'=>9999,'form_id'=>3,'user_id'=>
    (int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0],
 'created_at'=>current_time('mysql'),'response'=>wp_json_encode([
  'names'=>['first_name'=>'Test'],'email'=>'qa-no-message@example.invalid'
 ])
];
$blocked=ETG_FluentCRM_Engagement::record_form_consent($entry,$policy[3]);
$assert(is_wp_error($blocked)&&
 $blocked->get_error_code()==='crm_no_explicit_consent',
 'consent_rule_refuses_unchecked_original_submission');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}etg_crm_consent")===$beforeConsent,
 'unchecked_does_not_create_marketing_consent_receipt');
// A real Fluent Forms submission with checked/unchecked state, with
// notifications blocked and all writes rolled back.
$emails=0;
$intercept=static function($previous)use(&$emails){$emails++;return true;};
$forceSync=static fn($enabled,$form)=>false;
add_filter('pre_wp_mail',$intercept,PHP_INT_MAX);
add_filter('fluentform/notifying_async_email',$forceSync,PHP_INT_MAX,2);
add_filter('fluentform/notifying_async_notifications',$forceSync,PHP_INT_MAX,2);
$original=get_current_user_id();wp_set_current_user(0);
$created=0;
try {
 $wpdb->query('START TRANSACTION');
 foreach([false,true] as $selected){
  $submission=[
   'dropdown'=>'Mr','names'=>[
    'first_name'=>'QA','last_name'=>'ConsentField'
   ],
   'email'=>'qa-consent-check@example.invalid',
   'description'=>'QA only, no customer contact or outbound message'
  ];
  if($selected)$submission['marketing_optin']=['yes'];
  $handler=new \FluentForm\App\Services\Form\SubmissionHandlerService();
  $r=$handler->handleSubmission($submission,3);
  $id=(int)($r['insert_id']??0);
  $assert($id>0,'native_original_form_submitted_'.($selected?'checked':'unchecked'));
  $raw=$wpdb->get_var($wpdb->prepare(
   "SELECT response FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d",$id
  ));
  $data=json_decode((string)$raw,true);
  $has=(array)($data['marketing_optin']??[]);
  $assert($selected?in_array('yes',$has,true):!in_array('yes',$has,true),
    'actual_saved_marketing_value_matches_checkbox_'.($selected?'checked':'unchecked'));
  $created++;
 }
}finally{
 $wpdb->query('ROLLBACK');
 wp_set_current_user($original);
 remove_filter('pre_wp_mail',$intercept,PHP_INT_MAX);
 remove_filter('fluentform/notifying_async_email',$forceSync,PHP_INT_MAX);
 remove_filter('fluentform/notifying_async_notifications',$forceSync,PHP_INT_MAX);
}
$assert($created===2,'both_native_submissions_executed');
$assert($emails>=1,'original_form_notifications_intercepted');
foreach($before as $name=>$count){
 $after=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$name");
 $assert($after===$count,'qa_transaction_preserves_'.$name);
}
echo wp_json_encode([
 'result'=>$failed?'FAIL':'PASS','passed'=>$passed,'failed'=>$failed,
 'tested_forms'=>[3,6],
 'native_checked_and_unchecked'=>true,
 'native_feed_enabled'=>false,'mail_intercepted'=>$emails,
 'transactional_cleanup'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($failed)exit(2);
