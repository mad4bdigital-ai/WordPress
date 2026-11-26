<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\GuestConsentProof;
use Commerce\Composition\MarketingConsentTranslations;
global $wpdb;
$passed=0;$errors=[];
$check=static function($truth,$label)use(&$passed,&$errors){
 if($truth)$passed++;else $errors[]=$label;
};
$policy=get_option('etg_crm_consent_forms',[]);
$translations=get_option('commerce_marketing_consent_i18n',[]);
$proofs=$wpdb->prefix.'commerce_guest_consent_proofs';
$originalCounts=[];
foreach(['fc_subscribers','fluentform_submissions','commerce_guest_consent_proofs',
 'commerce_request_batches','wc_orders','etg_crm_consent'] as $t)
 $originalCounts[$t]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$t");
$language=static fn($current)=>'ar';
$de=static fn($current)=>'de';
$sent=[];
$ready=static fn($allowed,$form)=>true;
$delivery=static function($prior,$message)use(&$sent){
 $sent[]=$message;
 return true;
};
$ids=[];
$check(GuestConsentProof::ready(),'durable_guest_proof_table_ready');
$check(isset($translations['translations']['ar']) &&
  isset($translations['translations']['de']),
 'site_language_text_catalog_ready');
foreach([3,6] as $formId) {
 $raw=(string)$wpdb->get_var($wpdb->prepare(
  "SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$formId));
 $check(hash_equals($policy[$formId]['form_sha256'],hash('sha256',$raw)),
  'form_'.$formId.'_unchanged_exact_policy_hash');
}
add_filter('wpml_current_language',$language,990);
$arabic=do_shortcode('[fluentform id="3"]');
remove_filter('wpml_current_language',$language,990);
$check(str_contains($arabic,'أوافق على استقبال عروض السفر'),
 'native_fluentform_renders_arabic_consent_sentence');
$check(str_contains($arabic,'تحديثات وعروض السفر'),
 'native_fluentform_renders_arabic_label');
$check(str_contains($arabic,'value="yes"')||
 str_contains($arabic,"value='yes'"),
 'arabic_display_retains_canonical_yes_value');
$check(!str_contains($arabic,'checked="checked"'),
 'translated_consent_stays_unchecked');
add_filter('wpml_current_language',$de,990);
$german=do_shortcode('[fluentform id="6"]');
remove_filter('wpml_current_language',$de,990);
$check(str_contains($german,'Reiseangebote und Neuigkeiten'),
 'native_fluentform_renders_german_consent');
$check(str_contains($german,'name="marketing_optin[]"') ||
 str_contains($german,"name='marketing_optin[]'"),
 'german_form_has_original_canonical_checkbox_name');
$check(has_filter('fluentform/rendering_field_data_input_checkbox',
 [MarketingConsentTranslations::class,'translate'])!==false,
 'translation_hook_registered_without_overwriting_schema');
try {
 $wpdb->query('START TRANSACTION');
 $now=current_time('mysql');
 foreach(['yes','no'] as $state) {
  $data=['email'=>'qa-anon-check@example.invalid'];
  if($state==='yes')$data['marketing_optin']=['yes'];
  $wpdb->insert($wpdb->prefix.'fluentform_submissions',[
   'form_id'=>3,'user_id'=>0,'status'=>'unread',
   'response'=>wp_json_encode($data),
   'source_url'=>'http://example.invalid/consent-evidence',
   'created_at'=>$now,'updated_at'=>$now
  ]);
  $ids[$state]=(int)$wpdb->insert_id;
  $check($ids[$state]>0,'saved_original_guest_form_'.$state);
 }
 $no=GuestConsentProof::issue(3,$ids['no']);
 $check(is_wp_error($no)&&
     $no->get_error_code()==='guest_consent_not_checked',
   'unchecked_guest_submission_not_a_marketing_proof');
 $noTransport=GuestConsentProof::issue(3,$ids['yes']);
 $check(is_wp_error($noTransport)&&
     $noTransport->get_error_code()==='guest_consent_delivery_not_configured',
   'no_approved_sender_fails_closed_and_no_email_sent');
 $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $proofs")===
  $originalCounts['commerce_guest_consent_proofs'],
  'no_pending_record_without_delivery_transport');
 add_filter('commerce_composition_guest_consent_transport_ready',$ready,90,2);
 add_filter('commerce_composition_guest_consent_deliver',$delivery,90,2);
 try {
  // Simulate a WPML Arabic-language request while capturing the original
  // opt-in; the saved statement must remain auditable when text later changes.
  add_filter('wpml_current_language',$language,990);
  try {
   do_action('fluentform/submission_inserted',
       $ids['yes'],['marketing_optin'=>['yes']],(object)['id'=>3]);
  }finally{
   remove_filter('wpml_current_language',$language,990);
  }
  $check(count($sent)===1,'native_original_form_event_invoked_trusted_delivery_adapter');
  $check($sent[0]['email']==='qa-anon-check@example.invalid',
   'challenge_sent_only_to_original_saved_email');
  $token=$sent[0]['token']??'';
  $check(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)===1,
   'one_time_256_bit_token');
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT id,form_id,submission_id,email_hmac,token_hmac,
     state,expires_utc FROM $proofs WHERE submission_id=%d",
   $ids['yes']),ARRAY_A);
  $check($row&&$row['state']==='pending'&&
   (int)$row['form_id']===3,'pending_proof_is_original_submission_scoped');
  $languageProof=$wpdb->get_row($wpdb->prepare(
   "SELECT display_language,statement_snapshot FROM $proofs
    WHERE submission_id=%d",$ids['yes']),ARRAY_A);
  $check(($languageProof['display_language']??null)==='ar'&&
   str_contains((string)($languageProof['statement_snapshot']??''),'أوافق على استقبال'),
   'exact_arabic_marketing_statement_preserved_at_consent_time');
  $check($row['email_hmac']!==$sent[0]['email']&&
   $row['token_hmac']!==$token,
   'no_raw_email_or_challenge_stored');
  $again=GuestConsentProof::issue(3,$ids['yes']);
  $check(!is_wp_error($again)&&$again['reused']===true &&
   count($sent)===1,'repeat_callback_does_not_send_second_challenge');
  $bad=GuestConsentProof::confirm(str_repeat('0',64));
  $check(is_wp_error($bad),'random_invalid_challenge_denied');
  $t=$wpdb->prefix.'fluentform_submissions';
  $originalResponse=(string)$wpdb->get_var($wpdb->prepare(
   "SELECT response FROM $t WHERE id=%d",$ids['yes']));
  $wpdb->update($t,['response'=>wp_json_encode([
   'email'=>'qa-anon-check@example.invalid'
  ])],['id'=>$ids['yes']]);
  $removedConsent=GuestConsentProof::confirm($token);
  $check(is_wp_error($removedConsent)&&
   $removedConsent->get_error_code()==='guest_consent_original_submission_changed',
   'original_checked_consent_cannot_be_removed_before_verification');
  $wpdb->update($t,['response'=>$originalResponse],['id'=>$ids['yes']]);
  $originalExpiry=$row['expires_utc'];
  $wpdb->update($proofs,['expires_utc'=>gmdate('Y-m-d H:i:s',time()-60)],
     ['submission_id'=>$ids['yes']]);
  $expired=GuestConsentProof::confirm($token);
  $check(is_wp_error($expired)&&
   $expired->get_error_code()==='guest_consent_token_expired_or_unknown',
   'expired_email_proof_cannot_be_consumed');
  $wpdb->update($proofs,['expires_utc'=>$originalExpiry],
     ['submission_id'=>$ids['yes']]);
  $request=new WP_REST_Request('POST',
   '/commerce-composition/v1/guest-consent/confirm');
  $request->set_param('token',$token);
  $rest=rest_do_request($request);
  $check($rest->get_status()===200&&
   ($rest->get_data()['verified_email']??false)===true &&
   ($rest->get_data()['marketing_crm_subscription']??true)===false,
   'one_time_rest_post_verifies_email_only_not_subscriber');
  $used=rest_do_request($request);
  $check($used->get_status()===400,'same_challenge_replay_denied');
  $state=$wpdb->get_var($wpdb->prepare(
   "SELECT state FROM $proofs WHERE submission_id=%d",$ids['yes']));
  $check($state==='verified_email','verification_record_kept_separate_from_crm');
 }finally{
  remove_filter('commerce_composition_guest_consent_transport_ready',$ready,90);
  remove_filter('commerce_composition_guest_consent_deliver',$delivery,90);
 }
}finally{
 $wpdb->query('ROLLBACK');
}
foreach($originalCounts as $table=>$count) {
 $current=(int)$wpdb->get_var(
  "SELECT COUNT(*) FROM {$wpdb->prefix}$table");
 $check($current===$count,'qa_rollback_'.$table);
}
$check(count($sent)===1,'no_real_outbound_delivery_provider_activated');
echo wp_json_encode([
 'result'=>$errors?'FAIL':'PASS','passed'=>$passed,'failed'=>$errors,
 'ar_de_wpml_field_render'=>true,
 'guest_double_optin_proof_only'=>true,
 'mock_delivery'=>true,'real_mail_enabled'=>false,
 'crm_state_mutated'=>false,'qa_cleanup'=>true
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
if($errors)exit(2);
