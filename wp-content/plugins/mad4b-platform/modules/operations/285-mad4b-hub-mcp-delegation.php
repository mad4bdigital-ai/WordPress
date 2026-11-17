<?php
/**
 * Plugin Name: MAD4B MCP Client Delegation
 * Description: The authenticated queue lease owner may delegate exact approved work to a registered VPS specialist without changing the parent executor.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_MCP_Delegation_V1 {
 const CONTRACT='mad4b.hub.mcp-client-delegation.v1';
 const STAGING='tours.dev.mad4b.com';
 public static function allowed():bool {
  return wp_get_environment_type()==='staging'
   && strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))===self::STAGING
   && class_exists('MAD4B_Hub_Bulk_Jobs_Queue')
   && MAD4B_Hub_Bulk_Jobs_Queue::writable()
   && current_user_can('manage_options');
 }
 private static function error($code,$http=409){
  return new WP_Error('mad4b_delegation_'.$code,'MCP client delegation rejected',
   ['status'=>$http,'contract'=>self::CONTRACT]);
 }
 private static function task($id,$token) {
  if(!is_string($id)||!wp_is_uuid($id)||!is_string($token)||
     !preg_match('/^[a-f0-9]{48}$/D',$token))return self::error('lease_input_invalid',400);
  global $wpdb;
  $table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE task_id=%s",$id),ARRAY_A);
  if(!$row||$row['state']!=='RUNNING'||
    !in_array($row['lease_owner'],['mcp_client','chatgpt_client'],true) ||
    !$row['lease_expires_at']||$row['lease_expires_at']<=gmdate('Y-m-d H:i:s') ||
    !hash_equals((string)$row['lease_hash'],hash('sha256',$token)))
      return self::error('parent_client_lease_invalid_or_expired',409);
  global $wpdb;
  $b=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $batch=$wpdb->get_row($wpdb->prepare("SELECT state FROM $b WHERE batch_id=%s",$row['batch_id']),ARRAY_A);
  if(($batch['state']??'')!=='ACTIVE')return self::error('parent_batch_not_active',409);
  $payload=json_decode((string)$row['payload_json'],true);
  if(!is_array($payload)||($payload['assigned_executor']??'mcp_client')!=='mcp_client'||
     ($payload['source_company']??'')!=='tourism-almallahgroup')
      return self::error('parent_scope_not_verified',403);
  $row['scope_payload']=$payload;
  return $row;
 }
 public static function delegate($input=[]) {
  if(!self::allowed())return self::error('permission',403);
  if(!is_array($input)||array_diff(array_keys($input),
     ['parent_task_id','parent_lease_token','operation','adapter_id','target_ref','client_step_key']))
       return self::error('unexpected_fields',400);
  $parent=self::task($input['parent_task_id']??null,$input['parent_lease_token']??null);
  if(is_wp_error($parent))return $parent;
  $operation=(string)($input['operation']??'');
  $target=(string)($input['target_ref']??$parent['target_ref']);
  $adapter=(string)($input['adapter_id']??'');
  $step=(string)($input['client_step_key']??'');
  if(!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$step)||
     !preg_match('/^[a-z][a-z0-9._:-]{2,127}$/D',$operation) ||
     !is_string($target)||strlen($target)>191||$target==='')
    return self::error('invalid_delegation_parameters',400);
  $stage=$parent['stage'];
  if(!in_array($stage,MAD4B_Hub_Bulk_Jobs_Queue::stages(),true))
    return self::error('stage_not_registered');
  $known=['site_health','runtime_resolve','workflow_inspect','content_job_read','source_sync_verify'];
  if($operation==='stage_work'){
    if(!preg_match('/^[a-z][a-z0-9._:-]{3,90}$/D',$adapter) ||
      !class_exists('MAD4B_Hub_Dynamic_Runtime_Resolver') ||
      MAD4B_Hub_Dynamic_Runtime_Resolver::server_adapter($stage,$adapter)===null)
      return self::error('registered_adapter_required',403);
  }elseif(!in_array($operation,$known,true) || $adapter!=='')
    return self::error('operation_not_registered',403);
  if($operation==='workflow_inspect'||$operation==='content_job_read') {
    if(!wp_is_uuid((string)$parent['content_job_id']))
      return self::error('exact_job_scope_required',403);
    if($target!==$parent['target_ref'])return self::error('target_must_match_parent',403);
  }
  $postId=0;
  if($operation==='source_sync_verify'){
    if(!preg_match('/^post:([1-9][0-9]{0,9})$/D',$target,$m) ||
      $target!==$parent['target_ref'])return self::error('exact_source_post_required',403);
    $postId=(int)$m[1];
  }
  if($operation!=='site_health'&&$target!==$parent['target_ref'])
    return self::error('target_scope_changed',403);
  $fingerprint=hash('sha256',implode('|',[$parent['task_id'],$step,$operation,$target,$adapter]));
  $batchKey='delegate.'.substr($fingerprint,0,55);
  $payload=[
    'assigned_executor'=>'vps_runner',
    'delegated_by_executor'=>'mcp_client',
    'delegated_by_wp_user_id'=>get_current_user_id(),
    'delegated_from_task_id'=>$parent['task_id'],
    'delegated_from_batch_id'=>$parent['batch_id'],
    'delegation_contract'=>self::CONTRACT,
    'delegation_fingerprint'=>$fingerprint,
    'source_company'=>'tourism-almallahgroup',
    'parent_lease_expires_at'=>$parent['lease_expires_at'],
    'stage_adapter_id'=>$adapter,
    'post_id'=>$postId,
    'execution_rights_not_escalated'=>true
  ];
  $out=MAD4B_Hub_Bulk_Jobs_Queue::enqueue([
    'batch_key'=>$batchKey,'max_concurrency'=>1,
    'items'=>[[
      'task_key'=>'delegated.1','stage'=>$stage,'job_id'=>$parent['content_job_id'],
      'operation'=>$operation,'target_ref'=>$target,
      'priority'=>8,'max_attempts'=>2,'depends_on'=>[],'payload'=>$payload
    ]]
  ]);
  if(is_wp_error($out))return $out;
  $child=$out['batches'][0]??null;
  if(!$child||count($child['tasks'])!==1)return self::error('child_batch_readback_failed',503);
  return [
    'contract'=>self::CONTRACT,'state'=>'DELEGATED_TO_REGISTERED_VPS_RUNNER',
    'parent_executor'=>'mcp_client',
    'parent_task_id'=>$parent['task_id'],'parent_batch_id'=>$parent['batch_id'],
    'child_batch_id'=>$child['batch_id'],'child_task_id'=>$child['tasks'][0]['task_id'],
    'operation'=>$operation,'stage'=>$stage,'target_ref'=>$target,
    'executor'=>'vps_runner','delegated_by_wp_user'=>get_current_user_id(),
    'delegation_sha256'=>$fingerprint,'replayed'=>!empty($out['replayed']),
    'child_state'=>$child['tasks'][0]['state'],'parent_task_unchanged'=>true,
    'child_execution_performed'=>false,
    'child_completion_requires_independent_receipt'=>true,
    'parent_completion_requires_separate_readback'=>true,
    'oauth_client_identity_claimed'=>false
  ];
 }
 public static function status($input=[]) {
  if(!self::allowed())return self::error('permission',403);
  return [
    'contract'=>self::CONTRACT,
    'default_executor'=>'mcp_client',
    'delegation_permitted_for_live_client_lease'=>true,
    'origin_scope'=>self::STAGING,
    'vps_executor'=>'vps_runner',
    'allowed_registered_operations'=>['site_health','runtime_resolve','workflow_inspect','content_job_read','source_sync_verify'],
    'dynamic_stage_adapters_supported'=>true,
    'arbitrary_shell_commands_allowed'=>false,
    'unattended_mcp_client_impersonation'=>false,
    'same_queue_and_independent_receipts'=>true
  ];
 }
 public static function rest(){
  if(!class_exists('MAD4B_Hub_Bulk_Jobs_Queue'))return;
  register_rest_route('mad4b-hub/v1','/control-workflow/delegation/mcp-to-vps',[
    'methods'=>'POST','permission_callback'=>[__CLASS__,'allowed'],
    'callback'=>static function($r){
       $x=self::delegate((array)$r->get_json_params());
       return is_wp_error($x)?$x:rest_ensure_response($x);
    }]);
  register_rest_route('mad4b-hub/v1','/control-workflow/delegation/status',[
    'methods'=>'GET','permission_callback'=>[__CLASS__,'allowed'],
    'callback'=>static fn()=>rest_ensure_response(self::status())
  ]);
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  foreach(['mad4b/hub-mcp-delegate'=>'delegate','mad4b/hub-mcp-delegation-status'=>'status'] as $name=>$method){
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   $write=$method==='delegate';
   wp_register_ability($name,[
     'label'=>$write?'Delegate active MCP lease to VPS':'MCP Delegation Status',
     'description'=>'Delegate exactly scoped work to a registered VPS runner. Parent MCP client retains ownership.',
     'category'=>$write?'mad4b-write':'mad4b-read',
     'execute_callback'=>[__CLASS__,$method],
     'permission_callback'=>[__CLASS__,'allowed'],
     'input_schema'=>['type'=>'object','properties'=>[
      'parent_task_id'=>['type'=>'string'],'parent_lease_token'=>['type'=>'string'],
      'operation'=>['type'=>'string'],'target_ref'=>['type'=>'string'],
      'adapter_id'=>['type'=>'string'],'client_step_key'=>['type'=>'string']
     ],'additionalProperties'=>false],
     'output_schema'=>['type'=>'object','additionalProperties'=>true],
     'meta'=>['public'=>false,'show_in_rest'=>false,
        'mcp'=>['public'=>false,'type'=>'tool','surface'=>$write?'write':'read'],
        'annotations'=>['readonly'=>!$write,'destructive'=>false,'idempotent'=>true]]
   ]);
  }
 }
 public static function boot(){
  add_action('rest_api_init',[__CLASS__,'rest'],52);
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],52);
 }
}
MAD4B_Hub_MCP_Delegation_V1::boot();

