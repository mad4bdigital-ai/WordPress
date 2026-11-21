<?php
/**
 * Plugin Name: Local QA Original Form 6 to Request Registry
 * Scope: Woo Lab fixture only; no checkout, sales, supplier booking or email.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;
function qa_original_form6_source_allowed(int $tour, ?int $rate):bool {
 return $tour===66996 && $rate!==null && $rate>0 &&
  get_post_type($tour)==='tours-and-activities' && get_post_status($tour)==='publish' &&
  get_post_type($rate)==='tour-rates' && get_post_status($rate)==='publish' &&
  (int)get_post_meta($rate,'related_tour_id',true)===$tour &&
  str_starts_with((string)get_post_meta($tour,'_booking_experience_qa_pricing_fixture',true),'cart-lab-') &&
  str_starts_with((string)get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true),'cart-lab-');
}
function qa_original_form6_source(int $form,int $id):?array {
 if($form!==6||$id<1)return null;
 global $wpdb;
 $t=$wpdb->prefix.'fluentform_submissions';
 $row=$wpdb->get_row($wpdb->prepare(
  "SELECT status,response FROM $t WHERE form_id=6 AND id=%d",$id),ARRAY_A);
 if(!$row||in_array($row['status'],['spam','trashed','deleted'],true))return null;
 $data=json_decode((string)$row['response'],true);
 $v=is_array($data)?($data['_shared_tour_booking_intent_v2']??null):null;
 if(!is_array($v)||($v['state']??null)!=='requires_review'||
   ($v['checkout_ready']??true)!==false||
   ($v['order_created']??true)!==false||
   ($v['source']??null)!=='rate')return null;
 $tour=(int)($v['tour_id']??0);$rate=(int)($v['rate_id']??0);
 return qa_original_form6_source_allowed($tour,$rate)
  ?['parent_id'=>$tour,'rate_id'=>$rate]:null;
}
add_filter('commerce_composition_rate_registry',static function($rules){
 if(is_array($rules)&&($rules['schema']??null)===1)return $rules;
 return ['schema'=>1,'definitions'=>[
  ['rate_post_type'=>'tour-rates','parent_post_type'=>'tours-and-activities',
   'parent_meta'=>'related_tour_id'],
  ['rate_post_type'=>'property-rates','parent_post_type'=>'properties',
   'parent_meta'=>'prop_related_property_id']
 ]];
});
add_filter('commerce_composition_resolved_policy',static function($policy,$tour,$rate){
 if(is_array($policy)||!qa_original_form6_source_allowed((int)$tour,
    $rate===null?null:(int)$rate))return $policy;
 // Review-only registry refers to the original saved form: no fabricated
 // independently priced or purchased components.
 return ['schema'=>1,'revision'=>'qa-original-6-v1',
  'parent'=>['source_type'=>'tours-and-activities','source_id'=>(int)$tour,
             'rate_id'=>(int)$rate,'service'=>'tours'],
  'groups'=>[]];
},10,3);
add_filter('commerce_composition_booking_form_policy',static function($policy,$ctx){
 if(!is_array($ctx)||!qa_original_form6_source_allowed(
    (int)($ctx['post_id']??0),isset($ctx['rate_id'])?(int)$ctx['rate_id']:null))
  return $policy;
 return ['display_mode'=>'booking_only','booking_form_id'=>6,'inquiry_form_id'=>0];
},10,2);
add_filter('commerce_composition_form_field_bindings',static function($mapping,$tour,$rate){
 return qa_original_form6_source_allowed((int)$tour,$rate===null?null:(int)$rate)
  ?['schema'=>1,'form_id'=>6,'groups'=>[]]:$mapping;
},10,3);
add_filter('commerce_composition_trusted_submission_context',static function($current,$sid,$fid){
 return $current!==null?$current:qa_original_form6_source((int)$fid,(int)$sid);
},10,3);
add_filter('commerce_composition_enable_auto_form_request_intake',static function($enabled,$fid,$sid){
 return $enabled===true?true:qa_original_form6_source((int)$fid,(int)$sid)!==null;
},10,3);
add_filter('commerce_composition_auto_form_request_actor_id',static function($actor,$fid,$sid){
 if($actor!==null)return $actor;
 if(!qa_original_form6_source((int)$fid,(int)$sid))return null;
 $id=(int)get_option('commerce_qa_form6_intake_actor',0);
 return $id>0&&user_can($id,'manage_options')?$id:null;
},10,3);
/**
 * Local QA-only original-source relation evidence.
 * Real production integrations must use an authenticated native FluentCRM feed.
 * No contact upsert, subscription, email or unverified contact promotion here.
 */
add_filter('commerce_composition_verified_event_contact',static function($owner,$source,$context){
 if($owner!==null||!is_array($source)||!is_array($context)||
    ($context['event']??null)!=='stored_form_submission'||
    ($source['domain']??null)!=='fluent_form'||
    ($source['source_key']??null)!=='tour_form6_inquiry')return $owner;
 $sid=(int)($source['source_id']??0);
 if(!qa_original_form6_source(6,$sid))return null;
 $actor=(int)get_option('commerce_qa_form6_intake_actor',0);
 if($actor<1||!user_can($actor,'manage_options'))return null;
 global $wpdb;
 $table=$wpdb->prefix.'fluentform_submissions';
 $saved=$wpdb->get_var($wpdb->prepare(
  "SELECT response FROM $table WHERE id=%d AND form_id=6",$sid));
 $fields=json_decode((string)$saved,true);
 $email=is_array($fields)?sanitize_email((string)($fields['email']??'')):'';
 if(!is_email($email)||!str_ends_with(strtolower($email),'@example.com'))return null;
 $t=$wpdb->prefix.'fc_subscribers';
 $matches=$wpdb->get_results($wpdb->prepare(
  "SELECT id,status FROM $t WHERE email=%s LIMIT 2",$email),ARRAY_A);
 if(count($matches)!==1||$matches[0]['status']!=='transactional')return null;
 return ['contact_id'=>(int)$matches[0]['id'],
         'actor_id'=>$actor,'role'=>'requester'];
},10,3);
// QA-only mail suppression is enabled solely around the real browser test.
add_filter('pre_wp_mail',static function($intercept){
 return get_option('commerce_qa_form6_test_mail_suppressed',false)==='1'
  ?true:$intercept;
},1);
