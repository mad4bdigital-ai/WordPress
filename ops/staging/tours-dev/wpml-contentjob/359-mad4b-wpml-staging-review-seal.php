<?php
/**
 * Plugin Name: MAD4B WPML — Staging Review Receipt Sealer
 * Description: User-authorized published translation readback, source QA and native ContentJob approval.
 * Version: 0.1.0-staging
 */
defined('ABSPATH')||exit;
final class MAD4B_WPML_Staging_Review_Seal_V1 {
 public static function ready(){
  return function_exists('wp_get_environment_type')
   &&wp_get_environment_type()==='staging'
   &&rtrim(home_url(),'/')==='https://tours.dev.mad4b.com'
   &&class_exists('MAD4B_WPML_ContentJob_Adapter')
   &&class_exists('MAD4B_Elementor_Localization_V1');
 }
 public static function seal($job_id,$review_ref){
  if(!self::ready()||get_current_user_id()!==26||!current_user_can('manage_options')
   ||!current_user_can('edit_others_posts')
   ||!class_exists('MAD4B_SCP_Policy')
   ||true!==MAD4B_SCP_Policy::can_mutate())
   return new WP_Error('mad4b_stage_seal_unauthorized');
  if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',(string)$job_id)
   ||!preg_match('/^MAD4B_STAGE_QA_20261102_[0-9]{4,7}$/D',(string)$review_ref))
   return new WP_Error('mad4b_stage_seal_invalid_identifiers');
  $scope=MAD4B_SCP_Operational_Integrity::capture();
  if(is_wp_error($scope)||($scope['scope']['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')
   return new WP_Error('mad4b_stage_seal_site_mismatch');
  $binding=MAD4B_WPML_ContentJob_Adapter::binding(['job_id'=>$job_id]);
  if(is_wp_error($binding))return $binding;
  if($binding['master_or_post_excluded'])return new WP_Error('mad4b_stage_seal_excluded');
  $source=(int)$binding['source_post_id'];$target=(int)$binding['target_post_id'];
  $lang=(string)$binding['target_language'];
  if(!in_array($source,[41731,41738,54880,43704],true)||!in_array($lang,['ar','fr','de','es','it'],true)
   ||get_post_type($target)!=='elementor_library'
   ||get_post_status($target)!=='publish'||get_post_status($source)!=='publish'
   ||$review_ref!=='MAD4B_STAGE_QA_20261102_'.$target)
   return new WP_Error('mad4b_stage_seal_invalid_target_or_reference');
  $receipt=(array)get_option('mad4b_wpml_staging_publication_receipt_'.$target.'_v1',[]);
  $raw=(string)get_post_meta($target,'_elementor_data',true);
  $source_trid=(int)apply_filters('wpml_element_trid',null,$source,'post_elementor_library');
  $target_trid=(int)apply_filters('wpml_element_trid',null,$target,'post_elementor_library');
  $lang_data=(array)apply_filters('wpml_post_language_details',null,$target);
  if(($receipt['contract']??'')!=='mad4b.wpml.staging-publication-receipt.v1'
   ||($receipt['status']??'')!=='published'||($receipt['wpml_readback_passed']??false)!==true
   ||($receipt['source']??0)!==$source||($receipt['target']??0)!==$target
   ||($receipt['lang']??'')!==$lang||($receipt['job']??'')!==$job_id
   ||($receipt['review_ref']??'')!==$review_ref
   ||!preg_match('/^mad4b[.]wpml[.]stage[.][a-f0-9]{32}$/D',(string)($receipt['receipt_id']??''))
   ||($receipt['elementor_sha256']??'')!==hash('sha256',$raw)
   ||!$source_trid||$source_trid!==$target_trid
   ||($lang_data['language_code']??'')!==$lang)
   return new WP_Error('mad4b_stage_seal_publication_proof_invalid');
  $scan=MAD4B_Elementor_Localization_V1::inspect(450);
  if(!is_array($scan)||empty($scan['complete']))return new WP_Error('mad4b_stage_seal_incomplete_scan');
  foreach((array)($scan['issues']??[]) as $issue){
   if((int)($issue['object_id']??0)===$source
     &&str_ends_with((string)($issue['code']??''),'_'.$lang)
     &&str_starts_with((string)($issue['code']??''),'loc_'))
    return new WP_Error('mad4b_stage_seal_live_gap_remaining',
     'New translation gap: '.(string)($issue['code']??'unknown'));
  }
  $native=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
  if(is_wp_error($native))return $native;
  if(($native['job']['state']??'')==='COMPLETED'){
   $audit=(array)get_option('mad4b_wpml_review_final_approval_'.$target.'_v1',[]);
   if(($audit['receipt_id']??'')===($receipt['receipt_id']??'')
     &&($audit['review_ref']??'')===$review_ref)
    return ['contract'=>'mad4b.wpml.staging-final-review.v1','already_completed'=>true,
     'target'=>$target,'state'=>'COMPLETED','receipt_id'=>$receipt['receipt_id']];
   return new WP_Error('mad4b_stage_seal_already_completed_without_audit');
  }
  if(($native['job']['state']??'')!=='WAITING_REVIEW')
   return new WP_Error('mad4b_stage_seal_job_not_waiting_review');
  global $wpdb;$lock='mad4b_elementor_localization_v1';
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!=='1')
   return new WP_Error('mad4b_stage_seal_outbox_busy');
  $changed=0;
  try{
   $outbox=(array)get_option(MAD4B_Elementor_Localization_V1::OPTION,[]);
   $gaps=array_values(array_unique((array)$binding['current_gap_codes']));
   if(!$gaps)return new WP_Error('mad4b_stage_seal_no_matching_gap');
   $receipts=[];
   foreach($outbox as $key=>$entry){
    if((int)($entry['source_post_id']??0)!==$source
      ||($entry['target_language']??'')!==$lang
      ||!in_array(($entry['gap_code']??''),$gaps,true))continue;
    if(!in_array(($entry['status']??''),['needs_review','verified','resolved_after_readback','queued'],true))
     return new WP_Error('mad4b_stage_seal_outbox_in_use');
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($entry['source_sha256']??'')))
     return new WP_Error('mad4b_stage_seal_digest_missing');
    $entry['status']='verified';$entry['readback_passed']=true;
    $entry['receipt_id']=$receipt['receipt_id'];$entry['review_reference']=$review_ref;
    $entry['verified_published_target_id']=$target;$entry['verified_at']=gmdate('c');
    $entry['last_error']=null;$entry['lease_until']=null;
    $outbox[$key]=$entry;$receipts[]=$key;$changed++;
   }
   if(!$changed)return new WP_Error('mad4b_stage_seal_historical_gap_not_found');
   update_option(MAD4B_Elementor_Localization_V1::OPTION,$outbox,false);
   if(get_option(MAD4B_Elementor_Localization_V1::OPTION,[])!==$outbox)
    return new WP_Error('mad4b_stage_seal_outbox_readback_failed');
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  $after=MAD4B_WPML_ContentJob_Adapter::binding(['job_id'=>$job_id]);
  if(is_wp_error($after)||($after['readback_verified']??false)!==true)
   return new WP_Error('mad4b_stage_seal_binding_readback_unverified');
  $complete=MAD4B_WPML_ContentJob_Adapter::approve_verified($job_id,$review_ref);
  if(is_wp_error($complete))return $complete;
  if(($complete['state']??'')!=='COMPLETED')
   return new WP_Error('mad4b_stage_seal_native_transition_unconfirmed');
  $record=['contract'=>'mad4b.wpml.staging-final-review.v1','job_id'=>$job_id,
    'source'=>$source,'target'=>$target,'lang'=>$lang,'review_ref'=>$review_ref,
    'receipt_id'=>$receipt['receipt_id'],'outbox_receipts_sealed'=>$changed,
    'native_state'=>'COMPLETED','wpml_readback_verified'=>true,'at'=>gmdate('c')];
  $key='mad4b_wpml_review_final_approval_'.$target.'_v1';
  update_option($key,$record,false);
  if(get_option($key,[])!==$record)
   return new WP_Error('mad4b_stage_seal_final_audit_write_uncertain');
  return $record;
 }
 public static function cli($args,$opts){
  $result=self::seal((string)($args[0]??''),(string)($opts['review-ref']??''));
  if(is_wp_error($result))WP_CLI::error($result->get_error_code().': '.$result->get_error_message());
  WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot(){
  if(self::ready()&&defined('WP_CLI')&&WP_CLI)
   WP_CLI::add_command('mad4b-wpml-seal',[__CLASS__,'cli']);
 }
}
add_action('plugins_loaded',['MAD4B_WPML_Staging_Review_Seal_V1','boot'],121);
