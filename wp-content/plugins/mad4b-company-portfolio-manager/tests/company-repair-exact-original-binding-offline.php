<?php
/** Local matching tests only; does NOT simulate/forge cryptographic 313 proof. */
define('ABSPATH','/');
function add_action(...$args){return true;}
function get_option($key,$default=false){return $GLOBALS['TEST_OPTIONS'][$key]??$default;}
class MAD4B_Central_VPS_Broker_V1 {
  const JOB='mad4b_test_broker_job_';
}
class MAD4B_Company_Native_Write_Executor_V1 {
  const PREFIX='mad4b_test_signed_original_';
  const FIELDS=['contract','job_id','company_code','environment','site_uuid',
  'canonical_origin','intent','original_operation','command_sha256',
  'native_receipt_sha256','independent_readback_sha256','before_sha256',
  'after_sha256','rollback_sha256','verified_at_epoch','nonce'];
  public static function certified_status($company,$environment,$intent):array {
    return $GLOBALS['TEST_STATUS']??[];
  }
}
require dirname(__DIR__).'/includes/class-mad4b-company-repair-job-contract.php';
require dirname(__DIR__).'/includes/class-mad4b-company-repair-capability-discovery.php';
require dirname(__DIR__).'/includes/class-mad4b-company-repair-jobs.php';
$check=[];$assert=function($name,$okay)use(&$check){$check[$name]=(bool)$okay;};
$call=function($method,...$args){
  $reflection=new ReflectionMethod('MAD4B_Company_Reconcile_Repair_Jobs_V1',$method);
  return $reflection->invoke(null,...$args);
};
$now=20000;$orig=str_repeat('b',32);$cmd=str_repeat('a',64);
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$job=[
 'company_code'=>'wayfatours','environment'=>'production',
 'site_uuid'=>$uuid,'canonical_origin'=>'https://wayfatours.com',
 'native_operation'=>'future.registered.operation',
 'resource_key'=>'incident:test_case',
 'baseline'=>[
  'resource_manifest_sha256'=>str_repeat('c',64),
  'before_sha256'=>str_repeat('d',64),
  'expected_after_sha256'=>str_repeat('e',64),
  'rollback_sha256'=>str_repeat('d',64),
 ],
 'original_job_id'=>$orig,'expected_command_sha256'=>$cmd,
];
$broker=[
 'job_id'=>$orig,'company'=>'wayfatours',
 'environment'=>'production','site_uuid'=>$uuid,
 'canonical_origin'=>'https://wayfatours.com',
 'domain_intent'=>$job['native_operation'],
 'command_sha256'=>$cmd,
 'domain_resource_key'=>$job['resource_key'],
 'resource_set_sha256'=>$job['baseline']['resource_manifest_sha256'],
];
$GLOBALS['TEST_OPTIONS'][MAD4B_Central_VPS_Broker_V1::JOB.$orig]=$broker;
$assert('matching_original_job_structurally_readable',
 is_array($call('original_broker_record',$job,$orig,$cmd)));
