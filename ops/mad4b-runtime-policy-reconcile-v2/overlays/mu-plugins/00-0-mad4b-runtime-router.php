<?php
/**
 * Plugin Name: MAD4B Modular Runtime Bootstrap
 * Description: Ordered early loader for source-live modular platform/commercial runtime.
 */
defined('ABSPATH')||exit;
if(defined('MAD4B_MU_MODULAR_LOADER_BOOTED'))return;
define('MAD4B_MU_MODULAR_LOADER_BOOTED',true);
$mad4b_file=WP_CONTENT_DIR.'/plugins/mad4b-platform/config/ordered-mu-map.php';
if(!is_file($mad4b_file))throw new RuntimeException('MAD4B_MODULE_MAP_NOT_INSTALLED');
$mad4b_ordered=require $mad4b_file;
if(!is_array($mad4b_ordered)||!$mad4b_ordered)
 throw new RuntimeException('MAD4B_SOURCE_MODULE_MAP_INVALID');
require_once WP_CONTENT_DIR.'/plugins/mad4b-platform/includes/class-mad4b-mu-map-contract.php';
if (!MAD4B_MU_Map_Contract_V1::current($mad4b_ordered))
 throw new RuntimeException('MAD4B_MU_MAP_INTEGRITY_MISMATCH');
// Resolves original inter-module PHP dependencies by their exact recorded name.
// Original MU modules remain the source until individually retired.
final class MAD4B_MU_File_Resolver_V1 {
 private static array $index=[];
 public static function init(array $ordered):void {
  foreach($ordered as $item){
   if(!is_array($item)||count($item)!==2)throw new RuntimeException('MU_RESOLVER_ENTRY_INVALID');
   $name=(string)$item[0];$path=$item[1];
   if(isset(self::$index[$name]))throw new RuntimeException('MU_RESOLVER_DUPLICATE');
   self::$index[$name]=$path;
  }
 }
 public static function path(string $name):string {
  if(!array_key_exists($name,self::$index))throw new RuntimeException('UNKNOWN_ORIGINAL_MODULE_NAME');
  $old=WPMU_PLUGIN_DIR.'/'.$name;
  if(is_file($old))return $old;
  $relative=self::$index[$name];
  if(!is_string($relative)||!preg_match('~^mad4b-(?:commerce|platform)/modules/[a-z-]+/[a-zA-Z0-9._-]+\.php$~D',$relative))
   throw new RuntimeException('MODULE_ORIGINAL_PATH_NOT_MAPPED');
  $candidate=WP_CONTENT_DIR.'/plugins/'.$relative;
  if(!is_file($candidate))throw new RuntimeException('MODULE_DEPENDENCY_MISSING');
  return $candidate;
 }
}
MAD4B_MU_File_Resolver_V1::init($mad4b_ordered);
// Fast anonymous public shells never bootstrap the signed central Broker/SSH
// execution lane. REST, admin, authenticated, cron, CLI and all other routes
// keep the exact complete original 377-entry ordered map.
$mad4b_public_control_slice = (
    (!defined('WP_CLI') || !WP_CLI)
    && (($_SERVER['REQUEST_METHOD']??'GET')==='GET' || ($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')
    && empty($_SERVER['QUERY_STRING'])
    && empty($_SERVER['HTTP_AUTHORIZATION'])
    && !preg_match('/(?:wordpress_logged_in_|wordpress_sec_|wp_woocommerce_session_)/', (string)($_SERVER['HTTP_COOKIE']??''))
    && preg_match('~^/(?:ar/|fr/|de/|es/|it/)?(?:tours-and-activities/[-A-Za-z0-9_%]{1,200}/)?$~D', (string)($_SERVER['REQUEST_URI']??''))===1
);
$mad4b_public_defer = [
    '305-mad4b-central-ssh-command-intake.php'=>true,
    '305-mad4b-original-sealed-rebind.php'=>true,
    '306-mad4b-central-vps-broker.php'=>true,
    '306-mad4b-original-core-recovery.php'=>true,
    '307-mad4b-vps-broker-operator-canary.php'=>true,
    '308-mad4b-vps-broker-diagnostics.php'=>true,
    '309-mad4b-vps-broker-journal.php'=>true,
    '310-mad4b-vps-broker-diagnostic-execute.php'=>true,
    '311-mad4b-broker-registry-ssh-status.php'=>true,
    '312-mad4b-broker-exact-ssh-authority.php'=>true,
    '313-mad4b-broker-remote-ssh-diagnostic.php'=>true,
    '314-mad4b-broker-general-shell-receipt.php'=>true,
    '324-mad4b-broker-late-native-reconciliation.php'=>true,
    '327-mad4b-company-native-host-proof.php'=>true,
];
foreach($mad4b_ordered as $mad4b_item){
 if(!is_array($mad4b_item)||count($mad4b_item)!==2)throw new RuntimeException('MAD4B_MODULE_RECORD_INVALID');
 $mad4b_name=$mad4b_item[0];$mad4b_relative=$mad4b_item[1];
 if($mad4b_public_control_slice && isset($mad4b_public_defer[$mad4b_name]))continue;
 if(!is_string($mad4b_name)||!preg_match('/^[a-zA-Z0-9._-]+\.php$/D',$mad4b_name))
  throw new RuntimeException('MAD4B_MODULE_BASENAME_INVALID');
 if($mad4b_relative===null){
  $mad4b_path=WPMU_PLUGIN_DIR.'/'.$mad4b_name;
 }else{
  if(!is_string($mad4b_relative)||!preg_match('~^mad4b-(?:commerce|platform)/modules/[a-z-]+/[a-zA-Z0-9._-]+\.php$~D',$mad4b_relative))
   throw new RuntimeException('MAD4B_PLUGIN_MODULE_PATH_INVALID');
  // Zero-downtime migration: prefer the original, byte-verified MU file
  // until the release executor atomically retires that exact filename.
  $mad4b_original=WPMU_PLUGIN_DIR.'/'.$mad4b_name;
  $mad4b_path=is_file($mad4b_original)?$mad4b_original:WP_CONTENT_DIR.'/plugins/'.$mad4b_relative;
 }
 if(!is_file($mad4b_path))throw new RuntimeException('MAD4B_ORDERED_MODULE_MISSING:'.$mad4b_name);
 require_once $mad4b_path;
}
unset($mad4b_file,$mad4b_ordered,$mad4b_item,$mad4b_name,$mad4b_relative,$mad4b_path,$mad4b_original,$mad4b_public_control_slice,$mad4b_public_defer);
