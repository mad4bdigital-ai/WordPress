<?php
if (!defined('ABSPATH')) exit;
/** WPML-like research grouping: group_id (TRID analogue) plus language/provider records. */
final class MAD4B_SCP_Research_Groups {
 const VERSION='mad4b.research-groups.v1';
 private static function tables(){global $wpdb;return array($wpdb->prefix.'mad4b_research_groups',$wpdb->prefix.'mad4b_research_records');}
 private static function allowed(){return wp_get_environment_type()==='staging' && current_user_can('manage_options');}
 public static function install(){
  if(!self::allowed()) return new WP_Error('mad4b_research_scope_denied','Staging administrator required.');
  global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';
  list($g,$r)=self::tables();$c=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $g (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   group_uid varchar(64) NOT NULL,
   task_key varchar(128) NOT NULL,
   site_uuid varchar(64) NOT NULL,
   source_locale varchar(12) NOT NULL DEFAULT 'en',
   created_by bigint(20) unsigned NOT NULL,
   created_at datetime NOT NULL,
   PRIMARY KEY  (id),
   UNIQUE KEY group_uid (group_uid),
   UNIQUE KEY task_scope (site_uuid,task_key)
  ) $c;");
  dbDelta("CREATE TABLE $r (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   group_id bigint(20) unsigned NOT NULL,
   record_uid varchar(64) NOT NULL,
   provider_id varchar(64) NOT NULL,
   locale varchar(12) NOT NULL,
   source_url varchar(2048) NOT NULL,
   source_sha256 char(64) NOT NULL,
   data_json longtext NOT NULL,
   captured_at datetime NOT NULL,
   PRIMARY KEY  (id),
   UNIQUE KEY record_uid (record_uid),
   UNIQUE KEY group_evidence (group_id,source_sha256,locale,provider_id),
   KEY group_id (group_id)
  ) $c;");
  return array('contract'=>self::VERSION,'tables'=>array($g,$r));
 }
 public static function group($task_key,$site_uuid,$locale='en'){
  if(!self::allowed()) return new WP_Error('mad4b_research_scope_denied','Staging administrator required.');
  $task_key=(string)$task_key;$site_uuid=(string)$site_uuid;
  if(!preg_match('/^[a-zA-Z0-9._:-]{8,128}$/',$task_key)||!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$site_uuid))return new WP_Error('mad4b_research_identity_invalid','Invalid task/site identity.');
  global $wpdb;list($g,)=self::tables();
  $found=$wpdb->get_row($wpdb->prepare("SELECT * FROM $g WHERE task_key=%s AND site_uuid=%s",$task_key,$site_uuid),ARRAY_A);
  if($found)return $found;
  $uid='rg_'.substr(hash('sha256',$site_uuid.'|'.$task_key),0,40);
  $ok=$wpdb->insert($g,array('group_uid'=>$uid,'task_key'=>$task_key,'site_uuid'=>$site_uuid,'source_locale'=>sanitize_key($locale),'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)));
  if(!$ok)return new WP_Error('mad4b_research_group_write_failed','Group creation failed.');
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM $g WHERE group_uid=%s",$uid),ARRAY_A);
 }
 public static function add_record($group_id,$provider,$locale,$url,$data){
  if(!self::allowed())return new WP_Error('mad4b_research_scope_denied','Staging administrator required.');
  global $wpdb;list($g,$r)=self::tables();$group_id=absint($group_id);
  if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $g WHERE id=%d",$group_id)))return new WP_Error('mad4b_research_group_missing','Group not found.');
  if(!is_array($data)||strlen(wp_json_encode($data))>65536||!in_array(sanitize_key($provider),array('firecrawl','serph_api','manual_research'),true)||!preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/i',$locale)||strlen($url)>2048||!wp_http_validate_url($url))return new WP_Error('mad4b_research_record_invalid','Unsupported research record.');
  $provider=sanitize_key($provider);$locale=strtolower(str_replace('_','-',$locale));$sha=hash('sha256',$url.'|'.wp_json_encode($data));
  $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $r WHERE group_id=%d AND source_sha256=%s AND locale=%s AND provider_id=%s",$group_id,$sha,$locale,$provider),ARRAY_A);
  if($existing)return $existing;
  $uid='rr_'.substr(hash('sha256',$group_id.'|'.$provider.'|'.$locale.'|'.$sha),0,40);
  if(!$wpdb->insert($r,array('group_id'=>$group_id,'record_uid'=>$uid,'provider_id'=>$provider,'locale'=>$locale,'source_url'=>esc_url_raw($url),'source_sha256'=>$sha,'data_json'=>wp_json_encode($data),'captured_at'=>current_time('mysql',true))))return new WP_Error('mad4b_research_record_write_failed','Research record insertion failed.');
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM $r WHERE record_uid=%s",$uid),ARRAY_A);
 }
 public static function records($group_id){
  if(!self::allowed())return new WP_Error('mad4b_research_scope_denied','Staging administrator required.');
  global $wpdb;list(,$r)=self::tables();
  return $wpdb->get_results($wpdb->prepare("SELECT id,group_id,record_uid,provider_id,locale,source_url,source_sha256,captured_at FROM $r WHERE group_id=%d ORDER BY id ASC LIMIT 100",absint($group_id)),ARRAY_A);
 }
}
