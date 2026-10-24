<?php
if (!defined('ABSPATH'))exit;
/** Google Ads resource mutation planning only; no OAuth write or remote modification. */
final class MAD4B_Google_Measurement_Ads_Plans {
 const CONTRACT='mad4b.google-ads-plan.v1';
 public static function run($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())
   return new WP_Error('mad4b_ads_denied','Owner access required.');
  $binding=MAD4B_Google_Measurement_Ads::binding();
  if (empty($binding['ready']))return new WP_Error('mad4b_ads_unbound','Google Ads account must be selected.');
  $operation=(string)($input['operation']??'');
  $supported=array('campaign_create','campaign_update','campaign_pause','campaign_enable',
   'budget_update','ad_group_create','ad_group_update','keyword_add','keyword_pause',
   'negative_keyword_add','ad_create','ad_update','asset_attach',
   'audience_attach','conversion_action_create','conversion_action_update',
   'recommendation_apply','customer_link_review');
  if (!in_array($operation,$supported,true))
   return new WP_Error('mad4b_ads_bad_operation','Unsupported Ads operation.');
  $resource=(string)($input['resource_id']??'');
  if ($resource!==''&&!preg_match('/^[0-9]{1,20}$/D',$resource))
   return new WP_Error('mad4b_ads_invalid_resource','Invalid resource identity.');
  $site=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():array();
  if (!is_array($site)||empty($site['origin_match'])||empty($site['environment_match']))
   return new WP_Error('mad4b_ads_site_unverified','Exact site identity required.');
  $changes=$input['changes']??array();
  if (!is_array($changes)||count($changes)>15)return new WP_Error('mad4b_ads_changes_invalid','Invalid changes.');
  $clean=array();
  foreach($changes as $key=>$val) {
   if (!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,40}$/D',$key)||
     !is_scalar($val)||strlen((string)$val)>200)
    return new WP_Error('mad4b_ads_changes_invalid','Unsupported change field.');
   $clean[$key]=(string)$val;
  }
  ksort($clean);
  $canonical=array('operation'=>$operation,'resource_id'=>$resource,'changes'=>$clean,
    'customer_id'=>$binding['customer_id'],'login_customer_id'=>$binding['login_customer_id'],
    'site_uuid'=>$binding['site_uuid'],'actor'=>(int)get_current_user_id());
  return array('contract'=>self::CONTRACT,'plan_sha256'=>hash('sha256',wp_json_encode($canonical,JSON_UNESCAPED_SLASHES)),
   'operation'=>$operation,'customer_id'=>$binding['customer_id'],
   'changes'=>$clean,'stage'=>'proposal_only',
   'developer_token_required'=>false,
   'required_google_scope'=>MAD4B_Google_Measurement_Ads::SCOPE,
   'required_checks'=>array('cloud_project_production_api_access','google_ads_customer_permissions',
    'current_resource_snapshot','budget_impact','policy_compliance','tracking_and_consent',
    'dry_run_validate_only','one_time_owner_approval','provider_readback'),
   'remote_mutation_enabled'=>false,'mutation_performed'=>false);
 }
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $name='mad4b/google-ads-operation-plan';
  if (function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array('label'=>'Google Ads Operation Plan',
   'description'=>'Read-only, exact account-bound plans for ads campaigns, budgets, conversion tracking, audiences and assets.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('operation'),
    'properties'=>array('operation'=>array('type'=>'string','enum'=>array('campaign_create','campaign_update','campaign_pause','campaign_enable','budget_update','ad_group_create','ad_group_update','keyword_add','keyword_pause','negative_keyword_add','ad_create','ad_update','asset_attach','audience_attach','conversion_action_create','conversion_action_update','recommendation_apply','customer_link_review')),
     'resource_id'=>array('type'=>'string','maxLength'=>20),
     'changes'=>array('type'=>'object','additionalProperties'=>array('type'=>'string')))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,
     'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
     'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
