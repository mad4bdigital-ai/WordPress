<?php
if (!defined('ABSPATH')) exit;
/** Guarded advanced operation proposals; never writes to Google or publishes a container. */
final class MAD4B_Google_Measurement_Operations {
 const CONTRACT='mad4b.google-measurement.operation-plan.v1';
 public static function run($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('measurement_forbidden','Owner required.');
  $binding=MAD4B_Google_Measurement_Admin::binding();
  if (empty($binding['ready']))return new WP_Error('measurement_binding_required','Exact Google property and container required.');
  $operation=(string)($input['operation']??'');
  $valid=array('audience_create','audience_update','audience_archive','gtm_tag_create','gtm_tag_update',
   'gtm_trigger_create','gtm_variable_create','gtm_workspace_version','gtm_publish','gtm_rollback',
   'custom_dimension_sync','report_schedule','anomaly_rules','conversion_reconciliation');
  if (!in_array($operation,$valid,true))return new WP_Error('measurement_operation_unsupported','Unsupported operation.');
  $scope=strpos($operation,'audience_')===0||$operation==='custom_dimension_sync'
    ?MAD4B_Google_Measurement_Bridge::SCOPE_GA4_EDIT
    :(strpos($operation,'gtm_')===0
      ?(in_array($operation,array('gtm_publish','gtm_rollback'),true)
          ?MAD4B_Google_Measurement_Bridge::SCOPE_GTM_PUBLISH
          :MAD4B_Google_Measurement_Bridge::SCOPE_GTM_EDIT)
      :MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ);
  $profile=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():array();
  if (!is_array($profile)||empty($profile['origin_match'])||empty($profile['environment_match']))
    return new WP_Error('measurement_site_unverified','Exact site identity missing.');
  $params=$input['parameters']??array();
  if (!is_array($params)||count($params)>20)return new WP_Error('measurement_invalid_parameters','Parameters invalid.');
  $safe=array();
  foreach($params as $key=>$value) {
   if (!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$key)||
      !is_scalar($value)||strlen((string)$value)>180)return new WP_Error('measurement_invalid_parameters','Unsupported field.');
   $safe[$key]=(string)$value;
  }
  ksort($safe);
  $basis=array('operation'=>$operation,'parameters'=>$safe,'scope'=>$scope,
   'site_uuid'=>$binding['site_uuid'],'property'=>$binding['ga4_property'],
   'container'=>$binding['gtm_container'],'actor'=>(int)get_current_user_id());
  return array('contract'=>self::CONTRACT,'operation'=>$operation,
   'exact_target'=>strpos($operation,'gtm_')===0?$binding['gtm_container']:$binding['ga4_property'],
   'required_scope'=>$scope,'parameters'=>$safe,
   'plan_sha256'=>hash('sha256',wp_json_encode($basis,JSON_UNESCAPED_SLASHES)),
   'implementation'=>'proposal_only',
   'next_steps'=>array('verify_provider_ownership','read_existing_remote_resource',
     'compute_diff_and_conflicts','verify_consent_and_conversion_ownership',
     'obtain_one_time_approval','implement_reviewed_executor','perform_readback'),
   'authorized_for_write'=>false,'mutation_performed'=>false,
   'publish_performed'=>false,'production_authorized'=>false);
 }
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $name='mad4b/google-measurement-operation-plan';
  if (function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array('label'=>'Google Measurement Advanced Operation Plan',
   'description'=>'Fail-closed proposals for GA4 audience lifecycle, GTM management, reporting automation and reconciliation.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,
    'required'=>array('operation'),'properties'=>array(
    'operation'=>array('type'=>'string','enum'=>array('audience_create','audience_update','audience_archive',
     'gtm_tag_create','gtm_tag_update','gtm_trigger_create','gtm_variable_create','gtm_workspace_version',
     'gtm_publish','gtm_rollback','custom_dimension_sync','report_schedule','anomaly_rules','conversion_reconciliation')),
    'parameters'=>array('type'=>'object','additionalProperties'=>array('type'=>'string')))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
   'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
