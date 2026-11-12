<?php
/** Exact provider signed-312 separate from general Original transport. */
define('ABSPATH','/');
function add_action(...$args){}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return false;}
class MockSourceProvider{
 public static $flags=[];
 public static function readback(){return [
  'contract'=>'mad4b.mock-provider-native-readback.v1',
  'repair_support'=>[
   'operation'=>'future.company.dynamic.write',
   'provider'=>'root_owned_future_write_provider',
   'scoped_preflight_contract'=>'mad4b.company-repair.exact-provider-312.v1',
   'scoped_preflight_read_only'=>true,
   'scoped_preflight'=>[__CLASS__,'exact_scope']
  ]
 ];}
 public static function exact_scope($company,$env,$operation){
  $base=[
   'contract'=>'mad4b.company-repair.exact-provider-312.v1',
   'state'=>'EXACT_COMPANY_PROVIDER_312_HOLD',
   'reason'=>'SIGNED_312_FOR_EXACT_SCOPE_MISSING',
   'company_code'=>$company,'environment'=>$env,
   'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
   'canonical_origin'=>'https://wayfatours.com',
   'operation'=>$operation,'provider'=>'root_owned_future_write_provider',
   'heartbeat_epoch'=>time(),
   'original_312_signed'=>false,'source_signature_verified'=>false,
   'original_313_signed'=>false,'native_job_submitted'=>false
  ];
  return array_replace($base,self::$flags);
 }
}
class MAD4B_Original_Native_Operation_Providers_V1{
 public static function profile($operation){
  if($operation!=='future.company.dynamic.write')return null;
  return [
   'provider'=>'root_owned_future_write_provider',
   'authority_family'=>'original_305',
   'target_scope'=>'wordpress',
   'evidence_contract'=>'mad4b.mock-provider-native-readback.v1',
   'readback'=>[MockSourceProvider::class,'readback']
  ];
 }
}
require dirname(__DIR__).'/includes/class-mad4b-company-repair-jobs.php';
$reflection=new ReflectionMethod('MAD4B_Company_Reconcile_Repair_Jobs_V1','exact_signed_provider_312');
$job=[
 'company_code'=>'wayfatours','environment'=>'production',
 'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
 'canonical_origin'=>'https://wayfatours.com',
 'native_operation'=>'future.company.dynamic.write'
];
$plan=[
 'provider_resolution_state'=>'EXACT_CAPABILITY_DISCOVERED',
 'registered_provider_candidates'=>[[
  'operation'=>'future.company.dynamic.write',
  'provider'=>'root_owned_future_write_provider',
  'scoped_preflight_id'=>MockSourceProvider::class.'::exact_scope'
 ]]
];
$tests=[];$test=static function($name,$ok)use(&$tests){$tests[$name]=(bool)$ok;};
$check=static fn()=>$reflection->invoke(null,$job,$plan);
$initial=$check();
$test('general_ready_does_not_substitute_exact_312',
 ($initial['verified']??true)===false);
$test('no_original313_emitted',
 ($initial['original_313_signed']??true)===false);
$valid=[
 'state'=>'EXACT_SOURCE_SIGNED_ORIGINAL_312_VERIFIED',
 'reason'=>null,'original_312_signed'=>true,'source_signature_verified'=>true
];
MockSourceProvider::$flags=$valid;
$good=$check();
$test('new_dynamically_registered_provider_can_verify_exact_company_312',
 $good['verified']===true &&$good['source_signature_verified']===true);
foreach([
 'stale_heartbeat'=>['heartbeat_epoch',time()-301],
 'future_heartbeat'=>['heartbeat_epoch',time()+11],
 'wrong_company'=>['company_code','other-company'],
 'wrong_environment'=>['environment','staging'],
 'wrong_site_uuid'=>['site_uuid','a3a7cc36-db7e-4a14-8456-b41857def05f'],
 'wrong_canonical_origin'=>['canonical_origin','https://other.example'],
 'wrong_operation'=>['operation','other.write'],
 'wrong_provider'=>['provider','another_provider'],
 'fake_signature_claim'=>['source_signature_verified',false],
 'original_312_missing'=>['original_312_signed',false],
 'original_313_claimed'=>['original_313_signed',true],
 'native_job_claimed'=>['native_job_submitted',true],
 'wrong_signature_contract'=>['contract','fake.contract']
] as $name=>[$key,$value]) {
 MockSourceProvider::$flags=array_replace($valid,[$key=>$value]);
 $test('reject_'.$name,$check()['verified']===false);
}
MockSourceProvider::$flags=$valid;
$changed=$plan;
$changed['registered_provider_candidates'][0]['scoped_preflight_id']='OtherSource::forge';
$test('forged_source_callback_not_picked',
 $reflection->invoke(null,$job,$changed)['verified']===false);
$changed=$plan;
$changed['registered_provider_candidates'][0]['operation']='unrelated.source.write';
$test('unrelated_operation_not_accepted',
 $reflection->invoke(null,$job,$changed)['verified']===false);
$changed=$plan;
$changed['provider_resolution_state']='MULTIPLE_PROVIDERS_NEED_EXPLICIT_GOVERNED_RESOLUTION';
$test('ambiguous_provider_not_accepted',
 $reflection->invoke(null,$job,$changed)['verified']===false);
echo json_encode(['pass'=>!in_array(false,$tests,true),'assertions'=>count($tests),
 'failed'=>array_keys(array_filter($tests,fn($x)=>!$x)),
 'exact_provider_signature_checked_via_registered_callback'=>true,
 'real_original_signature_test'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$tests,true))exit(1);
