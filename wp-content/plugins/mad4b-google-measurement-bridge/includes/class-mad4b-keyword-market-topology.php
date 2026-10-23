<?php
if (!defined('ABSPATH')) exit;
/** Non-authorizing audit of Profile/Market policy isolation. */
final class MAD4B_Keyword_Market_Topology {
 const CONTRACT='mad4b.search-market-topology-audit.v1';
 public static function run($input=array()) {
  if(!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('market_topology_denied','Read permission required.');
  if(!class_exists('MAD4B_SCP_Search_Context')||!class_exists('MAD4B_SCP_Search_Providers'))
   return new WP_Error('market_topology_unavailable','Feature 007 unavailable.');
  $id=(string)($input['profile_id']??'');
  if($id===''||strlen($id)>96||!preg_match('/^[A-Za-z0-9_:-]+$/D',$id))
   return new WP_Error('market_topology_profile','Choose a valid existing Search Profile.');
  $profile=MAD4B_SCP_Search_Context::profile($id);
  if(is_wp_error($profile))return $profile;
  $markets=array();$ids=array();$warnings=array();
  $providers=(array)($profile['provider_policy']['allowed']??array());
  foreach((array)($profile['markets']??array()) as $market){
   if(!is_array($market)||empty($market['id'])||empty($market['country']))return new WP_Error('market_topology_invalid','Malformed market.');
   $market_id=(string)$market['id'];$country=(string)$market['country'];
   if(isset($ids[$market_id]))return new WP_Error('market_topology_duplicate','Duplicate market ID.');
   $ids[$market_id]=true;
   $locations=array_keys((array)($market['provider_locations']??array()));
   $missing=array_values(array_diff($providers,$locations));
   $overlay=(array)($profile['overlays']['market'][$market_id]??array());
   $markets[]=array('market_id'=>$market_id,'country'=>$country,'provider_location_mappings'=>$locations,
    'missing_provider_location_mappings'=>$missing,'has_market_overlay'=>!empty($overlay),
    'independent_market_budget_certified'=>false,'market_capture_ready'=>false);
   if($missing)$warnings[]='Location mapping is missing for market '.$market_id;
  }
  if(count($markets)>1)$warnings[]='Shared provider-account budget is not proof of per-market spend isolation.';
  $frozen=!empty($profile['provider_policy']['freeze_spend']);
  return array('contract'=>self::CONTRACT,'profile_id'=>$id,'strategy_scope'=>'multi-market-or-single-market',
   'revision'=>$profile['revision']??null,'market_count'=>count($markets),'markets'=>$markets,
   'provider_allowed'=>$providers,'profile_spend_frozen'=>$frozen,
   'profile_observation_enabled'=>!empty($profile['enabled']),
   'warnings'=>$warnings,'ready_for_paid_capture'=>false,'review_required'=>true,
   'authorizing'=>false,'mutation_performed'=>false);
 }
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  $name='mad4b/keyword-market-topology-audit';
  if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array('label'=>'Search Profile Market Isolation Audit',
   'description'=>'Read profile markets and find absent provider location mappings and spend-isolation evidence.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('profile_id'),
    'properties'=>array('profile_id'=>array('type'=>'string','maxLength'=>96))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
   'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
