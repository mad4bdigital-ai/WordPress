<?php
if (!defined('ABSPATH'))exit;
/**
 * Brand Authority content/ad group handoff contract: plans and checks only.
 * No publishing, no direct CPT mutation, no external ad creation.
 */
final class MAD4B_Keyword_Handoff {
 const CONTRACT='mad4b.keyword-handoff-plan.v1';
 public static function run($input=array()){
  if(!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('keyword_handoff_denied','Owner read permission required.');
  $project_id=(string)($input['project_id']??'');
  if(!preg_match('/^research_[a-f0-9]{20}$/D',$project_id))return new WP_Error('keyword_project_identity','Valid research project identity required.');
  $project=MAD4B_Keyword_Projects::run(array('action'=>'get','project_id'=>$project_id));
  if(is_wp_error($project))return $project;
  $record=$project['project'];
  $type=(string)($input['target']??'content_job');
  if(!in_array($type,array('content_job','ads_ad_group'),true))return new WP_Error('keyword_target','Unsupported planning target.');
  $source=array('contract'=>self::CONTRACT,'project_id'=>$project_id,
   'keyword_sha256'=>hash('sha256',wp_json_encode($record['keywords'],JSON_UNESCAPED_UNICODE)),
   'draft_title'=>$record['title'],'keywords'=>array_slice($record['keywords'],0,50),
   'site_uuid'=>$record['site_uuid'],'target'=>$type);
  $ads=null;
  if($type==='ads_ad_group'){
   $ads=MAD4B_Google_Measurement_Ads::binding();
   if(empty($ads['ready']))return new WP_Error('keyword_ads_unbound','Google Ads account is not bound.');
   $source['customer_id']=$ads['customer_id'];
  }
  $receipt=hash('sha256',wp_json_encode($source,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return array('contract'=>self::CONTRACT,'plan_sha256'=>$receipt,'target'=>$type,
   'source'=>$source,'handoff_status'=>'proposal_only',
   'preconditions'=>$type==='content_job'
    ?array('brand_context_receipt','approved_editorial_guidelines','gsc_serp_validation','dedup_existing_content','one_time_content_job_approval')
    :array('google_ads_scope','account_rights','negative_keyword_review','forecast_review','one_time_ads_approval'),
   'content_created'=>false,'ad_group_created'=>false,'mutation_performed'=>false);
 }
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  $name='mad4b/keyword-handoff-plan';
  if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array('label'=>'Keyword Research Handoff Plan',
   'description'=>'Plan exact review-only handoff to WordPress Content Jobs or Google Ads groups.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('project_id','target'),
    'properties'=>array('project_id'=>array('type'=>'string','pattern'=>'^research_[a-f0-9]{20}$'),
     'target'=>array('type'=>'string','enum'=>array('content_job','ads_ad_group')))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
