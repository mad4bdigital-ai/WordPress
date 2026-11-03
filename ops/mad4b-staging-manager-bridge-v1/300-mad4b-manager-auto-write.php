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
  'operations.write'=>'operations_manage','commerce.write'=>'commerce_manage'];
 static function site():bool {
  return function_exists('wp_get_environment_type')&&wp_get_environment_type()==='staging'
   && defined('DB_NAME')&&DB_NAME==='etg_commerce_staging'
   && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
   && class_exists('MAD4B_Company_Portfolio_Manager_V1');
 }
 static function read_opt($name):array {
  $v=get_option($name,[]);return is_array($v)?$v:[];
 }
 static function enrolled_owner():int {
  if(!self::site()||!class_exists('MAD4B_SCP_Site_Profile')
    ||!MAD4B_SCP_Site_Profile::origin_enrolled()
    ||!MAD4B_SCP_Site_Profile::site_urls_match_enrollment())return 0;
  $ids=MAD4B_SCP_Site_Profile::oauth_user_ids();
  if(count($ids)!==1)return 0;
  $uid=(int)$ids[0];$u=get_user_by('id',$uid);
  return $u && user_can($u,'manage_options')&&user_can($u,'activate_plugins')?$uid:0;
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
      !filter_var((string)($candidate['canonical_origin']??''),FILTER_VALIDATE_URL) ||
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
  return ['state'=>'REGISTERED','company_code'=>$company,
   'environment'=>$selected,'canonical_origin'=>$target['canonical_origin'],
   'site_uuid'=>$target['site_uuid'],'available_environments'=>array_keys($company_targets),
   'target_is_company_environment'=>true];
 }
 static function entitlement($manager_id,$company,$intent,$user_id):array {
  $base=['contract'=>self::CONTRACT,'manager_id'=>$manager_id,'company_code'=>$company,
   'intent'=>$intent,'user_id'=>$user_id,'policy_default'=>'automatic','company_staging_required'=>false,
   'assignment_authorized'=>false,'manager_membership_active'=>false,
   'company_binding_verified'=>false,'remote_write_ready'=>false,
   'remote_execution_performed'=>false,'blockers'=>[]];
  if(!self::site()){$base['blockers'][]='central_staging_required';return $base;}
  if(!isset(self::RIGHTS[$intent])){$base['blockers'][]='unsupported_intent';return $base;}
  $owner=self::enrolled_owner();
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
  $assignment=self::read_opt(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS)[$manager_id.'|'.$company]??null;
  $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
  $base['company_binding_verified']=!is_wp_error($binding);
  if(is_wp_error($binding)){$base['blockers'][]='company_binding_invalid';return $base;}
  $base['assignment_revision']=(int)($assignment['revision']??0);
  $base['assignment_authorized']=$base['manager_membership_active'] &&
   is_array($assignment)&&($assignment['state']??'')==='active' &&
   ($assignment['company_site_uuid']??'')===$binding['company_site_uuid'] &&
   hash_equals((string)($assignment['binding_sha256']??''),$binding['binding_sha256']) &&
   in_array(self::RIGHTS[$intent],(array)($assignment['rights']??[]),true);
  if(!$base['assignment_authorized']) {
   $base['blockers'][]=$base['manager_membership_active']?'active_exact_company_write_delegation_required':'manager_membership_inactive';
   return $base;
  }
  // Entitlement is automatic; actual remote execution is a separate provider
  // capability. Never substitute company identity/read proof for write proof.
  if(!class_exists('MAD4B_Company_Execution_Resolver_V1')) {
   $base['blockers'][]='native_execution_resolver_missing';return $base;
  }
  $native_intent=$intent==='strategy.write'||$intent==='operations.write'||$intent==='commerce.write'
   ?'content.write':$intent;
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
  $old=self::read_opt(self::SNAPSHOT);
  $next=['contract'=>self::CONTRACT,'states'=>$states];
  // Not a grant and no extra write when the snapshot has not changed.
  if(($old['states']??[])!==$states)update_option(self::SNAPSHOT,$next,false);
 }
 static function rest_permission():bool {
  return self::site() && is_user_logged_in()&&current_user_can('manage_options')&&
   get_current_user_id()===self::enrolled_owner();
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
