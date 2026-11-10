<?php
/** Staging-only Company Manager -> original Skill Set -> ContentJob tests. */
defined('WP_CLI')&&WP_CLI || exit(2);
$checks=[];
$core='MAD4B_SCP_Company_Reconcile_Skill_Set_V1';
$consumer='MAD4B_Company_ContentJob_Skill_Preflight_V1';
$checks['native_owner_loaded']=class_exists($core,false);
$checks['manager_consumer_loaded']=class_exists($consumer,false);
$checks['seed_manifest_v16']=MAD4B_SCP_Skill_Seeder::SEED_VERSION===16;
$skill=MAD4B_SCP_Skill_Registry::get_skill(
 'workflow','company-reconcile','content-jobs-ui-reconcile');
$checks['runtime_skill_enabled']=is_array($skill)&&($skill['enabled']??false)===true;
$checks['runtime_skill_digest_verified']=is_array($skill)&&
 $skill['sha256']===hash('sha256',$skill['content']);
$planAbility=wp_get_ability('mad4b/content-job-reconcile-skill-plan');
$acceptAbility=wp_get_ability('mad4b/content-job-reconcile-skill-acceptance');
$checks['original_plan_ability_exists']=(bool)$planAbility;
$checks['original_acceptance_ability_exists']=(bool)$acceptAbility;
$checks['no_extra_contentjob_ability']=!wp_has_ability(
 'mad4b/content-job-company-reconcile-preflight');
$checks['original_mcp_read_classification']=$planAbility
 &&$acceptAbility
 &&$planAbility->get_category()==='mad4b-read'
 &&$acceptAbility->get_category()==='mad4b-read'
 &&($planAbility->get_meta_item('mcp')['surface']??'')==='read'
 &&($acceptAbility->get_meta_item('mcp')['surface']??'')==='read';
$table=MAD4B_SCP_Schema::tables()['content_jobs'];
global $wpdb;
$jobBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$membershipBefore=get_option('mad4b_company_network_memberships_v1',null);
$strategyBefore=get_option('mad4b_company_strategy_records_v1',null);
$inputs=[
 'tours-central'=>['company_code'=>'tours-central','environment'=>'staging',
   'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'],
 'wayfatours'=>['company_code'=>'wayfatours','environment'=>'production',
   'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c']
];
$details=[];
foreach($inputs as $code=>$scope) {
 $native=$core::plan($scope);
 $consumerPlan=$consumer::inspect($code);
 $consumerPost=$consumer::inspect($code,'company_reconcile','post_apply_acceptance');
 $nativePost=$core::acceptance($scope+[
  'expected_skill_set_sha256'=>$native['skill_set_sha256']??'']);
 $checks['native_plan_'.$code]=($native['state']??'')==='READY_FOR_GOVERNED_REPAIR_PLAN'
  &&($native['pre_execution_skill_selection_passed']??false)===true;
 $checks['manager_consumes_native_'.$code]=is_array($consumerPlan)
  &&($consumerPlan['native_skill_set_sha256']??null)===($native['skill_set_sha256']??'')
  &&($consumerPlan['native_required_skill_count']??0)===($native['required_skill_count']??-1)
  &&($consumerPlan['can_prepare_company_plan']??false)===true;
 $checks['post_apply_native_hold_'.$code]=is_array($consumerPost)
  &&($consumerPost['post_apply_original_result']??'')==='UNTESTED_NATIVE_PROVIDER_RECEIPTS_REQUIRED'
  &&($consumerPost['can_finalize_company_reconcile']??true)===false
  &&($nativePost['reconcile_closure_accepted']??true)===false;
 $checks['nine_ui_acceptance_checks_'.$code]=is_array($consumerPlan)
  &&count($consumerPlan['post_apply_acceptance_checks'])===9
  &&count(array_filter($consumerPlan['post_apply_acceptance_checks'],
     static fn($x)=>$x['state']==='UNTESTED'))===9;
 $checks['no_native_authority_'.$code]=($native['native_execution_authorized']??true)===false
  &&($consumerPlan['can_execute_company_reconcile']??true)===false
  &&($consumerPlan['content_jobs_created']??-1)===0
  &&($consumerPlan['external_skill_autoinstall']??true)===false;
 $details[$code]=[
  'native_skill_set_sha256'=>$native['skill_set_sha256']??null,
  'selected_skills'=>$native['required_skill_count']??null,
  'post_apply'=>$nativePost['state']??null,
  'manager_state'=>$consumerPlan['state']??null];
}
$checks['company_uuids_distinct']=$inputs['wayfatours']['site_uuid']
 !==$inputs['tours-central']['site_uuid'];
$checks['invalid_operation_denied']=is_wp_error(
 $consumer::inspect('wayfatours','execute_unauthorized'));
$checks['unregistered_company_denied']=is_wp_error(
 $consumer::inspect('not-enrolled-company'));
$checks['no_target_uuid_override']=is_wp_error(
 $consumer::inspect('wayfatours','company_reconcile','unknown_phase'));
$route='/mad4b/v1/company-managers/companies/wayfatours/contentjob-preflight';
$anon=rest_do_request(new WP_REST_Request('GET',$route));
$checks['rest_anonymous_403']=$anon->get_status()===403;
$ids=get_users(['role'=>'administrator','fields'=>'ids','number'=>1]);
if(!$ids){echo wp_json_encode(['pass'=>false,'error'=>'NO_ADMIN_FOR_TEST']),PHP_EOL;exit(8);}
wp_set_current_user((int)$ids[0]);
$r=rest_do_request(new WP_REST_Request('GET',$route));
$checks['rest_admin_plan_200']=$r->get_status()===200
 &&($r->get_data()['native_skill_set_sha256']??null)===$details['wayfatours']['native_skill_set_sha256'];
$req=new WP_REST_Request('GET',$route);
$req->set_param('phase','post_apply_acceptance');
$rp=rest_do_request($req);
$checks['rest_admin_acceptance_200']=$rp->get_status()===200
 &&($rp->get_data()['state']??'')==='POST_APPLY_ACCEPTANCE_HOLD';
$checks['rest_cache_private']=($r->get_headers()['Cache-Control']??'')==='no-store, private';
$checks['rest_no_post']=in_array(rest_do_request(
 new WP_REST_Request('POST',$route))->get_status(),[404,405],true);
$source=file_get_contents(ABSPATH.
 'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-strategy-management.php');
$checks['strategy_calls_preflight_before_lock']=is_string($source)
 &&str_contains($source,'ready_for_strategy_reconcile($code)')
 &&strpos($source,'ready_for_strategy_reconcile($code)')<
   strpos($source,'SELECT GET_LOCK(%s,3)');
$launchFile=file_get_contents(ABSPATH.
 'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-vps-company-launch.php');
$checks['launch_readiness_reports_preflight']=is_string($launchFile)
 &&str_contains($launchFile,'MAD4B_Company_ContentJob_Skill_Preflight_V1::inspect')
 &&str_contains($launchFile,'contentjob_preflight_required');
$checks['membership_unchanged']=$membershipBefore===
 get_option('mad4b_company_network_memberships_v1',null);
$checks['strategy_unchanged']=$strategyBefore===
 get_option('mad4b_company_strategy_records_v1',null);
$jobAfter=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$checks['content_jobs_unchanged']=$jobBefore===$jobAfter;
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
 'checks'=>$checks,'count'=>count($checks),
 'content_jobs_before'=>$jobBefore,'content_jobs_after'=>$jobAfter,
 'native_skill_sha256'=>$skill['sha256'],
 'scopes'=>$details],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
