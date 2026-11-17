<?php
/**
 * Plugin Name: MAD4B Universal VPS Dynamic Executor Registry
 * Description: Discover WordPress capabilities and execute only governed, explicitly registered VPS contracts through the native Hub Bulk Queue.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Universal_VPS_V1 {
 const CONTRACT='mad4b.hub.universal-vps.v1';
 const STAGE='INTEGRATION_QA';
 const LIMIT=100;
 private static $contracts=null;
 public static function scoped() {
  return wp_get_environment_type()==='staging'
    && strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
 }
 public static function readable() {
  return self::scoped() && class_exists('MAD4B_Hub_Bulk_Jobs_Queue')
     && MAD4B_Hub_Bulk_Jobs_Queue::readable();
 }
 public static function writable() {
  return self::scoped() && class_exists('MAD4B_Hub_Bulk_Jobs_Queue')
     && MAD4B_Hub_Bulk_Jobs_Queue::writable();
 }
 private static function err($code,$status=422){
  return new WP_Error('mad4b_universal_vps_'.$code,$code,
   ['status'=>$status,'contract'=>self::CONTRACT]);
 }
 /** Installed plugins register executable contracts. Raw capability discovery never authorizes. */
 /**
  * Capability contracts are data-driven:
  * - saved, admin-managed declarations (option v2)
  * - self-declared WordPress Ability metadata (mad4b_vps)
  * - installed-provider contracts exposed through one extension filter.
  * Discovery is never sufficient on its own to grant execution.
  */
 public static function contracts(){
  if(self::$contracts!==null)return self::$contracts;
  $registry=get_option('mad4b_hub_vps_capability_contracts_v2',[]);
  if(!is_array($registry))$registry=[];
  $disabled=array_fill_keys(array_filter((array)($registry['disabled_abilities']??[]),'is_string'),true);
  $all=function_exists('wp_get_abilities')?wp_get_abilities():[];
  $contracts=[];
  foreach((array)($registry['items']??[]) as $entry){
   if(is_array($entry)){$entry['_source']='database';$contracts[]=$entry;}
  }
  // Providers self-describe their bounded executor policy; no name lists or
  // code changes are needed to onboard newly installed compliant providers.
  foreach($all as $name=>$ability){
   if(!is_string($name)||!is_object($ability)||!method_exists($ability,'get_meta'))continue;
   $meta=(array)$ability->get_meta();
   $advert=$meta['mad4b_vps']??($meta['mcp']['mad4b_vps']??null);
   if(!is_array($advert)||($advert['auto_register']??false)!==true)continue;
   $stages=$advert['stages']??($meta['mad4b_hub_stages']??[]);
   if(!is_array($stages)||!$stages)continue;
   $readonly=($meta['annotations']['readonly']??null)===true;
   $contract=[
    'id'=>self::generated_id($name),'ability'=>$name,
    'stages'=>$stages,'executor'=>'vps_runner','transport'=>'wp_ability',
    'read_only'=>$readonly,'requires_job'=>($advert['requires_job']??true)===true,
    'independent_verifier'=>$readonly?'repeat_readonly_ability':null,
    'enabled'=>true,'priority'=>(int)($advert['priority']??35),
    'owner'=>explode('/',$name,2)[0],'_source'=>'ability_metadata'
   ];
   $contracts[]=$contract;
  }
  $installed=apply_filters('mad4b_hub_vps_capability_contracts',[]);
  if(is_array($installed))foreach($installed as $entry){
   if(is_array($entry)){$entry['_source']='provider_filter';$contracts[]=$entry;}
  }
  $available=[];$seen=[];
  foreach($contracts as $contract){
   if(!is_array($contract)||($contract['enabled']??false)!==true)continue;
   $id=(string)($contract['id']??'');
   $name=(string)($contract['ability']??'');
   $stages=$contract['stages']??null;
   if(!preg_match('/^[a-z][a-z0-9._:-]{3,90}$/D',$id)||
      !preg_match('/^[a-z][a-z0-9._-]{1,79}\/[a-z][a-z0-9._-]{1,99}$/D',$name)||
      isset($seen[$id])||isset($disabled[$name])||
      !is_array($stages)||!$stages||
      array_diff($stages,MAD4B_Hub_Bulk_Jobs_Queue::stages())||
      ($contract['executor']??'')!=='vps_runner'||
      ($contract['transport']??'')!=='wp_ability'||
      !in_array(($contract['read_only']??null),[true,false],true)||
      !function_exists('wp_get_ability'))continue;
   $a=wp_get_ability($name);
   if(!$a)continue;
   $meta=(array)$a->get_meta();
   $ann=(array)($meta['annotations']??[]);
   $readonly=($ann['readonly']??false)===true;
   if($readonly!==$contract['read_only'])continue;
   $verifier=$contract['independent_verifier']??null;
   if($readonly) {
    if($verifier!=='repeat_readonly_ability'||
       ($ann['destructive']??null)!==false ||
       ($ann['idempotent']??null)!==true)continue;
   } else {
    // A write needs a plugin-owned, callable preflight + separate readback.
    // Database metadata cannot contain executable callbacks.
    if(($contract['_source']??'')!=='provider_filter'||
       ($contract['requires_job']??false)!==true||
       !is_callable($verifier)||
       !is_callable($contract['preflight']??null))continue;
   }
   $schema=$a->get_input_schema();
   if(($schema['type']??'')!=='object')continue;
   $required=array_values((array)($schema['required']??[]));
   if(count($required)>25)continue;
   $available[$id]=[
    'id'=>$id,'ability'=>$name,'stages'=>array_values(array_unique($stages)),
    'readonly'=>$readonly,'required_fields'=>$required,
    'requires_job'=>($contract['requires_job']??true)===true,
    'input_schema'=>$schema,'preflight'=>$contract['preflight']??null,
    'verifier'=>$verifier,'priority'=>max(1,min(99,(int)($contract['priority']??35))),
    'owner'=>sanitize_text_field((string)($contract['owner']??explode('/',$name,2)[0])),
    'registration_source'=>$contract['_source']??'unknown'
   ];
   $seen[$id]=true;
  }
  self::$contracts=$available;
  return $available;
 }
 public static function generated_id($ability_name){
  return 'dynamic.'.substr(hash('sha256',(string)$ability_name),0,24);
 }
 private static function exact($id,$stage) {
  $c=self::contracts()[$id]??null;
  if(!$c||!in_array($stage,$c['stages'],true))return null;
  return $c;
 }
 private static function valid_task($task,$c) {
  if(!self::writable()||!is_array($task)||!is_array($task['payload']??null))return false;
  if(($task['operation']??'')!=='stage_work'||
     !in_array(($task['stage']??''),$c['stages'],true) ||
     ($task['payload']['assigned_executor']??'')!=='vps_runner' ||
     ($task['payload']['stage_adapter_id']??'')!==$c['id']||
     ($task['payload']['stage_ability']??'')!==$c['ability'])return false;
  $input=$task['payload']['stage_input']??null;
  if(!is_array($input)||strlen(wp_json_encode($input))>12000 ||
     array_diff($c['required_fields'],array_keys($input)))return false;
  $job=(string)($task['content_job_id']??'');
  if($c['requires_job'] && !wp_is_uuid($job))return false;
  if($job!==''){
   $r=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
   if(is_wp_error($r)||!in_array(($r['job']['state']??''),['NEW','QUEUED','RUNNING'],true)||
      ($r['job']['stage']??'')!==($task['stage']??''))return false;
   if(isset($input['job_id'])&&$input['job_id']!==$job)return false;
  } elseif(($task['target_ref']??'')!=='site:'.strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)))return false;
  if(is_callable($c['preflight'])&&call_user_func($c['preflight'],$task,$input)!==true)
    return false;
  return true;
 }
 private static function sanitized_digest($output) {
  $json=wp_json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($json)||strlen($json)>100000)return '';
  return hash('sha256',$json);
 }
 public static function route_ready($stage,$id,$context=[]) {
  $c=self::exact((string)$id,(string)$stage);
  if(!$c||!is_array($context))return false;
  if(($context['stage_ability']??'')!==$c['ability']||
     !array_key_exists('stage_input',$context)||
     !is_array($context['stage_input']))return false;
  return self::valid_task([
    'operation'=>'stage_work','stage'=>$stage,
    'target_ref'=>(string)($context['target_ref']??''),
    'content_job_id'=>(string)($context['content_job_id']??''),
    'payload'=>array_merge($context,[
      'assigned_executor'=>'vps_runner',
      'stage_adapter_id'=>$id,
      'stage_ability'=>$c['ability']
    ])
  ],$c);
 }
 public static function manifests($existing){
  if(!is_array($existing))$existing=[];
  if(!self::scoped())return $existing;
  foreach(self::contracts() as $c)
    $existing[]=['id'=>$c['id'],'stages'=>$c['stages'],
      'ability'=>$c['ability'],'executor'=>'vps_runner','priority'=>$c['priority'],
      'enabled'=>true,'ready'=>true,'contract'=>self::CONTRACT];
  return $existing;
 }
 public static function dispatch($prior,$task,$heartbeat){
  if($prior!==null)return $prior;
  if(($task['operation']??'')!=='stage_work')return null;
  $id=(string)($task['payload']['stage_adapter_id']??'');
  $c=self::exact($id,(string)($task['stage']??''));
  if(!$c||!self::valid_task($task,$c))return null;
  $ability=wp_get_ability($c['ability']);
  $args=$task['payload']['stage_input'];
  $permitted=$ability->check_permissions($args);
  if(is_wp_error($permitted))return $permitted;
  if($permitted!==true)return self::err('underlying_ability_denied',403);
  $live=$heartbeat(180);
  if(is_wp_error($live))return $live;
  $result=$ability->execute($args);
  if(is_wp_error($result))return $result;
  $digest=self::sanitized_digest($result);
  if(!$digest)return self::err('output_contract_invalid',409);
  // A successfully executed read-only screening Ability may still produce
  // a policy HOLD. That is not a completed workflow step.
  $observed=is_array($result)
    ?(string)($result['screen']['state']??$result['state']??''):'';
  $held=in_array($observed,[
    'HOLD_REVIEW','HOLD_INJECTION_REVIEW','BLOCKED','WAITING_REVIEW',
    'WAITING_EXECUTOR','WAITING_PROVIDER'],true);
  return ['verified'=>true,'contract'=>self::CONTRACT,
    'completion_state'=>$held?'pending':'complete',
    'observed_result_state'=>$observed,
    'review_hold_required'=>$held,
    'operation'=>'stage_work','stage'=>$task['stage'],
    'result_kind'=>'governed_ability_result','adapter_id'=>$c['id'],
    'ability_name'=>$c['ability'],'result_sha256'=>$digest,
    'input_sha256'=>hash('sha256',wp_json_encode($args)),
    'evidence_ref'=>'ability:'.$c['id'].':'.$digest,
    'readonly'=>$c['readonly'],'mutation_performed'=>!$c['readonly'],
    'job_id'=>(string)$task['content_job_id'],
    'target_ref'=>$task['target_ref'],'task_id'=>$task['task_id']];
 }
 public static function verify($prior,$result,$task){
  if($prior===true)return true;
  if(($result['contract']??'')!==self::CONTRACT||
    ($task['operation']??'')!=='stage_work')return false;
  // Queue finish() passes the locked database row (payload_json), whereas
  // dispatch() receives a hydrated task (payload). Normalize without trusting
  // receipt-supplied inputs before independently re-reading the Ability.
  if(!is_array($task['payload']??null)) {
    $stored=json_decode((string)($task['payload_json']??''),true);
    if(!is_array($stored))return false;
    $task['payload']=$stored;
  }
  $id=(string)($result['adapter_id']??'');
  $c=self::exact($id,(string)($task['stage']??''));
  if(!$c||!self::valid_task($task,$c) ||
    ($result['operation']??'')!=='stage_work' ||
    ($result['stage']??'')!==$task['stage'] ||
    ($result['ability_name']??'')!==$c['ability'] ||
    ($result['task_id']??'')!==($task['task_id']??'') ||
    ($result['job_id']??'')!==(string)$task['content_job_id']||
    ($result['target_ref']??'')!==$task['target_ref'] ||
    ($result['verified']??false)!==true ||
    ($result['readonly']??null)!==$c['readonly'])return false;
  $input=$task['payload']['stage_input'];
  if(($result['input_sha256']??'')!==hash('sha256',wp_json_encode($input)))return false;
  $digest=(string)($result['result_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||
    ($result['evidence_ref']??'')!=='ability:'.$id.':'.$digest)return false;
  if($c['readonly']) {
    $a=wp_get_ability($c['ability']);
    if(!$a||$a->check_permissions($input)!==true)return false;
    $current=$a->execute($input);
    if(is_wp_error($current))return false;
    $state=is_array($current)
      ?(string)($current['screen']['state']??$current['state']??''):'';
    $held=in_array($state,[
      'HOLD_REVIEW','HOLD_INJECTION_REVIEW','BLOCKED','WAITING_REVIEW',
      'WAITING_EXECUTOR','WAITING_PROVIDER'],true);
    return self::sanitized_digest($current)===$digest &&
      ($result['observed_result_state']??null)===$state &&
      ($result['review_hold_required']??null)===$held &&
      ($result['completion_state']??null)===($held?'pending':'complete');
  }
  return call_user_func($c['verifier'],$task,$result)===true;
 }
 public static function discovery($input=[]){
  if(!self::readable())return self::err('permission',403);
  if(!is_array($input)||array_diff(array_keys($input),
      ['source','query','limit','cursor','stage']))return self::err('input_invalid',400);
  $source=(string)($input['source']??'wordpress_abilities');
  $query=strtolower(trim((string)($input['query']??'')));
  $stage=(string)($input['stage']??'');
  $limit=(int)($input['limit']??40);$cursor=(int)($input['cursor']??0);
  if(!in_array($source,['wordpress_abilities','vps_contracts','wp_cli','action_scheduler','provider_manifests'],true)||
     $limit<1||$limit>100||$cursor<0||$cursor>20000||
     strlen($query)>80||($stage!==''&&!in_array($stage,MAD4B_Hub_Bulk_Jobs_Queue::stages(),true)))
    return self::err('discovery_parameters_invalid',400);
  $rows=[];
  if($source==='wordpress_abilities'){
    foreach(wp_get_abilities() as $name=>$ability){
     $meta=(array)$ability->get_meta();
     $installed=array_values(array_filter(self::contracts(),static fn($c)=>$c['ability']===$name));
     $rows[]=['id'=>$name,'source'=>'wp_ability','read_only'=>(($meta['annotations']['readonly']??false)===true),
       'executable_by_vps'=>count($installed)>0,
       'registered_adapters'=>array_column($installed,'id'),
       'mode'=>count($installed)?'governed_contract':'discover_only'];
    }
  }elseif($source==='vps_contracts'){
    foreach(self::contracts() as $c)$rows[]=['id'=>$c['id'],'source'=>'trusted_php_contract',
     'ability'=>$c['ability'],'stages'=>$c['stages'],'read_only'=>$c['readonly'],
     'ready'=>true,'verification'=>$c['readonly']?'independent_repeat_readback':'registered_independent_verifier'];
  }elseif($source==='wp_cli'){
    // Enumerate the live WP-CLI registry, never execute or interpolate commands.
    if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI')&&
      method_exists('WP_CLI','get_root_command')){
      $root=WP_CLI::get_root_command();
      $todo=[[$root,'']];$walked=0;
      while($todo&&$walked<500){
        [$node,$prefix]=array_shift($todo);$walked++;
        if(!is_object($node)||!method_exists($node,'get_subcommands'))continue;
        foreach((array)$node->get_subcommands() as $name=>$child) {
          $key=trim($prefix.' '.(string)$name);
          if(strlen($key)>150)continue;
          $rows[]=['id'=>$key,'source'=>'wp_cli',
             'executable_by_vps'=>false,'mode'=>'discover_only_requires_trusted_adapter'];
          if(count($rows)<500)$todo[]=[$child,$key];
        }
      }
    }
  }elseif($source==='action_scheduler'){
    if(function_exists('as_get_scheduled_actions')){
      $actions=as_get_scheduled_actions(['status'=>'pending','per_page'=>150],'OBJECT');
      $hooks=[];
      if(is_array($actions))foreach($actions as $action){
        if(is_object($action)&&method_exists($action,'get_hook')){
          $hook=$action->get_hook();
          if(is_string($hook)&&strlen($hook)<190)$hooks[$hook]=true;
        }
      }
      foreach(array_keys($hooks) as $hook)
        $rows[]=['id'=>$hook,'source'=>'action_scheduler',
          'executable_by_vps'=>false,'mode'=>'discover_only_requires_trusted_adapter'];
    }
  }else{
    // Existing registered VPS manifests and installed provider declarations.
    $raw=apply_filters('mad4b_hub_dynamic_executor_manifests',[]);
    if(is_array($raw))foreach($raw as $item){
      if(!is_array($item)||!is_string($item['id']??null))continue;
      $rows[]=['id'=>$item['id'],'source'=>'installed_adapter_manifest',
        'ability'=>$item['ability']??null,'stages'=>$item['stages']??[],
        'declared_ready'=>($item['ready']??false)===true,
        'executable_by_vps'=>($item['enabled']??false)===true &&
          ($item['ready']??false)===true &&
          ($item['executor']??'')==='vps_runner',
        'mode'=>'governed_contract_subject_to_task_scope'];
    }
    $extra=apply_filters('mad4b_hub_vps_provider_discovery',[]);
    if(is_array($extra))foreach(array_slice($extra,0,300) as $x){
      if(is_array($x)&&is_string($x['id']??null))
        $rows[]=['id'=>$x['id'],'source'=>'registered_provider',
         'executable_by_vps'=>false,'mode'=>'discover_only_requires_trusted_adapter'];
    }
  }
  $rows=array_values(array_filter($rows,static fn($v)=>
    (!$query||str_contains(strtolower($v['id']),$query))&&
    (!$stage||in_array($stage,(array)($v['stages']??[]),true))));
  usort($rows,static fn($a,$b)=>strcmp($a['id'],$b['id']));
  $size=count($rows);$page=array_slice($rows,$cursor,$limit);
  return ['contract'=>self::CONTRACT,'source'=>$source,'stage'=>$stage,
   'matched'=>$size,'results'=>$page,
   'next_cursor'=>$cursor+$limit<$size?$cursor+$limit:null,
   'default_executor'=>'mcp_client',
   'discovery_never_grants_execution'=>true,'mutation_performed'=>false];
 }
 public static function plan($input=[]){
  if(!self::readable())return self::err('permission',403);
  if(!is_array($input)||array_diff(array_keys($input),['stage','adapter_id','job_id','target_ref','stage_input']))
   return self::err('invalid_input',400);
  $stage=strtoupper((string)($input['stage']??''));
  $id=(string)($input['adapter_id']??'');
  $job=(string)($input['job_id']??'');
  $target=(string)($input['target_ref']??'');
  $args=$input['stage_input']??[];
  if(!is_array($args)||strlen(wp_json_encode($args))>12000)return self::err('stage_input_invalid',400);
  $c=self::exact($id,$stage);
  if(!$c)return ['contract'=>self::CONTRACT,'stage'=>$stage,'adapter_id'=>$id,
     'state'=>'WAITING_EXECUTOR','reason'=>'registered_stage_adapter_missing',
     'selected_executor'=>null,'mutation_performed'=>false];
  $task=['operation'=>'stage_work','stage'=>$stage,'target_ref'=>$target,
    'content_job_id'=>$job,
    'payload'=>['assigned_executor'=>'vps_runner','stage_adapter_id'=>$id,
      'stage_ability'=>$c['ability'],'stage_input'=>$args]];
  $valid=self::valid_task($task,$c);
  return ['contract'=>self::CONTRACT,'adapter_id'=>$id,'stage'=>$stage,
    'ability'=>$c['ability'],'state'=>$valid?'READY_FOR_QUEUED_EXECUTION':'WAITING_CONTRACT_INPUT',
    'selected_executor'=>$valid?'vps_runner':null,'default_executor'=>'mcp_client',
    'requires_job'=>$c['requires_job'],'readonly'=>$c['readonly'],
    'required_fields'=>$c['required_fields'],
    'execution_performed'=>false,'mutation_performed'=>false];
 }
 /**
  * Snapshot the active local transport policy at enqueue. Keep the value
  * deterministic for Hub Queue's existing request-hash/idempotency contract.
  * The queue row's created_at is the capture timestamp.
  */
 private static function enqueue_policy_snapshot(){
  if(!class_exists('MAD4B_SCP_Capability_Transport_Routing',false)
     ||!class_exists('MAD4B_SCP_Site_Profile',false))
      return self::err('execution_policy_unavailable',503);
  $profile=MAD4B_SCP_Site_Profile::status();
  $policy=MAD4B_SCP_Capability_Transport_Routing::execution_preferences();
  $transport=MAD4B_SCP_Capability_Transport_Routing::VPS;
  $mode=MAD4B_SCP_Capability_Transport_Routing::mode_for_transport($transport);
  if(!is_array($profile)||!is_array($policy)
     ||empty($profile['origin_match'])
     ||empty($profile['deployment_binding_match'])
     ||($profile['environment']??'')!=='staging'
     ||!is_string($profile['site_uuid']??null)
     ||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$profile['site_uuid'])
     ||!is_int($policy['revision']??null)
     ||!is_string($mode)||$mode!=='registered_operator')
       return self::err('execution_policy_not_ready_for_registered_operator',409);
  return [
     'contract'=>'mad4b.execution-policy-snapshot.v1',
     'mode'=>$mode,
     'revision'=>$policy['revision'],
     'site_uuid'=>$profile['site_uuid'],
     'environment'=>'staging',
     'transport'=>$transport
  ];
 }
 public static function enqueue($input=[]){
  if(!self::writable())return self::err('write_permission_denied',403);
  if(!is_array($input)||array_diff(array_keys($input),[
    'batch_key','task_key','adapter_id','stage','job_id','target_ref',
    'stage_input','priority','trigger_source','trigger_event_id']))return self::err('enqueue_fields_invalid',400);
  $batch=(string)($input['batch_key']??'');
  $key=(string)($input['task_key']??'');
  $adapter=(string)($input['adapter_id']??'');
  $stage=(string)($input['stage']??'');
  $job=(string)($input['job_id']??'');
  $target=(string)($input['target_ref']??'');
  $args=$input['stage_input']??[];
  if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$batch) ||
     !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$key)||
     !is_array($args))return self::err('enqueue_input_invalid',400);
  $p=self::plan(['adapter_id'=>$adapter,'stage'=>$stage,
    'job_id'=>$job,'target_ref'=>$target,'stage_input'=>$args]);
  if(is_wp_error($p))return $p;
  if(($p['state']??'')!=='READY_FOR_QUEUED_EXECUTION')return self::err('contract_preflight_required',409);
  $contract=self::exact($adapter,$stage);
  if(!$contract)return self::err('contract_lost',409);
  $priority=max(1,min(9,(int)($input['priority']??5)));
  $policy_snapshot=self::enqueue_policy_snapshot();
  if(is_wp_error($policy_snapshot))return $policy_snapshot;
  $payload=[
     'assigned_executor'=>'vps_runner','stage_adapter_id'=>$adapter,
     'stage_ability'=>$contract['ability'],'stage_input'=>$args,
     'universal_vps_contract'=>self::CONTRACT,
     'execution_policy_snapshot'=>$policy_snapshot,
     'trigger_source'=>sanitize_key((string)($input['trigger_source']??'manual')),
     'trigger_event_id'=>substr((string)($input['trigger_event_id']??''),0,100),
  ];
  $queue=MAD4B_Hub_Bulk_Jobs_Queue::enqueue([
    'batch_key'=>$batch,'max_concurrency'=>1,'items'=>[[
      'task_key'=>$key,'stage'=>$stage,'operation'=>'stage_work',
      'job_id'=>$job,'target_ref'=>$target,'priority'=>$priority,
      'depends_on'=>[],'max_attempts'=>$contract['readonly']?2:1,
      'payload'=>$payload
    ]]
  ]);
  if(is_wp_error($queue))return $queue;
  return ['contract'=>self::CONTRACT,'state'=>'ENQUEUED',
    'batch_id'=>$queue['batches'][0]['batch_id']??'',
    'replayed'=>($queue['replayed']??false)===true,
    'stage'=>$stage,'adapter_id'=>$adapter,'selected_executor'=>'vps_runner',
    'default_executor'=>'mcp_client',
    'execution_performed'=>false,'readback_required'=>true];
 }
 public static function status($input=[]){
  if(!self::readable())return self::err('permission',403);
  return ['contract'=>self::CONTRACT,'registered_contract_count'=>count(self::contracts()),
    'registered_contract_ids'=>array_keys(self::contracts()),
    'discovered_abilities_count'=>count(wp_get_abilities()),
    'default_executor'=>'mcp_client','vps_worker_registered'=>class_exists('MAD4B_Hub_Bulk_VPS_Runner'),
    'queue_integration'=>true,'independent_readbacks'=>true,
    'auto_discovery_sources'=>['wordpress_abilities','vps_contracts','wp_cli','action_scheduler','provider_manifests'],
    'discovered_not_necessarily_executable'=>true,
    'arbitrary_shell_execution'=>false,'mutation_performed'=>false];
 }
 public static function routes(){
  if(!self::scoped())return;
  foreach(['/universal-vps/status'=>'status','/universal-vps/discover'=>'discovery',
    '/universal-vps/plan'=>'plan','/universal-vps/enqueue'=>'enqueue'] as $url=>$method)
    register_rest_route('mad4b-hub/v1',$url,[
      'methods'=>$method==='status'?'GET':'POST',
      'permission_callback'=>[__CLASS__,$method==='enqueue'?'writable':'readable'],
      'callback'=>static function($request)use($method){
        $r=call_user_func([__CLASS__,$method],$request->get_params());
        return is_wp_error($r)?$r:rest_ensure_response($r);
      }]);
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability')||!self::scoped())return;
  foreach(['status','discovery','plan','enqueue'] as $method){
    $name='mad4b/hub-universal-vps-'.($method==='discovery'?'discover':$method);
    if(wp_has_ability($name))continue;
    wp_register_ability($name,[
      'label'=>'MAD4B Universal VPS '.ucfirst($method),
      'description'=>'Discover and plan exact capability routes; no execution authority from discovery.',
      'category'=>$method==='enqueue'?'mad4b-write':'mad4b-read',
      'execute_callback'=>[__CLASS__,$method],
      'permission_callback'=>[__CLASS__,$method==='enqueue'?'writable':'readable'],
      'input_schema'=>['type'=>'object','properties'=>[
        'batch_key'=>['type'=>'string'],'task_key'=>['type'=>'string'],
        'priority'=>['type'=>'integer'],'trigger_source'=>['type'=>'string'],
        'trigger_event_id'=>['type'=>'string'],
        'source'=>['type'=>'string'],'query'=>['type'=>'string'],
        'stage'=>['type'=>'string'],'adapter_id'=>['type'=>'string'],
        'job_id'=>['type'=>'string'],'target_ref'=>['type'=>'string'],
        'limit'=>['type'=>'integer'],'cursor'=>['type'=>'integer'],
        'stage_input'=>['type'=>'object','additionalProperties'=>true]
      ],'additionalProperties'=>false],
      'output_schema'=>['type'=>'object','additionalProperties'=>true],
      'meta'=>['public'=>false,'show_in_rest'=>false,
        'mcp'=>['public'=>false,'type'=>'tool',
          'surface'=>$method==='enqueue'?'write':'read'],
        'annotations'=>['readonly'=>$method!=='enqueue',
           'idempotent'=>true,'destructive'=>false]]
    ]);
  }
 }
 public static function boot(){
  add_filter('mad4b_hub_dynamic_executor_manifests',[__CLASS__,'manifests'],37,1);
  add_filter('mad4b_hub_bulk_stage_dispatch',[__CLASS__,'dispatch'],37,3);
  add_filter('mad4b_hub_bulk_verify_stage_receipt',[__CLASS__,'verify'],37,3);
  add_action('rest_api_init',[__CLASS__,'routes'],64);
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],64);
 }
}
require_once __DIR__ . '/288-mad4b-provider-onboarding-v1.php';
require_once __DIR__ . '/289-mad4b-firecrawl-research-bridge-v1.php';
require_once __DIR__ . "/290-mad4b-dynamic-provider-authority-reconciler.php";
require_once __DIR__ . "/291-mad4b-firecrawl-signed-transport-v1.php";
MAD4B_Hub_Universal_VPS_V1::boot();

