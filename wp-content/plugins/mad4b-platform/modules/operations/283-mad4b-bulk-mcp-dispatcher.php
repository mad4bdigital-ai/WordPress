<?php
/**
 * Plugin Name: MAD4B Bulk MCP Client Dispatcher
 * Description: Source-first bulk work planning, bounded job submission and exclusive VPS specialist delegation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Bulk_MCP_Dispatcher_V1 {
 const CONTRACT='mad4b.hub.mcp-dispatcher.v1';
 const GAP_SOURCE='tourism-almallahgroup';
 const BATCH_SIZE=75;
 private static function site(){
  return wp_get_environment_type()==='staging' &&
   strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
 }
 public static function admin(){
  return self::site() && class_exists('MAD4B_Hub_Bulk_Jobs_Queue') &&
   MAD4B_Hub_Bulk_Jobs_Queue::writable();
 }
 private static function err($code,$http=409){
  return new WP_Error('mad4b_mcp_bulk_'.$code,'Bulk MCP dispatcher rejected operation',
   ['status'=>$http,'contract'=>self::CONTRACT]);
 }
 public static function allow_vps($authorized,$executor){
  // Only host-owned WP-CLI script may claim the specialist queue.
  return $authorized || ($executor==='vps_runner' && self::site() &&
   defined('WP_CLI') && WP_CLI && defined('MAD4B_VPS_BULK_RUNNER') &&
   MAD4B_VPS_BULK_RUNNER===true && current_user_can('manage_options'));
 }
 private static function stage($gap){
  return [
   'property_identity_missing'=>['ENTITY_RESOLUTION','verify_property_identity'],
   'description_missing'=>['PROVIDER_RESEARCH','research_source_description'],
   'source_pricing_missing'=>['PRICING','resolve_source_pricing'],
   'inventory_unverified'=>['AVAILABILITY','verify_source_inventory'],
   'source_rate_dates_invalid'=>['RATE_GENERATION','review_rate_date_range'],
   'entity_identity_review'=>['ENTITY_RESOLUTION','review_canonical_identity'],
  ][$gap]??null;
 }
 public static function readiness(){
  if(!self::site() || !class_exists('MAD4B_Hub_Bulk_Jobs_Queue'))
    return self::err('not_staging',403);
  $router=MAD4B_Hub_Execution_Router::status();
  $queue=MAD4B_Hub_Bulk_Jobs_Queue::list_queue();
  $gap=class_exists('MAD4B_Hub_Canonical_Gaps_V1')?
      MAD4B_Hub_Canonical_Gaps_V1::status():null;
  $verified=is_array($router)?!empty($router['current_request_verified_chatgpt_client']):false;
  return [
   'contract'=>self::CONTRACT,'site'=>home_url('/'),
   'default_executor'=>MAD4B_Hub_Execution_Router::DEFAULT_MODE,
   'mcp_client_transport_authenticated_this_request'=>$verified,
   'connected_mcp_client_can_claim_when_authenticated'=>true,
   'connected_client_required_for_unattended_mcp_stage_execution'=>true,
   'local_vps_is_not_an_mcp_llm_client'=>true,
   'vps_specialist_operation_allowlist'=>['source_sync_verify','canonical_gap_scan'],
   'vps_runner_claim_requires_host_wpcli'=>true,
   'vps_runner_does_not_claim_mcp_tasks'=>true,
   'legacy_chatgpt_client_alias_supported'=>true,
   'enabled_stages'=>MAD4B_Hub_Bulk_Jobs_Queue::stages(),
   'queue_ready'=>!is_wp_error($queue),
   'existing_batches'=>is_wp_error($queue)?null:($queue['count']??0),
   'open_gap_tasks'=>is_wp_error($gap)?null:($gap['count']??null),
   'automatic_commercial_execution'=>false,'auto_distribute'=>MAD4B_Hub_Auto_Distribute_V1::settings()['enabled'],
  ];
 }
 public static function plan_gaps($input=[],$do_enqueue=false) {
  if(!self::admin())return self::err('permission',403);
  if(!is_array($input) || array_diff(array_keys($input),['offset','limit','gap_code','max_concurrency','batch_prefix']))
   return self::err('input_shape',400);
  $offset=$input['offset']??0;$limit=$input['limit']??self::BATCH_SIZE;
  $concurrency=$input['max_concurrency']??2;$code=$input['gap_code']??'';
  if(!is_int($offset)||$offset<0||!is_int($limit)||$limit<1||$limit>self::BATCH_SIZE ||
   !is_int($concurrency)||$concurrency<1||$concurrency>8 ||
   !is_string($code)||($code!==''&&!preg_match('/^[a-z_]{3,60}$/D',$code)))
   return self::err('pagination',400);
  $rows=[];$walk=$offset;
  while(count($rows)<$limit){
   $window=min(50,$limit-count($rows));
   $part=MAD4B_Hub_Canonical_Gaps_V1::tasks($code,$walk,$window);
   if(is_wp_error($part))return $part;
   $piece=$part['tasks']??[];
   $rows=array_merge($rows,$piece);$walk+=count($piece);
   if(count($piece)<$window)break;
  }
  if(!$rows)return ['contract'=>self::CONTRACT,'batch_count'=>0,'offset'=>$offset,
   'next_offset'=>$offset,'complete'=>true,'executor'=>'mcp_client','enqueue_executed'=>false];
  $items=[];$seen=[];
  foreach($rows as $row){
   if(!is_array($row) || !isset($row['task_id'],$row['canonical_id'],$row['gap_code']) ||
    !is_int($row['task_id']) || !is_int($row['canonical_id']))
    return self::err('gap_contract',503);
   $mapping=self::stage($row['gap_code']);
   if(!$mapping)return self::err('gap_unrecognized',422);
   $taskkey='gap.'.(int)$row['task_id'];
   if(isset($seen[$taskkey]))return self::err('gap_duplicate');
   $seen[$taskkey]=true;
   $items[]=[
    'task_key'=>$taskkey,
    'stage'=>$mapping[0],
    'operation'=>$mapping[1],
    'target_ref'=>'canonical:'.(int)$row['canonical_id'],
    'priority'=>$row['severity']==='P0'?8:5,
    'max_attempts'=>3,
    'depends_on'=>[],
    'payload'=>[
     'assigned_executor'=>'mcp_client',
     'source_company'=>self::GAP_SOURCE,
     'canonical_id'=>(int)$row['canonical_id'],
     'gap_task_id'=>(int)$row['task_id'],
     'gap_code'=>$row['gap_code'],
     'evidence_required'=>true,
     'no_automatic_publish'=>true,
     'no_inferred_prices_or_rights'=>true,
     'requires_specific_ability_and_result_readback'=>true,
    ]
   ];
  }
  $scope=hash('sha256',self::GAP_SOURCE.'|'.$code);
  $prefix=(string)($input['batch_prefix']??'gaps');
  if(!preg_match('/^[a-z][a-z0-9.-]{2,24}$/D',$prefix))return self::err('batch_prefix',400);
  $batchkey=$prefix.'.'.substr($scope,0,12).'.'.$offset.'.'.$limit;
  $body=['batch_key'=>$batchkey,'max_concurrency'=>$concurrency,'items'=>$items];
  if(!$do_enqueue){
   return ['contract'=>self::CONTRACT,'batch_key'=>$batchkey,
    'jobs'=>count($items),'offset'=>$offset,'next_offset'=>$offset+count($items),
    'complete'=>count($items)<$limit,'executor'=>'mcp_client',
    'stage_counts'=>array_count_values(array_column($items,'stage')),
    'enqueue_executed'=>false,'uses_existing_bulk_queue'=>true,
    'no_provider_execution_performed'=>true];
  }
  $r=MAD4B_Hub_Bulk_Jobs_Queue::enqueue($body);
  if(is_wp_error($r))return $r;
  return ['contract'=>self::CONTRACT,'batch_key'=>$batchkey,
   'batch_id'=>$r['batches'][0]['batch_id']??null,
   'jobs'=>count($items),'replayed'=>!empty($r['replayed']),
   'next_offset'=>$offset+count($items),'complete'=>count($items)<$limit,
   'executor'=>'mcp_client','queue_committed'=>true,'execution_performed'=>false];
 }
 public static function plan_vps($input=[],$do_enqueue=false){
  if(!self::admin())return self::err('permission',403);
  if(!is_array($input)||array_diff(array_keys($input),['batch_key','operation','target_ref']))
   return self::err('vps_input',400);
  $op=(string)($input['operation']??'');
  if(!in_array($op,['source_sync_verify','canonical_gap_scan'],true))
   return self::err('vps_operation_not_allowlisted',403);
  $batchkey=(string)($input['batch_key']??'');
  if(!preg_match('/^vps\.[a-z0-9._:-]{8,110}$/D',$batchkey))return self::err('vps_batch_key',400);
  $stage=$op==='source_sync_verify'?'SOURCE_INGESTION':'ENTITY_RESOLUTION';
  $row=['batch_key'=>$batchkey,'max_concurrency'=>1,'items'=>[
   ['task_key'=>'specialist.1','stage'=>$stage,'operation'=>$op,
    'target_ref'=>$op==='source_sync_verify'?'provider:tourism_wp':'company:tourism-almallahgroup',
    'priority'=>8,'max_attempts'=>2,'depends_on'=>[],
    'payload'=>['assigned_executor'=>'vps_runner',
     'source_company'=>self::GAP_SOURCE,
     'require_exact_staging_host'=>true,'no_publication'=>true]]
  ]];
  if(!$do_enqueue)return ['contract'=>self::CONTRACT,'batch'=>$row,
   'executor'=>'vps_runner','execution_performed'=>false];
  $r=MAD4B_Hub_Bulk_Jobs_Queue::enqueue($row);
  if(is_wp_error($r))return $r;
  return ['contract'=>self::CONTRACT,'batch_key'=>$batchkey,
   'batch_id'=>$r['batches'][0]['batch_id']??null,
   'executor'=>'vps_runner','queue_committed'=>true,'execution_performed'=>false];
 }
 public static function rest(){
  if(!self::site())return;
  $def=[
   '/bulk-mcp/readiness'=>['GET','readiness',false],
   '/bulk-mcp/plan-gaps'=>['GET','plan_gaps',false],
   '/bulk-mcp/enqueue-gaps'=>['POST','plan_gaps',true],
   '/bulk-mcp/plan-vps'=>['GET','plan_vps',false],
   '/bulk-mcp/enqueue-vps'=>['POST','plan_vps',true],
  ];
  foreach($def as $route=>$d)
   register_rest_route('mad4b-central/v1',$route,[
    'methods'=>$d[0],'permission_callback'=>[__CLASS__,'admin'],
    'callback'=>static function($req)use($d){
     $args=$req->get_params();
     if($d[1]==='readiness')$r=self::readiness();
     elseif($d[1]==='plan_vps')$r=self::plan_vps($args,$d[2]);
     else $r=self::plan_gaps($args,$d[2]);
     return is_wp_error($r)?$r:rest_ensure_response($r);
    }
   ]);
 }
 public static function ability_plan($input=[]){return self::plan_gaps($input,false);}
 public static function ability_enqueue($input=[]){return self::plan_gaps($input,true);}
 public static function ability_status($input=[]){return self::readiness();}
 public static function abilities(){
  if(!self::site() || !function_exists('wp_register_ability'))return;
  foreach([
   'mad4b/hub-bulk-mcp-status'=>['ability_status',false],
   'mad4b/hub-bulk-mcp-plan-gaps'=>['ability_plan',false],
   'mad4b/hub-bulk-mcp-enqueue-gaps'=>['ability_enqueue',true],
  ] as $name=>$def) {
   if(function_exists('wp_has_ability') && wp_has_ability($name))continue;
   wp_register_ability($name,[
    'label'=>str_replace('mad4b/','MAD4B ',$name),
    'description'=>'MCP client default: durable gap batch planning and idempotent bounded enqueue.',
    'category'=>$def[1]?'mad4b-write':'mad4b-read',
    'execute_callback'=>[__CLASS__,$def[0]],
    'permission_callback'=>[__CLASS__,'admin'],
    'input_schema'=>['type'=>'object','properties'=>[
     'offset'=>['type'=>'integer','minimum'=>0],
     'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>75],
     'gap_code'=>['type'=>'string'],'max_concurrency'=>['type'=>'integer'],
     'batch_prefix'=>['type'=>'string']], 'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,
     'mcp'=>['public'=>false,'type'=>'tool','surface'=>$def[1]?'write':'read'],
     'annotations'=>['readonly'=>!$def[1],'destructive'=>false,'idempotent'=>true]],
   ]);
  }
 }
 public static function menu(){
  if(!self::site())return;
  add_submenu_page('mad4b-shared-content-hub','MCP Bulk Dispatcher','MCP Bulk Dispatcher',
   'manage_options','mad4b-bulk-mcp-dispatcher',[__CLASS__,'screen']);
 }
 public static function screen(){
  if(!self::admin())wp_die('Forbidden');
  echo '<div class="wrap"><h1>MAD4B Bulk MCP Dispatcher</h1>';
  echo '<p>MCP Client is the default task executor. VPS Runner is delegated only for allowlisted specialist tasks. Claiming never implies execution.</p>';
  foreach([self::readiness(),self::plan_gaps(['offset'=>0,'limit'=>75])] as $data){
   echo '<pre>'.esc_html(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
  }
  echo '</div>';
 }
 public static function boot(){
  add_filter('mad4b_hub_bulk_authorized_executor',[__CLASS__,'allow_vps'],10,2);
  add_action('rest_api_init',[__CLASS__,'rest'],49);
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],49);
  add_action('admin_menu',[__CLASS__,'menu'],28);
 }
}
MAD4B_Bulk_MCP_Dispatcher_V1::boot();

