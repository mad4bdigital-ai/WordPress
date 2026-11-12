<?php
/**
 * End-to-end mock WordPress options and Original Registry test.
 * No real tenant, broker, target or Original signature is modified.
 */
define('ABSPATH','/');
define('WP_CLI',true);
class WP_Error{
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
class WP_CLI{public static function add_command(...$args){}}
class TestDB{
 public function prepare($sql,...$args){return sprintf($sql,...array_map(fn($v)=>"'".$v."'",$args));}
 public function get_var($query){return str_contains($query,'GET_LOCK')?1:1;}
}
$GLOBALS['wpdb']=new TestDB();
$GLOBALS['TEST_OPTIONS']=[];
function add_action(...$args){}
function wp_is_uuid($v){return is_string($v)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$v)===1;}
function current_user_can($permission){return ($GLOBALS['ADMIN']??true)&&$permission==='manage_options';}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_option($key,$fallback=false){return $GLOBALS['TEST_OPTIONS'][$key]??$fallback;}
function update_option($key,$value,$autoload=false){
 $GLOBALS['TEST_OPTIONS'][$key]=$value;return true;
}
function add_option($key,$value,$deprecated='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['TEST_OPTIONS']))return false;
 $GLOBALS['TEST_OPTIONS'][$key]=$value;return true;
}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class MAD4B_Company_Portfolio_Manager_V1{
 public static function staging(){return true;}
}
class MAD4B_Company_Environment_Registry_V2{}
class MAD4B_Company_Reconcile_Work_Plan_V1{
 public static function inspect($company,$environment){
  $GLOBALS['WORK_PLAN_INSPECT_COUNT']=($GLOBALS['WORK_PLAN_INSPECT_COUNT']??0)+1;
  if($company!=='wayfatours'||$environment!=='production')
   return new WP_Error('target_not_registered');
  return $GLOBALS['TEST_PLAN'];
 }
 public static function original_policy(){return [
  'repair_provider_discovery_contract'=>'mad4b.company-repair.provider-capability.v1',
  'repair_provider_discovery_mode'=>'trusted_original_registry_scoped_readback_dynamic',
  'repair_ambiguous_provider_policy'=>'hold_no_auto_selection'
 ];}
}
class TestNativeProvider{
 public static function readback(){return [
  'contract'=>'mad4b.test-resource-provider.v2',
  'provider_ready'=>false,
  'operation'=>'future.source.resource.write',
  'provider'=>'test_source_provider',
  'repair_support'=>[
    'contract'=>'mad4b.company-repair.provider-capability.v1',
    'operation'=>'future.source.resource.write',
    'provider'=>'test_source_provider',
    'evidence_contract'=>'mad4b.test-resource-provider.v2',
    'resource_scope'=>'exact_site_resource',
    'mutation_authorized'=>false,'independent_313_required'=>true,
    'rollback_via_new_original_job'=>true,
    'resource_resolver_contract'=>'mad4b.company-repair.resource-resolver.v2',
    'resource_resolution_read_only'=>true,
    'resource_resolver'=>[__CLASS__,'resolve_resource'],
    'resource_types'=>['wordpress.post'],
    'scoped_preflight_contract'=>'mad4b.company-repair.exact-provider-312.v1',
    'scoped_preflight_read_only'=>true,
    'scoped_preflight'=>[__CLASS__,'scoped312'],
    'supported_checks'=>['target_identity']
  ]
 ];}
 public static function scoped312($company,$environment,$operation){
  $ready=($GLOBALS['TEST_SIGNED_312']??false)===true;
  return [
   'contract'=>'mad4b.company-repair.exact-provider-312.v1',
   'state'=>$ready?'EXACT_SOURCE_SIGNED_ORIGINAL_312_VERIFIED':'EXACT_COMPANY_PROVIDER_312_HOLD',
   'reason'=>$ready?null:'TEST_SIGNED_312_NOT_AVAILABLE',
   'company_code'=>$company,'environment'=>$environment,
   'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
   'canonical_origin'=>'https://wayfatours.com',
   'operation'=>$operation,'provider'=>'test_source_provider',
   'heartbeat_epoch'=>time(),
   'original_312_signed'=>$ready,'source_signature_verified'=>$ready,
   'original_313_signed'=>false,'native_job_submitted'=>false
  ];
 }
 public static function resolve_resource($request){
  $GLOBALS['OBSERVED_REQUEST']=$request;
  $base=[
    'contract'=>'mad4b.company-repair.resource-observation.v1',
    'state'=>'EXACT_RESOURCE_READ_ONLY_OBSERVED',
    'observation_level'=>'TARGET_WORDPRESS_AUTHENTICATED_READ_ONLY',
    'company_code'=>$request['company_code'],
    'environment'=>$request['environment'],
    'site_uuid'=>$request['site_uuid'],
    'canonical_origin'=>$request['canonical_origin'],
    'source_plan_sha256'=>$request['source_plan_sha256'],
    'operation'=>'future.source.resource.write',
    'provider'=>'test_source_provider',
    'resource_type'=>$request['resource_type'],
    'resource_id'=>$request['resource_id'],
    'resource_key'=>$request['resource_type'].':'.$request['resource_id'],
    'ownership_scope'=>'mixed',
    'before_sha256'=>str_repeat('b',64),
    'resource_manifest_sha256'=>str_repeat('c',64),
    'company_preservation_sha256'=>str_repeat('d',64),
    'observed_epoch'=>time(),
    'original_313_signed'=>false,'original_312_signed'=>false,
    'mutation_performed'=>false,'native_job_created'=>false
  ];
  return array_replace($base,$GLOBALS['OVERRIDES']??[]);
 }
}
class MAD4B_Company_Native_Write_Executor_V1 {
 public static function preflight_status($company,$environment,$operation){
  return [
   'state'=>'AUTHORITY_AND_EXECUTOR_READY_FOR_EXACT_JOB',
   'scope_preflight_passed'=>true,
   'target_wordpress_actor_capability_verified'=>true,
   'operation_provider_ready'=>true,
   'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
   'canonical_origin'=>'https://wayfatours.com',
   'original_313_signed'=>false
  ];
 }
}
class MAD4B_Original_Native_Operation_Providers_V1{
 const CONTRACT='mad4b.original-native-operation-providers.v1';
 const MAX_PROVIDERS=128;
 public static function discover(){return [
    'contract'=>self::CONTRACT,'state'=>'DISCOVERY_ONLY',
    'operations'=>[[
      'operation'=>'future.source.resource.write','provider'=>'test_source_provider',
      'authority_family'=>'original_305','target_scope'=>'wordpress',
      'command_format'=>'wpcli.argv.json.v1','evidence_contract'=>'mad4b.test-resource-provider.v2'
    ]],
    'provider_count'=>1
  ];}
 public static function profile($operation){
  if($operation!=='future.source.resource.write')return null;
  return [
    'operation'=>'future.source.resource.write','provider'=>'test_source_provider',
    'authority_family'=>'original_305','target_scope'=>'wordpress',
    'command_format'=>'wpcli.argv.json.v1','evidence_contract'=>'mad4b.test-resource-provider.v2',
    'readback'=>[TestNativeProvider::class,'readback']
  ];
 }
}
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$GLOBALS['TEST_PLAN']=[
 'company_binding_verified'=>true,'requested_environment_registered'=>true,
 'depth_audit'=>[
  'dependency_graph_valid'=>true,
  'incident_probe_mapping'=>[
   ['code'=>'synthetic_missing_resource','evidence_lane'=>'target_local']
  ]],
 'environment_inventory'=>['production'=>[
  'site_uuid'=>$uuid,'canonical_origin'=>'https://wayfatours.com'
 ]],
 'incident_scenarios'=>[
  ['code'=>'synthetic_missing_resource','related_check'=>'target_identity',
   'priority'=>'P1','ownership_scope'=>'mixed',
   'owner'=>'test_source_provider','repair_strategy'=>'exact post readback',
   'acceptance'=>'independent signed 313 only','target_probe_check'=>null]
 ],
 'work_items'=>[
   ['check'=>'target_identity','stage'=>'bootstrap','depends_on'=>[]]
 ],
 'work_plan_sha256'=>str_repeat('a',64)
];
require dirname(__DIR__).'/includes/class-mad4b-company-repair-jobs.php';
$cls='MAD4B_Company_Reconcile_Repair_Jobs_V1';
$assertions=[];$test=static function($name,$value)use(&$assertions){
 $assertions[$name]=(bool)$value;
};
$plan=$cls::plan('wayfatours','production','synthetic_missing_resource');
$test('dynamic_op_from_provider_not_static_list',!is_wp_error($plan)
 &&$plan['native_operation']==='future.source.resource.write'
 &&$plan['provider_resolution_state']==='EXACT_CAPABILITY_DISCOVERED');
