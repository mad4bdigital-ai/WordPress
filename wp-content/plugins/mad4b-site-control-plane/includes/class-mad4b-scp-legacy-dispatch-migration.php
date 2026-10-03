<?php
if(!defined('ABSPATH')){exit;}
/** Deny-only migration telemetry for legacy fixed-dispatch callers without signed preparation. */
final class MAD4B_SCP_Legacy_Dispatch_Migration{
 const CONTRACT='mad4b.legacy-dispatch-migration.v1';const ERROR_CONTRACT='mad4b.dispatch-preparation-required.v2';const CONFIG_FILE='config/legacy-dispatch-migration.json';private static $config=null;
 public static function clear_cache(){self::$config=null;}
 public static function deny($lane,$reason_code,$ability_name=''){
  $c=self::config();if(is_wp_error($c))return new WP_Error('mad4b_dispatch_migration_policy_unavailable','Legacy dispatcher migration policy is unavailable; execution remains denied.',array('authorizing'=>false));
  $lane=sanitize_key((string)$lane);if(''===$lane)$lane='fixed_dispatch';$reason_code=sanitize_key((string)$reason_code);if(''===$reason_code)$reason_code='unprepared';
  $metric='legacy_dispatch.'.$lane.'.denied';$recorded=false;if(class_exists('MAD4B_SCP_Runtime_Metrics')&&method_exists('MAD4B_SCP_Runtime_Metrics','record')){$r=MAD4B_SCP_Runtime_Metrics::record($metric,1);$recorded=!is_wp_error($r)&&true===$r;}
  return new WP_Error('mad4b_dispatch_preparation_required','Prepare the target again and supply its exact signed preparation identity.',array(
   'contract'=>self::CONTRACT,'error_contract'=>self::ERROR_CONTRACT,'migration_state'=>(string)$c['current_state'],'lane'=>$lane,'reason_code'=>$reason_code,
   'ability_name_sha256'=>''!==(string)$ability_name?hash('sha256',(string)$ability_name):'','required_action'=>'rediscover_and_prepare_signed_identity',
   'legacy_execution_allowed'=>false,'signed_preparation_required'=>true,'sunset_gates'=>$c['sunset_gates'],'telemetry_metric'=>$metric,'telemetry_recorded'=>$recorded,'authorizing'=>false
  ));
 }
 public static function status(){$c=self::config();return array('contract'=>self::CONTRACT,'error_contract'=>self::ERROR_CONTRACT,'ready'=>!is_wp_error($c),'blocker'=>is_wp_error($c)?$c->get_error_code():'','current_state'=>is_wp_error($c)?'unavailable':(string)$c['current_state'],'legacy_execution_allowed'=>false,'signed_preparation_required'=>true,'sunset_gates'=>is_wp_error($c)?array():(array)$c['sunset_gates'],'authorizing'=>false);}
 private static function config(){
  if(null!==self::$config)return self::$config;$root=defined('MAD4B_SCP_DIR')?MAD4B_SCP_DIR:dirname(__DIR__).'/';$path=$root.self::CONFIG_FILE;
  if(!is_readable($path))return self::$config=new WP_Error('mad4b_legacy_dispatch_migration_config_missing','Legacy dispatcher migration configuration is unavailable.');
  $d=json_decode((string)file_get_contents($path),true);if(!is_array($d)||self::CONTRACT!==(isset($d['contract'])?(string)$d['contract']:'')||self::ERROR_CONTRACT!==(isset($d['error_contract'])?(string)$d['error_contract']:'')||'deny_unprepared'!==(isset($d['current_state'])?(string)$d['current_state']:''))return self::$config=new WP_Error('mad4b_legacy_dispatch_migration_config_invalid','Legacy dispatcher migration configuration is invalid.');
  $g=isset($d['sunset_gates'])&&is_array($d['sunset_gates'])?$d['sunset_gates']:array();if(empty($g['signed_preparation_required'])||!array_key_exists('schema_only_legacy_admission',$g)||false!==$g['schema_only_legacy_admission']||!array_key_exists('compatibility_bypass_allowed',$g)||false!==$g['compatibility_bypass_allowed']||empty($g['explicit_removal_review_required']))return self::$config=new WP_Error('mad4b_legacy_dispatch_migration_config_invalid','Legacy dispatcher sunset gates are invalid.');return self::$config=$d;
 }
}
