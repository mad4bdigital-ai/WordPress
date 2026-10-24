<?php
if (!defined('ABSPATH')) exit;
/**
 * Read-only consumer of canonical Feature 007 Search Intelligence.
 * No HTTP transport, provider credential, spend, or evidence storage here.
 */
final class MAD4B_Keyword_Search_Bridge {
 const CONTRACT='mad4b.keyword-search-bridge.v1';
 public static function run($input=array()) {
  if (!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('keyword_search_permission','Owner read access required.');
  if (!class_exists('MAD4B_SCP_Search_Runtime'))
   return new WP_Error('keyword_search_runtime_missing','Canonical Search Intelligence unavailable.');
  $action=(string)($input['action']??'status');
  $profile=(string)($input['profile_id']??'');
  if ($profile!=='' && !preg_match('/^[a-zA-Z0-9_:-]{1,96}$/D',$profile))
   return new WP_Error('keyword_search_profile_invalid','Invalid profile identifier.');
  if ($action==='status') {
   $status=MAD4B_SCP_Search_Runtime::status($profile!==''?array('profile_id'=>$profile):array());
   if(is_wp_error($status))return $status;
   return array('contract'=>self::CONTRACT,'source'=>'mad4b.adaptive-search-intelligence.v1',
    'status'=>$status,'read_only'=>true,'provider_requests_performed'=>false);
  }
  if ($profile==='')return new WP_Error('keyword_search_profile_required','Select canonical Search Profile first.');
  if ($action==='discover')
   $result=MAD4B_SCP_Search_Runtime::discover(array('profile_id'=>$profile));
  elseif($action==='cohort')
   $result=MAD4B_SCP_Search_Runtime::cohort(array('profile_id'=>$profile,'limit'=>50));
  else return new WP_Error('keyword_search_action_invalid','Unsupported read-only operation.');
  if(is_wp_error($result))return $result;
  return array('contract'=>self::CONTRACT,'source'=>'mad4b.adaptive-search-intelligence.v1',
   'operation'=>$action,'profile_id'=>$profile,'data'=>$result,
   'read_only'=>true,'provider_requests_performed'=>false,'capture_not_authorized'=>true);
 }
 public static function register() {
  if(!function_exists('wp_register_ability'))return;
  $name='mad4b/keyword-canonical-search-read';
  if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array(
   'label'=>'Canonical Search Intelligence for Keyword Research',
   'description'=>'Read-only delegation to existing MAD4B Search Intelligence. No direct SERP or billable provider request.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,
    'properties'=>array('action'=>array('type'=>'string','enum'=>array('status','discover','cohort')),
     'profile_id'=>array('type'=>'string','maxLength'=>96))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,
    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
