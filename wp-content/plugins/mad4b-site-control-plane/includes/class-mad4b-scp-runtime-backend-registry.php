<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Portable, fail-closed runtime backend catalog. Discovery never grants execution.
 * Providers can register additional candidates; execution belongs to separate
 * signed, authorized adapters, not this catalog.
 */
final class MAD4B_SCP_Runtime_Backend_Registry {
 const CONTRACT = 'mad4b.runtime-backend-registry.v1';
 const TYPES = array('local_php','docker','ssh','https_runner','queue_worker','custom');
 const PRESETS = array(
  'shared_hosting'=>array('local_php','https_runner','queue_worker'),
  'managed_wordpress'=>array('https_runner','queue_worker','local_php'),
  'vps'=>array('docker','local_php','ssh','https_runner','queue_worker'),
  'containerized'=>array('docker','https_runner','queue_worker'),
  'external_runner'=>array('https_runner','queue_worker','ssh'),
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
  if(!function_exists('current_user_can')||!current_user_can('manage_options')||!function_exists('update_option'))return false;
  if(!isset(self::PRESETS[$name]))return false;
  if($reset) {
   if(!function_exists('delete_option'))return false;
   return delete_option('mad4b_runtime_backend_preset_custom_'.$name);
  }
  $valid=self::normalize_preset($types);
  if($valid===null)return false;
  return update_option('mad4b_runtime_backend_preset_custom_'.$name,$valid,false);
 }
 public static function sanitize_candidates($candidates) {
  $out=array();
  if (!is_array($candidates)) return $out;
  foreach(array_slice($candidates,0,64) as $id=>$v) {
   if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$id) || !is_array($v)) continue;
   $type=$v['type']??'';
   if (!in_array($type,self::TYPES,true)) continue;
   $ops=array_values(array_unique(array_filter(array_map('strval',(array)($v['operations']??array())),static function($op){return strlen($op)<=96 && preg_match('/^[a-z][a-z0-9._-]{2,95}$/D',$op);})));
   $out[$id]=array(
    'type'=>$type,'operations'=>array_slice($ops,0,48),
    'discovered'=>true===($v['discovered']??false),
    'configured'=>true===($v['configured']??false),
    'reachable'=>true===($v['reachable']??false),
    'certified'=>true===($v['certified']??false),
    'authorized'=>true===($v['authorized']??false),
    'isolation_verified'=>true===($v['isolation_verified']??false),
    'identity_verified'=>true===($v['identity_verified']??false),
    'last_probe_at'=>max(0,(int)($v['last_probe_at']??0)),
    'backoff_until'=>max(0,(int)($v['backoff_until']??0)),
    'latency_ms'=>max(0,min(60000,(int)($v['latency_ms']??60000))),
   );
  }
  ksort($out,SORT_STRING);
  return $out;
 }
 public static function ready($v,$operation,$now=null) {
  $now=$now??time();
  return is_array($v) && in_array($operation,$v['operations']??array(),true)
   && !empty($v['discovered']) && !empty($v['configured']) && !empty($v['reachable'])
   && !empty($v['certified']) && !empty($v['authorized'])
   && !empty($v['identity_verified']) && !empty($v['isolation_verified'])
   && (int)($v['backoff_until']??0)<=$now;
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
   'operations'=>$r['operations'],'backoff_active'=>$r['backoff_until']>time()
  );
  return array('contract'=>self::CONTRACT,'preset'=>isset(self::PRESETS[$preset])?$preset:'shared_hosting',
    'backends'=>$public,'read_only'=>true,'mutation_performed'=>false,'authorization_granted'=>false);
 }
 public static function observe() {
  $candidates=array();
  if(function_exists('apply_filters')) $candidates=apply_filters('mad4b_runtime_backend_candidates',array());
  $preset=function_exists('get_option')?(string)get_option('mad4b_runtime_backend_preset','shared_hosting'):'shared_hosting';
  return self::snapshot($candidates,$preset);
 }
 public static function builtin_candidates($candidates) {
  if(!is_array($candidates)) $candidates=array();
  if(!class_exists('MAD4B_SCP_Developer_Host_Capabilities',false))return $candidates;
  $host=MAD4B_SCP_Developer_Host_Capabilities::snapshot();
  $candidates['local_host']=array(
   'type'=>'local_php','operations'=>array('php.lint','runtime.status.read'),
   'discovered'=>true,'configured'=>true,'reachable'=>true,
   'certified'=>!empty($host['normal_no_network_execution_ready']),
   'authorized'=>false,'identity_verified'=>!empty($host['non_root_verified']),
   'isolation_verified'=>!empty($host['normal_no_network_execution_ready'])
  );
  foreach(array('isolated_vps_runner'=>'https_runner','ssh_host_runner'=>'ssh') as $id=>$type) {
   $row=$host['external_backends'][$id]??array();
   $candidates[$id]=array(
    'type'=>$type,'operations'=>array('runtime.status.read','php.lint'),
    'discovered'=>!empty($row['discovered']),'configured'=>!empty($row['configured']),
    'reachable'=>!empty($row['live_available_observed']),
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
