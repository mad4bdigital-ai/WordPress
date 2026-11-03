<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\FluentSubmissionAdapter;
use Commerce\Composition\RequestBasket;
use Commerce\Composition\ClaimLedger;

global $wpdb;
$submissions=$wpdb->prefix.'fluentform_submissions';
$claims=ClaimLedger::table();
$wcSessions=$wpdb->prefix.'woocommerce_sessions';
$checks=0;$fail=[];
$check=static function($ok,$name)use(&$checks,&$fail){$checks++;if(!$ok)$fail[]=$name;};
$countBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $submissions");
$claimsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $claims");
$ordersBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders");
$originalMode=get_option('booking_experience_global_policy',[]);
$oldSession=WC()->session;$sessionKey=$oldSession->get_customer_id();
$existingSession=$wpdb->get_var($wpdb->prepare(
 "SELECT session_value FROM $wcSessions WHERE session_key=%s",$sessionKey));
$fixture=0;
$policy=[
 'schema'=>1,'revision'=>7,'required_kind'=>'package',
 'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>60645,'rate_id'=>61158],
 'groups'=>[['key'=>'stay','role'=>'choose_one_included','options'=>[
  ['service'=>'properties','source_type'=>'properties','source_id'=>36474],
  ['service'=>'properties','source_type'=>'properties','source_id'=>36481]
 ]]]
];
$rules=static function($prior,$pid,$rate)use($policy){
 return $pid===60645&&$rate===61158?$policy:$prior;
};
$map=static function($prev,$pid,$rate){
 return ['schema'=>1,'form_id'=>6,'groups'=>[
  ['key'=>'stay','field_name'=>'dropdownitenary','control'=>'select']
 ]];
};
$opt=$originalMode;$opt['display_mode']='dual_tabs';
$mode=static fn($val)=>$opt;
try {
 // Only the QA fixture row is inserted: no email, contact or payment data.
 $saved=$wpdb->insert($submissions,[
  'form_id'=>6,'status'=>'unread',
  'response'=>wp_json_encode(['dropdownitenary'=>'36474']),
  'source_url'=>'http://example.invalid/test-fixture',
  'created_at'=>gmdate('Y-m-d H:i:s'),
  'updated_at'=>gmdate('Y-m-d H:i:s')
 ],['%d','%s','%s','%s','%s','%s']);
 $check($saved===1,'real_fluent_database_submission_inserted');
 $fixture=(int)$wpdb->insert_id;
 $check($fixture>0,'real_submission_id_issued');
 $proof=Commerce\Composition\SubmissionProvenance::verify($fixture,6);
 $check($proof===true,'original_fluent_submission_verified_without_mock');
 $foreign=Commerce\Composition\SubmissionProvenance::verify($fixture,3);
 $check(is_wp_error($foreign)&&$foreign->get_error_code()==='submission_form_mismatch',
  'wrong_template_not_accepted');
 $on=static fn($old,$form,$id)=>$form===6&&$id===$fixture;
 $context=static fn($old,$sid,$fid)=>$sid===$fixture&&$fid===6
     ?['parent_id'=>60645,'rate_id'=>61158]:null;
 add_filter('commerce_composition_resolved_policy',$rules,50,3);
 add_filter('commerce_composition_form_field_bindings',$map,50,3);
 add_filter('pre_option_booking_experience_global_policy',$mode,50);
 add_filter('commerce_composition_enable_submission_to_request_basket',$on,50,3);
 add_filter('commerce_composition_trusted_submission_context',$context,50,3);
 try {
  $data=['dropdownitenary'=>'36474'];
  $form=(object)['id'=>6];
  // The native hook is invoked in a controlled callback-only harness. Other
  // Fluent/CRM/email listeners are NOT run with this incomplete fixture.
  $result=FluentSubmissionAdapter::stageSubmission($fixture,$data,$form);
  $check(!is_wp_error($result),'saved_fluent_entry_becomes_request');
  $check(!is_wp_error($result)&&$result['outcome']['status']==='staged_request',
    'saved_form_request_staged');
  $key='6:'.$fixture;
  $items=RequestBasket::entries();
  $check(isset($items[$key])&&$items[$key]['composition']['components'][0]['source_id']===36474,
   'verified_component_stored');
  $check(!is_wp_error($result)&&$result['intent']['source_submission_id']===$fixture,
   'intent_points_to_exact_saved_submission');
  $claim=$wpdb->get_row($wpdb->prepare(
   "SELECT state FROM $claims WHERE form_id=%d AND submission_id=%d",6,$fixture),ARRAY_A);
  $check($claim && $claim['state']==='staged','committed_global_claim_for_real_form_row');
  $again=FluentSubmissionAdapter::stageSubmission($fixture,$data,$form);
  $check(!is_wp_error($again) && $again['outcome']['status']==='already_present',
   'saved_submission_retry_deduped_globally');
  $check(count(RequestBasket::entries())===1,'exactly_one_request_line');
  $check(count(WC()->cart->get_cart())===0,'no_paid_wc_cart_inserted');
  FluentSubmissionAdapter::onSubmission($fixture,$data,$form);
  $confirmation=apply_filters('fluentform/submission_confirmation',
      ['message'=>'Generic received','redirectUrl'=>'https://example.invalid'],
      $form,[],$fixture,$data);
  $check(str_contains((string)($confirmation['message']??''),'added to your request basket'),
      'saved_fluent_submission_receives_confirmed_request_message');
  $check(str_contains((string)($confirmation['message']??''),'No payment has been taken'),
      'customer_acknowledgment_never_implies_paid_booking');
  $check(!array_key_exists('redirectUrl',$confirmation),
      'generic_confirmation_redirect_suppressed_for_handled_booking');
 }finally {
  remove_filter('commerce_composition_resolved_policy',$rules,50);
  remove_filter('commerce_composition_form_field_bindings',$map,50);
  remove_filter('pre_option_booking_experience_global_policy',$mode,50);
  remove_filter('commerce_composition_enable_submission_to_request_basket',$on,50);
  remove_filter('commerce_composition_trusted_submission_context',$context,50);
 }
}finally{
 if($fixture>0){
  $wpdb->delete($claims,['form_id'=>6,'submission_id'=>$fixture],['%d','%d']);
  $wpdb->delete($submissions,['id'=>$fixture,'form_id'=>6],['%d','%d']);
 }
 if($existingSession===null)$oldSession->delete_session($sessionKey);
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $submissions")===$countBefore,
 'fluent_fixture_deleted');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM $claims")===$claimsBefore,
 'unique_claim_fixture_deleted');
$check(get_option('booking_experience_global_policy',[])===$originalMode,
 'inquiry_policy_unchanged');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===$ordersBefore,
 'hpos_unchanged');
$check($wpdb->get_var($wpdb->prepare(
 "SELECT session_value FROM $wcSessions WHERE session_key=%s",$sessionKey))===$existingSession,
 'session_fixture_removed');
echo wp_json_encode([
 'result'=>$fail?'FAIL':'PASS',
 'assertions'=>$checks,'failed'=>$fail,
 'real_saved_fluent_submission'=>true,
 'full_fluent_email_crm_listener_chain'=>false,
 'all_qa_fixtures_removed'=>true
]).PHP_EOL;
if($fail)exit(2);
