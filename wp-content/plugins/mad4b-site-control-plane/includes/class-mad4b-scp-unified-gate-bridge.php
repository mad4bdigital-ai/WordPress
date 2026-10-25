<?php
if(!defined('ABSPATH'))exit;
/** Read-only, additive bridge to existing Impact Policy / Approval Ticket gates. */
final class MAD4B_SCP_Unified_Gate_Bridge {
 const CONTRACT='mad4b.unified-original-gate-bridge.v1';
 const OWNER_MAP=[
  'brand_context'=>'MAD4B_SCP_Context_Authority',
  'content_job'=>'MAD4B_SCP_Content_Jobs',
  'product_catalog'=>'MAD4B_SCP_Catalog_Object_Store',
  'seo_metadata'=>'MAD4B_SCP_G5_SEO_Provider_Families',
  'localization'=>'MAD4B_SCP_Translation_Bridge_Adapter',
  'design_template'=>'MAD4B_SCP_Browser_Acceptance_Core',
  'booking'=>'original_booking_owner_required',
  'pricing'=>'original_pricing_owner_required',
  'provider_configuration'=>'MAD4B_SCP_Agent_Registry',
  'plugin_lifecycle'=>'original_package_owner_required',
  'deployment'=>'original_deployment_owner_required',
  'developer'=>'original_developer_owner_required',
  'breakglass'=>'original_breakglass_owner_required',
  'media'=>'original_media_owner_required',
  'generic'=>'original_owner_required'
 ];
 public static function plan($query){
  if(!is_array($query))return new WP_Error('mad4b_gate_query_invalid','Gate selector required.');
  $module=(string)($query['module']??'');
  $ability=(string)($query['ability']??'');
  $provider=(string)($query['provider']??'core');
  $operation=(string)($query['operation']??'content_review');
  $resource=(string)($query['resource']??'');
  if(!isset(self::OWNER_MAP[$module]) || !preg_match('~^[a-z0-9._/-]{3,128}$~D',$ability)
   ||!preg_match('/^[a-z0-9_-]{2,48}$/D',$provider))
   return new WP_Error('mad4b_gate_query_denied','Unknown original owner or ability.');
  $policy=MAD4B_SCP_Unified_Policy::resolve(['module'=>$module,'operation'=>$operation,'resource'=>$resource]);
  if(is_wp_error($policy))return $policy;
  $registered=function_exists('wp_has_ability')&&wp_has_ability($ability);
  $impact=class_exists('MAD4B_SCP_Impact_Policy')
   ?MAD4B_SCP_Impact_Policy::classify($ability,$provider,[]):null;
  $impact_valid=is_array($impact)&&!is_wp_error($impact);
  $owner=self::OWNER_MAP[$module];
  $owner_class_ready=class_exists($owner);
  $qa=in_array($module,MAD4B_SCP_Unified_Policy::CONTENT_QA,true)
      &&$operation==='content_review';
  $next=$qa && $owner_class_ready && $registered?'original_owner_receipt_required'
       :($impact_valid && !empty($impact['approval_required'])?'original_approval_ticket_required'
       :'original_owner_gate_required');
  return ['contract'=>self::CONTRACT,'module'=>$module,'operation'=>$operation,'resource'=>$resource,
   'ability'=>$ability,'provider'=>$provider,
   'original_owner'=>$owner,'original_owner_class_ready'=>$owner_class_ready,
   'registered_ability'=>$registered,'impact_classified'=>$impact_valid,
   'impact_risk_tier'=>$impact_valid?($impact['risk_tier']??null):null,
   'original_ticket_required'=>$impact_valid?(bool)($impact['approval_required']??false):null,
   'original_approval_lane'=>$impact_valid?($impact['approval_lane']??null):null,
   'policy_mode'=>$policy['mode'],'decision_rule'=>$policy['decision_rule'],
   'policy_sha256'=>$policy['policy_sha256'],'next_requirement'=>$next,
   'grant_issued'=>false,'execution_authorized'=>false,
   'publication_authorized'=>false,'mutation_performed'=>false,'read_only'=>true];
 }
}
