<?php
defined('ABSPATH') || exit;
/** Media identity belongs to the central job/version, NOT to a WordPress post. */
final class MAD4B_Hub_Media_Manifest {
 const CONTRACT='mad4b.hub.media-manifest.v1';
 const MAX_ITEMS=45;
 static function table(){global $wpdb;return $wpdb->prefix.'mad4b_hub_media_manifests';}
 static function allowed(){return class_exists('MAD4B_Content_Hub')&&MAD4B_Content_Hub::permission();}
 static function error($code,$http=409){return new WP_Error('mad4b_manifest_'.$code,$code,['status'=>$http]);}
 static function install(){
  if(!self::allowed())return self::error('authority_required',403);
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  global $wpdb;$t=self::table();$collate=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $t (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id char(36) NOT NULL,
site_uuid char(36) NOT NULL,
brand_id varchar(191) NOT NULL,
record_key char(64) NOT NULL DEFAULT '',
content_version_sha256 char(64) NOT NULL,
manifest_sha256 char(64) NOT NULL,
items_json longtext NOT NULL,
revision bigint(20) unsigned NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY job_version (job_id,content_version_sha256),
KEY job_updated (job_id,updated_at)
) $collate;");
  return ['table_ready'=>self::ready(),'contract'=>self::CONTRACT];
 }
 static function ready(){
  global $wpdb;$t=self::table();
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
 }
 static function job($id){
  if(!is_string($id)||!preg_match('/^[a-f0-9-]{36}$/D',$id))return self::error('job_id_invalid',400);
  if(!class_exists('MAD4B_SCP_Content_Jobs'))return self::error('job_provider_missing',503);
  $result=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
  if(is_wp_error($result))return $result;
  $j=$result['job']??null;
  if(!is_array($j)||($j['job_id']??'')!==$id||empty($j['site_uuid'])||empty($j['brand_id']))
   return self::error('job_identity_invalid',404);
  return $j;
 }
 static function clean_items($items,$job_id=''){
  if(!is_array($items)||count($items)>self::MAX_ITEMS)return self::error('items_invalid',400);
  $out=[];$seen=[];
  foreach($items as $item){
   if(!is_array($item))return self::error('item_invalid',400);
   $kind=sanitize_key((string)($item['kind']??''));
   $role=sanitize_key((string)($item['role']??''));
   if(!in_array($kind,['scraped_candidate','local_attachment'],true)
      ||!in_array($role,['featured','inline','gallery'],true))return self::error('item_type_invalid',400);
   $id=absint($item['attachment_id']??0);
   $source=esc_url_raw((string)($item['source_url']??''));
   if($kind==='local_attachment'){
    if(!$id||get_post_type($id)!=='attachment'||!wp_attachment_is_image($id))
     return self::error('attachment_invalid',422);
    $image=class_exists('MAD4B_Editorial_Featured_Image_Gate')
      ?MAD4B_Editorial_Featured_Image_Gate::verify_image($id):['valid'=>false];
    if(empty($image['valid']))return self::error('attachment_file_unverified',422);
   }else{
    if($id || strlen($source)>2048 || !preg_match('~^https://~i',$source))
     return self::error('scraped_candidate_identity_invalid',400);
    $p=wp_parse_url($source);
    if(!is_array($p)||empty($p['host'])||isset($p['user'])||isset($p['pass']))
     return self::error('scraped_candidate_url_invalid',400);
   }
   // Attachment IDs and source URLs are treated as candidate identities;
   // a caller may NEVER self-declare a license, visual or capture proof.
   $identity=$kind==='local_attachment'?'attachment:'.$id:'source:'.hash('sha256',$source);
   if(isset($seen[$identity]))return self::error('duplicate_asset',409);
   $seen[$identity]=true;
   $capture_id=(string)($item['capture_record_id']??'');
   $capture_verified=false;
   if($kind==='scraped_candidate'&&$capture_id!==''){
    if(!class_exists('MAD4B_Hub_Capture_Registry'))return self::error('capture_registry_required',409);
    $receipt=MAD4B_Hub_Capture_Registry::get($capture_id,true);
    if(is_wp_error($receipt)||$receipt['job_id']!==$job_id||!$receipt['html_available']||
       !hash_equals(hash('sha256',(string)($receipt['raw_html']??'')),(string)$receipt['source_html_sha256']))
      return self::error('capture_provenance_invalid',409);
    foreach((array)($receipt['parsed']['images']??[]) as $captured){
      if(($captured['source_url']??'')===$source){$capture_verified=true;break;}
    }
    if(!$capture_verified)return self::error('candidate_not_in_verified_capture',409);
   }
   $out[]=[
    'kind'=>$kind,'role'=>$role,
    'attachment_id'=>$id,'source_url'=>$source,
    'candidate_sha256'=>hash('sha256',$identity),
    'capture_record_id'=>$capture_verified?$capture_id:'',
    'capture_verified'=>$capture_verified,'rights_verified'=>false,
    'visual_verified'=>false,'publish_authorized'=>false
   ];
  }
  return $out;
 }
 static function plan($input=[]){
  if(!self::allowed())return self::error('authority_required',403);
  if(!self::ready())return self::error('schema_missing',503);
  if(!is_array($input))return self::error('input_invalid',400);
  $job=self::job((string)($input['job_id']??''));if(is_wp_error($job))return $job;
  $ver=(string)($input['content_version_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$ver))return self::error('content_version_required',400);
  $items=self::clean_items($input['items']??[],$job['job_id']);
  if(is_wp_error($items))return $items;
  $key=(string)($input['record_key']??'');
  if($key!==''){
   if(!preg_match('/^[a-f0-9]{64}$/D',$key))return self::error('record_key_invalid',400);
   $record=MAD4B_Hub_Canonical_Store::read_record($key);
   if(is_wp_error($record)||($record['job_id']??'')!==$job['job_id'])
    return self::error('canonical_job_mismatch',409);
  }
  $hash=hash('sha256',wp_json_encode([
   'job_id'=>$job['job_id'],'site_uuid'=>$job['site_uuid'],'brand_id'=>$job['brand_id'],
   'record_key'=>$key,'version'=>$ver,'items'=>$items
  ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  $old=self::read($job['job_id'],$ver);
  if(is_wp_error($old))return $old;
  return ['contract'=>self::CONTRACT,'job_id'=>$job['job_id'],'site_uuid'=>$job['site_uuid'],
   'brand_id'=>$job['brand_id'],'record_key'=>$key,'content_version_sha256'=>$ver,
   'manifest_sha256'=>$hash,'items'=>$items,
   'existing_revision'=>(int)($old['revision']??0),
   'stored_manifest_sha256'=>(string)($old['manifest_sha256']??''),
   'unverified_count'=>count($items),'publication_authorized'=>false,'mutation_performed'=>false];
 }
 static function read($job_id,$version=''){
  if(!self::ready())return self::error('schema_missing',503);
  $job=self::job($job_id);if(is_wp_error($job))return $job;
  global $wpdb;$t=self::table();
  if($version!==''){
   if(!preg_match('/^[a-f0-9]{64}$/D',$version))return self::error('version_invalid',400);
   $q=$wpdb->prepare("SELECT * FROM $t WHERE job_id=%s AND content_version_sha256=%s LIMIT 1",$job_id,$version);
  }else $q=$wpdb->prepare("SELECT * FROM $t WHERE job_id=%s ORDER BY id DESC LIMIT 1",$job_id);
  $r=$wpdb->get_row($q,ARRAY_A);
  if(!$r)return null;
  if($r['site_uuid']!==$job['site_uuid']||$r['brand_id']!==$job['brand_id'])
   return self::error('scope_drift',409);
  $items=json_decode((string)$r['items_json'],true);
  if(!is_array($items))return self::error('manifest_json_invalid',500);
  $r['items']=$items;unset($r['items_json']);
  return $r;
 }
 static function apply($input=[]){
  if(!self::allowed())return self::error('authority_required',403);
  $p=self::plan($input);if(is_wp_error($p))return $p;
  $expected=(string)($input['expected_manifest_sha256']??'');
  $revision=(int)($input['expected_revision']??-1);
  if(!hash_equals($p['manifest_sha256'],$expected)||$revision!==$p['existing_revision'])
   return self::error('stale_plan',409);
  if($revision>0&&$p['manifest_sha256']===($p['stored_manifest_sha256']??''))
   return ['contract'=>self::CONTRACT,'state'=>'already_stored','mutation_performed'=>false];
  global $wpdb;$t=self::table();$now=gmdate('Y-m-d H:i:s');
  $common=['record_key'=>$p['record_key'],'manifest_sha256'=>$p['manifest_sha256'],
    'items_json'=>wp_json_encode($p['items']),'updated_at'=>$now];
  if($revision===0){
   $insert=array_merge($common,['job_id'=>$p['job_id'],'site_uuid'=>$p['site_uuid'],
    'brand_id'=>$p['brand_id'],'content_version_sha256'=>$p['content_version_sha256'],
    'revision'=>1,'created_at'=>$now]);
   $ok=$wpdb->insert($t,$insert);
  }else{
   $common['revision']=$revision+1;
   $ok=$wpdb->update($t,$common,['job_id'=>$p['job_id'],
     'content_version_sha256'=>$p['content_version_sha256'],'revision'=>$revision]);
  }
  if($ok!==1)return self::error('cas_conflict',409);
  $back=self::read($p['job_id'],$p['content_version_sha256']);
  if(is_wp_error($back)||!$back||$back['manifest_sha256']!==$p['manifest_sha256'])
   return self::error('readback_failed',500);
  return ['contract'=>self::CONTRACT,'state'=>'stored','job_id'=>$p['job_id'],
   'manifest_sha256'=>$back['manifest_sha256'],'revision'=>(int)$back['revision'],
   'media_count'=>count($p['items']),'publication_authorized'=>false,'mutation_performed'=>true];
 }
 static function boot(){
  add_action('wp_abilities_api_init',function(){
   if(!function_exists('wp_register_ability'))return;
   foreach(['plan'=>false,'apply'=>true,'read'=>false] as $mode=>$write){
    $name='mad4b/hub-media-manifest-'.$mode;
    if(wp_has_ability($name))continue;
    wp_register_ability($name,[
     'label'=>'MAD4B hub-media-manifest '.ucfirst($mode),
     'description'=>'Central, site-scoped versioned content and media binding; no publication.',
     'category'=>$write?'mad4b-write':'mad4b-read',
     'execute_callback'=>static function($args)use($mode){
       $args=is_array($args)?$args:[];
       if($mode==='read')return self::read((string)($args['job_id']??''),(string)($args['content_version_sha256']??''));
       if($mode==='get')return self::get((string)($args['job_id']??''));
       return self::$mode($args);
     },
     'permission_callback'=>[__CLASS__,'allowed'],
     'input_schema'=>['type'=>'object','properties'=>[
       'job_id'=>['type'=>'string'],'content_version_sha256'=>['type'=>'string'],
       'record_key'=>['type'=>'string'],'items'=>['type'=>'array'],
       'company_id'=>['type'=>'string'],'destination_site_uuid'=>['type'=>'string'],
       'language'=>['type'=>'string'],'target_post_type'=>['type'=>'string'],
       'target_post_id'=>['type'=>'integer'],'expected_revision'=>['type'=>'integer'],
       'expected_manifest_sha256'=>['type'=>'string'],'expected_plan_sha256'=>['type'=>'string']
     ],'additionalProperties'=>false],
     'output_schema'=>['type'=>'object','additionalProperties'=>true],
     'meta'=>['public'=>false,'show_in_rest'=>false,
       'mcp'=>['public'=>false,'type'=>'tool','surface'=>$write?'write':'read']]
    ]);
   }
  },65);
  add_action('rest_api_init',function(){
   foreach(['plan'=>'POST','apply'=>'POST','read'=>'GET'] as $action=>$method)
    register_rest_route('mad4b-hub/v1','/media-manifest/'.$action,[
      'methods'=>$method,'callback'=>static function($req)use($action){
       $args=$req->get_params();
       return $action==='read'?self::read((string)($args['job_id']??''),(string)($args['content_version_sha256']??'')):self::$action($args);
      },'permission_callback'=>[__CLASS__,'allowed']
    ]);
  },65);
 }
}