$type='wordpress.post';$rid='123';
$preview=$cls::resource_preview('wayfatours','production','synthetic_missing_resource',$type,$rid);
$test('actual_same_file_trusted_provider_callback',!is_wp_error($preview)
 &&($preview['state']??'')==='EXACT_RESOURCE_OBSERVED_UNSIGNED');
$test('callback_exact_site_no_mutation',
 ($GLOBALS['OBSERVED_REQUEST']['site_uuid']??'')===$uuid
 &&($GLOBALS['OBSERVED_REQUEST']['allow_write']??true)===false);
$test('callback_sees_dynamic_selector',
 ($GLOBALS['OBSERVED_REQUEST']['resource_id']??'')==='123');
$test('read_only_preview_created_no_job',count($GLOBALS['TEST_OPTIONS'])===0);
$sourceOwnership=$GLOBALS['TEST_PLAN']['incident_scenarios'][0]['ownership_scope'];
$GLOBALS['TEST_PLAN']['incident_scenarios'][0]['ownership_scope']='tenant_owned';
$ownershipHold=$cls::resource_preview('wayfatours','production',
 'synthetic_missing_resource',$type,$rid);
$test('resource_owner_cannot_override_source_tenant_policy',
 ($ownershipHold['reason']??null)==='PROVIDER_RESOURCE_OWNERSHIP_CONFLICT_WITH_SOURCE');
