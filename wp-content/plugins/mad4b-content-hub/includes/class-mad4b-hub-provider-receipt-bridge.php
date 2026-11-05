<?php
defined('ABSPATH') || exit;
/**
 * MAD4B Content Hub Provider Execution & Receipt Bridge v1
 * Staging, native client leases only. Never impersonates MCP or manufactures
 * source evidence. No second queue or independent commercial publishing path.
 */
final class MAD4B_Hub_Provider_Receipt_Bridge {
 const CONTRACT='mad4b.hub.provider-receipt-bridge.v1';
 const MAP=[
  'mad4b/research-keyword-record'=>'keyword_research',
  'mad4b/research-serp-record'=>'serp_research',
  'mad4b/research-competitor-select'=>'competitor_set'
 ];
 private static function fail($name,$http=409){
  return new WP_Error('mad4b_bridge_'.$name,$name,['status'=>$http,'contract'=>self::CONTRACT]);
 }
 private static function permitted($write=false){
  return class_exists('MAD4B_Content_Hub') &&
    MAD4B_Content_Hub::permission() &&
    (!$write || (class_exists('MAD4B_SCP_Policy')&&MAD4B_SCP_Policy::can_mutate()));
 }
 private static function task($id){
  if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',(string)$id))
   return self::fail('task_id_invalid',422);
  global $wpdb;
  $t=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $b=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $r=$wpdb->get_row($wpdb->prepare("SELECT q.*,b.state AS batch_state,b.batch_key
    FROM $t q JOIN $b b ON b.batch_id=q.batch_id WHERE q.task_id=%s LIMIT 1",$id),ARRAY_A);
  if(!is_array($r))return self::fail('task_missing',404);
  $p=json_decode((string)$r['payload_json'],true);
  if(!is_array($p))return self::fail('task_payload_invalid',409);
  $r['payload']=$p;
  return $r;
 }
 private static function adapters(){
  // Only trusted site PHP code may register an adapter; never from client input.
  $v=apply_filters('mad4b_hub_provider_bridge_adapters',[]);
  if(!is_array($v))return [];
  $valid=[];
  foreach($v as $id=>$a){
   if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{2,63}$/D',$id)||
      !is_array($a)||!is_callable($a['probe']??null)||
      !is_callable($a['execute']??null)||
      !is_array($a['operations']??null))continue;
   $valid[$id]=$a;
  }
  return $valid;
 }
 private static function context($t){
  $p=$t['payload'];
  $origin=$p['workflow_origin']??null;
  if($origin!==null){
   if(!is_array($origin)||($origin['contract']??'')!=='mad4b.hub.workflow-origin.v1')
    return self::fail('origin_invalid',409);
   $hash=(string)($origin['origin_sha256']??'');unset($origin['origin_sha256']);
   if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||
      !hash_equals($hash,hash('sha256',wp_json_encode($origin,JSON_UNESCAPED_SLASHES))))
    return self::fail('origin_digest_invalid',409);
  }
  $job=(string)$t['content_job_id'];
  if($job!==''){
   if($t['operation']!=='stage_work'||$t['stage']!=='INFORMATION_GAIN')
    return self::fail('unsupported_content_stage',409);
   $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
   if(is_wp_error($j))return $j;
   if(($j['job']['stage']??'')!==$t['stage']||
      !in_array($j['job']['state']??'',['NEW','QUEUED','RUNNING'],true)||
      (int)($p['content_job_revision']??-1)!==(int)($j['job']['job_revision']??-2))
    return self::fail('job_revision_or_stage_stale',409);
   $ability=(string)($p['preferred_ability']??'');
   if(!isset(self::MAP[$ability]))return self::fail('research_capability_not_resolved',409);
   return ['workflow_type'=>'content_production','job_id'=>$job,
     'capability'=>$ability,'artifact_type'=>self::MAP[$ability],
     'record_id'=>$job,'source_company'=>'','operation'=>$t['operation'],
     'stage'=>$t['stage']];
  }
  if(!str_starts_with((string)($t['batch_key']??'gaps.native_claim'),'gaps.'))
   return self::fail('unsupported_noncontent_origin',409);
  $operations=[
   'verify_source_inventory'=>'inventory_unverified',
   'resolve_source_pricing'=>'source_pricing_missing',
   'review_canonical_identity'=>'entity_identity_review',
   'verify_property_identity'=>'property_identity_missing',
   'review_rate_date_range'=>'source_rate_dates_invalid'
  ];
  if(!isset($operations[$t['operation']]))
   return self::fail('unsupported_product_operation',409);
  $gap=(int)($p['gap_task_id']??0);
  $canonical=(int)($p['canonical_id']??0);
  if($gap<1||$canonical<1||$t['target_ref']!=='canonical:'.$canonical)
   return self::fail('canonical_binding_invalid',409);
  global $wpdb;
  $g=$wpdb->prefix.'mad4b_hub_canonical_gaps';
  $e=$wpdb->prefix.'mad4b_hub_canonical_entities';
  $row=$wpdb->get_row($wpdb->prepare("SELECT g.id,g.canonical_id,g.gap_code,g.state,g.company_id,g.source_sha256,g.provider_hint,
   e.company_id AS entity_company,e.snapshot_json
   FROM $g g JOIN $e e ON e.id=g.canonical_id WHERE g.id=%d AND e.id=%d LIMIT 1",
    $gap,$canonical),ARRAY_A);
  if(!$row || $row['company_id']!==$row['entity_company'] ||
     $row['company_id']!==($p['source_company']??'') ||
     $row['gap_code']!==($p['gap_code']??'')||
     $operations[$t['operation']]!==$row['gap_code'])
   return self::fail('canonical_scope_mismatch',409);
  return ['workflow_type'=>'product_data','gap_id'=>$gap,
   'canonical_id'=>$canonical,'gap_code'=>$row['gap_code'],
   'gap_state'=>$row['state'],'source_sha256'=>$row['source_sha256'],
   'source_company'=>$row['company_id'],'capability'=>$row['provider_hint'],
   'record_id'=>'canonical_gap:'.$gap,'operation'=>$t['operation'],
   'stage'=>$t['stage']];
 }
 private static function artifact($job,$type){
  if(!class_exists('MAD4B_SCP_Artifacts'))return null;
  $r=MAD4B_SCP_Artifacts::list_artifacts(['job_id'=>$job,'artifact_type'=>$type]);
  if(is_wp_error($r))return null;
  $found=null;
  foreach((array)($r['items']??[]) as $a){
   if(($a['job_id']??'')!==$job||($a['artifact_type']??'')!==$type||
      ($a['status']??'')!=='active'||
      !preg_match('/^[a-f0-9]{64}$/D',(string)($a['content_sha256']??''))||
      !is_array($a['payload']??null)||!$a['payload'])continue;
   $payload=$a['payload'];
   if(in_array($type,['keyword_research','serp_research'],true)){
    $field=$type==='keyword_research'?'normalized_records':'result_records';
    if(($payload['provider_state']??'')!=='succeeded'||
       !empty($payload['error_class'])||
       empty($payload[$field])||!is_array($payload[$field])||
       empty($payload['source_refs'])||!is_array($payload['source_refs'])||
       !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['request_sha256']??'')))
     continue;
   }elseif($type==='competitor_set'){
    if(empty($payload['selected'])||!is_array($payload['selected'])||
       !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['selection_sha256']??''))||
       !preg_match('/^[a-f0-9-]{36}$/D',(string)($payload['serp_snapshot_id']??'')))
     continue;
    $serp=self::artifact($job,'serp_research');
    if(!$serp||($serp['artifact_id']??'')!==($payload['serp_snapshot_id']??''))
     continue;
   }
   if($found===null||(int)$a['version']>(int)$found['version'])$found=$a;
  }
  return $found;
 }
 private static function provider_for($ctx,$task){
  $providers=self::adapters();
  // Do not choose by name alone: a registered probe must explicitly confirm
  // the source, capability and the exact task identity.
  foreach($providers as $id=>$adapter){
   if(!in_array($ctx['capability'],$adapter['operations'],true))continue;
   try {
    $probe=call_user_func($adapter['probe'],$ctx,$task);
   }catch(Throwable $e){
    // A failed provider probe means not ready. Never promote a task
    // based on a crashed custom adapter or its unverified output.
    continue;
   }
   if($probe===true)return ['id'=>$id,'adapter'=>$adapter];
  }
  return null;
 }
 public static function plan($input=[]){
  if(!self::permitted())return self::fail('permission',403);
  $t=self::task((string)($input['task_id']??''));
  if(is_wp_error($t))return $t;
  $ctx=self::context($t);
  if(is_wp_error($ctx))return ['contract'=>self::CONTRACT,
    'task_id'=>$t['task_id'],'state'=>'HOLD_SOURCE_SCOPE',
    'reason'=>$ctx->get_error_code(),'mutation_performed'=>false];
  $mode='WAITING_PROVIDER';$reason='no_verified_provider_adapter';
  $evidence='';
  if($ctx['workflow_type']==='content_production'){
   $a=self::artifact($ctx['job_id'],$ctx['artifact_type']);
   if($a && (string)($a['created_at']??'') >= (string)$t['created_at']){
    $mode='READY_READBACK';$reason='persisted_content_artifact';
    $evidence='artifact:'.$a['artifact_id'];
   }
  }
  // Real providers can perform native acquisition/persistence via a
  // registered server-side callback. No browser/client-supplied code.
  $adapter=self::provider_for($ctx,$t);
  if($mode!=='READY_READBACK'&&$adapter){
   $mode='READY_PROVIDER';$reason='registered_provider_probe_confirmed';
  }
  if($t['batch_state']!=='ACTIVE'||!in_array($t['state'],['WAITING_EXECUTOR','QUEUED','RETRY'],true)||
     (int)$t['attempts']!==0){
   $mode='NOT_RUNNABLE';$reason='task_not_unclaimed_active';
  }
  $view=['contract'=>self::CONTRACT,'task_id'=>$t['task_id'],
   'batch_id'=>$t['batch_id'],'task_state'=>$t['state'],
   'workflow_type'=>$ctx['workflow_type'],'source_record_id'=>$ctx['record_id'],
   'capability'=>$ctx['capability'],'provider_id'=>$adapter['id']??'',
   'state'=>$mode,'reason'=>$reason,'evidence_ref'=>$evidence,
   'executor'=>'native_mcp_client_lease','provider_called'=>false,
   'publication_performed'=>false,'mutation_performed'=>false];
  $view['plan_sha256']=hash('sha256',wp_json_encode($view,JSON_UNESCAPED_SLASHES));
  return $view;
 }
 public static function status($input=[]){
  if(!self::permitted())return self::fail('permission',403);
  global $wpdb;
  $t=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $b=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $items=$wpdb->get_results("SELECT q.task_id FROM $t q
   JOIN $b b ON b.batch_id=q.batch_id
   WHERE b.state='ACTIVE' AND q.state='WAITING_EXECUTOR'
   ORDER BY q.id ASC LIMIT 101",ARRAY_A);
  if(!is_array($items)||$wpdb->last_error)return self::fail('status_query_failed',503);
  $states=[];$types=[];$examples=[];
  foreach(array_slice($items,0,100) as $item){
   $v=self::plan(['task_id'=>$item['task_id']]);
   $state=is_wp_error($v)?'ERROR':($v['state']??'UNKNOWN');
   $type=is_array($v)?($v['workflow_type']??'unknown'):'unknown';
   $states[$state]=($states[$state]??0)+1;
   $types[$type]=($types[$type]??0)+1;
   if(count($examples)<4)$examples[]=[
    'task_id'=>$item['task_id'],'state'=>$state,'workflow_type'=>$type,
    'reason'=>is_wp_error($v)?$v->get_error_code():($v['reason']??'')];
  }
  return ['contract'=>self::CONTRACT,'states'=>$states,'workflow_types'=>$types,
   'sampled'=>min(100,count($items)),'truncated'=>count($items)>100,
   'examples'=>$examples,'provider_execution_performed'=>false,
   'queue_mutation_performed'=>false,'production_mutation'=>false];
 }
 // Readback: never accept provider-supplied verified booleans or a made-up URL.
 private static function proof($t,$ctx,$ref){
  if($ctx['workflow_type']==='content_production'){
   if(!preg_match('/^artifact:([a-f0-9-]{36})$/D',$ref,$m))
    return self::fail('artifact_ref_invalid',409);
   $r=MAD4B_SCP_Artifacts::get_artifact(['artifact_id'=>$m[1]]);
   if(is_wp_error($r))return $r;
   $a=$r['artifact']??[];
   $current=self::artifact($ctx['job_id'],$ctx['artifact_type']);
   if(!$current||!is_array($a)||
      ($a['job_id']??'')!==$ctx['job_id']||
      ($a['artifact_type']??'')!==$ctx['artifact_type']||
      ($a['status']??'')!=='active'||
      (string)($a['created_at']??'')<(string)($t['created_at']??'')||
      ($current['artifact_id']??'')!==$m[1]||
      !hash_equals((string)($a['content_sha256']??''),(string)$current['content_sha256']))
    return self::fail('research_artifact_readback_mismatch',409);
   return ['ref'=>$ref,'sha256'=>$a['content_sha256'],'provider'=>'native-artifact',
    'job_id'=>$ctx['job_id']];
  }
  if($ref!=='canonical_gap:'.$ctx['gap_id'])
   return self::fail('canonical_evidence_ref_invalid',409);
  // An unresolved gap is evidence of MISSING SOURCE, never a completed task.
  global $wpdb;
  $g=$wpdb->prefix.'mad4b_hub_canonical_gaps';
  $r=$wpdb->get_row($wpdb->prepare("SELECT id,canonical_id,company_id,gap_code,state,source_sha256
     FROM $g WHERE id=%d LIMIT 1",$ctx['gap_id']),ARRAY_A);
  if(!$r||$r['canonical_id']!=$ctx['canonical_id']||
     $r['company_id']!==$ctx['source_company']||$r['gap_code']!==$ctx['gap_code']||
     !in_array($r['state'],['resolved','verified'],true)||
     !preg_match('/^[a-f0-9]{64}$/D',(string)$r['source_sha256']))
   return self::fail('canonical_gap_unresolved_or_unverified',409);
  // A flag change in the local gap table is NOT a provider receipt.
  // Require a registered native source adapter to independently read back
  // the resolved record before completing pricing/inventory/identity work.
  $provider=self::provider_for($ctx,$t);
  if(!$provider||!is_callable($provider['adapter']['verify']??null))
   return self::fail('canonical_provider_readback_missing',409);
  $verified=call_user_func($provider['adapter']['verify'],$ctx,$t,[
    'evidence_ref'=>$ref,'source_sha256'=>$r['source_sha256'],
    'canonical_id'=>$ctx['canonical_id'],'gap_id'=>$ctx['gap_id']]);
  if($verified!==true)return self::fail('canonical_provider_readback_failed',409);
  return ['ref'=>$ref,'sha256'=>$r['source_sha256'],
    'provider'=>$ctx['capability'],'canonical_id'=>$ctx['canonical_id']];
 }
 public static function verify_stage_receipt($verified,$result,$task){
  if($verified===true)return true;
  if(!is_array($result)||($result['receipt_contract']??'')!==self::CONTRACT)
   return false;
  $p=is_array($task['payload']??null)?$task['payload']:
      json_decode((string)($task['payload_json']??''),true);
  if(!is_array($p))return false;
  $task['payload']=$p;
  $ctx=self::context($task);
  if(is_wp_error($ctx))return false;
  if(($result['workflow_type']??'')!==$ctx['workflow_type']||
     ($result['source_record_id']??'')!==$ctx['record_id'])return false;
  $proof=self::proof($task,$ctx,(string)($result['evidence_ref']??''));
  return !is_wp_error($proof)
    &&hash_equals((string)$proof['sha256'],(string)($result['evidence_sha256']??''));
 }
 private static function lease_valid($t,$input){
  $owner=(string)($input['executor_id']??'');
  $token=(string)($input['lease_token']??'');
  if(!in_array($owner,['mcp_client','chatgpt_client','provider_bridge'],true)||
     !preg_match('/^[a-f0-9]{48}$/D',$token)||
     $t['state']!=='RUNNING'||$t['lease_owner']!==$owner||
     !$t['lease_expires_at']||$t['lease_expires_at']<gmdate('Y-m-d H:i:s')||
     !hash_equals((string)$t['lease_hash'],hash('sha256',$token)))
   return self::fail('native_lease_not_verified',409);
  return true;
 }
 public static function complete($input=[]){
  if(!self::permitted(true))return self::fail('write_permission',403);
  $t=self::task((string)($input['task_id']??''));
  if(is_wp_error($t))return $t;
  $lease=self::lease_valid($t,$input);
  if(is_wp_error($lease))return $lease;
  $ctx=self::context($t);
  if(is_wp_error($ctx))return $ctx;
  $ref=(string)($input['evidence_ref']??'');
  $proof=self::proof($t,$ctx,$ref);
  if(is_wp_error($proof))return $proof;
  $result=['receipt_contract'=>self::CONTRACT,'verified'=>true,
   'stage'=>$t['stage'],'operation'=>$t['operation'],
   'evidence_ref'=>$ref,'evidence_sha256'=>$proof['sha256'],
   'workflow_type'=>$ctx['workflow_type'],'source_record_id'=>$ctx['record_id'],
   'provider_id'=>$proof['provider'],'job_id'=>$ctx['job_id']??'',
   'source_readback_verified'=>true,'publish_authorized'=>false];
  $f=MAD4B_Hub_Bulk_Jobs_Queue::finish(['task_id'=>$t['task_id'],
    'executor_id'=>$input['executor_id'],'lease_token'=>$input['lease_token'],
    'success'=>true,'result'=>$result]);
  if(is_wp_error($f))return $f;
  if(($f['state']??'')!=='COMPLETED')return self::fail('queue_finish_not_completed',409);
  // The queue result_json is the authoritative durable receipt. No parallel
  // mutable receipt state; reread it for consistency after commit.
  $after=self::task($t['task_id']);
  if(is_wp_error($after))return $after;
  $saved=json_decode((string)($after['result_json']??''),true);
  if(($after['state']??'')!=='COMPLETED'||
     !is_array($saved)||$saved['evidence_sha256']!==$proof['sha256'])
   return self::fail('queue_result_readback_failed',503);
  return ['contract'=>self::CONTRACT,'task_id'=>$t['task_id'],
    'state'=>'COMPLETED','receipt_ref'=>'queue_result:'.$t['task_id'],
    'evidence_ref'=>$ref,'source_sha256'=>$proof['sha256'],
    'provider_result_readback'=>true,'queue_receipt_persisted'=>true,
    'publication_performed'=>false];
 }
 public static function dispatch($input=[]){
  if(!self::permitted(true))return self::fail('write_permission',403);
  $t=self::task((string)($input['task_id']??''));
  if(is_wp_error($t))return $t;
  $lease=self::lease_valid($t,$input);
  if(is_wp_error($lease))return $lease;
  $ctx=self::context($t);
  if(is_wp_error($ctx))return $ctx;
  $provider=self::provider_for($ctx,$t);
  if(!$provider){
   $park=MAD4B_Hub_Bulk_Jobs_Queue::park([
    'task_id'=>$t['task_id'],'executor_id'=>$input['executor_id'],
    'lease_token'=>$input['lease_token']]);
   if(is_wp_error($park))return $park;
   return ['contract'=>self::CONTRACT,'task_id'=>$t['task_id'],
    'state'=>'WAITING_PROVIDER','queue_state'=>'WAITING_EXECUTOR',
    'provider_called'=>false,'lease_released'=>true,
    'side_effects_performed'=>false];
  }
  if(($input['provider_id']??'')!==$provider['id'])
   return self::fail('provider_selection_changed',409);
  $receipt=call_user_func($provider['adapter']['execute'],$ctx,$t,$input);
  if(is_wp_error($receipt))return $receipt;
  if(!is_array($receipt)||empty($receipt['evidence_ref']))
   return self::fail('provider_did_not_persist_evidence',409);
  // Never finish on an untrusted callback response: independent native readback.
  return self::complete(array_merge($input,['evidence_ref'=>$receipt['evidence_ref']]));
 }
 public static function reconcile_readback($input=[]){
  if(!self::permitted(true))return self::fail('write_permission',403);
  $id=(string)($input['task_id']??'');
  $plan=self::plan(['task_id'=>$id]);
  if(is_wp_error($plan))return $plan;
  if(($plan['state']??'')!=='READY_READBACK')
   return self::fail('verified_persisted_receipt_not_available',409);
  $expected=(string)($input['expected_plan_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||
      !hash_equals((string)$plan['plan_sha256'],$expected))
   return self::fail('stale_or_unapproved_readback_plan',409);
  $claim=MAD4B_Hub_Bulk_Jobs_Queue::claim([
   'task_id'=>$id,'batch_id'=>$plan['batch_id'],
   'executor_id'=>'provider_bridge','lease_seconds'=>180]);
  if(is_wp_error($claim))return $claim;
  if(($claim['claimed']??false)!==true)
   return ['contract'=>self::CONTRACT,'task_id'=>$id,
     'state'=>'WAITING_CAPACITY','reason'=>$claim['reason']??'no_claim',
     'execution_performed'=>false,'mutation_performed'=>false];
  if(($claim['task_id']??'')!==$id)
   return self::fail('claimed_task_identity_mismatch',503);
  $finished=self::complete([
    'task_id'=>$id,'executor_id'=>'provider_bridge',
    'lease_token'=>$claim['lease_token'],
    'evidence_ref'=>$plan['evidence_ref']]);
  if(is_wp_error($finished)){
   // This is readback-only; no provider call or mutation occurred before
   // finish. Relinquish the lease rather than leave a false active executor.
   $park=MAD4B_Hub_Bulk_Jobs_Queue::park([
    'task_id'=>$id,'executor_id'=>'provider_bridge',
    'lease_token'=>$claim['lease_token']]);
   return ['contract'=>self::CONTRACT,'task_id'=>$id,
    'state'=>'READBACK_NOT_COMPLETED',
    'reason'=>$finished->get_error_code(),
    'parked_without_provider_execution'=>!is_wp_error($park)];
  }
  $finished['execution_path']='staging_verified_existing_evidence';
  $finished['provider_called']=false;
  return $finished;
 }
 public static function boot(){
  add_filter('mad4b_hub_bulk_authorized_executor',
    static fn($approved,$owner)=>$approved ||
      ($owner==='provider_bridge'&&self::permitted(true)),15,2);
  add_filter('mad4b_hub_bulk_verify_stage_receipt',[__CLASS__,'verify_stage_receipt'],27,3);
  add_action('rest_api_init',static function(){
   register_rest_route('mad4b-hub/v1','/provider-bridge/plan',[
    'methods'=>'GET','callback'=>static fn($r)=>self::plan($r->get_params()),
    'permission_callback'=>static fn()=>self::permitted()]);
   register_rest_route('mad4b-hub/v1','/provider-bridge/status',[
    'methods'=>'GET','callback'=>static fn($r)=>self::status($r->get_params()),
    'permission_callback'=>static fn()=>self::permitted()]);
   foreach(['complete','dispatch','reconcile_readback'] as $action){
    register_rest_route('mad4b-hub/v1','/provider-bridge/'.$action,[
     'methods'=>'POST','callback'=>static fn($r)=>self::$action($r->get_json_params()?:[]),
     'permission_callback'=>static fn()=>self::permitted(true)]);
   }
  },58);
  add_action('wp_abilities_api_init',static function(){
   if(!function_exists('wp_register_ability'))return;
   foreach(['plan'=>false,'status'=>false,'complete'=>true,'dispatch'=>true,'reconcile_readback'=>true] as $method=>$write){
    $name='mad4b/hub-provider-bridge-'.str_replace('_','-',$method);
    if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
    wp_register_ability($name,[
     'label'=>'Hub Provider Receipt Bridge '.$method,
     'description'=>'Claim is performed by a native client. Source backed provider receipt and readback only.',
     'category'=>$write?'mad4b-write':'mad4b-read',
     'execute_callback'=>[__CLASS__,$method],
     'permission_callback'=>static fn()=>self::permitted($write),
     'input_schema'=>['type'=>'object','properties'=>[
      'task_id'=>['type'=>'string'],'executor_id'=>['type'=>'string'],
      'lease_token'=>['type'=>'string'],'provider_id'=>['type'=>'string'],
      'evidence_ref'=>['type'=>'string'],
      'expected_plan_sha256'=>['type'=>'string']],
      'required'=>['task_id'],'additionalProperties'=>false],
     'output_schema'=>['type'=>'object','additionalProperties'=>true],
     'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>[
      'public'=>false,'type'=>'tool','surface'=>$write?'write':'read']]
    ]);
   }
  },57);
 }
}
