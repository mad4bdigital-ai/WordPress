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
foreach($mad4b_ordered as $mad4b_item){
 if(!is_array($mad4b_item)||count($mad4b_item)!==2)throw new RuntimeException('MAD4B_MODULE_RECORD_INVALID');
 $mad4b_name=$mad4b_item[0];$mad4b_relative=$mad4b_item[1];
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
unset($mad4b_file,$mad4b_ordered,$mad4b_item,$mad4b_name,$mad4b_relative,$mad4b_path,$mad4b_original);