$GLOBALS['TEST_PLAN']['incident_scenarios'][0]['ownership_scope']=$sourceOwnership;
$wrong=$cls::resource_preview('wayfatours','production','synthetic_missing_resource',
 'wordpress.term','123');
$test('unsupported_dynamic_resource_type_hold',
 ($wrong['state']??'')==='RESOURCE_RESOLUTION_HOLD');
$created=$cls::create_resource('wayfatours','production','synthetic_missing_resource',
 $type,$rid,$plan['source_plan_sha256']);
$test('real_resource_child_journal_saved',!is_wp_error($created)
 &&($created['state']??'')==='EXACT_RESOURCE_JOB_PERSISTED_AWAITING_BASELINE');
$id=$created['repair_id']??'';
$test('unique_resource_child_identity',strlen($id)===40&&$id!==$plan['id']);
$record=$cls::status($id);
$test('resource_job_hash_chain_and_binding',!is_wp_error($record)
 &&MAD4B_Company_Repair_Job_Contract_V1::valid_chain($record)
 &&$record['revision']===0
 &&$record['resource_key']==='wordpress.post:123');
$GLOBALS['WORK_PLAN_INSPECT_COUNT']=0;
$assessment=$cls::baseline_review_scope(
 'wayfatours','production','synthetic_missing_resource','wordpress.post','123');
