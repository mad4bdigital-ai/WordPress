<?php
/**
 * Plugin Name: MAD4B Company Manager Automatic Scoped Write Eligibility (Staging)
 * Description: Active company delegation and active manager membership automatically authorize an entitlement; the real native provider remains the exclusive write executor.
 * Version: 0.2.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_Manager_Auto_Write_V1 {
 const CONTRACT='mad4b.manager.automatic-write.v1';
 const SNAPSHOT='mad4b_manager_auto_write_state_v1';
 const CRON='mad4b_manager_auto_write_reconcile_v1';
 const RIGHTS=['content.write'=>'content_manage','strategy.write'=>'strategy_manage',
  'operations.write'=>'operations_manage','commerce.write'=>'commerce_manage',
  'plugin.install'=>'operations_manage'];
 static function site():bool {
  return function_exists('wp_get_environment_type')&&wp_get_environment_type()==='staging'
   && defined('DB_NAME')&&DB_NAME==='etg_commerce_staging'
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
   && class_exists('MAD4B_Company_Portfolio_Manager_V1');
 }
 static function read_opt($name):array {
  $v=get_option($name,[]);return is_array($v)?$v:[];
 }
 static function enrolled_owner(int $requested_user_id=0):int {
  if(!self::site()||!class_exists('MAD4B_SCP_Site_Profile')
    ||!MAD4B_SCP_Site_Profile::origin_enrolled()
    ||!MAD4B_SCP_Site_Profile::site_urls_match_enrollment())return 0;
  // Multiple authorized manager members are valid; caller eligibility is per user.
  $ids=MAD4B_SCP_Site_Profile::oauth_user_ids();
  if(!is_array($ids)||!$ids)return 0;
  $manager=class_exists('MAD4B_Company_Operation_Admission_V1',false)
   ? MAD4B_Company_Operation_Admission_V1::manager_id():'';
  $members=self::read_opt(MAD4B_Company_Portfolio_Manager_V1::MEMBERS);
  $managers=self::read_opt(MAD4B_Company_Portfolio_Manager_V1::MANAGERS);
  if(!wp_is_uuid($manager)||($managers[$manager]['state']??'')!=='registered')return 0;
  $valid=[];
  foreach($ids as $id){
   $uid=(int)$id;
   $member=$members[$manager.'|'.$uid]??null;
   $user=$uid>0?get_user_by('id',$uid):false;
   if(!$user||!user_can($user,'manage_options')||!user_can($user,'activate_plugins')
      ||!MAD4B_SCP_Site_Profile::user_is_enrolled($uid)
      ||!is_array($member)||($member['state']??'')!=='active'
      ||(int)($member['user_id']??0)!==$uid)continue;
   $valid[]=$uid;
  }
  if($requested_user_id>0)return in_array($requested_user_id,$valid,true)?$requested_user_id:0;
  sort($valid,SORT_NUMERIC);
  return $valid?(int)$valid[0]:0;
 }
 // Resolve the actual company environment. The central controller's own
 // Staging environment must never be imposed on a company site.
 static function company_target($company):array {
  if(!self::site() || !class_exists('MAD4B_Company_Execution_Resolver_V1'))
   return ['state'=>'RESOLVER_UNAVAILABLE','blockers'=>['execution_resolver_missing']];
  $targets=MAD4B_Company_Execution_Resolver_V1::discover();
  $company_targets=[];
  foreach($targets as $key=>$target){
   if(!is_array($target) || ($target['company_id']??'')!==$company ||
      !in_array(($target['environment']??''),['production','staging'],true) ||
      $key!==$company.':'.$target['environment'])continue;
   $company_targets[$target['environment']]=$target;
  }
  if(!$company_targets)return ['state'=>'TARGET_NOT_ENROLLED',
     'blockers'=>['company_environment_not_registered']];
  $preferred=self::read_opt('mad4b_company_default_execution_environment_v1')[$company]??'production';
  // An environment without canonical, current identity cannot be selected
  // for executable work. A host's identity never substitutes for a site UUID.
  $verified=[];
  foreach($company_targets as $env=>$candidate){
   $proof=(array)($candidate['identity']??[]);
   if(!wp_is_uuid((string)($candidate['site_uuid']??'')) ||
      !class_exists('MAD4B_Company_Portfolio_Manager_V1',false) ||
      MAD4B_Company_Portfolio_Manager_V1::public_origin((string)($candidate['canonical_origin']??''))==='' ||
      untrailingslashit((string)($candidate['canonical_origin']??''))!==MAD4B_Company_Portfolio_Manager_V1::public_origin((string)($candidate['canonical_origin']??'')) ||
      (empty($proof['site_profile_v4_verified']) &&
       empty($proof['local_vps_binding'])))continue;
   $verified[$env]=$candidate;
  }
  if(!$verified)return ['state'=>'TARGET_IDENTITY_NOT_VERIFIED',
     'blockers'=>['no_verified_company_environment_site_identity']];
  if(isset($verified[$preferred]))$selected=$preferred;
  elseif(isset($verified['staging']))$selected='staging';
  elseif(isset($verified['production']))$selected='production';
  else $selected=array_key_first($verified);
  $target=$verified[$selected];
  $public_origin=MAD4B_Company_Portfolio_Manager_V1::public_origin((string)$target['canonical_origin']);
  return ['state'=>'REGISTERED','company_code'=>$company,
   'environment'=>$selected,'canonical_origin'=>$public_origin,
   'public_admin_url'=>$public_origin.'/wp-admin/',
   'public_login_url'=>$public_origin.'/wp-login.php',
   'public_scheme'=>'https','public_port'=>443,
   'internal_proxy_port_exposed'=>false,
   'site_uuid'=>$target['site_uuid'],'available_environments'=>array_keys($company_targets),
   'target_is_company_environment'=>true];
 }
 static function entitlement($manager_id,$company,$intent,$user_id):array {
  $base=['contract'=>self::CONTRACT,'manager_id'=>$manager_id,'company_code'=>$company,
   'intent'=>$intent,'user_id'=>$user_id,'policy_default'=>'automatic','company_staging_required'=>false,
   'assignment_authorized'=>false,'manager_membership_active'=>false,
   'company_binding_verified'=>false,'remote_write_ready'=>false,
   'native_job_submission_ready'=>false,
   'remote_execution_performed'=>false,'blockers'=>[]];
  if(!self::site()){$base['blockers'][]='central_staging_required';return $base;}
  if(!isset(self::RIGHTS[$intent])){$base['blockers'][]='unsupported_intent';return $base;}
  $owner=is_int($user_id)?self::enrolled_owner($user_id):0;
  if($owner<1||$user_id!==$owner){$base['blockers'][]='exact_enrolled_administrator_required';return $base;}
  $man=self::read_opt(MAD4B_Company_Portfolio_Manager_V1::MANAGERS)[$manager_id]??null;
  if(!is_array($man)||($man['state']??'')!=='registered') {
   $base['blockers'][]='manager_not_registered';return $base;
  }
  $member=self::read_opt(MAD4B_Company_Portfolio_Manager_V1::MEMBERS)[$manager_id.'|'.$user_id]??null;
  $base['manager_membership_active']=is_array($member)&&($member['state']??'')==='active';
  // Exact Staging Company+Environment assignments do not live in the Production registry.
  // Identity only selects the lane: the bridge verifies original owner membership and
  // ACTIVE owner approval separately, and never bootstraps Native Executor grants.
  $target=self::company_target($company);
  if(($target['environment']??'')==='staging') {
   if(!class_exists('MAD4B_Staging_Manager_Admission_Bridge_V1',false)) {
    $base['blockers'][]='staging_environment_manager_bridge_missing';
    return $base;
   }
   return MAD4B_Staging_Manager_Admission_Bridge_V1::entitlement(
      $manager_id,$company,$intent,$user_id);
  }
  // Production owner authorization is exclusively from the new exact
  // environment + site UUID record. The legacy manager|company row remains
  // historical Staging context and must never authorize Production writes.
  $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
  $base['company_binding_verified']=!is_wp_error($binding);
  if(is_wp_error($binding)){$base['blockers'][]='company_binding_invalid';return $base;}
  $assignment=MAD4B_Company_Portfolio_Manager_V1::production_assignment_status($manager_id,$company);
  if(is_wp_error($assignment)){$base['blockers'][]='production_assignment_scope_invalid';return $base;}
  $base['assignment_revision']=(int)($assignment['revision']??0);
  $base['assignment_authorized']=$base['manager_membership_active'] &&
    MAD4B_Company_Portfolio_Manager_V1::has_production_delegation(
      $manager_id,$company,self::RIGHTS[$intent],$user_id);
  if(!$base['assignment_authorized']) {
   $base['blockers'][]=$base['manager_membership_active']?'active_exact_company_write_delegation_required':'manager_membership_inactive';
   return $base;
  }
  // Entitlement is automatic; actual remote execution is a separate provider
  // capability. Never substitute company identity/read proof for write proof.
  if(!class_exists('MAD4B_Company_Execution_Resolver_V1')) {
   $base['blockers'][]='native_execution_resolver_missing';return $base;
  }
  $native_intent=$intent; // No cross-scope alias: each Native 312 operation must be independently certified.
  $target=self::company_target($company);
  $base['execution_target_environment']=$target['environment']??null;
  $base['execution_target_origin']=$target['canonical_origin']??null;
  $base['execution_target_site_uuid']=$target['site_uuid']??null;
  if(($target['state']??'')!=='REGISTERED'){
   $base['blockers']=array_merge($base['blockers'],$target['blockers']??['company_target_not_ready']);
   return $base;
  }
  $plan=MAD4B_Company_Execution_Resolver_V1::plan($company,$target['environment'],$native_intent);
  $base['native_plan_state']=$plan['state']??'UNKNOWN';
  $base['native_plan_blockers']=$plan['blockers']??[];
  // A qualified 305/312 submission is not a signed 313 write.
  $base['native_job_submission_ready']=($plan['native_job_submission_ready']??false)===true
    && ($plan['selected_submission_channel']??null)==='original_native_wpcli_305_312_313'
    && ($plan['environment']??null)===$target['environment']
    && ($plan['site_uuid']??null)===$target['site_uuid']
    && ($plan['site_uuid']??null)===$binding['company_site_uuid']
    && ($plan['canonical_origin']??null)===$target['canonical_origin'];
  // The native executor must be explicitly authorized for the target company,
  // exact environment and original site identity. This is not a blanket grant.
  $base['remote_write_ready']=($plan['execution_allowed']??false)===true &&
    ($plan['remote_write_permitted']??false)===true &&
    !empty($plan['selected_channel']) &&
    ($plan['environment']??'')===$target['environment'] &&
    ($plan['site_uuid']??'')===$target['site_uuid'] &&
    ($plan['site_uuid']??'')===$binding['company_site_uuid'];
  if(!$base['remote_write_ready']) {
   $base['blockers'][]='native_write_provider_not_ready';
   foreach((array)($plan['blockers']??[]) as $reason) {
    $base['blockers'][]=sanitize_key((string)$reason);
   }
  }
  return $base;
 }
 static function status():array {
  if(!self::site())return ['contract'=>self::CONTRACT,'state'=>'WRONG_ENVIRONMENT'];
  $owner=self::enrolled_owner();
  $rows=[];
  foreach(self::read_opt(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS) as $r){
   if(!is_array($r))continue;
   $id=(string)($r['manager_id']??'');$company=(string)($r['company_code']??'');
   if(!wp_is_uuid($id)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))continue;
   foreach(self::RIGHTS as $intent=>$right) {
    $rows[] = self::entitlement($id,$company,$intent,$owner);
   }
  }
  return ['contract'=>self::CONTRACT,'policy'=>'auto_on_active_manager_and_company_assignment',
   'enabled_by_default'=>true,'manual_secondary_approval_required'=>false,
   'native_provider_write_proof_still_required'=>true,'company_staging_required'=>false,
   'company_environment_selection'=>'enrolled_company_environment_prefer_primary_production',
   'authoritative_company'=>'verified_company_site_uuid',
   'production_mutation'=>false,'rows'=>$rows,
   'snapshot'=>self::read_opt(self::SNAPSHOT)];
 }
 static function schedule(...$args):void {
  if(!self::site())return;
  if(!wp_next_scheduled(self::CRON))
   wp_schedule_single_event(time()+10,self::CRON);
 }
 static function reconcile():void {
  if(!self::site())return;
  $status=self::status();$states=[];
  foreach($status['rows'] as $x) {
   $key=$x['manager_id'].'|'.$x['company_code'].'|'.$x['intent'];
   $states[$key]=['company'=>$x['company_code'],'intent'=>$x['intent'],
     'member_active'=>$x['manager_membership_active'],
     'assignment_authorized'=>$x['assignment_authorized'],
     'remote_write_ready'=>$x['remote_write_ready'],
     'target_environment'=>$x['execution_target_environment']??null,
     'blockers'=>$x['blockers']];
  }
  // Reuse original ContentJob Skill Registry; no parallel executor.
  $sets=[];
  foreach($status['rows'] as $row){
   $code=(string)($row['company_code']??'');
   if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)||isset($sets[$code]))continue;
   $target=self::company_target($code);
   // Company Manager is the required consumer. The consumer delegates to the
   // original ContentJob Skill Set owner and never invents a parallel plan.
   $p=class_exists('MAD4B_Company_ContentJob_Skill_Preflight_V1',false)
     &&($target['state']??'')==='REGISTERED'
     ?MAD4B_Company_ContentJob_Skill_Preflight_V1::inspect(
        $code,'company_reconcile','pre_execution')
     :new WP_Error('mad4b_company_skill_consumer_missing',
        'Company Manager exact Skill preflight is unavailable');
   $valid=is_array($p)&&($p['contract']??'')===
     MAD4B_Company_ContentJob_Skill_Preflight_V1::CONTRACT;
   // Consume the ORIGINAL Company Bootstrap owner registry and replacement
   // history. Never copy the owner email or inherit old-site authority.
   $owner=class_exists('MAD4B_Company_Bootstrap_Contract',false)
    ? MAD4B_Company_Bootstrap_Contract::record($code)
    : new WP_Error('bootstrap_original_registry_unavailable');
   $ownerValid=is_array($owner)
    &&($owner['site_uuid']??null)===($target['site_uuid']??null)
    &&($owner['owner_email_set']??false)===true
    &&($owner['revision']??0)>0;
   $prior=class_exists('MAD4B_Company_Site_Replacement_V1',false)
    ?(MAD4B_Company_Site_Replacement_V1::records()[$code]??[]):[];
   $past=array_filter((array)($prior['history']??[]),static function($entry){
    return is_array($entry) && wp_is_uuid((string)($entry['site_uuid']??''));
   });
   $sets[$code]=[
    'state'=>$valid?($p['state']??'HOLD'):'HOLD_COMPANY_MANAGER_PREFLIGHT',
    'original_owner_bootstrap'=>[
       'state'=>$ownerValid?'IDENTITY_RECORDED':'HOLD_ORIGINAL_OWNER_BOOTSTRAP',
       'revision'=>$ownerValid?(int)$owner['revision']:null,
       'owner_email_registered'=>$ownerValid,
       'administrator_import_state'=>is_array($owner)?($owner['administrator_import_state']??'unknown'):'unknown',
       'owner_creation_state'=>is_array($owner)?($owner['owner_creation_state']??'unknown'):'unknown',
       'owner_personal_data_exported'=>false
    ],
    'original_site_replacement'=>[
       'state'=>(string)($prior['state']??'UNAVAILABLE'),
       'previous_sites'=>count($past),
       'authority_inherited'=>false,
       'historical_brand_proof'=>'UNTESTED'
    ],
    'environment'=>$target['environment']??null,
    'site_uuid'=>$target['site_uuid']??null,
    'skill_set_sha256'=>$valid?($p['native_skill_set_sha256']??null):null,
    'selected_skill_count'=>$valid?($p['native_required_skill_count']??0):0,
    'missing_skills'=>$valid?($p['native_missing_required_skills']??[]):[],
    'plugin_families'=>$valid?($p['native_detected_provider_families']??[]):[],
    'dimensions'=>$valid?($p['native_selected_dimensions']??[]):[],
    'external_recommendation_count'=>$valid?($p['native_external_recommendation_count']??0):0,
    'pre_execution_selection_passed'=>$valid&&
      ($p['can_prepare_company_plan']??false)===true,
    'target_provider_readback'=>'UNTESTED',
    'closure_authorized'=>false,
    'company_manager_consumer_required'=>true,
    'content_jobs_created'=>0
   ];
  }
  ksort($sets,SORT_STRING);
  $old=self::read_opt(self::SNAPSHOT);
  $next=['contract'=>self::CONTRACT,'states'=>$states,'dynamic_reconcile_skill_sets'=>$sets];
  // Not a grant and no extra write when the snapshot has not changed.
  if(($old['states']??[])!==$states||($old['dynamic_reconcile_skill_sets']??[])!==$sets)update_option(self::SNAPSHOT,$next,false);
 }
 static function rest_permission():bool {
  return self::site() && is_user_logged_in()&&current_user_can('manage_options')&&
   get_current_user_id()===self::enrolled_owner(get_current_user_id());
 }
 static function rest():void {
  if(!self::site())return;
  register_rest_route('mad4b/v1','/company-manager-auto-write/status',[
    'methods'=>'GET','permission_callback'=>[__CLASS__,'rest_permission'],
    'callback'=>static fn()=>rest_ensure_response(self::status())]);
 }
 static function boot():void {
  add_action('rest_api_init',[__CLASS__,'rest'],70);
  add_action(self::CRON,[__CLASS__,'reconcile']);
  foreach(['mad4b_manager_company_assignments_v1',
   'mad4b_company_manager_memberships_v1',
   'mad4b_tourism_company_site_registry_v1',
   'mad4b_portfolio_environment_sites_v1',
   'mad4b_company_execution_resolver_v1'] as $key)
   add_action('update_option_'.$key,[__CLASS__,'schedule'],10,3);
 }
}
MAD4B_Manager_Auto_Write_V1::boot();
