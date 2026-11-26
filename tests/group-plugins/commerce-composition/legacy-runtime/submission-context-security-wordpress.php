<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\SubmissionContext;
use Commerce\Composition\RequestIntake;
global $wpdb;
$pass=0;$fail=[];$ok=static function($v,$label)use(&$pass,&$fail){$v?$pass++:$fail[]=$label;};
$formId=6;$sub=0;$prev=get_current_user_id();
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$original=get_option('booking_experience_global_policy',[]);
$mode=static function($old)use($original){$opts=$original;$opts['display_mode']='dual_tabs';return $opts;};
$policy=static function($old,$parent,$rate){
 if($parent!==60645||$rate!==61158)return $old;
 return ['schema'=>1,'revision'=>7,'required_kind'=>'package',
  'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>60645,'rate_id'=>61158],
  'groups'=>[['key'=>'stay','role'=>'choose_one_included','options'=>[
   ['service'=>'properties','source_type'=>'properties','source_id'=>36474],
   ['service'=>'properties','source_type'=>'properties','source_id'=>36481]
  ]]]];
};
$mapping=static fn($old,$parent,$rate)=>['schema'=>1,'form_id'=>6,'groups'=>[
 ['key'=>'stay','field_name'=>'dropdownitenary','control'=>'select']]];
$before=[
 'forms'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions"),
 'context'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_submission_contexts"),
 'claims'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_claims"),
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")
];
add_filter('pre_option_booking_experience_global_policy',$mode,50);
add_filter('commerce_composition_resolved_policy',$policy,50,3);
add_filter('commerce_composition_form_field_bindings',$mapping,50,3);
try{
 $saved=$wpdb->insert($wpdb->prefix.'fluentform_submissions',[
  'form_id'=>6,'status'=>'unread',
  'response'=>wp_json_encode(['dropdownitenary'=>'36474']),
  'source_url'=>'http://example.invalid/qa-context-integrity',
  'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')
 ]);
 $sub=(int)$wpdb->insert_id;
 $ok($saved===1&&$sub>0,'real_form_row_persisted');
 wp_set_current_user(0);
 $denied=SubmissionContext::saveTrusted(6,$sub,60645,61158);
 $ok(is_wp_error($denied)&&$denied->get_error_code()==='submission_context_permission_denied',
  'anonymous_cannot_attest_business_context');
 wp_set_current_user($admin);
 $save=SubmissionContext::saveTrusted(6,$sub,60645,61158);
 $ok($save===true,'authorized_context_written');
 $again=SubmissionContext::saveTrusted(6,$sub,60645,61158);
 $ok($again===true,'repeat_same_source_attestation_idempotent');
 $record=SubmissionContext::read(6,$sub);
 $ok(!is_wp_error($record)&&$record['parent_id']===60645 &&
   $record['rate_id']===61158,'source_receipt_verified');
 $table=$wpdb->prefix.'commerce_submission_contexts';
 $seal=$wpdb->get_var($wpdb->prepare(
  "SELECT seal_sha256 FROM $table WHERE form_id=6 AND submission_id=%d",$sub));
 $wpdb->update($table,['seal_sha256'=>str_repeat('0',64)],[
  'form_id'=>6,'submission_id'=>$sub
 ]);
 $tampered=SubmissionContext::read(6,$sub);
 $ok(is_wp_error($tampered) &&
   $tampered->get_error_code()==='submission_context_seal_invalid',
   'forged_original_source_receipt_detected');
 $forged=RequestIntake::create(6,$sub);
 $ok(is_wp_error($forged),'forged_receipt_cannot_authorize_request_creation');
 $wpdb->update($table,['seal_sha256'=>$seal],[
  'form_id'=>6,'submission_id'=>$sub
 ]);
 $restored=SubmissionContext::read(6,$sub);
 $ok(!is_wp_error($restored),'original_receipt_restored_after_test');
 $formTable=$wpdb->prefix.'fluentform_submissions';
 $wpdb->update($formTable,['response'=>wp_json_encode(['dropdownitenary'=>'999999'])],
   ['id'=>$sub]);
 $unlinked=RequestIntake::create(6,$sub);
 $ok(is_wp_error($unlinked) &&
   $unlinked->get_error_code()==='request_field_choice_unlinked',
   'saved_form_field_cannot_select_unrelated_package_component');
 $wpdb->update($formTable,['response'=>wp_json_encode(['dropdownitenary'=>'36474'])],
   ['id'=>$sub]);
 remove_filter('pre_option_booking_experience_global_policy',$mode,50);
 $notApproved=RequestIntake::create(6,$sub);
 $ok(is_wp_error($notApproved),
   'current_inquiry_only_policy_prevents_server_request_creation');
 add_filter('pre_option_booking_experience_global_policy',$mode,50);
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches")===$before['requests'],
   'failed_attestations_did_not_create_request');
 $ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_claims")===$before['claims'],
   'failed_attestations_did_not_create_global_claim');
}finally{
 if($sub>0){
  $wpdb->delete($wpdb->prefix.'commerce_submission_contexts',
   ['form_id'=>6,'submission_id'=>$sub]);
  $wpdb->delete($wpdb->prefix.'fluentform_submissions',['id'=>$sub]);
 }
 wp_set_current_user($prev);
 remove_filter('pre_option_booking_experience_global_policy',$mode,50);
 remove_filter('commerce_composition_resolved_policy',$policy,50);
 remove_filter('commerce_composition_form_field_bindings',$mapping,50);
}
foreach(['fluentform_submissions'=>'forms','commerce_submission_contexts'=>'context',
 'commerce_request_claims'=>'claims','commerce_request_batches'=>'requests',
 'wc_orders'=>'orders'] as $table=>$key){
 $current=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$table");
 $ok($current===$before[$key],'all_fixtures_removed_'.$key);
}
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS',
 'passed'=>$pass,'failed'=>$fail,
 'forged_receipt_rejected'=>true,
 'unlinked_components_rejected'=>true,
 'default_inquiry_policy_preserved'=>true]).PHP_EOL;
if($fail)exit(2);