$test('baseline_candidate_resource_journal_review_only',
 !is_wp_error($assessment)
 &&$assessment['state']==='UNSIGNED_BASELINE_CANDIDATE_REVIEW_ONLY'
 &&$assessment['before_sha256']===str_repeat('b',64)
 &&$assessment['expected_after_sha256']===null
 &&$assessment['rollback_sha256']===null
 &&$assessment['original_313_verified']===false
 &&$assessment['journal_updated_by_review']===false);
$test('baseline_review_uses_one_work_plan_inspection',
 $GLOBALS['WORK_PLAN_INSPECT_COUNT']===1);
$test('baseline_review_cannot_create_or_mutate_job',
 $cls::status($id)['revision']===0);

$test('no_native_submission_from_resource_create',
 ($record['original_305_submitted_by_repair_job']??true)===false);
$again=$cls::create_resource('wayfatours','production','synthetic_missing_resource',
 $type,$rid,$plan['source_plan_sha256']);
$jobCount=static fn()=>count(array_filter(array_keys($GLOBALS['TEST_OPTIONS']),
 static fn($key)=>str_starts_with($key,$cls::PREFIX)));
$test('duplicate_resource_not_created',
 ($again['state']??'')==='EXACT_RESOURCE_JOB_ALREADY_EXISTS'
 &&$jobCount()===1);
$other=$cls::create_resource('wayfatours','production','synthetic_missing_resource',
 $type,'124',$plan['source_plan_sha256']);
$test('second_resource_separate_child',!is_wp_error($other)
 &&$other['repair_id']!==$id&&$jobCount()===2);
$queue=$cls::queue('wayfatours','production');
$test('operator_queue_reads_exact_resource_children',
 !is_wp_error($queue)
 &&($queue['resource_child_journals']??-1)===2
 &&($queue['resource_child_integrity_holds']??-1)===0);
$indexKey=$cls::RESOURCE_INDEX_PREFIX.$plan['id'];
$untrusted=$GLOBALS['TEST_OPTIONS'][$indexKey];
$GLOBALS['TEST_OPTIONS'][$indexKey]['ids'][0]=str_repeat('f',40);
$corrupt=$cls::queue('wayfatours','production');
$test('index_digest_tamper_holds_queue',
 !is_wp_error($corrupt)
 &&($corrupt['resource_child_integrity_holds']??0)>0);
$GLOBALS['TEST_OPTIONS'][$indexKey]=$untrusted;
$GLOBALS['OVERRIDES']=['before_sha256'=>str_repeat('e',64)];
$drift=$cls::create_resource('wayfatours','production','synthetic_missing_resource',
 $type,$rid,$plan['source_plan_sha256']);
$test('changed_source_refuses_same_resource_duplicate',is_wp_error($drift));
$unmatched=$cls::baseline_review_scope(
 'wayfatours','production','synthetic_missing_resource','wordpress.post','123');
$test('changed_source_cannot_make_fresh_baseline_candidate',
 !is_wp_error($unmatched)&&$unmatched['state']==='BASELINE_REVIEW_HOLD');
$pre=$cls::preflight($id);
$test('changed_source_blocks_native_312_preflight',
 !is_wp_error($pre)&&$pre['original_provider_preflight_ready']===false
 &&$pre['resource_binding_state']==='EXACT_RESOURCE_TARGET_CHANGED_OR_READBACK_UNAVAILABLE');
unset($GLOBALS['OVERRIDES']);
$pre=$cls::preflight($id);
$test('matching_resource_reaches_provider_preflight_only',
 !is_wp_error($pre)&&$pre['original_provider_preflight_ready']===false
 &&$pre['resource_binding_state']==='EXACT_SOURCE_PROVIDER_RESOURCE_EVIDENCE_PRESENT');
