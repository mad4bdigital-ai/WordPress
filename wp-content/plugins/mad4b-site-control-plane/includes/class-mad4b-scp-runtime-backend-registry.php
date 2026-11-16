<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Portable, fail-closed runtime backend catalog. Discovery never grants execution.
 * Providers can register additional candidates; execution belongs to separate
 * signed, authorized adapters, not this catalog.
 */
final class MAD4B_SCP_Runtime_Backend_Registry {
 const CONTRACT = 'mad4b.runtime-backend-registry.v1';
 const TYPES = array('local_php','docker','ssh','vps_stdio','https_runner','queue_worker','custom');
 const PRESETS = array(
  'shared_hosting'=>array('local_php','vps_stdio','https_runner','queue_worker'),
  'managed_wordpress'=>array('https_runner','queue_worker','local_php'),
  'vps'=>array('docker','vps_stdio','local_php','ssh','https_runner','queue_worker'),
  'containerized'=>array('docker','vps_stdio','https_runner','queue_worker'),
  'external_runner'=>array('vps_stdio','https_runner','queue_worker','ssh'),
 );
 public static function default_preset($name) {
  return self::PRESETS[$name] ?? self::PRESETS['shared_hosting'];
 }
 public static function normalize_preset($items) {
  if(!is_array($items)||count($items)>count(self::TYPES))return null;
  $out=array();
  foreach($items as $value) {
   if(!is_string($value)||!in_array($value,self::TYPES,true)||in_array($value,$out,true)) return null;
   $out[]=$value;
  }
  return $out ?: null;
 }
 public static function effective_preset($name) {
  $default=self::default_preset($name);
  if(!function_exists('get_option'))return $default;
  $custom=get_option('mad4b_runtime_backend_preset_custom_'.$name,null);
  return self::normalize_preset($custom)??$default;
 }
 public static function save_preset($name,$types,$reset=false) {
  if(!function_exists('current_user_can')||true!==current_user_can('manage_options')||!function_exists('update_option'))return false;
  if(!isset(self::PRESETS[$name]))return false;
  if($reset) {
   if(!function_exists('delete_option'))return false;
   return delete_option('mad4b_runtime_backend_preset_custom_'.$name);
  }
  $valid=self::normalize_preset($types);
  if($valid===null)return false;
  return update_option('mad4b_runtime_backend_preset_custom_'.$name,$valid,false);
 }
 private static function unsigned_number($value,$default,$max) {
  if($value===null)return $default;
  if(!is_int($value) && !(is_string($value)&&preg_match('/^[0-9]{1,16}$/D',$value)))
   return null;
  if((float)$value>$max)return null;
  return max(0,(int)$value);
 }
 public static function sanitize_candidates($candidates) {
  $out=array();
  if (!is_array($candidates)) return $out;
  foreach(array_slice($candidates,0,64) as $id=>$v) {
   if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$id) || !is_array($v)) continue;
   $type=$v['type']??'';
   if (!in_array($type,self::TYPES,true)) continue;
   // Do not coerce an unexpected WP_Error, array or object into an
   // executable operation ID. A malformed provider registration is not
   // a partially approved backend.
   $requested=$v['operations']??array();
   if(!is_array($requested)||count($requested)>64)continue;
   $ops=array();
   foreach($requested as $op) {
    if(!is_string($op)||!preg_match('/^[a-z][a-z0-9._-]{2,95}$/D',$op)){
     $ops=null;break;
    }
    if(!in_array($op,$ops,true))$ops[]=$op;
   }
   if($ops===null)continue;
   $last=self::unsigned_number($v['last_probe_at']??null,0,PHP_INT_MAX);
   $backoff=self::unsigned_number($v['backoff_until']??null,0,PHP_INT_MAX);
   $latency=self::unsigned_number($v['latency_ms']??null,60000,60000);
   if($last===null||$backoff===null||$latency===null)continue;
   $out[$id]=array(
    'type'=>$type,'operations'=>array_slice($ops,0,48),
    'discovered'=>true===($v['discovered']??false),
    'configured'=>true===($v['configured']??false),
    'reachable'=>true===($v['reachable']??false),
    'certified'=>true===($v['certified']??false),
    'authorized'=>true===($v['authorized']??false),
    'isolation_verified'=>true===($v['isolation_verified']??false),
    'identity_verified'=>true===($v['identity_verified']??false),
    'read_probe_observed'=>true===($v['read_probe_observed']??false),
    'last_probe_at'=>$last,
    'backoff_until'=>$backoff,
    'latency_ms'=>$latency,
   );
  }
  ksort($out,SORT_STRING);
  return $out;
 }
 public static function ready($v,$operation,$now=null) {
  $now=$now??time();
  return is_array($v) && is_string($operation)
   && is_array($v['operations']??null) && in_array($operation,$v['operations'],true)
   && ($v['discovered']??null)===true && ($v['configured']??null)===true
   && ($v['reachable']??null)===true && ($v['certified']??null)===true
   && ($v['authorized']??null)===true && ($v['identity_verified']??null)===true
   && ($v['isolation_verified']??null)===true
   && is_int($v['backoff_until']??null) && $v['backoff_until']<=$now;
 }
 public static function select($candidates,$operation,$preset='shared_hosting',$now=null) {
  $rows=self::sanitize_candidates($candidates);
  $order=self::effective_preset($preset);
  $matching=array();
  foreach($rows as $id=>$row) if(self::ready($row,$operation,$now)) {
   $rank=array_search($row['type'],$order,true);
   $matching[]=array('id'=>$id,'type'=>$row['type'],'rank'=>$rank===false?99:$rank,'latency_ms'=>$row['latency_ms']);
  }
  usort($matching,static function($a,$b){return ($a['rank']<=>$b['rank']) ?: (($a['latency_ms']<=>$b['latency_ms']) ?: strcmp($a['id'],$b['id']));});
  return array('contract'=>self::CONTRACT,'selected'=>$matching[0]['id']??null,
    'eligible_count'=>count($matching),'ranked'=>$matching,
    'execution_performed'=>false,'authorization_granted'=>false);
 }
 public static function fallback_allowed($operation,$outcome) {
  return in_array($operation,array('php.lint','wp.status','workspace.test.read','runtime.status.read'),true)
   && 'confirmed_not_started'===$outcome;
 }
 public static function snapshot($candidates,$preset='shared_hosting') {
  $rows=self::sanitize_candidates($candidates);
  $public=array();
  foreach($rows as $id=>$r) $public[$id]=array(
   'type'=>$r['type'],'discovered'=>$r['discovered'],'configured'=>$r['configured'],
   'reachable'=>$r['reachable'],'certified'=>$r['certified'],
   'authorized'=>$r['authorized'],'ready'=>self::ready($r,'runtime.status.read'),
   'read_probe_observed'=>$r['read_probe_observed'],
   'operations'=>$r['operations'],'backoff_active'=>$r['backoff_until']>time()
  );
  return array('contract'=>self::CONTRACT,'preset'=>isset(self::PRESETS[$preset])?$preset:'shared_hosting',
    'backends'=>$public,'read_only'=>true,'mutation_performed'=>false,'authorization_granted'=>false);
 }
 public static function observe() {
  $candidates=array();
  if(function_exists('apply_filters')) $candidates=apply_filters('mad4b_runtime_backend_candidates',array());
  $preset=function_exists('get_option')?get_option('mad4b_runtime_backend_preset','shared_hosting'):'shared_hosting';
  return self::snapshot($candidates,is_string($preset)?$preset:'shared_hosting');
 }
 /** Fresh site-bound STDIO inventory/read canary. A transient is
  * discovery only; it can never grant host identity or operation authority.
  */
 public static function local_stdio_read_observation() {
  if(!function_exists('get_transient')
   || !class_exists('MAD4B_SCP_Site_Profile',false))return false;
  try {
   $probe=get_transient('mad4b_vps_mcp_stdio_probe_v1');
   $site=MAD4B_SCP_Site_Profile::status();
  } catch(\Throwable $error) {
   return false;
  }
  if(!is_array($probe)||!is_array($site))return false;
  $now=time();
  return ($probe['contract']??'')==='mad4b.vps-mcp-stdio-read-probe.v1'
    && ($site['contract']??'')==='mad4b.site-profile.v2'
    && ($site['origin_match']??null)===true
    && ($site['environment_match']??null)===true
    && ($site['deployment_binding_match']??null)===true
    && is_string($probe['site_uuid']??null)
    && hash_equals((string)($site['site_uuid']??''),$probe['site_uuid'])
    && ($probe['canonical_origin']??'')===($site['canonical_origin']??'')
    && ($probe['environment']??'')===($site['environment']??'')
    && is_int($probe['observed_at']??null)
    && $probe['observed_at']>$now-300 && $probe['observed_at']<=$now+5
    && (int)($probe['tool_count']??0)>0 && (int)($probe['tool_count']??0)<=1200
    && is_string($probe['read_tool_name']??null)
    && 1===preg_match('/^[a-z][a-z0-9._-]{2,95}$/D',$probe['read_tool_name'])
    && is_string($probe['readback_sha256']??null)
    && 1===preg_match('/^[a-f0-9]{64}$/D',$probe['readback_sha256'])
    && ($probe['read_tool_verified']??null)===true
    && ($probe['oauth_used']??null)===false
    && ($probe['mutation_performed']??null)===false;
 }
 public static function builtin_candidates($candidates) {
  if(!is_array($candidates)) $candidates=array();
  if(!class_exists('MAD4B_SCP_Developer_Host_Capabilities',false))return $candidates;
  try {
   $host=MAD4B_SCP_Developer_Host_Capabilities::snapshot();
  } catch(\Throwable $error) {
   $host=array();
  }
  if(!is_array($host))$host=array();
  $candidates['local_host']=array(
   'type'=>'local_php','operations'=>array('php.lint','runtime.status.read'),
   'discovered'=>true,'configured'=>true,'reachable'=>true,
   'certified'=>($host['normal_no_network_execution_ready']??null)===true,
   'authorized'=>false,'identity_verified'=>($host['non_root_verified']??null)===true,
   'isolation_verified'=>($host['normal_no_network_execution_ready']??null)===true
  );
  $stdio_observed=self::local_stdio_read_observation();
  $external=is_array($host['external_backends']??null)?$host['external_backends']:array();
  foreach(array('isolated_vps_runner'=>'vps_stdio','ssh_host_runner'=>'ssh','isolated_docker_runner'=>'docker') as $id=>$type) {
   $row=is_array($external[$id]??null)?$external[$id]:array();
   $candidates[$id]=array(
    'type'=>$type,'operations'=>array('runtime.status.read','php.lint'),
    'discovered'=>($row['discovered']??null)===true||($id==='isolated_vps_runner'&&$stdio_observed),
    'configured'=>($row['configured']??null)===true||($id==='isolated_vps_runner'&&$stdio_observed),
    'reachable'=>($row['live_available_observed']??null)===true||($id==='isolated_vps_runner'&&$stdio_observed),
    'read_probe_observed'=>$id==='isolated_vps_runner'&&$stdio_observed,
    'certified'=>false,'authorized'=>false,'identity_verified'=>false,'isolation_verified'=>false
   );
  }
  return $candidates;
 }
 public static function register_lifecycle() {
  if(function_exists('add_filter')) add_filter('mad4b_runtime_backend_candidates',array(__CLASS__,'builtin_candidates'),10,1);
  if(function_exists('add_action')) {
   add_action('mad4b_runtime_backend_refresh',array(__CLASS__,'refresh'));
   add_action('mad4b_developer_runner_refresh',array(__CLASS__,'refresh'),50);
  }
 }
 public static function refresh() {
  // A plugin lifecycle observation, never a direct execution request.
  if(!function_exists('do_action') || !function_exists('wp_get_environment_type'))return;
  do_action('mad4b_runtime_backend_probe_due');
 }
}
