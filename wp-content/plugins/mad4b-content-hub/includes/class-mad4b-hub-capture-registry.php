<?php
defined('ABSPATH') || exit;
/** Server-side-only immutable captures. Client-submitted HTML is never proof. */
final class MAD4B_Hub_Capture_Registry {
 const CONTRACT='mad4b.hub.capture-registry.v1';
 private static $in_provider_callback=false;
 public static function table(){global $wpdb;return $wpdb->prefix.'mad4b_hub_capture_registry';}
 public static function allowed(){return wp_get_environment_type()==='staging' &&
   class_exists('MAD4B_Content_Hub')&&MAD4B_Content_Hub::permission();}
 private static function err($key,$http=409){return new WP_Error('mad4b_capture_'.$key,$key,['status'=>$http]);}
 public static function install(){
  if(!self::allowed())return self::err('not_authorized',403);
  global $wpdb;$t=self::table();require_once ABSPATH.'wp-admin/includes/upgrade.php';
  dbDelta("CREATE TABLE $t (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
record_id char(36) NOT NULL,
job_id char(36) NOT NULL,
provider_id varchar(70) NOT NULL,
source_url varchar(2048) NOT NULL,
source_html_sha256 char(64) NOT NULL DEFAULT '',
source_markdown_sha256 char(64) NOT NULL DEFAULT '',
raw_html longtext NOT NULL,
raw_markdown longtext NOT NULL,
parsed_json longtext NOT NULL,
captured_at datetime NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY record_id (record_id),
KEY job_created (job_id,created_at)
) ".$wpdb->get_charset_collate().";");
  return ['contract'=>self::CONTRACT,'ready'=>self::ready()];
 }
 public static function ready(){global $wpdb;$t=self::table();return
  $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;}
 public static function get($id,$with_raw=false){
  if(!self::allowed()||!self::ready())return self::err('not_available',403);
  if(!is_string($id)||!preg_match('/^[a-f0-9-]{36}$/D',$id))return self::err('record_id_invalid',400);
  global $wpdb;$t=self::table();
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE record_id=%s LIMIT 1",$id),ARRAY_A);
  if(!$row)return self::err('not_found',404);
  $job=MAD4B_Hub_Media_Manifest::job((string)$row['job_id']);
  if(is_wp_error($job))return $job;
  $parsed=json_decode((string)$row['parsed_json'],true);
  if(!is_array($parsed))return self::err('parsed_data_corrupt',500);
  $result=['contract'=>self::CONTRACT,'record_id'=>$id,'job_id'=>$row['job_id'],
   'provider_id'=>$row['provider_id'],'source_url'=>$row['source_url'],
   'source_html_sha256'=>$row['source_html_sha256'],
   'source_markdown_sha256'=>$row['source_markdown_sha256'],
   'captured_at'=>$row['captured_at'],
   'parsed'=>$parsed,'html_available'=>$row['raw_html']!=='',
   'server_record_exists'=>true,'rights_checked'=>false,
   'publication_authorized'=>false];
  if($with_raw){$result['raw_html']=$row['raw_html'];$result['raw_markdown']=$row['raw_markdown'];}
  return $result;
 }
 /** Private execution gate: only a real server-side provider callback can persist. */
 private static function persist($job_id,$normalized,$parsed){
  if(!self::$in_provider_callback)return self::err('trusted_provider_execution_required',403);
  $job=MAD4B_Hub_Media_Manifest::job((string)$job_id);
  if(is_wp_error($job))return $job;
  $record_id=strtolower(wp_generate_uuid4());
  $now=gmdate('Y-m-d H:i:s');
  global $wpdb;
  $ok=$wpdb->insert(self::table(),[
    'record_id'=>$record_id,'job_id'=>$job_id,
    'provider_id'=>$normalized['provider_id'],'source_url'=>$normalized['source_url'],
    'source_html_sha256'=>$normalized['source_html_sha256'],
    'source_markdown_sha256'=>$normalized['source_markdown_sha256'],
    'raw_html'=>$normalized['html'],'raw_markdown'=>$normalized['markdown'],
    'parsed_json'=>wp_json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    'captured_at'=>$now,'created_at'=>$now
  ]);
  if($ok!==1)return self::err('insert_failed',500);
  return ['contract'=>self::CONTRACT,'record_id'=>$record_id,'job_id'=>$job_id,
   'provider_id'=>$normalized['provider_id'],
   'source_html_sha256'=>$normalized['source_html_sha256'],
   'image_count'=>$parsed['image_count'],
   'html_verified_on_server'=>$normalized['html']!=='',
   'rights_checked'=>false,'publication_authorized'=>false];
 }
 /**
  * Captures only the exact current Staging homepage through the stored
  * native Firecrawl profile. Never accepts supplied html or provider results.
  */
 public static function run_owned($input=[]){
  if(!self::allowed())return self::err('not_authorized',403);
  if(!self::ready())return self::err('schema_missing',503);
  if(!is_array($input))return self::err('input_invalid',400);
  $job_id=(string)($input['job_id']??'');
  $job=MAD4B_Hub_Media_Manifest::job($job_id);
  if(is_wp_error($job))return $job;
  $source=home_url('/');
  // Avoid amplifying the current high-load VPS through extra WP/HTTP workers.
  if(class_exists('MAD4B_Hub_Adaptive_Concurrency')){
   $capacity=MAD4B_Hub_Adaptive_Concurrency::snapshot();
   if((int)($capacity['target']??0)<1)return self::err('adaptive_backpressure',429);
  }
  if(!class_exists('MAD4B_SCP_Firecrawl_Provider'))return self::err('provider_missing',503);
  $response=MAD4B_SCP_Firecrawl_Provider::scrape_owned_source($source,true);
  if(is_wp_error($response))return $response;
  if(empty($response['network_executed'])||($response['provider']??'')!=='firecrawl'||
    ($response['source_url']??'')!==$source)return self::err('provider_origin_mismatch',409);
  $normalized=MAD4B_Hub_Capture_Parser::normalize('firecrawl',$response,$source);
  if(is_wp_error($normalized))return $normalized;
  if($normalized['source_markdown_sha256']!==($response['source_sha256']??''))
    return self::err('provider_content_hash_mismatch');
  $parsed=MAD4B_Hub_Capture_Parser::parse($normalized);
  if(is_wp_error($parsed))return $parsed;
  self::$in_provider_callback=true;
  try{$stored=self::persist($job_id,$normalized,$parsed);}
  finally{self::$in_provider_callback=false;}
  if(is_wp_error($stored))return $stored;
  $version=(string)($input['content_version_sha256']??'');
  if($version!==''){
   $handoff=self::handoff(['record_id'=>$stored['record_id'],
    'content_version_sha256'=>$version]);
   $stored['manifest_handoff']=is_wp_error($handoff)
    ?['state'=>'blocked','reason'=>$handoff->get_error_code()]:$handoff;
  }
  return $stored;
 }
 /** Hand a server-verified capture to Content Hub as NON-PUBLISHABLE
  * image candidates. No rights determination, no media download. */
 public static function handoff($input=[]){
  if(!self::allowed())return self::err('not_authorized',403);
  $receipt=self::get((string)($input['record_id']??''),true);
  if(is_wp_error($receipt))return $receipt;
  if(!$receipt['html_available'] ||
    !hash_equals(hash('sha256',(string)$receipt['raw_html']),(string)$receipt['source_html_sha256']))
    return self::err('raw_capture_not_verified',409);
  $version=(string)($input['content_version_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$version))return self::err('content_version_required',400);
  $items=[];
  foreach(array_slice((array)$receipt['parsed']['images'],0,45) as $image){
    if(empty($image['source_url']))continue;
    $items[]=['kind'=>'scraped_candidate','role'=>(string)$image['role'],
      'source_url'=>(string)$image['source_url'],
      'capture_record_id'=>$receipt['record_id']];
  }
  $input=['job_id'=>$receipt['job_id'],
    'content_version_sha256'=>$version,'items'=>$items];
  $plan=MAD4B_Hub_Media_Manifest::plan($input);
  if(is_wp_error($plan))return $plan;
  if($plan['existing_revision']>0 &&
     hash_equals((string)$plan['stored_manifest_sha256'],(string)$plan['manifest_sha256']))
    return ['contract'=>self::CONTRACT,'state'=>'already_handed_off',
      'media_count'=>count($items),'mutation_performed'=>false];
  $result=MAD4B_Hub_Media_Manifest::apply(array_merge($input,[
    'expected_revision'=>$plan['existing_revision'],
    'expected_manifest_sha256'=>$plan['manifest_sha256']]));
  if(is_wp_error($result))return $result;
  return ['contract'=>self::CONTRACT,'state'=>'hub_candidates_stored',
    'manifest_sha256'=>$result['manifest_sha256'],
    'media_count'=>count($items),
    'capture_verified'=>true,'rights_checked'=>false,
    'publication_authorized'=>false,'mutation_performed'=>true];
 }
 /** Verify exact stored bytes, URL and hash. Markdown-only records cannot
  * satisfy the image-context HTML verification requirement. */
 public static function verify($current,$request){
  if(!self::allowed()||!is_array($request))return $current;
  $id=(string)($request['record_id']??'');
  $found=self::get($id,true);
  if(is_wp_error($found))return $current;
  $html=(string)($found['raw_html']??'');
  if($html===''||
    !hash_equals((string)($request['source_page_url']??''),(string)$found['source_url'])||
    !hash_equals((string)($request['source_html_sha256']??''),(string)$found['source_html_sha256'])||
    !hash_equals(hash('sha256',$html),(string)$found['source_html_sha256']))return $current;
  return ['verified'=>true,'record_id'=>$id,'provider_id'=>$found['provider_id'],
   'source_page_url'=>$found['source_url'],
   'source_html_sha256'=>$found['source_html_sha256']];
 }
 public static function boot(){
  add_filter('mad4b_hub_scraped_media_verify_capture',[__CLASS__,'verify'],10,2);
  add_action('wp_abilities_api_init',function(){
   if(!function_exists('wp_register_ability'))return;
   foreach(['mad4b/hub-capture-read'=>true,'mad4b/hub-capture-run-owned'=>false] as $name=>$readonly){
    if(wp_has_ability($name))continue;
    wp_register_ability($name,[
     'label'=>$readonly?'Read Server Capture':'Capture Owned Staging Homepage',
     'description'=>'Exact central Staging source evidence, no publication or rights assertion',
     'category'=>$readonly?'mad4b-read':'mad4b-write',
     'execute_callback'=>static function($args)use($readonly){
      $args=is_array($args)?$args:[];
      return $readonly?self::get((string)($args['record_id']??'')):self::run_owned($args);
     },
     'permission_callback'=>[__CLASS__,'allowed'],
     'input_schema'=>['type'=>'object','properties'=>[
       'record_id'=>['type'=>'string'],'job_id'=>['type'=>'string'],'content_version_sha256'=>['type'=>'string']
     ],'additionalProperties'=>false],
     'output_schema'=>['type'=>'object','additionalProperties'=>true],
     'meta'=>['public'=>false,'show_in_rest'=>false,
       'mcp'=>['public'=>false,'type'=>'tool',
         'surface'=>$readonly?'read':'write']]
    ]);
   }
  },65);
 }
}
