<?php
defined('ABSPATH') || exit;
/**
 * Four independent, receipt-verified AI governance stages bound to one
 * canonical ContentJob and one immutable research source version.
 * The VPS executes preparation and validation, NEVER AI inference.
 */
final class MAD4B_Hub_AI_Queue_Pipeline {
 const CONTRACT='mad4b.hub.ai-governance-queue.v2';
 const STAGES=array('CONTEXT_ASSEMBLY','PROMPT_ENGINEERING','PROMPT_INJECTION_DEFENSE','OUTPUT_CONTRACT_QA');
 const OPERATION='ai_guard_stage';
 const COMPANY='tourism-almallahgroup';
 const SOURCE_ID='hub-owned-homepage';
 private static function error($code,$http=409){
  return new WP_Error('mad4b_ai_queue_'.$code,$code,array('status'=>$http,'contract'=>self::CONTRACT));
 }
 public static function boot(){
  add_action('mad4b_hub_research_candidate_persisted',array(__CLASS__,'on_source_persisted'),20,1);
  add_action('rest_api_init',array(__CLASS__,'routes'),57);
  add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),57);
 }
 private static function permission($write=false){
  return class_exists('MAD4B_Hub_Bulk_Jobs_Queue') &&
    ($write?MAD4B_Hub_Bulk_Jobs_Queue::writable():MAD4B_Hub_Bulk_Jobs_Queue::readable());
 }
 public static function job_allowed($job,$stage){
  return in_array($stage,self::STAGES,true) &&
   is_array($job) && ($job['stage']??'')==='INFORMATION_GAIN' &&
   in_array($job['state']??'',array('QUEUED','RUNNING'),true) &&
   ($job['language']??'')==='en';
 }
 private static function scoped($p){
  if(!self::permission())return self::error('read_permission',403);
  if(!class_exists('MAD4B_Hub_AI_Context_Guard_V1'))return self::error('guard_missing',503);
  $id=(string)($p['job_id']??'');$key=(string)($p['record_key']??'');
  $version=(string)($p['version_sha256']??'');
  if(!wp_is_uuid($id)||!preg_match('/^[a-f0-9]{64}$/D',$key)
    ||!preg_match('/^[a-f0-9]{64}$/D',$version))return self::error('source_identity_invalid',400);
  $j=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$id));
  $r=MAD4B_Hub_Canonical_Store::read_record($key);
  if(is_wp_error($j))return $j;
  if(is_wp_error($r))return $r;
  $job=$j['job'];
  if(!self::job_allowed($job,'CONTEXT_ASSEMBLY'))
   return self::error('job_stage_not_eligible');
  if($r['job_id']!==$id ||$r['version_sha256']!==$version
    ||$r['state']!=='research_candidate'
    ||$r['source_url']!==home_url('/')
    ||($r['provenance']['provider']??'')!=='firecrawl'
    ||($r['provenance']['job_id']??'')!==$id
    ||($r['normalized']['publish_authorized']??true)!==false)
   return self::error('research_lineage_or_rights_drift');
  $scope=MAD4B_SCP_Operational_Integrity::capture();
  if(is_wp_error($scope))return $scope;
  if(($job['site_uuid']??'')!==($scope['scope']['site_uuid']??'')
    ||($job['brand_id']??'')!==($scope['scope']['brand_ref']??''))
   return self::error('company_scope_changed',403);
  $desc=mb_substr((string)($r['normalized']['description']??''),0,1000);
  if(mb_strlen(trim($desc))<60)return self::error('source_evidence_missing');
  $source_sha=hash('sha256',$desc);
  $guard=array(
    'company_id'=>self::COMPANY,'job_id'=>$id,
    'task_type'=>'research','language'=>'en',
    'goal'=>'Research from the owned central Staging homepage. Keep facts cited and limitations explicit. No sales price, booking, publication or remote distribution.',
    'source_documents'=>array(array('id'=>self::SOURCE_ID,
      'origin'=>home_url('/'),'license'=>'owned','text'=>$desc)));
  return array('job'=>$job,'record'=>$r,'guard'=>$guard,'source_sha'=>$source_sha,
    'binding_sha256'=>hash('sha256',implode('|',array(
      $id,$key,$version,$r['source_sha256'],$source_sha))));
 }
 public static function context_for_client($input){ return self::scoped($input); }
 private static function payload($input,$stage,$jobRevision){
  return array(
    'assigned_executor'=>'vps_runner','content_job_id'=>$input['job_id'],
    'content_job_revision'=>$jobRevision,
    'record_key'=>$input['record_key'],'version_sha256'=>$input['version_sha256'],
    'model_execution_authorized'=>false,'stage'=> $stage,
    'contract'=>self::CONTRACT,'source_company'=>self::COMPANY,
    'output'=>($stage==='OUTPUT_CONTRACT_QA'?($input['output']??null):null));
 }
 public static function enqueue($input=array()){
  if(!self::permission(true))return self::error('write_permission',403);
  if(!is_array($input) || array_diff(array_keys($input),
    array('job_id','record_key','version_sha256','output','client_request_key')))
    return self::error('input_fields',400);
  $scope=self::scoped($input);
  if(is_wp_error($scope))return $scope;
  $output=$input['output']??null;
  if($output!==null &&(!is_array($output)||!$output
    ||strlen(wp_json_encode($output))>10000))
    return self::error('candidate_output_invalid',400);
  $hash=$output!==null?hash('sha256',wp_json_encode($output)):'no-output';
  $key='ai.governed.'.substr(hash('sha256',implode('|',array(
    $input['job_id'],$input['record_key'],$input['version_sha256'],
    (int)$scope['job']['job_revision'],$hash))),0,60);
  global $wpdb;
  $table=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $existing=$wpdb->get_var($wpdb->prepare("SELECT batch_id FROM $table WHERE batch_key=%s",$key));
  if(is_string($existing)&&wp_is_uuid($existing))
    return array('contract'=>self::CONTRACT,'batch_id'=>$existing,
      'batch_key'=>$key,'replayed'=>true,
      'four_stages_enqueued'=>true,'model_invoked'=>false);
  $tasks=array();
  foreach(self::STAGES as $n=>$stage){
    $item=array('task_key'=>'ai-'.($n+1),'stage'=>$stage,
      'operation'=>self::OPERATION,'job_id'=>$input['job_id'],
      'priority'=>8-$n,'max_attempts'=>2,
      'depends_on'=>$n?array('ai-'.$n):array(),
      'payload'=>self::payload($input,$stage,(int)$scope['job']['job_revision']));
    $tasks[]=$item;
  }
  $res=MAD4B_Hub_Bulk_Jobs_Queue::enqueue(array(
    'batch_key'=>$key,'max_concurrency'=>1,'items'=>$tasks));
  if(is_wp_error($res))return $res;
  return array('contract'=>self::CONTRACT,
     'batch_id'=>$res['batches'][0]['batch_id'],'batch_key'=>$key,
     'replayed'=>(bool)($res['replayed']??false),
     'stages'=>self::STAGES,'four_stages_enqueued'=>true,
     'model_invoked'=>false,'publications_performed'=>false);
 }
 public static function stage_receipt($task){
  $stage=(string)($task['stage']??'');
  if(($task['operation']??'')!==self::OPERATION
    ||!in_array($stage,self::STAGES,true))return self::error('stage_not_registered');
  $p=(array)($task['payload']??array());
  if(($p['contract']??'')!==self::CONTRACT ||($p['stage']??'')!==$stage
   ||($p['content_job_id']??'')!==($task['content_job_id']??'')
   ||($p['model_execution_authorized']??true)!==false)
    return self::error('payload_binding_invalid');
  $scope=self::scoped(array(
    'job_id'=>$task['content_job_id'],'record_key'=>$p['record_key']??'',
    'version_sha256'=>$p['version_sha256']??''));
  if(is_wp_error($scope))return $scope;
  if((int)($p['content_job_revision']??0)!==(int)$scope['job']['job_revision'])
    return self::error('stale_job_revision');
  $guard=$scope['guard'];$value=array();$pending=false;$state='COMPLETED';
  if($stage==='CONTEXT_ASSEMBLY'){
    $r=MAD4B_Hub_AI_Context_Guard_V1::assemble($guard);
    if(is_wp_error($r))return $r;
    if(($r['state']??'')!=='CONTEXT_ASSEMBLED')return self::error('assembly_failed');
    $value=array('assembled_sha256'=>hash('sha256',wp_json_encode($r['context'])),
      'layer_count'=>count($r['context']['layers']??array()));
  }elseif($stage==='PROMPT_ENGINEERING'){
    $r=MAD4B_Hub_AI_Context_Guard_V1::compile($guard);
    if(is_wp_error($r))return $r;
    $pending=($r['state']??'')!=='READY_FOR_GEMINI_PREFLIGHT';
    $value=array('prompt_sha256'=>$r['prompt_engineering']['prompt_sha256']??'',
      'context_fingerprint'=>$r['fingerprint_sha256']??'',
      'injection_state'=>$r['injection_defense']['state']??'',
      'model_invoked'=>false);
  }elseif($stage==='PROMPT_INJECTION_DEFENSE'){
    $r=MAD4B_Hub_AI_Context_Guard_V1::injection_defense($guard);
    if(is_wp_error($r))return $r;
    $pending=($r['screen']['state']??'')==='HOLD_REVIEW';
    $value=array('source_screen_sha256'=>hash('sha256',wp_json_encode($r['screen'])),
      'screen_state'=>$r['screen']['state']??'',
      'heuristic_only'=>true,'model_invoked'=>false);
  }else{
    $output=$p['output']??null;
    $requirements=MAD4B_Hub_AI_Context_Guard_V1::requirements('research');
    if(is_wp_error($requirements))return $requirements;
    if($output===null){
      $pending=true;
      $value=array('output_contract'=>$requirements['contract'],
       'state'=>'AWAITING_MCP_CLIENT_OUTPUT','model_invoked'=>false);
    }else{
      $guard['output']=$output;
      $check=MAD4B_Hub_AI_Context_Guard_V1::validate_output($guard);
      if(is_wp_error($check))return $check;
      $pending=($check['state']??'')!=='PASS';
      $value=array('output_contract'=>$requirements['contract'],
       'qa_state'=>$check['state'],'issues'=>$check['issues'],
       'output_sha256'=>hash('sha256',wp_json_encode($output)),
       'format_only'=>true,'factual_verification'=>false,
       'publication_authorized'=>false);
    }
  }
  $proof=array('stage'=>$stage,'source_binding_sha256'=>$scope['binding_sha256'],
   'verification'=>$value,'pending'=>$pending);
  $hash=hash('sha256',wp_json_encode($proof));
  return array('verified'=>true,'operation'=>self::OPERATION,
    'stage'=>$stage,'job_id'=>$task['content_job_id'],
    'result_kind'=>'governed_ai_stage_preparation',
    'evidence_ref'=>'guard-stage:'.$hash,'evidence_sha256'=>$hash,
    'source_binding_sha256'=>$scope['binding_sha256'],
    'receipt'=>$value,'completion_state'=>$pending?'pending':'completed',
    'model_invoked'=>false,'external_model_required'=>$pending,
    'publish_ready'=>false,'content_published'=>false,
    'production_mutated'=>false);
 }
 public static function verify($task,$result){
  if(($result['operation']??'')!==self::OPERATION
    ||($task['operation']??'')!==self::OPERATION
    ||($result['stage']??'')!==($task['stage']??'')
    ||($result['model_invoked']??true)!==false)return false;
  $p=json_decode((string)($task['payload_json']??''),true);
  if(!is_array($p))return false;
  $expected=self::stage_receipt(array('operation'=>$task['operation'],
   'stage'=>$task['stage'],'content_job_id'=>$task['content_job_id'],
   'payload'=>$p));
  return is_array($expected)&&($expected['verified']??false)===true
    &&hash_equals((string)($expected['evidence_sha256']??''),
         (string)($result['evidence_sha256']??''))
    &&($expected['completion_state']??'')===($result['completion_state']??'');
 }
 public static function on_source_persisted($event){
  if(!is_array($event)||!isset($event['job_id'],$event['record_key'],$event['version_sha256']))
    return;
  $queued=self::enqueue(array(
    'job_id'=>(string)$event['job_id'],
    'record_key'=>(string)$event['record_key'],
    'version_sha256'=>(string)$event['version_sha256']));
  if(is_wp_error($queued))error_log('MAD4B AI four-stage enqueue denied: '.$queued->get_error_code());
 }
 public static function status($input=array()){
  if(!self::permission())return self::error('read_permission',403);
  return array('contract'=>self::CONTRACT,'stages'=>self::STAGES,
    'transport'=>'vps_runner_for_guard_tasks_mcp_client_for_external_output',
    'model_inference_available'=>false,'gemini_key_required'=>false,
    'source_bound_to_original_job'=>true,
    'output_contract'=>'mad4b.hub.formal-output.v1',
    'auto_publish'=>false,'mutation_performed'=>false);
 }
 public static function routes(){
  foreach(array(
   '/control-workflow/ai-pipeline/status'=>array('GET','status',false),
   '/control-workflow/ai-pipeline/enqueue'=>array('POST','enqueue',true)
  ) as $route=>$settings){
   register_rest_route('mad4b-hub/v1',$route,array(
    'methods'=>$settings[0],
    'permission_callback'=>static fn()=>self::permission($settings[2]),
    'callback'=>static fn($req)=>call_user_func(array(__CLASS__,$settings[1]),$req->get_params())));
  }
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  foreach(array('status'=>false,'enqueue'=>true) as $m=>$write){
   $name='mad4b/hub-ai-pipeline-'.$m;
   if(wp_has_ability($name))continue;
   wp_register_ability($name,array(
     'label'=>'MAD4B AI Governance Pipeline '.$m,
     'description'=>'Exact-ContentJob four-stage AI governance, no inference or automatic publication.',
     'category'=>$write?'mad4b-write':'mad4b-read',
     'execute_callback'=>array(__CLASS__,$m),
     'permission_callback'=>static fn()=>self::permission($write),
     'input_schema'=>array('type'=>'object','properties'=>array(
      'job_id'=>array('type'=>'string'),'record_key'=>array('type'=>'string'),
      'version_sha256'=>array('type'=>'string'),
      'output'=>array('type'=>'object','additionalProperties'=>true)),
      'required'=>$write?array('job_id','record_key','version_sha256'):array(),
      'additionalProperties'=>false),
     'output_schema'=>array('type'=>'object','additionalProperties'=>true),
     'meta'=>array('public'=>false,'show_in_rest'=>false,
       'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$write?'write':'read'),
       'annotations'=>array('readonly'=>!$write,'destructive'=>false,'idempotent'=>true))
   ));
  }
 }
}
