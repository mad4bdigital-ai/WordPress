<?php
defined('ABSPATH')||exit;
/** Isolated per-page crawl results; never substitute wp_posts or supersede ContentJob artifacts. */
final class MAD4B_SCP_Firecrawl_Page_Store {
 private static function enrolled_write_site():bool {
  if(!class_exists('MAD4B_SCP_Site_Profile',false))return false;
  $s=MAD4B_SCP_Site_Profile::status();
  return is_array($s) && ($s['configured']??false)===true
   && ($s['origin_match']??false)===true
   && ($s['deployment_binding_match']??false)===true
   && ($s['environment_match']??false)===true
   && ($s['write_enabled']??false)===true;
 }

 public static function table(){
  global $wpdb;
  return $wpdb->prefix.'mad4b_firecrawl_crawl_pages_v1';
 }
 public static function ensure_schema(){
  if(!self::enrolled_write_site())return false;
  global $wpdb;
  $table=self::table();
  $existing=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
  if($existing===$table)return true;
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  $charset=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $table (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    site_uuid varchar(36) NOT NULL,
    crawl_id varchar(72) NOT NULL,
    job_id varchar(36) NOT NULL DEFAULT '',
    source_sha256 char(64) NOT NULL,
    source_url text NOT NULL,
    content_sha256 char(64) NOT NULL,
    markdown longtext NOT NULL,
    metadata_json longtext NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY unique_crawl_page_version (site_uuid,crawl_id,source_sha256,content_sha256),
    KEY site_crawl (site_uuid,crawl_id),
    KEY job_crawl (job_id,crawl_id)
   ) $charset;");
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
 }
 public static function save_pages($crawl_id,$seed_url,$pages,$internal=false){
  $poll_authorized=$internal===true && doing_action('mad4b_firecrawl_crawl_poll_v1');
  if(!self::enrolled_write_site()||
    (!current_user_can('manage_options') && !$poll_authorized))
   return new WP_Error('mad4b_crawl_pages_admin_required');
  $runs=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(!is_array($runs)||!isset($runs[$crawl_id])||
    !hash_equals((string)$runs[$crawl_id]['source_url'],(string)$seed_url))
   return new WP_Error('mad4b_crawl_pages_unowned');
  if(!is_array($pages))return new WP_Error('mad4b_crawl_pages_invalid');
  if(!self::ensure_schema())return new WP_Error('mad4b_crawl_page_schema_unavailable');
  global $wpdb;
  $table=self::table();
  $seedhost=strtolower((string)wp_parse_url($seed_url,PHP_URL_HOST));
  $uuid=class_exists('MAD4B_SCP_Site_Profile')?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
  if($uuid==='')return new WP_Error('mad4b_crawl_page_site_unavailable');
  $job_id=(string)($runs[$crawl_id]['job_id']??'');
  if($job_id!==''){
   $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
   if(is_wp_error($job))return $job;
  }
  $run_mode=(string)($runs[$crawl_id]['mode']??'');
  $section='/'.trim((string)wp_parse_url($seed_url,PHP_URL_PATH),'/').'/';
  $stored=0;$skipped=0;$duplicates=0;
  foreach($pages as $entry){
   if(!is_array($entry)){$skipped++;continue;}
   $metadata=is_array($entry['metadata']??null)?$entry['metadata']:[];
   $source=(string)($metadata['sourceURL']??$metadata['url']??'');
   $text=(string)($entry['markdown']??'');
   if(!str_starts_with($source,'https://')||!wp_http_validate_url($source)||
      strtolower((string)wp_parse_url($source,PHP_URL_HOST))!==$seedhost||
      ($run_mode==='section_crawl' && !str_starts_with(
       '/'.ltrim((string)wp_parse_url($source,PHP_URL_PATH),'/'),$section)) ||
      trim($text)===''){
    $skipped++;continue;
   }
   // Preserve a whole page or report a failure; never silently truncate evidence.
   if(strlen($text)>2000000)return new WP_Error('mad4b_crawl_page_too_large');
   $sourcehash=hash('sha256',$source);
   $meta=wp_json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   $contenthash=hash('sha256',$text);
   $exists=$wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $table WHERE site_uuid=%s AND crawl_id=%s AND source_sha256=%s AND content_sha256=%s LIMIT 1",
    $uuid,$crawl_id,$sourcehash,$contenthash));
   if($exists){$duplicates++;continue;}
   $ok=$wpdb->insert($table,[
    'site_uuid'=>$uuid,'crawl_id'=>$crawl_id,'job_id'=>$job_id,
    'source_sha256'=>$sourcehash,'source_url'=>$source,
    'content_sha256'=>$contenthash,
    'markdown'=>$text,'metadata_json'=>(string)$meta,
    'updated_at'=>gmdate('Y-m-d H:i:s')
   ],['%s','%s','%s','%s','%s','%s','%s','%s','%s']);
   if($ok===false){
    $collision=$wpdb->get_var($wpdb->prepare(
     "SELECT id FROM $table WHERE site_uuid=%s AND crawl_id=%s AND source_sha256=%s AND content_sha256=%s LIMIT 1",
     $uuid,$crawl_id,$sourcehash,$contenthash));
    if($collision){$duplicates++;continue;}
    return new WP_Error('mad4b_crawl_page_persist_failed');
   }
   $stored++;
  }
  return ['contract'=>'mad4b.firecrawl.crawl-pages.v1',
   'crawl_id'=>$crawl_id,'job_id'=>$job_id,'stored_in_batch'=>$stored,
   'duplicate_pages'=>$duplicates,'skipped_invalid'=>$skipped,'site_uuid'=>$uuid,
   'source_host'=>$seedhost,'content_job_artifacts_modified'=>false];
 }
 public static function list_by_job($job_id,$offset=0,$page_size=100){
  if(!current_user_can('manage_options')||!wp_is_uuid($job_id)||!self::ensure_schema())
   return new WP_Error('mad4b_crawl_contenthub_inventory_denied');
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
  if(is_wp_error($job))return $job;
  global $wpdb;
  $table=self::table();$uuid=(string)MAD4B_SCP_Site_Profile::site_uuid();
  $offset=max(0,(int)$offset);$page_size=max(1,min(200,(int)$page_size));
  $rows=$wpdb->get_results($wpdb->prepare(
   "SELECT id,crawl_id,source_url,source_sha256,content_sha256,updated_at FROM $table
    WHERE site_uuid=%s AND job_id=%s ORDER BY id ASC LIMIT %d OFFSET %d",
    $uuid,$job_id,$page_size,$offset),ARRAY_A);
  $count=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM $table WHERE site_uuid=%s AND job_id=%s",
   $uuid,$job_id));
  return ['contract'=>'mad4b.firecrawl.contenthub-page-inventory.v1',
   'job_id'=>$job_id,'count'=>$count,'offset'=>$offset,
   'has_more'=>($offset+count($rows))<$count,'pages'=>$rows,
   'review_only'=>true,'published'=>false,'markdown_exposed'=>false];
 }
 public static function read_by_job($job_id,$page_id){
  if(!current_user_can('manage_options')||!wp_is_uuid($job_id)||
   (int)$page_id<1||!self::ensure_schema())
   return new WP_Error('mad4b_crawl_page_read_denied');
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
  if(is_wp_error($job))return $job;
  global $wpdb;
  $table=self::table();$uuid=(string)MAD4B_SCP_Site_Profile::site_uuid();
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT id,crawl_id,source_url,content_sha256,markdown,updated_at FROM $table
   WHERE site_uuid=%s AND job_id=%s AND id=%d LIMIT 1",
   $uuid,$job_id,(int)$page_id),ARRAY_A);
  if(!is_array($row))return new WP_Error('mad4b_crawl_page_missing');
  if(!hash_equals((string)$row['content_sha256'],hash('sha256',(string)$row['markdown'])))
   return new WP_Error('mad4b_crawl_page_integrity_failed');
  return ['contract'=>'mad4b.firecrawl.contenthub-page-read.v1',
   'page'=>$row,'review_only'=>true,'published'=>false];
 }
 public static function summary($crawl_id,$internal=false){
  $poll_authorized=$internal===true && doing_action('mad4b_firecrawl_crawl_poll_v1');
  if((!current_user_can('manage_options') && !$poll_authorized)||!self::ensure_schema())
   return new WP_Error('mad4b_crawl_page_store_unavailable');
  $runs=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(!is_array($runs)||!isset($runs[$crawl_id]))
   return new WP_Error('mad4b_crawl_page_unowned');
  global $wpdb;
  $table=self::table();
  $uuid=(string)MAD4B_SCP_Site_Profile::site_uuid();
  $n=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM $table WHERE site_uuid=%s AND crawl_id=%s",$uuid,$crawl_id));
  return ['crawl_id'=>$crawl_id,'job_id'=>(string)($runs[$crawl_id]['job_id']??''),
   'stored_page_versions'=>$n,'site_uuid'=>$uuid,
   'content_job_artifacts_modified'=>false];
 }
}
