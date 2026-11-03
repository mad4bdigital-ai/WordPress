<?php
defined('ABSPATH') || exit;
/**
 * One authoritative provenance classification at central queue ingress.
 * Product gaps are owned by the canonical product registry; editorial
 * jobs are owned by Content Jobs. Execution stays in shared infrastructure.
 */
final class MAD4B_Hub_Workflow_Origin {
 const CONTRACT='mad4b.hub.workflow-origin.v1';
 const TYPES=['product_data','content_production','qa_diagnostic','integration_operation'];
 // Ephemeral dispatch scope: set only after the native Trigger Bus verified
 // the Content Job/event, and cleared immediately after synchronous intake.
 private static $dispatch_scope=null;
 public static function begin_verified_dispatch($type,$event_id,$job_id){
  $prior=self::$dispatch_scope;
  self::$dispatch_scope=[
   'type'=>(string)$type,'event_id'=>(string)$event_id,'job_id'=>(string)$job_id
  ];
  return $prior;
 }
 public static function end_verified_dispatch($prior=null){
  self::$dispatch_scope=$prior;
 }
 private static function fail($c,$status=409){
  return new WP_Error('mad4b_workflow_origin_'.$c,$c,['status'=>$status]);
 }
 private static function central(){
  return wp_get_environment_type()==='staging' &&
   strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
 }
 private static function table($suffix){global $wpdb;return $wpdb->prefix.'mad4b_hub_'.$suffix;}
 private static function id($x){return is_string($x)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$x);}
 private static function event($payload,$key,$job_id=''){
  $src=(string)($payload['trigger_source']??'');
  if($src!==''&&!in_array($src,['manual','event','system','schedule'],true))
   return self::fail('trigger_source_invalid',422);
  $detail=(string)($payload['trigger_event_type']??'');
  if($detail!==''&&!in_array($detail,[
    'job_created','job_transition','artifact_created','executor_optin',
    'stage_event','manual','direct_intake','system_schedule','unknown_event'
  ],true))return self::fail('trigger_event_type_invalid',422);
  if($src==='manual' && $detail!=='' && $detail!=='manual')
   return self::fail('manual_trigger_type_conflict',422);
  if($src==='event' && $detail==='manual')
   return self::fail('native_event_type_conflict',422);
  if($src==='event' && !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['trigger_event_id']??'')))
   return self::fail('native_event_receipt_id_required',422);
  if($detail==='')$detail=($src==='manual'?'manual':
   ($src==='event'?'unknown_event':
    ($src==='schedule'?'system_schedule':'direct_intake')));
  // Store exact event ID if present, without claiming independent verification.
  $eid=(string)($payload['trigger_event_id']??'');
  if($eid!==''&&!preg_match('/^[a-f0-9]{64}$/D',$eid))
   return self::fail('trigger_event_id_invalid',422);
  $scope=self::$dispatch_scope;
  $verified=is_array($scope)
   &&hash_equals((string)$scope['type'],$detail)
   &&hash_equals((string)$scope['event_id'],$eid)
   &&hash_equals((string)$scope['job_id'],(string)$job_id);
  // Direct REST/MCP intake may not impersonate a native WordPress event.
  if($src==='event'&&!$verified)
   return self::fail('native_event_dispatch_receipt_required',403);
  $evidence=$verified?'native_bus_verified_dispatch':
    ($src==='manual'?'manual_declared_with_verified_job':
     ($src==='event'?'unverified_event':'source_record_validated_at_enqueue'));
  return ['type'=>$detail,'source'=>$src?:'direct_intake',
    'event_id'=>$eid,'evidence_status'=>$evidence];
 }
 private static function canonical_gap($item){
  global $wpdb;
  $payload=$item['payload'];
  $canonical=(string)($payload['canonical_id']??'');
  $gap=(string)($payload['gap_task_id']??'');
  if(!preg_match('/^[1-9][0-9]{0,17}$/D',$canonical) ||
     !preg_match('/^[1-9][0-9]{0,17}$/D',$gap) ||
     $item['target_ref']!=='canonical:'.$canonical)
   return self::fail('canonical_gap_reference_invalid',422);
  $g=self::table('canonical_gaps');$e=self::table('canonical_entities');
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT g.id,g.canonical_id,g.company_id,g.source_hub_id,g.source_type,
     g.gap_code,g.provider_hint,g.state AS gap_state,
     e.company_id AS entity_company,e.source_type AS entity_type,
     e.source_hub_id AS entity_hub_id,e.language,e.snapshot_json
     FROM $g g INNER JOIN $e e ON e.id=g.canonical_id
     WHERE g.id=%d AND e.id=%d LIMIT 1",(int)$gap,(int)$canonical),ARRAY_A);
  if(!$row || (string)$row['company_id']!==(string)$row['entity_company'] ||
    $row['source_type']!==$row['entity_type'] ||
    (int)$row['source_hub_id']!==(int)$row['entity_hub_id'] ||
    $row['gap_code']!==(string)($payload['gap_code']??'') ||
    $row['company_id']!==(string)($payload['source_company']??''))
   return self::fail('canonical_gap_source_mismatch',409);
  if($row['gap_state']!=='needs_evidence')
   return self::fail('canonical_gap_not_waiting_evidence',409);
  if(($payload['requires_specific_ability_and_result_readback']??null)!==true ||
     ($payload['no_automatic_publish']??null)!==true)
   return self::fail('canonical_gap_governance_incomplete',409);
  $snapshot=json_decode($row['snapshot_json'],true);
  $provider=is_array($snapshot)?(string)($snapshot['provider']??''):'';
  $dataset=is_array($snapshot)?(string)($snapshot['dataset']??''):'';
  if($provider===''||$dataset==='')return self::fail('canonical_provider_missing',409);
  return [
   'workflow_type'=>'product_data','origin_type'=>'canonical_gap',
   'source_record_id'=>'canonical_gap:'.$gap,
   'source_company'=>$row['company_id'],'company_id'=>$row['company_id'],
   'company_scope'=>'verified_canonical_source_not_distribution_target',
   'source_site_uuid'=>'','source_type'=>$row['source_type'],
   'source_hub_id'=>(int)$row['source_hub_id'],
   'source_language'=>$row['language'],'source_provider'=>$provider,
   'source_dataset'=>$dataset,'gap_code'=>$row['gap_code'],
   'required_capability'=>(string)($row['provider_hint']?:$item['operation']),
   'executor_lane'=>'product_gap_resolution',
   'source_verified'=>true,'publish_authorized'=>false
  ];
 }
 private static function job($item){
  $id=$item['job_id'];
  if(!class_exists('MAD4B_SCP_Content_Jobs'))return self::fail('job_provider_unavailable',503);
  $r=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
  if(is_wp_error($r))return $r;
  $job=$r['job']??null;
  if(!is_array($job)||($job['job_id']??'')!==$id)
   return self::fail('job_binding_mismatch',403);
  $payload=$item['payload'];
  if(isset($payload['content_job_id']) && $payload['content_job_id']!==$id)
   return self::fail('job_payload_scope_mismatch',409);
  if(!self::id((string)($job['site_uuid']??'')))
   return self::fail('job_site_identity_missing',409);
  return [
   'workflow_type'=>'content_production',
   'origin_type'=>'content_job','source_record_id'=>$id,
   'source_company'=>(string)($job['tenant_id']??''),
   'company_id'=>(string)($job['tenant_id']??''),
   'company_scope'=>'verified_job_tenant_not_destination_company',
   'source_site_uuid'=>$job['site_uuid'],
   'source_type'=>(string)($job['content_type']??''),
   'source_hub_id'=>null,'source_language'=>(string)($job['language']??''),
   'source_provider'=>(string)($payload['provider']??''),
   'source_dataset'=>'','job_revision'=>(int)($job['job_revision']??0),
   'required_capability'=>(string)($payload['stage_ability']??$payload['preferred_ability']??$item['operation']),
   'executor_lane'=>'content_job_stage',
   'source_verified'=>true,'publish_authorized'=>false
  ];
 }
 private static function diagnostic($item,$key){
  // Diagnostics cannot be attributed to commercial companies from a prefix.
  if(!preg_match('/^(?:qa[._-]|rt[-.]|vps[._-]|delegate[._-])/', $key))
   return self::fail('integration_origin_required',422);
  return [
   'workflow_type'=>'qa_diagnostic','origin_type'=>'diagnostic_request',
   'source_record_id'=>$item['target_ref']?:$item['task_key'],
   'source_company'=>'','company_id'=>'',
   'company_scope'=>'platform_diagnostic_not_company_owned',
   'source_site_uuid'=>'','source_type'=>'diagnostic',
   'source_hub_id'=>null,'source_language'=>'','source_provider'=>'',
   'source_dataset'=>'','required_capability'=>(string)($item['payload']['stage_ability']??$item['operation']),
   'executor_lane'=>'diagnostic_adapter',
   'source_verified'=>false,'publish_authorized'=>false
  ];
 }
 private static function integration($item){
  $source=$item['payload']['workflow_source']??null;
  if(!is_array($source)||($source['contract']??'')!=='mad4b.workflow.integration-source.v1')
   return self::fail('integration_source_contract_required',422);
  $provider=(string)($source['provider_id']??'');
  $record=(string)($source['source_record_id']??'');
  if(!preg_match('/^[a-z][a-z0-9_-]{2,69}$/D',$provider) ||
     $record===''||strlen($record)>190 ||
     $record!==$item['target_ref'])
   return self::fail('integration_source_invalid',422);
  if(($item['payload']['assigned_executor']??'mcp_client')!=='mcp_client')
   return self::fail('integration_mcp_receipt_required',403);
  return [
   'workflow_type'=>'integration_operation','origin_type'=>'provider_handoff',
   'source_record_id'=>$record,'source_company'=>'','company_id'=>'',
   'company_scope'=>'not_attributed_until_provider_readback',
   'source_site_uuid'=>'','source_type'=>'integration',
   'source_hub_id'=>null,'source_language'=>'',
   'source_provider'=>$provider,'source_dataset'=>'',
   'required_capability'=>(string)($item['payload']['stage_ability']??$item['operation']),
   'executor_lane'=>'provider_client_receipt',
   'source_verified'=>false,'publish_authorized'=>false
  ];
 }
 public static function derive($batch_key,$item){
  if(!self::central())return self::fail('central_staging_only',403);
  if(!is_array($item)||!is_array($item['payload']??null))
   return self::fail('task_invalid',422);
  $key=(string)$batch_key;
  if($item['job_id']!==''){
   if(str_starts_with($key,'gaps.'))return self::fail('mixed_gap_job_route',409);
   $core=self::job($item);
  }elseif(str_starts_with($key,'gaps.')){
   $core=self::canonical_gap($item);
  }else{
   // QA is never a company workflow. Other target-only operations require
   // a declared provider handoff and remain fenced awaiting verified receipt.
   $core=preg_match('/^(?:qa[._-]|rt[-.]|vps[._-]|delegate[._-])/', $key)
     ?self::diagnostic($item,$key):self::integration($item);
  }
  if(is_wp_error($core))return $core;
  $ev=self::event($item['payload'],$key,$item['job_id']);
  if(is_wp_error($ev))return $ev;
  if($core['workflow_type']==='product_data'&&$ev['source']==='direct_intake'){
   // This means a previously recorded Canonical Gap was admitted to the
   // queue. It does not claim a new discovery event occurred now.
   $ev['type']='canonical_gap_record_intake';
   $ev['evidence_status']='canonical_gap_record_verified_at_enqueue';
  }
  $origin=[
   'contract'=>self::CONTRACT,
   'workflow_type'=>$core['workflow_type'],
   'origin_type'=>$core['origin_type'],
   'source_record_id'=>$core['source_record_id'],
   'company_id'=>$core['company_id'],
   'source_company'=>$core['source_company'],
   'company_scope'=>$core['company_scope'],
   'source_site_uuid'=>$core['source_site_uuid'],
   'source_type'=>$core['source_type'],
   'source_hub_id'=>$core['source_hub_id'],
   'source_language'=>$core['source_language'],
   'source_provider'=>$core['source_provider'],
   'source_dataset'=>$core['source_dataset'],
   'trigger_event_type'=>$ev['type'],
   'trigger_source'=>$ev['source'],
   'trigger_event_id'=>$ev['event_id'],
   'trigger_evidence_status'=>$ev['evidence_status'],
   'required_capability'=>$core['required_capability'],
   'executor_lane'=>$core['executor_lane'],
   'job_revision'=>$core['job_revision']??null,
   'gap_code'=>$core['gap_code']??'',
   'source_verified'=>$core['source_verified'],
   'publish_authorized'=>false,
   'routing_performed'=>true
  ];
  // Original task input cannot impersonate a derived origin.
  if(isset($item['payload']['workflow_origin']) ||
     isset($item['payload']['workflow_origin_sha256']))
   return self::fail('caller_supplied_provenance_forbidden',422);
  $origin['origin_sha256']=hash('sha256',wp_json_encode($origin,JSON_UNESCAPED_SLASHES));
  return $origin;
 }
 public static function normalize_batch($batch){
  if(!is_array($batch)||!is_array($batch['items']??null))
   return self::fail('batch_invalid',400);
  $batch_type=null;
  foreach($batch['items'] as &$item){
   $origin=self::derive((string)$batch['batch_key'],$item);
   if(!is_wp_error($origin)){
    if($batch_type!==null && $batch_type!==$origin['workflow_type'])
     return self::fail('mixed_workflow_types_in_batch',409);
    $batch_type=$origin['workflow_type'];
   }
   if(is_wp_error($origin))return $origin;
   $item['payload']['workflow_origin']=$origin;
   if(strlen(wp_json_encode($item['payload']))>16000)
    return self::fail('payload_provenance_over_limit',422);
  }unset($item);
  return $batch;
 }
 /** Pure source-lane policy for planner presentation. Adapter readiness and
  * permissions remain enforced by Executor Selection separately.
  */
 public static function selection_lane($payload,$executor){
  if(!is_array($payload))return ['allowed'=>false,'reason'=>'payload_invalid'];
  $origin=$payload['workflow_origin']??null;
  if($origin===null)return ['allowed'=>true,'reason'=>'legacy_without_origin'];
  if(!is_array($origin)||($origin['contract']??'')!==self::CONTRACT)
   return ['allowed'=>false,'reason'=>'origin_contract_invalid'];
  $digest=(string)($origin['origin_sha256']??'');
  unset($origin['origin_sha256']);
  if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||
     !hash_equals($digest,hash('sha256',wp_json_encode($origin,JSON_UNESCAPED_SLASHES))))
   return ['allowed'=>false,'reason'=>'origin_digest_invalid'];
  $kind=(string)($origin['workflow_type']??'');
  if(!in_array($kind,self::TYPES,true))
   return ['allowed'=>false,'reason'=>'workflow_type_invalid'];
  if(in_array($kind,['product_data','integration_operation'],true)&&$executor!=='mcp_client')
   return ['allowed'=>false,'reason'=>$kind==='product_data'
     ?'product_gap_requires_verified_provider_receipt'
     :'integration_requires_verified_provider_receipt'];
  return ['allowed'=>true,'reason'=>'workflow_lane_allowed'];
 }
 /** Executor contract validated at enqueue, reassignment, and claim.
  * A legacy task has no authenticated origin: grandfather its existing routing,
  * but never label it verified or grant it new permissions.
  */
 public static function executor_guard($task,$executor){
  if(!is_array($task)||!is_string($executor)||
     !in_array($executor,['mcp_client','vps_runner','action_scheduler'],true))
   return self::fail('executor_guard_input_invalid',422);
  $payload=$task['payload']??null;
  if(!is_array($payload))$payload=json_decode((string)($task['payload_json']??''),true);
  if(!is_array($payload))return self::fail('executor_payload_invalid',409);
  $o=$payload['workflow_origin']??null;
  if($o===null)return true; // explicitly legacy only
  if(!is_array($o)||($o['contract']??'')!==self::CONTRACT)
   return self::fail('origin_contract_invalid',409);
  $digest=(string)($o['origin_sha256']??'');
  unset($o['origin_sha256']);
  if(!preg_match('/^[a-f0-9]{64}$/D',$digest)
     ||!hash_equals($digest,hash('sha256',wp_json_encode($o,JSON_UNESCAPED_SLASHES))))
   return self::fail('origin_digest_mismatch',409);
  $type=(string)($o['workflow_type']??'');
  $job=(string)($task['job_id']??$task['content_job_id']??'');
  if(!in_array($type,self::TYPES,true))
   return self::fail('workflow_type_invalid',409);
  if($type==='content_production'&&($job===''||$job!==($o['source_record_id']??'')))
   return self::fail('content_job_binding_mismatch',409);
  if($type!=='content_production'&&$job!=='')
   return self::fail('noncontent_job_binding_forbidden',409);
  if($type==='product_data'){
   if(!str_starts_with((string)($task['target_ref']??''),'canonical:') ||
      !str_starts_with((string)($o['source_record_id']??''),'canonical_gap:'))
    return self::fail('product_gap_binding_mismatch',409);
   // Resolving product pricing/identity/inventory gaps requires stage-specific
   // provider readback. A generic VPS/AS executor is not sufficient.
   if($executor!=='mcp_client')return self::fail('product_gap_verified_adapter_required',403);
  }
  if($type==='integration_operation'&&$executor!=='mcp_client')
   return self::fail('integration_native_provider_receipt_required',403);
  if($executor!=='mcp_client'){
   if(!class_exists('MAD4B_Hub_Executor_Selection'))
    return self::fail('executor_catalog_unavailable',503);
   $plan=MAD4B_Hub_Executor_Selection::plan([
    'task'=>['stage'=>(string)($task['stage']??''),
             'operation'=>(string)($task['operation']??''),
             'payload'=>$payload,
             'target_ref'=>(string)($task['target_ref']??''),
             'content_job_id'=>$job],
    'preferences'=>[$executor]
   ]);
   if(is_wp_error($plan) ||($plan['selected_executor']??'')!==$executor)
    return self::fail('local_executor_capability_not_confirmed',403);
  }
  return true;
 }
 /** Recheck current canonical record at the point of Claim. An approved
  * intake snapshot does not authorize stale gap work after resolution.
  */
 public static function claim_source_guard($task){
  $payload=$task['payload']??null;
  if(!is_array($payload))$payload=json_decode((string)($task['payload_json']??''),true);
  if(!is_array($payload))return self::fail('claim_payload_invalid',409);
  $o=$payload['workflow_origin']??null;
  if(!is_array($o)||($o['contract']??'')!==self::CONTRACT)return true; // legacy
  if(($o['workflow_type']??'')!=='product_data')return true;
  $gap=(string)($o['source_record_id']??'');
  $ref=(string)($task['target_ref']??'');
  if(!preg_match('/^canonical_gap:([1-9][0-9]{0,17})$/D',$gap,$m)
     ||!preg_match('/^canonical:([1-9][0-9]{0,17})$/D',$ref,$n))
   return self::fail('claim_canonical_scope_invalid',409);
  global $wpdb;
  $g=self::table('canonical_gaps');$e=self::table('canonical_entities');
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT g.state,g.gap_code,g.company_id,g.source_hub_id,
      e.company_id AS entity_company,e.source_hub_id AS entity_hub
     FROM $g g JOIN $e e ON e.id=g.canonical_id
     WHERE g.id=%d AND e.id=%d LIMIT 1",(int)$m[1],(int)$n[1]),ARRAY_A);
  if(!is_array($row)||$row['state']!=='needs_evidence'
    ||$row['company_id']!==($o['source_company']??'')
    ||$row['entity_company']!==($o['source_company']??'')
    ||$row['gap_code']!==($o['gap_code']??'')
    ||(int)$row['entity_hub']!==(int)$row['source_hub_id']
    ||(int)$row['source_hub_id']!==(int)($o['source_hub_id']??-1))
   return self::fail('claim_canonical_gap_stale_or_mismatched',409);
  return true;
 }
 /** Canonical origin readback without reconstructing missing legacy data. */
 public static function audit(){
  if(!class_exists('MAD4B_Hub_Bulk_Jobs_Queue') ||
     !MAD4B_Hub_Bulk_Jobs_Queue::readable())return self::fail('permission',403);
  global $wpdb;
  $t=self::table('bulk_tasks');
  $rows=$wpdb->get_results("SELECT state,payload_json FROM $t
    WHERE state IN ('QUEUED','WAITING_EXECUTOR','WAITING_EXTERNAL',
     'NEEDS_RECONCILIATION','DEAD_LETTER','RUNNING','RETRY')
    ORDER BY id DESC LIMIT 1001",ARRAY_A);
  if(!is_array($rows)||$wpdb->last_error)return self::fail('audit_read_failed',503);
  $by=[];$legacy=0;
  foreach(array_slice($rows,0,1000) as $r){
   $p=json_decode((string)$r['payload_json'],true);
   $o=is_array($p)?($p['workflow_origin']??null):null;
   if(!is_array($o)||($o['contract']??'')!==self::CONTRACT){$legacy++;continue;}
   $digest=(string)($o['origin_sha256']??'');
   unset($o['origin_sha256']);
   if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||
      !hash_equals($digest,hash('sha256',wp_json_encode($o,JSON_UNESCAPED_SLASHES)))){
    $by['INVALID_ORIGIN_DIGEST']=($by['INVALID_ORIGIN_DIGEST']??0)+1;continue;
   }
   $key=(string)$o['workflow_type'];
   $by[$key]=($by[$key]??0)+1;
  }
  return ['contract'=>self::CONTRACT,'by_workflow_type'=>$by,
   'legacy_without_authoritative_origin'=>$legacy,
   'bounded_count'=>min(1000,count($rows)),
   'truncated'=>count($rows)>1000,
   'read_only'=>true,'production_mutation'=>false];
 }
 public static function boot(){
  add_action('wp_abilities_api_init',static function(){
   if(!function_exists('wp_register_ability'))return;
   $name='mad4b/hub-workflow-origin-audit';
   if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
   wp_register_ability($name,[
    'label'=>'Workflow Origin Coverage Audit',
    'description'=>'Read-only source-backed workflow origin classification and legacy coverage.',
    'category'=>'mad4b-read',
    'execute_callback'=>static fn($args)=>self::audit(),
    'permission_callback'=>static fn()=>class_exists('MAD4B_Hub_Bulk_Jobs_Queue') &&
      MAD4B_Hub_Bulk_Jobs_Queue::readable(),
    'input_schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>[
      'public'=>false,'type'=>'tool','surface'=>'read']]
   ]);
  },58);
  add_action('rest_api_init',static function(){
   register_rest_route('mad4b-hub/v1','/workflow-origin/audit',[
    'methods'=>'GET','callback'=>[__CLASS__,'audit'],
    'permission_callback'=>static fn()=>class_exists('MAD4B_Hub_Bulk_Jobs_Queue')
       && MAD4B_Hub_Bulk_Jobs_Queue::readable()
   ]);
  },52);
 }
}
