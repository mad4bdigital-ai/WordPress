<?php
/** No WordPress/DB: negative identity, ownership and no-auto-apply cases. */
define('ABSPATH','/');
function wp_is_uuid($v){return is_string($v)&&preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D',$v)===1;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
require dirname(__DIR__).'/includes/class-mad4b-company-reconcile-work-plan.php';
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$other='49c562d1-8f2f-456f-b454-26816c6ba4cb';
$targets=[
 'staging'=>['company_id'=>'sampleco','environment'=>'staging','site_uuid'=>$other,'canonical_origin'=>'https://staging.example.com'],
 'production'=>['company_id'=>'sampleco','environment'=>'production','site_uuid'=>$uuid,'canonical_origin'=>'https://example.com']
];
$policy=[
 'contract'=>'sample',
 'stages'=>[
   'bootstrap'=>['target_identity','original_authority'],
   'functional'=>['post_taxonomy_schema'],
   'presentation'=>['brand_kit_page_options'],
   'commercial'=>['payment_checkout']
 ],
 'incident_scenarios'=>[
   ['code'=>'payment_guard','related_check'=>'payment_checkout','ownership_scope'=>'reconnect']
 ]
];
$c=MAD4B_Company_Reconcile_Work_Plan_V1::class;
$checks=[];
$test=function($label,$truth)use(&$checks){$checks[$label]=(bool)$truth;};
$p=$c::build('sampleco',$targets,$policy,['environment'=>'production','canonical_origin'=>'https://example.com',
 'site_uuid'=>$uuid,'stages'=>['original_authority'=>['state'=>'PASS']]]);
$test('exact_production_report_bind',$p['legacy_report_bound_to_target']&&$p['legacy_report_matching_environment']==='production');
$test('no_signed_evidence_promoted',!$p['authority_granted']&&!$p['ready_to_publish']
 &&($p['work_items'][1]['target_state']??'')==='INDEPENDENT_READBACK_REQUIRED');
$test('separate_environment_site_uuid',$p['environment_inventory']['production']['site_uuid']!==$p['environment_inventory']['staging']['site_uuid']);
$notBound=$c::build('sampleco',$targets,$policy,['environment'=>'staging','canonical_origin'=>'https://example.com','site_uuid'=>$uuid]);
$test('cross_environment_uuid_denied',!$notBound['legacy_report_bound_to_target']);
$new=$c::build('sampleco',[],$policy);
$test('new_target_no_enrollment_claim',$new['state']==='START_COMPANY_TARGET_PROFILE_PENDING'&&!$new['authority_granted']);
$bad=$c::build('sampleco',['production'=>['company_id'=>'sampleco','environment'=>'production','site_uuid'=>'x','canonical_origin'=>'https://example.com']],$policy);
$test('malformed_record_denied',$bad['state']==='IDENTITY_REGISTRY_REVIEW_REQUIRED');
$old=$policy;$old['provider_owners']=['original_authority'=>'Owner Old'];
$newPolicy=$policy;$newPolicy['provider_owners']=['original_authority'=>'Owner New'];
$a=$c::build('sampleco',$targets,$old);$b=$c::build('sampleco',$targets,$newPolicy);
$test('owner_change_updates_digest',$a['work_plan_sha256']!==$b['work_plan_sha256']);
$test('payment_never_cloned',count(array_filter($p['work_items'],fn($x)=>($x['ownership_scope']??'')==='reconnect'))===1);
$test('all_operations_non_authorizing',count(array_filter($p['work_items'],fn($x)=>($x['auto_apply']??true)!==false))===0);
echo json_encode(['pass'=>!in_array(false,$checks,true),'assertions'=>count($checks),'checks'=>$checks]).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
