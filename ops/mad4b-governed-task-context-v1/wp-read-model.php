<?php
/** Local WP-CLI-only bounded evidence reader. READ-ONLY: two exact tasks. */
defined('ABSPATH') || exit;
global $wpdb, $args;
if (!isset($args) || count($args)!==2) { fwrite(STDERR,"EXACT_TWO_TASK_IDS_REQUIRED\n"); exit(2); }
if (DB_NAME!=='etg_commerce_staging'
    || wp_get_environment_type()!=='staging'
    || rtrim(home_url('/'),'/')!=='https://tours.dev.mad4b.com') {
    fwrite(STDERR,"ORIGINAL_CENTRAL_SCOPE_INVALID\n"); exit(2);
}
if (!class_exists('MAD4B_SCP_Content_Jobs')) {fwrite(STDERR,"ORIGINAL_JOBS_MISSING\n");exit(2);}
$table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
$batch=$wpdb->prefix.'mad4b_hub_bulk_batches';
$records=[];
foreach($args as $task_id) {
 if (!is_string($task_id) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$task_id)){
   fwrite(STDERR,"TASK_ID_INVALID\n");exit(2);
 }
 $q=$wpdb->prepare("SELECT q.*, b.state AS batch_state FROM $table q
                   INNER JOIN $batch b ON b.batch_id=q.batch_id WHERE q.task_id=%s LIMIT 1",$task_id);
 $r=$wpdb->get_row($q,ARRAY_A);
 if (!$r || empty($r['content_job_id'])) {fwrite(STDERR,"BOUND_TASK_MISSING\n");exit(2);}
 $original=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$r['content_job_id']]);
 if (is_wp_error($original) || !isset($original['job']) || !is_array($original['job'])){
  fwrite(STDERR,"ORIGINAL_CONTENT_JOB_SCOPE_INVALID\n");exit(2);
 }
 $j=$original['job'];
 $payload=json_decode((string)$r['payload_json'],true);
 if (!is_array($payload)) {fwrite(STDERR,"PAYLOAD_INVALID\n");exit(2);}
 $origin=$payload['workflow_origin']??[];
 $origin_valid=is_array($origin) && ($origin['contract']??'')==='mad4b.hub.workflow-origin.v1';
 if($origin_valid){
  $proof=(string)($origin['origin_sha256']??'');
  unset($origin['origin_sha256']);
  $origin_valid=preg_match('/^[a-f0-9]{64}$/D',$proof)===1
    && hash_equals($proof,hash('sha256',wp_json_encode($origin,JSON_UNESCAPED_SLASHES)));
 }
 $raw_deps=json_decode((string)$r['dependencies_json'],true);
 if(!is_array($raw_deps)) {fwrite(STDERR,"DEPENDENCIES_INVALID\n");exit(2);}
 $dependency_data=[];$all=true;
 foreach($raw_deps as $name){
  $qdep=$wpdb->get_row($wpdb->prepare("SELECT state,result_json FROM $table
                    WHERE batch_id=%s AND task_key=%s LIMIT 1",$r['batch_id'],$name),ARRAY_A);
  if(!$qdep || ($qdep['state']??'')!=='COMPLETED'){$all=false;continue;}
  $receipt=json_decode((string)$qdep['result_json'],true);
  if(!is_array($receipt) || ($receipt['verified']??false)!==true){$all=false;continue;}
  $dependency_data[$name]=hash('sha256',(string)$qdep['result_json']);
 }
 ksort($dependency_data);
 $semantic_input=array_intersect_key($payload,array_flip([
  'requested_step','resolution_task','stage_input','stage_ability',
  'stage_adapter_id','preferred_ability','capability_query','provider'
 ]));
 ksort($semantic_input);
 $records[]=[
  'job'=>['job_id'=>$j['job_id'],'site_uuid'=>$j['site_uuid'],
    'tenant_id'=>$j['tenant_id'],'brand_id'=>$j['brand_id'],
    'language'=>$j['language'],'market'=>$j['country'],
    'content_type'=>$j['content_type'],'revision'=>(int)$j['job_revision'],
    'stage'=>$j['stage'],'state'=>$j['state']],
  'task'=>['task_id'=>$r['task_id'],'stage'=>$r['stage'],
    'operation'=>$r['operation'],'state'=>$r['state'],
    'batch_state'=>$r['batch_state'],'target_ref'=>$r['target_ref'],
    'bound_revision'=>isset($payload['content_job_revision'])?(int)$payload['content_job_revision']:null,
    'attempts'=>(int)$r['attempts']],
  'input'=>['semantic_sha256'=>hash('sha256',wp_json_encode($semantic_input,JSON_UNESCAPED_SLASHES)),
    'source_snapshot_sha256'=>null, 'capability_sha256'=>null,
    'policy_snapshot_sha256'=>null, 'output_contract'=>null,
    'effect_type'=>'unknown'],
  'provenance'=>['authoritative_scope_verified'=>true,
    'origin_verified'=>false,'source_identity_verified'=>$origin_valid,
    'source_version_sha256'=>null,'source_reference_sha256'=>null,
    'trigger_source'=>$payload['trigger_source']??''],
  'dependencies'=>['all_predecessors_completed'=>$all,
    'receipts_sha256'=>$all?hash('sha256',wp_json_encode($dependency_data,JSON_UNESCAPED_SLASHES)):null,
    'expected_artifact_contract'=>null]
 ];
}
echo wp_json_encode($records,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
