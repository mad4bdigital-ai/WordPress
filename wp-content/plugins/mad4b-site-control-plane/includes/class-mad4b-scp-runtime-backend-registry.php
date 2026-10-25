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
  $order=self::default_preset($preset);
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
 public static function register_lifecycle() {
  if(function_exists('add_action')) add_action('mad4b_runtime_backend_refresh',array(__CLASS__,'refresh'));
 }
 public static function refresh() {
  // A plugin lifecycle observation, never a direct execution request.
  if(!function_exists('do_action') || !function_exists('wp_get_environment_type'))return;
  do_action('mad4b_runtime_backend_probe_due');
 }
}
