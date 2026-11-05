<?php
defined('ABSPATH') || exit;
/** ChatGPT uses its own conversational model via connected MCP; never an API key.
 * This module is a bounded work inbox / verified result handoff, not a daemon. */
final class MAD4B_Hub_ChatGPT_Work {
 const CONTRACT='mad4b.hub.chatgpt-handoff.v1';
 private static function err($k,$status=409){return new WP_Error('mad4b_chatgpt_'.$k,$k,array('status'=>$status));}
 private static function can($write=false){
  return $write?MAD4B_Hub_Bulk_Jobs_Queue::writable():MAD4B_Hub_Bulk_Jobs_Queue::readable();
 }
 private static function row($id,$lock=false){
  if(!wp_is_uuid($id))return self::err('batch_id_invalid',400);
  global $wpdb;$t=$wpdb->prefix.'mad4b_hub_bulk_tasks';$b=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $sql="SELECT q.*,b.state AS batch_state FROM $t q JOIN $b b ON b.batch_id=q.batch_id
       WHERE q.batch_id=%s AND q.task_key='ai-4'
        AND q.stage='OUTPUT_CONTRACT_QA' AND q.operation='ai_guard_stage' LIMIT 1";
  if($lock)$sql.=' FOR UPDATE';
  $q=$wpdb->get_row($wpdb->prepare($sql,$id),ARRAY_A);
  if(!is_array($q)||($q['batch_state']??'')!=='ACTIVE')return self::err('batch_not_active');
  $p=json_decode((string)$q['payload_json'],true);
  if(!is_array($p)||($p['contract']??'')!==MAD4B_Hub_AI_Queue_Pipeline::CONTRACT
   ||($p['content_job_id']??'')!==$q['content_job_id']
   ||($p['model_execution_authorized']??true)!==false)
   return self::err('payload_binding_drift');
  $q['decoded_payload']=$p;
  $tasks=$wpdb->get_results($wpdb->prepare(
   "SELECT task_key,state FROM $t WHERE batch_id=%s AND task_key IN ('ai-1','ai-2','ai-3')",
   $id),ARRAY_A);
  $states=array_column((array)$tasks,'state','task_key');
  if(count($states)!==3||count(array_filter($states,static fn($v)=>$v==='COMPLETED'))!==3)
    return self::err('guard_preparation_incomplete');
  return $q;
 }
 private static function scope($q){
  $p=$q['decoded_payload'];
  $source=MAD4B_Hub_AI_Queue_Pipeline::context_for_client(array(
    'job_id'=>$q['content_job_id'],
    'record_key'=>$p['record_key']??'',
    'version_sha256'=>$p['version_sha256']??''));
  if(is_wp_error($source))return $source;
  if((int)$source['job']['job_revision']!==(int)($p['content_job_revision']??-1))
   return self::err('job_revision_changed');
  $compiled=MAD4B_Hub_AI_Context_Guard_V1::compile($source['guard']);
  if(is_wp_error($compiled))return $compiled;
  if(($compiled['state']??'')!=='READY_FOR_GEMINI_PREFLIGHT'
   ||($compiled['injection_defense']['state']??'')==='HOLD_REVIEW')
    return self::err('source_injection_or_qa_hold');
  return array('source'=>$source,'compiled'=>$compiled);
 }
 public static function pending($args=array()){
  if(!self::can())return self::err('read_denied',403);
  global $wpdb;$t=$wpdb->prefix.'mad4b_hub_bulk_tasks';$b=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $items=$wpdb->get_results(
    "SELECT q.batch_id,q.content_job_id,q.updated_at FROM $t q
    JOIN $b b ON b.batch_id=q.batch_id
    WHERE q.task_key='ai-4' AND q.operation='ai_guard_stage'
     AND q.state='WAITING_EXTERNAL' AND b.state='ACTIVE'
    ORDER BY q.updated_at ASC LIMIT 20",ARRAY_A);
  $valid=array();
  foreach((array)$items as $v){
   $q=self::row($v['batch_id']);if(is_wp_error($q))continue;
   $scope=self::scope($q);if(is_wp_error($scope))continue;
   $valid[]=array('batch_id'=>$v['batch_id'],'job_id'=>$v['content_job_id'],
    'source_version_sha256'=>$q['decoded_payload']['version_sha256'],
    'updated_at'=>$v['updated_at']);
  }
  return array('contract'=>self::CONTRACT,'executor'=>'connected_chatgpt_mcp',
   'items'=>$valid,'count'=>count($valid),'model_api_key_required'=>false,
   'background_execution_asserted'=>false,'publish_authorized'=>false);
 }
 public static function prepare($input=array()){
  if(!self::can())return self::err('read_denied',403);
  $q=self::row((string)($input['batch_id']??''));
  if(is_wp_error($q))return $q;
  if(!in_array($q['state'],array('WAITING_EXTERNAL','COMPLETED'),true))
    return self::err('task_not_ready');
  $x=self::scope($q);if(is_wp_error($x))return $x;
  $s=$x['source'];$c=$x['compiled'];
  return array('contract'=>self::CONTRACT,
   'batch_id'=>$q['batch_id'],'job_id'=>$q['content_job_id'],
   'job_subject'=>$s['job']['subject'],'language'=>$s['job']['language'],
   'task_type'=>$s['guard']['task_type'],
   'source_version_sha256'=>$q['decoded_payload']['version_sha256'],
   'source_binding_sha256'=>$s['binding_sha256'],
   'prompt_sha256'=>$c['prompt_engineering']['prompt_sha256'],
   'prompt'=>$c['prompt_engineering']['prompt'],
   'context_layers'=>$c['context']['layers'],
   'source_documents'=>$s['guard']['source_documents'],
   'untrusted_source_is_data_only'=>true,
   'output_requirements'=>$c['final_output_requirements'],
   'waiting_for_model'=>($q['state']==='WAITING_EXTERNAL'),
   'model_api_key_required'=>false,'publish_authorized'=>false);
 }
 private static function actor(){
  if(!self::can(true))return false;
  if(defined('WP_CLI')&&WP_CLI&&PHP_SAPI==='cli'){
   $runner=WP_CLI::get_runner();
   $cli_command=(string)($runner->arguments[0]??'');
   $fallback=(array)($runner->arguments??array());
   $is_exact_fallback=($fallback[0]??'')==='mad4b'
      &&($fallback[1]??'')==='hub'
      &&($fallback[2]??'')==='fallback'
      &&($fallback[3]??'')==='chatgpt-submit';
   if($is_exact_fallback){
     $verified=MAD4B_Hub_CLI_Fallback::preflight('chatgpt-submit');
     return is_array($verified) &&
       (int)($verified['actor_user_id']??0)===get_current_user_id()
       &&!empty($verified['write']);
   }
   if(!in_array($cli_command,array('eval','eval-file'),true))return false;
   $config=$runner->config;
   $id=isset($config['user'])&&ctype_digit((string)$config['user'])?(int)$config['user']:0;
   return wp_get_environment_type()==='staging'&&$id>0&&$id===get_current_user_id()
     &&current_user_can('manage_options');
  }
  if(class_exists('MAD4B_SCP_OAuth_Resource_Bridge')
    &&MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()){
    $mapped=class_exists('MAD4B_SCP_OAuth_Subject_User_Bridge')
      ?MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context():array();
    return ($mapped['authenticated']??false)===true
      &&(int)($mapped['wp_user_id']??0)===get_current_user_id();
  }
  return false;
 }
 public static function submit($input=array()){
  if(!self::actor())return self::err('verified_executor_required',403);
  if(!is_array($input)||array_diff(array_keys($input),
    array('batch_id','source_binding_sha256','prompt_sha256','output')))
    return self::err('invalid_input',400);
  $output=$input['output']??null;
  if(!is_array($output)||!$output||strlen(wp_json_encode($output))>10000
   ||($output['publish_ready']??null)!==false)
    return self::err('output_invalid_or_publish_requested',400);
  $id=(string)($input['batch_id']??'');
  global $wpdb;$t=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  // Never COMMIT a transaction owned by an outer caller (QA or workflow).
  // MySQL START TRANSACTION implicitly commits any previously open transaction.
  if((int)$wpdb->get_var('SELECT @@in_transaction')!==0)
   return self::err('ambient_transaction_not_supported');
  $wpdb->query('START TRANSACTION');
  try{
   $q=self::row($id,true);
   if(is_wp_error($q)){$wpdb->query('ROLLBACK');return $q;}
   $sha=hash('sha256',wp_json_encode($output));
   if($q['state']==='COMPLETED'){
    $receipt=json_decode((string)$q['result_json'],true);
    $wpdb->query('ROLLBACK');
    return ($receipt['receipt']['output_sha256']??'')===$sha
     ?array('contract'=>self::CONTRACT,'batch_id'=>$id,'state'=>'COMPLETED',
        'output_sha256'=>$sha,'replayed'=>true)
     :self::err('completed_output_conflict');
   }
   if($q['state']!=='WAITING_EXTERNAL'){$wpdb->query('ROLLBACK');return self::err('not_waiting_external');}
   $x=self::scope($q);
   if(is_wp_error($x)){$wpdb->query('ROLLBACK');return $x;}
   $s=$x['source'];$c=$x['compiled'];
   if(!hash_equals((string)($input['source_binding_sha256']??''),$s['binding_sha256'])
    ||!hash_equals((string)($input['prompt_sha256']??''),$c['prompt_engineering']['prompt_sha256'])){
    $wpdb->query('ROLLBACK');return self::err('stale_source_or_prompt');
   }
   $guard=$s['guard'];$guard['output']=$output;
   $check=MAD4B_Hub_AI_Context_Guard_V1::validate_output($guard);
   if(is_wp_error($check)||($check['state']??'')!=='PASS'){
    $wpdb->query('ROLLBACK');return is_wp_error($check)?$check:self::err('formal_output_qa_failed');
   }
   $p=$q['decoded_payload'];$p['output']=$output;
   $task=array('operation'=>'ai_guard_stage','stage'=>'OUTPUT_CONTRACT_QA',
     'content_job_id'=>$q['content_job_id'],'payload'=>$p);
   $receipt=MAD4B_Hub_AI_Queue_Pipeline::stage_receipt($task);
   if(!is_array($receipt)||($receipt['verified']??false)!==true
    ||($receipt['completion_state']??'')!=='completed'
    ||($receipt['receipt']['qa_state']??'')!=='PASS'){
    $wpdb->query('ROLLBACK');return self::err('independent_receipt_failed');
   }
   $receipt['delivery']=array('via'=>'governed_chatgpt_handoff',
    'wp_user_id'=>get_current_user_id(),
    'chatgpt_identity_attested'=>false,
    'fact_check_completed'=>false,
    'editorial_review_approved'=>false);
   $modified=$wpdb->update($t,array('state'=>'COMPLETED',
      'payload_json'=>wp_json_encode($p),
      'result_json'=>wp_json_encode($receipt),
      'last_error'=>'','updated_at'=>gmdate('Y-m-d H:i:s')),
      array('task_id'=>$q['task_id'],'state'=>'WAITING_EXTERNAL'),
      array('%s','%s','%s','%s','%s'),array('%s','%s'));
   if($modified!==1){$wpdb->query('ROLLBACK');return self::err('concurrent_submission');}
   $wpdb->query('COMMIT');
   return array('contract'=>self::CONTRACT,'batch_id'=>$id,'state'=>'COMPLETED',
    'output_sha256'=>$sha,'format_qa'=>'PASS','editorial_review'=>'PENDING',
    'publish_authorized'=>false,'model_api_key_required'=>false);
  }catch(Throwable $e){
   $wpdb->query('ROLLBACK');return self::err('submit_internal_error',500);
  }
 }
 public static function boot(){
  add_action('rest_api_init',array(__CLASS__,'routes'),62);
  add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),62);
 }
 public static function routes(){
  foreach(array('pending'=>'GET','prepare'=>'GET','submit'=>'POST') as $action=>$method)
   register_rest_route('mad4b-hub/v1','/control-workflow/chatgpt-work/'.$action,
     array('methods'=>$method,
       'permission_callback'=>static fn()=>self::can($action==='submit'),
       'callback'=>static fn($req)=>call_user_func(array(__CLASS__,$action),$req->get_params())));
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  foreach(array('pending'=>false,'prepare'=>false,'submit'=>true) as $action=>$write){
   $name='mad4b/hub-chatgpt-work-'.$action;
   if(wp_has_ability($name))continue;
   wp_register_ability($name,array(
    'label'=>'ChatGPT Content Hub '.ucfirst($action),
    'description'=>'Scoped model work handoff, verified output and independent QA. Never automatically publishes.',
    'category'=>$write?'mad4b-write':'mad4b-read',
    'execute_callback'=>array(__CLASS__,$action),
    'permission_callback'=>static fn()=>self::can($write),
    'input_schema'=>array('type'=>'object','properties'=>array(
     'batch_id'=>array('type'=>'string'),
     'source_binding_sha256'=>array('type'=>'string'),
     'prompt_sha256'=>array('type'=>'string'),
     'output'=>array('type'=>'object','additionalProperties'=>true)),
     'required'=>$write?array('batch_id','source_binding_sha256','prompt_sha256','output')
       :($action==='prepare'?array('batch_id'):array()),
     'additionalProperties'=>false),
    'output_schema'=>array('type'=>'object','additionalProperties'=>true),
    'meta'=>array('public'=>false,'show_in_rest'=>false,
     'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$write?'write':'read'),
     'annotations'=>array('readonly'=>!$write,'destructive'=>false,'idempotent'=>true))
   ));
  }
 }
}