$cases=[
 'company'=>'otherco',
 'environment'=>'staging',
 'site_uuid'=>'different_uuid',
 'canonical_origin'=>'https://evil.invalid',
 'domain_intent'=>'other.write',
 'command_sha256'=>str_repeat('f',64),
 'domain_resource_key'=>'another:post',
 'resource_set_sha256'=>str_repeat('f',64),
 'job_id'=>str_repeat('f',32),
];
foreach($cases as $name=>$value){
 $GLOBALS['TEST_OPTIONS'][MAD4B_Central_VPS_Broker_V1::JOB.$orig]=$broker;
 $GLOBALS['TEST_OPTIONS'][MAD4B_Central_VPS_Broker_V1::JOB.$orig][$name]=$value;
 $assert('reject_original_'.$name,
   $call('original_broker_record',$job,$orig,$cmd)===null);
}
$GLOBALS['TEST_OPTIONS'][MAD4B_Central_VPS_Broker_V1::JOB.$orig]=$broker;
$proof=[
 'contract'=>'test','job_id'=>$orig,'company_code'=>$job['company_code'],
 'environment'=>$job['environment'],'site_uuid'=>$uuid,
 'canonical_origin'=>$job['canonical_origin'],
 'intent'=>$job['native_operation'],'original_operation'=>'company.remote.wpcli.full',
 'command_sha256'=>$cmd,'native_receipt_sha256'=>str_repeat('1',64),
 'independent_readback_sha256'=>str_repeat('2',64),
 'before_sha256'=>$job['baseline']['before_sha256'],
 'after_sha256'=>$job['baseline']['expected_after_sha256'],
 'rollback_sha256'=>$job['baseline']['rollback_sha256'],
 'verified_at_epoch'=>$now,'nonce'=>str_repeat('9',32),
];
$hash=hash('sha256',implode(chr(10),
 array_map(fn($field)=>(string)$proof[$field],MAD4B_Company_Native_Write_Executor_V1::FIELDS)));
$index=MAD4B_Company_Native_Write_Executor_V1::PREFIX.$job['company_code'].'_'.
 $job['environment'].'_'.$job['native_operation'];
$GLOBALS['TEST_OPTIONS'][$index]=$proof;
$GLOBALS['TEST_STATUS']=[
 'state'=>'ORIGINAL_EXACT_WRITE_AND_ROLLBACK_CERTIFIED',
 'site_uuid'=>$uuid,'canonical_origin'=>$job['canonical_origin'],
 'receipt_sha256'=>$hash
];
$assert('exact_signed_adapter_receipt_structural_match',
 ($call('signed_exact_result',$job)['state']??null)==='EXACT_SIGNED_PROOF_MATCH');
foreach([
 'wrong_historical_job'=>['job_id',str_repeat('a',32)],
 'wrong_command'=>['command_sha256',str_repeat('a',63).'b'],
 'wrong_before'=>['before_sha256',str_repeat('e',64)],
 'wrong_rollback'=>['rollback_sha256',str_repeat('e',64)]
] as $name=>[$field,$value]){
 $GLOBALS['TEST_OPTIONS'][$index]=$proof;
 $GLOBALS['TEST_OPTIONS'][$index][$field]=$value;
 $assert($name.'_cannot_close_new_repair',
  ($call('signed_exact_result',$job)['state']??null)!=='EXACT_SIGNED_PROOF_MATCH');
}
$GLOBALS['TEST_OPTIONS'][$index]=$proof;
$GLOBALS['TEST_OPTIONS'][$index]['after_sha256']=str_repeat('f',64);
// The test adapter represents an independent certifier that would verify
// the signed *different* after state, not reuse the old proof digest.
$GLOBALS['TEST_STATUS']['receipt_sha256']=hash('sha256',implode(chr(10),
 array_map(fn($field)=>(string)$GLOBALS['TEST_OPTIONS'][$index][$field],
  MAD4B_Company_Native_Write_Executor_V1::FIELDS)));
$res=$call('signed_exact_result',$job);
$assert('postimage_mismatch_is_signed_drift_hold',
 ($res['state']??null)==='SIGNED_EXACT_JOB_POSTIMAGE_MISMATCH'
 &&($res['observed_after_sha256']??null)===str_repeat('f',64));
$GLOBALS['TEST_OPTIONS'][$index]=$proof;
$GLOBALS['TEST_STATUS']['site_uuid']='different';
$assert('wrong_site_certificate_rejected',
 ($call('signed_exact_result',$job)['state']??null)==='ORIGINAL_313_FOR_THIS_JOB_NOT_VERIFIED');
echo json_encode(['pass'=>!in_array(false,$check,true),
 'assertions'=>count($check),'failed'=>array_keys(array_filter($check,fn($v)=>!$v)),
 'actual_signature_verified_in_this_test'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$check,true))exit(1);
