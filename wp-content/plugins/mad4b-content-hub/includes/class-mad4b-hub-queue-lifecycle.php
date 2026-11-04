<?php
defined('ABSPATH') || exit;
/** Central Staging queue coverage, safe terminal-batch reconciliation.
 * Does not execute jobs, change tool permissions or infer executor availability.
 */
final class MAD4B_Hub_Queue_Lifecycle {
 const CONTRACT='mad4b.hub.queue-lifecycle.v1';
 const TERMINAL=['COMPLETED','CANCELLED','DEAD_LETTER'];
 const WAITING=['QUEUED','RETRY','RUNNING','CANCEL_REQUESTED','WAITING_JOB','WAITING_EXECUTOR','WAITING_EXTERNAL','NEEDS_RECONCILIATION'];
 static function allowed($write=false){
  return wp_get_environment_type()==='staging'
   && strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com'
   && class_exists('MAD4B_Hub_Bulk_Jobs_Queue')
   && ($write?MAD4B_Hub_Bulk_Jobs_Queue::writable():MAD4B_Hub_Bulk_Jobs_Queue::readable());
 }
 static function err($why,$status=409){
  return new WP_Error('mad4b_queue_lifecycle_'.$why,$why,['status'=>$status]);
 }
 static function tables(){
  global $wpdb;return [$wpdb->prefix.'mad4b_hub_bulk_batches',$wpdb->prefix.'mad4b_hub_bulk_tasks'];
 }
 static function classify($task){
  $executor=(string)($task['assigned_executor']??'mcp_client');
  $operation=(string)($task['operation']??'');
  $stage=(string)($task['stage']??'');
  $manual=['verify_source_inventory','review_canonical_identity',
   'verify_property_identity','resolve_source_pricing','review_rate_date_range'];
  if($executor==='mcp_client'){
   // A name in payload is NOT evidence of a live connected client.
   return ['mode'=>in_array($operation,$manual,true)?'human_or_client_review':'external_client_handoff',
     'confirmed_live_executor'=>false,'automatic_fallback_allowed'=>false];
  }
  if(!class_exists('MAD4B_Hub_Executor_Selection'))
   return ['mode'=>'not_registered','confirmed_live_executor'=>false,'automatic_fallback_allowed'=>false];
  $result=MAD4B_Hub_Executor_Selection::plan(['task'=>[
   'stage'=>$stage,'operation'=>$operation,
   'payload'=>(array)($task['payload']??[]),
   'target_ref'=>(string)($task['target_ref']??''),
   'content_job_id'=>(string)($task['content_job_id']??'')
  ],'preferences'=>[$executor]]);
  if(is_wp_error($result))return ['mode'=>'unsupported','confirmed_live_executor'=>false,'automatic_fallback_allowed'=>false];
  $okay=(string)($result['selected_executor']??'')===$executor;
  return ['mode'=>$okay?'registered_but_capacity_unconfirmed':'no_eligible_adapter',
   'confirmed_live_executor'=>false,'registered_capability'=>$okay,
   'automatic_fallback_allowed'=>false];
 }
 // Choose only a registered and policy-allowed local executor.
 // Explicit executor choices are never overridden.
 static function prepare_enqueue($item){
  if(!is_array($item)||!isset($item['payload'])||!is_array($item['payload']))
   return self::err('invalid_task_item',400);
  if(!array_key_exists('assigned_executor',$item['payload'])){
   $t=['stage'=>$item['stage'],'operation'=>$item['operation'],
     'payload'=>$item['payload'],
     'target_ref'=>$item['target_ref'],'content_job_id'=>$item['job_id']];
   if(class_exists('MAD4B_Hub_Executor_Selection')){
    $plan=MAD4B_Hub_Executor_Selection::plan(['task'=>$t,
      'preferences'=>['action_scheduler','vps_runner']]);
    if(!is_wp_error($plan) &&
       in_array((string)($plan['selected_executor']??''),['action_scheduler','vps_runner'],true)){
      $item['payload']['assigned_executor']=$plan['selected_executor'];
      $item['payload']['executor_selection']=[
       'method'=>'verified_auto_capability','selection_reason'=>'registered_stage_or_operation',
       'selected_at'=>gmdate('c')];
    }
   }
   // No ability is not permission to fabricate an MCP client session.
   if(!isset($item['payload']['assigned_executor']))
    $item['payload']['assigned_executor']='mcp_client';
  }
  if(strlen(wp_json_encode($item['payload']))>16000)return self::err('resolved_payload_too_large',400);
  $item['initial_state']=self::initial_state($item);
  return $item;
 }
 static function initial_state($item){
  $executor=(string)($item['payload']['assigned_executor']??'mcp_client');
  // The client must explicitly claim work before it is treated as available.
  if($executor==='mcp_client')return 'WAITING_EXECUTOR';
  $c=self::classify([
   'assigned_executor'=>$executor,'stage'=>$item['stage'],
   'operation'=>$item['operation'],'payload'=>$item['payload'],
   'target_ref'=>$item['target_ref'],'content_job_id'=>$item['job_id']
  ]);
  return empty($c['registered_capability'])?'WAITING_EXECUTOR':'QUEUED';
 }
 static function audit(){
  if(!self::allowed())return self::err('permission',403);
  global $wpdb;[$b,$t]=self::tables();
  $rows=$wpdb->get_results("SELECT q.stage,q.operation,q.state,q.payload_json,b.state AS batch_state,
    q.target_ref,q.content_job_id FROM $t q JOIN $b b ON q.batch_id=b.batch_id
    WHERE q.state IN ('QUEUED','WAITING_EXECUTOR','WAITING_EXTERNAL','NEEDS_RECONCILIATION','RETRY')
      AND b.state IN ('ACTIVE','PAUSED') ORDER BY q.id ASC LIMIT 500",ARRAY_A);
  $by=[];$modes=[];
  foreach((array)$rows as $r){
   $payload=json_decode((string)$r['payload_json'],true);
   $payload=is_array($payload)?$payload:[];
   $c=self::classify(['assigned_executor'=>$payload['assigned_executor']??'mcp_client',
    'stage'=>$r['stage'],'operation'=>$r['operation'],'payload'=>$payload,
    'target_ref'=>$r['target_ref'],'content_job_id'=>$r['content_job_id']]);
   $key=$r['stage'].'/'.$r['operation'];
   if(!isset($by[$key]))$by[$key]=['count'=>0,'mode'=>$c['mode'],'executor'=>$payload['assigned_executor']??'mcp_client'];
   $by[$key]['count']++;
   $modes[$c['mode']]=($modes[$c['mode']]??0)+1;
  }
  return ['contract'=>self::CONTRACT,'sampled_count'=>count($rows),
   'mode_counts'=>$modes,'operations'=>$by,'mutation_performed'=>false,
   'client_presence_proven'=>false,'production_mutation'=>false];
 }
 static function close_batch($batch_id,$apply=false){
  if(!self::allowed($apply))return self::err('permission',403);
  if(!is_string($batch_id)||!preg_match('/^[a-f0-9-]{36}$/D',$batch_id))
   return self::err('invalid_batch_id',400);
  global $wpdb;[$b,$t]=self::tables();
  if($apply && false===$wpdb->query('START TRANSACTION'))
   return self::err('transaction_unavailable',503);
  $query=$wpdb->prepare("SELECT * FROM $b WHERE batch_id=%s LIMIT 1".($apply?' FOR UPDATE':''),$batch_id);
  $row=$wpdb->get_row($query,ARRAY_A);
  if(!$row){if($apply)$wpdb->query('ROLLBACK');return self::err('batch_not_found',404);}
  $states=$wpdb->get_col($wpdb->prepare("SELECT state FROM $t WHERE batch_id=%s".($apply?' FOR UPDATE':''),$batch_id));
  $counts=array_count_values($states);
  $nonterminal=0;foreach($counts as $state=>$count)if(!in_array($state,self::TERMINAL,true))$nonterminal+=$count;
  $target='';$reason='pending_work';
  if($row['state']!=='ACTIVE')$reason='batch_not_active';
  elseif(count($states)!==(int)$row['item_count'])$reason='count_drift';
  elseif($nonterminal===0){
   $target=!empty($counts['DEAD_LETTER'])?'FAILED':
     (!empty($counts['COMPLETED'])&&!empty($counts['CANCELLED'])?'PARTIAL':
      (!empty($counts['CANCELLED'])?'CANCELLED':'COMPLETED'));
   $reason='all_tasks_terminal';
  }
  if($apply&&$target!==''){
   $ok=$wpdb->update($b,['state'=>$target,'updated_at'=>gmdate('Y-m-d H:i:s')],
    ['id'=>$row['id'],'state'=>'ACTIVE']);
   if($ok!==1){$wpdb->query('ROLLBACK');return self::err('concurrent_batch_change',409);}
  }
  if($apply){
   if(false===$wpdb->query('COMMIT'))return self::err('commit_unknown',500);
  }
  return ['contract'=>self::CONTRACT,'batch_id'=>$batch_id,'current_state'=>$row['state'],
   'target_state'=>$target,'reason'=>$reason,'task_counts'=>$counts,
   'eligible'=>$target!=='','mutation_performed'=>$apply&&$target!=='',
   'production_mutation'=>false];
 }
 static function plan($input=[]){
  return self::close_batch((string)($input['batch_id']??''),false);
 }
 static function apply($input=[]){
  if(!is_array($input))return self::err('input_invalid',400);
  $p=self::plan($input);if(is_wp_error($p))return $p;
  if(!$p['eligible'])return self::err('batch_not_terminal',409);
  if((string)($input['expected_target_state']??'')!==$p['target_state'])
   return self::err('stale_batch_plan',409);
  return self::close_batch((string)$input['batch_id'],true);
 }
 static function boot(){
  add_action('rest_api_init',static function(){
   foreach(['audit'=>['GET',false],'plan'=>['GET',false],'apply'=>['POST',true]] as $act=>$spec)
    register_rest_route('mad4b-hub/v1','/queue-lifecycle/'.$act,[
     'methods'=>$spec[0],'callback'=>static function($req)use($act){
      $a=$req->get_params();return self::$act($a);
     },
     'permission_callback'=>static fn()=>self::allowed($spec[1])
    ]);
  },55);
  add_action('wp_abilities_api_init',static function(){
   if(!function_exists('wp_register_ability'))return;
   foreach(['audit'=>false,'plan'=>false,'apply'=>true] as $act=>$write){
    $name='mad4b/hub-queue-lifecycle-'.$act;
    if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
    wp_register_ability($name,[
     'label'=>'MAD4B Queue Lifecycle '.ucfirst($act),
     'description'=>'Central Staging queue coverage and terminal batch readback; no task execution.',
     'category'=>$write?'mad4b-write':'mad4b-read',
     'execute_callback'=>static function($args)use($act){return self::$act(is_array($args)?$args:[]);},
     'permission_callback'=>static fn()=>self::allowed($write),
     'input_schema'=>['type'=>'object','properties'=>[
      'batch_id'=>['type'=>'string'],'expected_target_state'=>['type'=>'string']
     ],'additionalProperties'=>false],
     'output_schema'=>['type'=>'object','additionalProperties'=>true],
     'meta'=>['public'=>false,'show_in_rest'=>false,
      'mcp'=>['public'=>false,'type'=>'tool','surface'=>$write?'write':'read']]
    ]);
   }
  },55);
 }
}
