<?php
/** Original V1 -> V2 Developer Authority compatibility view is non-authorizing. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=null){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function sanitize_key($value){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$value));}
function firstparty_check($passed,$name){
 if(!$passed){fwrite(STDERR,"FAIL: ".$name.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-developer-authority-policy-v2.php';
$klass='MAD4B_SCP_Developer_Authority_Policy_V2';
$ability='mad4b/original-registered-developer-operation';
$site='11111111-1111-4111-8111-111111111111';
$sha=str_repeat('b',64);
$plan=[
 'contract'=>$klass::LEGACY_PLAN_CONTRACT,
 'site_uuid'=>$site,'site_profile_digest'=>$sha,
 'site_profile_revision'=>14,'environment'=>'production',
 'operation'=>'read_review','production_allowed'=>false
];
$plan['plan_sha256']=hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$admission=[
 'state'=>'eligible_for_exact_canonical_plan_only',
 'authorization_issued'=>false,'mutation_performed'=>false,
 'contract'=>'mad4b.registered-write-admission.v1',
 'site_uuid'=>$site,'profile_digest'=>$sha,'profile_revision'=>14,
 'environment'=>'production','policy_sha256'=>str_repeat('d',64),
 'operation_id'=>'developer:review','ability'=>$ability
];
$original=$plan;
$view=$klass::project($plan,$admission,$ability);
firstparty_check(is_array($view)
 && $view['contract']===$klass::CONTRACT
 && $view['legacy_plan_sha256']===$plan['plan_sha256']
 && $view['canonical_plan_modified']===false
 && $view['authorization_issued']===false
 && $view['grant_issued']===false
 && $view['approval_issued']===false
 && $view['mutation_performed']===false
 && $view['v2_view_is_authorizing']===false
 && $view['legacy_production_allowed_field']===false,
 'Original new V2 view never rewrites old Production veto or creates a grant');
firstparty_check($plan===$original && preg_match('/^[a-f0-9]{64}$/D',$view['view_sha256']),
 'Original signed V1 plan data remains unchanged');
$bad_admission=[
 'unverified'=>['state'=>'unverified'],
 'missing_authorized_flag'=>['authorization_issued'=>null],
 'forged_mutation'=>['mutation_performed'=>true],
 'wrong_contract'=>['contract'=>'unknown'],
 'foreign_ability'=>['ability'=>'mad4b/other'],
 'site_uuid_drift'=>['site_uuid'=>'22222222-2222-4222-8222-222222222222'],
 'profile_digest_drift'=>['profile_digest'=>str_repeat('e',64)],
 'revision_drift'=>['profile_revision'=>15],
 'environment_drift'=>['environment'=>'staging'],
 'missing_policy'=>['policy_sha256'=>''],
 'missing_operation'=>['operation_id'=>''],
];
foreach($bad_admission as $name=>$changes){
 $invalid=array_replace($admission,$changes);
 firstparty_check(is_wp_error($klass::project($plan,$invalid,$ability)),
  'Untrusted newer authority projection must deny '.$name);
}
foreach([
 'forged_historical_production_allow'=>['production_allowed'=>true],
 'wrong_plan_contract'=>['contract'=>'unknown'],
 'site_id_without_sha'=>['site_uuid'=>'different'],
 'invalid_revision'=>['site_profile_revision'=>0],
 'missing_legacy_sha'=>['plan_sha256'=>''],
] as $name=>$changes){
 $invalid=array_replace($plan,$changes);
 firstparty_check(is_wp_error($klass::project($invalid,$admission,$ability)),
  'Changed signed original V1 plan requires independent review: '.$name);
}
$bad=$plan;
$bad['plan_sha256']=hash('sha256','forged plan');
firstparty_check(is_wp_error($klass::project($bad,$admission,$ability)),
 'Any changed original V1 policy SHA rejects the V2 view');
firstparty_check(is_wp_error($klass::project($plan,new WP_Error('admission_unverified'),$ability)),
 'WP_Error original admission is not eligibility');
firstparty_check(is_wp_error($klass::project($plan,$admission,'mad4b/another')),
 'Different requested original Ability cannot inherit an admission');
echo 'PASS: DEVELOPER_POLICY_V2_ORIGINAL '.($GLOBALS['checks']??0).
 ' actual-source V1/V2 provenance and no-grant assertions'.PHP_EOL;
