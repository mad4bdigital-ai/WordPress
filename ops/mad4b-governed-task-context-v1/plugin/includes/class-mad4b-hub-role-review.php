<?php
defined('ABSPATH') || exit;
/**
 * Site-local ChatGPT draft inbox. WordPress roles, not a hardcoded user or
 * a shared privileged Agent, determine visibility and human review.
 */
final class MAD4B_Hub_Role_Review {
 const CONTRACT='mad4b.hub.role-review.v1';
 private static function table(){global $wpdb;return $wpdb->prefix.'mad4b_hub_editorial_reviews';}
 private static function master($uid=0){
  $u=get_userdata($uid?:get_current_user_id());
  return $u && (in_array('master_admin',(array)$u->roles,true)
       &&user_can($u,'mad4b_manage_company_operations')
       ||in_array('administrator',(array)$u->roles,true)&&user_can($u,'manage_options'));
 }
 private static function reader(){
  return get_current_user_id()>0
    &&class_exists('MAD4B_SCP_Site_Profile') && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
    &&(self::master()||current_user_can('edit_posts'));
 }
 private static function err($name,$status=403){
  return new WP_Error('mad4b_review_'.$name,$name,array('status'=>$status));
 }
 public static function boot(){
  add_action('rest_api_init',array(__CLASS__,'routes'),61);
  add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),61);
  add_action('admin_menu',array(__CLASS__,'menu'),45);
 }
 public static function install(){
  if(!current_user_can('manage_options')
   ||wp_get_environment_type()!=='staging'
   ||!class_exists('MAD4B_SCP_Site_Profile') || !MAD4B_SCP_Site_Profile::origin_enrolled())return self::err('installation_denied');
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  global $wpdb;$table=self::table();$charset=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $table (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
batch_id char(36) NOT NULL,
job_id char(36) NOT NULL,
output_sha256 char(64) NOT NULL,
media_package_sha256 char(64) NOT NULL DEFAULT '',
decision varchar(24) NOT NULL,
reviewer_user_id bigint(20) unsigned NOT NULL,
review_channel varchar(32) NOT NULL DEFAULT 'browser',
review_note varchar(500) NOT NULL,
reviewed_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY batch_id (batch_id),
KEY reviewer_user (reviewer_user_id),
KEY job_id (job_id)
) ENGINE=InnoDB $charset;");
  return array('table_exists'=>self::table_ready(),'contract'=>self::CONTRACT);
 }
 private static function table_ready(){
  global $wpdb;$table=self::table();
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;
 }
 private static function source($batch){
  if(!is_string($batch)||!preg_match('/^[a-f0-9-]{36}$/D',$batch))return self::err('batch_invalid',400);
  global $wpdb;$t=$wpdb->prefix.'mad4b_hub_bulk_tasks';$b=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT q.*,b.state AS batch_state FROM $t q JOIN $b b ON b.batch_id=q.batch_id
    WHERE q.batch_id=%s AND q.task_key='ai-4'
      AND q.operation='ai_guard_stage' AND q.stage='OUTPUT_CONTRACT_QA' LIMIT 1",$batch),ARRAY_A);
  if(!$row)return self::err('batch_not_found',404);
  $j=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$row['content_job_id']));
  if(is_wp_error($j))return $j;
  $job=$j['job'];
  if(!self::master() && (int)($job['created_by_user']??0)!==get_current_user_id())
   return self::err('not_your_job',403);
  $payload=json_decode((string)$row['payload_json'],true);
  $receipt=json_decode((string)$row['result_json'],true);
  if(($row['state']??'')!=='COMPLETED'||($row['batch_state']??'')!=='ACTIVE'
    ||!is_array($payload)||!is_array($receipt)
    ||empty($payload['output'])||!is_array($payload['output'])
    ||($receipt['verified']??false)!==true
    ||($receipt['receipt']['qa_state']??'')!=='PASS'
    ||($receipt['receipt']['publication_authorized']??true)!==false
    ||($payload['content_job_id']??'')!==$row['content_job_id'])
    return self::err('draft_not_ready',409);
  if(($job['job_revision']??null)!==($payload['content_job_revision']??null))
    return self::err('job_revision_changed',409);
  $record=MAD4B_Hub_Canonical_Store::read_record((string)($payload['record_key']??''));
  if(is_wp_error($record)||($record['job_id']??'')!==$row['content_job_id']
    ||($record['version_sha256']??'')!==($payload['version_sha256']??''))
    return self::err('source_binding_changed',409);
  $short_source=mb_substr((string)($record['normalized']['description']??''),0,1000);
  $current_binding=hash('sha256',implode('|',array(
      $row['content_job_id'],$record['record_key'],$record['version_sha256'],
      $record['source_sha256'],hash('sha256',$short_source))));
  if(!hash_equals($current_binding,(string)($receipt['source_binding_sha256']??'')))
    return self::err('source_receipt_changed',409);
  $sha=hash('sha256',wp_json_encode($payload['output']));
  if(($receipt['receipt']['output_sha256']??'')!==$sha)return self::err('output_changed',409);
  return array('row'=>$row,'job'=>$job,'output'=>$payload['output'],
    'output_sha256'=>$sha,'receipt'=>$receipt,
    'source_url'=>isset($record['source_url'])?esc_url_raw((string)$record['source_url']):'');
 }
 public static function inbox($input=array()){
  if(!self::reader())return self::err('read_denied');
  global $wpdb;$t=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $b=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $rows=$wpdb->get_results(
   "SELECT q.batch_id FROM $t q JOIN $b b ON b.batch_id=q.batch_id
     WHERE q.task_key='ai-4' AND q.operation='ai_guard_stage'
     AND q.state='COMPLETED' AND b.state='ACTIVE' ORDER BY q.updated_at DESC LIMIT 45",ARRAY_A);
  $items=array();
  foreach((array)$rows as $r){
   $s=self::source((string)$r['batch_id']);if(is_wp_error($s))continue;
   $review=self::review_row($r['batch_id']);
   $items[]=array(
    'batch_id'=>$r['batch_id'],'job_id'=>$s['row']['content_job_id'],
    'job_subject'=>$s['job']['subject'],
    'source_url'=>$s['source_url'],
    'title'=>mb_substr((string)($s['output']['title']??''),0,180),
    'summary'=>mb_substr((string)($s['output']['summary']??''),0,850),
    'language'=>(string)($s['output']['language']??''),
    'task_type'=>(string)($s['output']['task_type']??''),
    'confidence'=>$s['output']['confidence']??null,
    'evidence_count'=>count((array)($s['output']['evidence_refs']??array())),
    'updated_at'=>(string)($s['row']['updated_at']??''),
    'output_sha256'=>$s['output_sha256'],
    'review_decision'=>$review['decision']??'AWAITING_MASTER_REVIEW',
    'reviewed_at'=>$review['reviewed_at']??null,
    'can_decide'=>self::master(),'published'=>false);
  }
  return array('contract'=>self::CONTRACT,'items'=>$items,'count'=>count($items),
    'role_lane'=>self::master()?'master_reviewer':'own_drafts_reader',
    'publish_authorized'=>false);
 }
 private static function review_row($batch){
  if(!self::table_ready())return null;
  global $wpdb;return $wpdb->get_row($wpdb->prepare(
   'SELECT decision,output_sha256,media_package_sha256,reviewer_user_id,review_channel,review_note,reviewed_at FROM '.self::table().' WHERE batch_id=%s',$batch),ARRAY_A);
 }
 public static function preview($input=array()){
  if(!self::reader())return self::err('read_denied');
  $s=self::source((string)($input['batch_id']??''));
  if(is_wp_error($s))return $s;
  $media=MAD4B_Hub_Review_Gallery::package([
   'job_id'=>$s['row']['content_job_id'],
   'output'=>$s['output'],'output_sha256'=>$s['output_sha256']
  ]);
  $review=self::review_row($s['row']['batch_id']);
  return array('contract'=>self::CONTRACT,'batch_id'=>$s['row']['batch_id'],
   'job_id'=>$s['row']['content_job_id'],'job_subject'=>$s['job']['subject'],
   'media_package_sha256'=>$media['sha256'],'media_count'=>$media['count'],
   'media_gallery_items'=>$media['media'],
   'featured_image_linked'=>$media['featured_image_linked'],
   'frontend_preview'=>MAD4B_Hub_Review_Frontend_Preview::resolve([
     'job_id'=>$s['row']['content_job_id']
   ]),
   'media_package_current'=>$review && !empty($review['media_package_sha256'])
     &&hash_equals($review['media_package_sha256'],$media['sha256']),
   'output'=>$s['output'],'output_sha256'=>$s['output_sha256'],
   'source_url'=>$s['source_url'],
   'review'=>$review,
   'format_pass'=>true,'facts_verified'=>false,'publish_authorized'=>false);
 }
 public static function decide($input=array()){
  if(!self::reader()||!self::master())return self::err('master_required');
  // An explicitly selected Administrator is a real WordPress site operator.
  // Local WP-CLI may review on Staging under that operator's own identity;
  // this is not a remote MCP/OAuth authentication shortcut.
  $cli_admin=false;
  if(defined('WP_CLI')&&WP_CLI && PHP_SAPI==='cli'
      && !defined('REST_REQUEST')
      &&wp_get_environment_type()==='staging'
      &&MAD4B_SCP_Site_Profile::origin_enrolled()
      &&MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
      &&class_exists('WP_CLI') && method_exists('WP_CLI','get_runner')) {
    $runner=WP_CLI::get_runner();
    $config=$runner->config;
    $cli_command=(string)($runner->arguments[0]??'');
    $explicit_id=isset($config['user'])&&ctype_digit((string)$config['user'])
      ?(int)$config['user']:0;
    $actor=wp_get_current_user();
    $cli_admin=in_array($cli_command,array('eval','eval-file'),true)
      &&$explicit_id>0 &&$explicit_id===(int)$actor->ID
      &&in_array('administrator',(array)$actor->roles,true)
      &&current_user_can('manage_options');
  }
  $oauth=class_exists('MAD4B_SCP_OAuth_Resource_Bridge')
   &&MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active();
  $mapped=$oauth&&class_exists('MAD4B_SCP_OAuth_Subject_User_Bridge')
   ?MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context():array();
  $oauth_ok=$oauth&&($mapped['authenticated']??false)===true
    &&(int)($mapped['wp_user_id']??0)===get_current_user_id();
  $nonce=(string)($input['wp_nonce']??'');
  $browser_ok=is_user_logged_in()&&$nonce!==''&&wp_verify_nonce($nonce,'mad4b_hub_editorial_review');
  if(!$cli_admin&&!$oauth_ok&&!$browser_ok)return self::err('verified_review_session_required');
  if(!self::table_ready())return self::err('review_store_missing',503);
  $decision=sanitize_key((string)($input['decision']??''));
  if(!in_array($decision,array('approve','needs_changes','reject'),true))
    return self::err('decision_invalid',400);
  $note=sanitize_textarea_field((string)($input['note']??''));
  if(strlen($note)<8||strlen($note)>500)return self::err('note_required',400);
  $s=self::source((string)($input['batch_id']??''));
  if(is_wp_error($s))return $s;
  if(!hash_equals($s['output_sha256'],(string)($input['expected_output_sha256']??'')))
    return self::err('stale_review',409);
  // Editorial decision is bound to the exact images the reviewer inspected.
  $gallery=MAD4B_Hub_Review_Gallery::package([
    'job_id'=>$s['row']['content_job_id'],
    'output'=>$s['output'],
    'output_sha256'=>$s['output_sha256']
  ]);
  $expected=(string)($input['expected_media_package_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$expected) ||
     !hash_equals($gallery['sha256'],$expected))
    return self::err('media_package_changed_or_missing',409);
  // The image gate for publishable article content is independent of
  // research-observation review; research is not a publish-ready article.
  $type=strtolower((string)($s['output']['task_type']??''));
  $article_like=in_array($type,['content','article','post','blog','blog_post',
      'editorial_article','seo_article','travel_article'],true)
      ||($s['job']['target_post_type']??'')==='post';
  if($decision==='approve'&&$article_like){
    if(empty($gallery['featured_image_linked'])||!empty($gallery['file_missing_count']))
      return self::err('publishable_article_image_missing',409);
    if(!empty($gallery['incomplete_metadata_count']))
      return self::err('article_image_metadata_incomplete',409);
    // Evidence of extraction and article identity is a separate QA gate.
    // Missing optional license metadata is recorded, not fabricated.
    if(!empty($gallery['pending_count']))
      return self::err('article_media_capture_not_verified',409);
  }
  $old=self::review_row($s['row']['batch_id']);
  if($old)return self::err('review_already_recorded',409);
  global $wpdb;
  $channel=$cli_admin?'wp_cli_administrator':($oauth_ok?'verified_oauth':'browser');
  $ok=$wpdb->insert(self::table(),array(
    'batch_id'=>$s['row']['batch_id'],'job_id'=>$s['row']['content_job_id'],
    'output_sha256'=>$s['output_sha256'],
    'media_package_sha256'=>$gallery['sha256'],'decision'=>$decision,
    'reviewer_user_id'=>get_current_user_id(),'review_channel'=>$channel,
    'review_note'=>$note,'reviewed_at'=>gmdate('Y-m-d H:i:s')),
    array('%s','%s','%s','%s','%s','%d','%s','%s','%s'));
  if(!$ok)return self::err('concurrent_review_conflict',409);
  return array('contract'=>self::CONTRACT,'decision'=>$decision,'reviewer_user_id'=>get_current_user_id(),
    'batch_id'=>$s['row']['batch_id'],'output_sha256'=>$s['output_sha256'],
    'media_package_sha256'=>$gallery['sha256'],
    'editorial_review_recorded'=>true,'review_channel'=>$channel,
    'publication_authorized'=>false,
    'content_job_state_unchanged'=>true);
 }
 public static function routes(){
  foreach(array('inbox'=>'GET','preview'=>'GET','decide'=>'POST') as $action=>$method)
   register_rest_route('mad4b-hub/v1','/control-workflow/role-review/'.$action,array(
    'methods'=>$method,'permission_callback'=>static fn()=>self::reader()
       &&($action!=='decide'||self::master()),
    'callback'=>static fn($request)=>call_user_func(array(__CLASS__,$action),$request->get_params())));
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  foreach(array('inbox','preview','decide') as $action){
   $write=$action==='decide';
   $name='mad4b/hub-role-review-'.$action;
   if(wp_has_ability($name))continue;
   wp_register_ability($name,array(
    'label'=>'MAD4B Content Review '.ucfirst($action),
    'description'=>'WP-user scoped ChatGPT output inbox and master-admin editorial review; no publication.',
    'category'=>$write?'mad4b-write':'mad4b-read',
    'execute_callback'=>array(__CLASS__,$action),
    'permission_callback'=>static fn()=>self::reader()&&(!$write||self::master()),
    'input_schema'=>array('type'=>'object','properties'=>array(
      'batch_id'=>array('type'=>'string'),'decision'=>array('type'=>'string'),
      'expected_output_sha256'=>array('type'=>'string'),
      'expected_media_package_sha256'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$'),
      'note'=>array('type'=>'string'),
      'wp_nonce'=>array('type'=>'string')),
      'required'=>$action==='preview'?array('batch_id'):
        ($write?array('batch_id','decision','expected_output_sha256','expected_media_package_sha256','note'):array()),
      'additionalProperties'=>false),
    'output_schema'=>array('type'=>'object','additionalProperties'=>true),
    'meta'=>array('public'=>false,'show_in_rest'=>false,
     'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$write?'write':'read'),
     'annotations'=>array('readonly'=>!$write,'destructive'=>false,'idempotent'=>!$write))
   ));
  }
 }
 public static function menu(){
  if(class_exists('MAD4B_Hub_Review_UI'))MAD4B_Hub_Review_UI::menu();
 }
 public static function page(){
  if(class_exists('MAD4B_Hub_Review_UI'))MAD4B_Hub_Review_UI::master_page();
 }
}