$wrongBaseline=[
 'contract'=>MAD4B_Company_Repair_Job_Contract_V1::BASELINE,
 'company_code'=>'wayfatours','environment'=>'production','site_uuid'=>$uuid,
 'canonical_origin'=>'https://wayfatours.com',
 'resource_key'=>'wordpress.post:123','source_plan_sha256'=>str_repeat('a',64),
 'before_sha256'=>str_repeat('f',64),'rollback_sha256'=>str_repeat('f',64),
 'expected_after_sha256'=>str_repeat('e',64),
 'resource_manifest_sha256'=>str_repeat('c',64),
 'evidence_level'=>'LOCAL_WORDPRESS_UNSIGNED','observed_epoch'=>time()
];
$bad=$cls::baseline($id,$wrongBaseline);
$test('wrong_before_hash_cannot_attach_baseline',is_wp_error($bad));
$right=$wrongBaseline;
$right['before_sha256']=str_repeat('b',64);
$right['rollback_sha256']=str_repeat('b',64);
$accepted=$cls::baseline($id,$right);
$test('resource_baseline_recorded_without_signing',
 !is_wp_error($accepted)
 &&$accepted['state']==='BASELINE_RECORDED_UNVERIFIED'
 &&$accepted['baseline_signed']===false);
$more=$cls::baseline($id,$right);
$test('baseline_cannot_be_replaced',is_wp_error($more));
$prepared=$cls::prepare($id);
$test('preflight_does_not_fabricate_signed312',
 !is_wp_error($prepared)&&$prepared['state']==='PROVIDER_OR_SIGNED_312_HOLD'
 &&$prepared['original_305_submitted']===false);
$GLOBALS['TEST_SIGNED_312']=true;
$pre=$cls::preflight($id);
$test('mock_exact_signed312_and_generic_transport_consistent',
 !is_wp_error($pre)&&$pre['original_provider_preflight_ready']===true
 &&$pre['specialized_provider_signed_312_verified']===true
 &&$pre['repair_execution_ready']===false
 &&$pre['executor_time_before_image_cas_verified']===false);
$prepared=$cls::prepare($id);
$test('signed_312_plus_unsigned_wpcli_never_passes_executor_cas',
 !is_wp_error($prepared)&&$prepared['state']==='EXACT_EXECUTOR_PREIMAGE_CAS_HOLD'
 &&$prepared['original_305_submitted']===false
 &&$prepared['original_313_verified']===false);
$after=$cls::status($id);
$test('cas_hold_does_not_advance_or_write_journal',
 !is_wp_error($after)&&$after['state']==='BASELINE_RECORDED_UNVERIFIED'
 &&$after['revision']===1);
unset($GLOBALS['TEST_SIGNED_312']);
$resume=$cls::resume($id);
$test('resume_never_submits_original305',
 !is_wp_error($resume)&&$resume['new_job_submitted']===false);
$rollback=$cls::rollback_plan($id);
$test('rollback_not_automatically_authorized',
 !is_wp_error($rollback)
 &&$rollback['automatic_rollback_authorized']===false);
$stored=$cls::status($id);
$test('postbaseline_journal_integrity',
 !is_wp_error($stored)&&MAD4B_Company_Repair_Job_Contract_V1::valid_chain($stored));
$bad=$stored;$bad['resolved_company_preservation_sha256']=str_repeat('f',64);
$test('tampered_preservation_value_rejected',
 !MAD4B_Company_Repair_Job_Contract_V1::valid_chain($bad));
$bad=$cls::create_resource('wayfatours','production','synthetic_missing_resource',
 $type,'123',str_repeat('e',64));
$test('source_plan_hash_mismatch_blocks_creation',is_wp_error($bad));
$wrong=$cls::create_resource('wayfatours','staging','synthetic_missing_resource',
 $type,'123',$plan['source_plan_sha256']);
$test('other_environment_never_inherits_prod_resource',is_wp_error($wrong));
echo json_encode(['pass'=>!in_array(false,$assertions,true),
  'assertions'=>count($assertions),
  'failed'=>array_keys(array_filter($assertions,fn($v)=>!$v)),
  'resource_children_created_in_mock_database'=>$jobCount(),
  'native_jobs_created'=>0,
],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$assertions,true))exit(1);
