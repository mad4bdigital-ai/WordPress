<?php
/** Read-only WP-CLI regression for Start Company and Company Reconcile 2026-11-11. */
if(!defined('WP_CLI')||!WP_CLI)exit;
$errors=[];$count=0;
$test=static function($label,$ok)use(&$errors,&$count){$count++;if(!$ok)$errors[]=$label;echo ($ok?'PASS ':'FAIL ').$label."\n";};
$path=WPMU_PLUGIN_DIR.'/company-start-reconcile/policy.json';$policy=json_decode((string)file_get_contents($path),true);
$uuid='11111111-1111-4111-8111-111111111111';$other='22222222-2222-4222-8222-222222222222';
$targets=['production'=>['company_id'=>'exampleco','environment'=>'production','site_uuid'=>$uuid,'canonical_origin'=>'https://example.invalid']];
$good=MAD4B_Company_Reconcile_Work_Plan_V1::build('exampleco',$targets,$policy,['site_uuid'=>$uuid,'environment'=>'production','canonical_origin'=>'https://example.invalid','stages'=>['target_identity'=>['state'=>'PASS']]]);
$forged=MAD4B_Company_Reconcile_Work_Plan_V1::build('exampleco',$targets,$policy,['site_uuid'=>$other,'environment'=>'production','canonical_origin'=>'https://example.invalid','stages'=>['target_identity'=>['state'=>'PASS']]]);
$bad=MAD4B_Company_Reconcile_Work_Plan_V1::build('example co',$targets,$policy,[]);
$items=array_column($good['work_items'],'ownership_scope','check');
$test('plan_34_from_policy',count($good['work_items'])===34);
$test('identity_report_bound_only_exact_uuid_environment_origin',($good['legacy_report_bound_to_target']??false)===true&&($forged['legacy_report_bound_to_target']??true)===false);
$wrongEnv=MAD4B_Company_Reconcile_Work_Plan_V1::build('exampleco',$targets,$policy,['site_uuid'=>$uuid,'environment'=>'staging','canonical_origin'=>'https://example.invalid']);
$wrongOrigin=MAD4B_Company_Reconcile_Work_Plan_V1::build('exampleco',$targets,$policy,['site_uuid'=>$uuid,'environment'=>'production','canonical_origin'=>'https://elsewhere.invalid']);
$test('same_uuid_different_environment_not_accepted',($wrongEnv['legacy_report_bound_to_target']??true)===false);
$test('same_uuid_different_origin_not_accepted',($wrongOrigin['legacy_report_bound_to_target']??true)===false);
$test('stage_hint_not_promoted_to_target_proof',($good['work_items'][0]['target_state']??'')==='INDEPENDENT_READBACK_REQUIRED');
$test('forged_site_receipt_not_reused',($forged['work_items'][0]['existing_stage_hint']??'')==='UNBOUND');
$test('plan_hash_ignores_untrusted_hints',$good['work_plan_sha256']===$forged['work_plan_sha256']);
$test('tenant_media_preserved',($items['media_provenance']??null)==='tenant_owned');
$test('payment_connections_not_cloned',($items['payment_checkout']??null)==='reconnect');
$test('original_authority_never_cloned',($items['original_authority']??null)==='never_clone');
$test('generated_css_is_target_local',($items['elementor_initial_ajax_css_parity']??null)==='site_generated');
$test('unsupported_company_rejected',($bad['state']??'')==='UNAVAILABLE');
$test('no_native_grant_or_autoapply',($good['authority_granted']??true)===false && count(array_filter($good['work_items'],static fn($x)=>($x['auto_apply']??true)!==false))===0);
$test('readback_contract_required',($good['required_target_probe_contract']??'')==='mad4b.company-reconcile.target-evidence.v1');
if(class_exists('MAD4B_Company_Start_Reconcile_Completeness_V1',false)){
 $real=MAD4B_Company_Start_Reconcile_Completeness_V1::project('wayfatours',[]);
 $test('real_start_company_exposes_plan',($real['company_reconcile_work_plan']['work_count']??0)===34);
 $test('no_spurious_publication_approval',($real['ready_to_publish']??true)===false);
}
echo 'SUMMARY '.($count-count($errors)).'/'.$count.' failed='.wp_json_encode($errors)."\n";
if($errors)WP_CLI::error('Company Reconcile regression failed');
