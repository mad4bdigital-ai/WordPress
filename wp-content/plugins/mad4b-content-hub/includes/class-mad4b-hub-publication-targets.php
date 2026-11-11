<?php
defined('ABSPATH')||exit;
/** Many company destinations per central content job; remote IDs are never local IDs. */
final class MAD4B_Hub_Publication_Targets {
 const CONTRACT='mad4b.hub.publication-target.v1';
 static function table(){global $wpdb;return $wpdb->prefix.'mad4b_hub_publication_targets';}
 static function allowed(){return class_exists('MAD4B_Content_Hub')&&MAD4B_Content_Hub::permission();}
 static function error($code,$status=409){return new WP_Error('mad4b_target_'.$code,$code,['status'=>$status]);}
 static function install(){
  if(!self::allowed())return self::error('authority_required',403);
  global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$t=self::table();
  dbDelta("CREATE TABLE $t (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id char(36) NOT NULL,
content_version_sha256 char(64) NOT NULL,
company_id varchar(191) NOT NULL,
destination_site_uuid char(36) NOT NULL,
language varchar(24) NOT NULL,
target_post_type varchar(64) NOT NULL,
target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
target_status varchar(32) NOT NULL DEFAULT 'pending',
binding_revision bigint(20) unsigned NOT NULL DEFAULT 1,
source_verified varchar(32) NOT NULL DEFAULT 'pending',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY destination (job_id,company_id,destination_site_uuid,language),
KEY job_destination (job_id,destination_site_uuid)
) ".$wpdb->get_charset_collate().";");
  return ['table_ready'=>self::ready(),'contract'=>self::CONTRACT];
 }
 static function ready(){
  global $wpdb;$t=self::table();
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t;
 }
 static function get($job_id){
  if(!self::ready())return self::error('schema_missing',503);
  $job=MAD4B_Hub_Media_Manifest::job((string)$job_id);
  if(is_wp_error($job))return $job;
  global $wpdb;$t=self::table();
  $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE job_id=%s ORDER BY id ASC",$job_id),ARRAY_A);
  return is_array($rows)?$rows:[];
 }
 static function plan($input=[]){
  if(!self::allowed())return self::error('authority_required',403);
  if(!is_array($input)||!self::ready())return self::error('input_or_schema_invalid',400);
  $job=MAD4B_Hub_Media_Manifest::job((string)($input['job_id']??''));
  if(is_wp_error($job))return $job;
  $stage=(string)($job['stage']??'');
  if(!in_array($stage,['DRAFT','SCHEDULING','PUBLISHING','POST_PUBLISH'],true) ||
    !in_array((string)($job['content_type']??''),['article','post','blog','blog_post','seo_article','travel_article','editorial_article','content'],true) ||
    in_array((string)($job['state']??''),['CANCELLED','FAILED'],true))
    return self::error('publication_stage_required',409);
  $ver=(string)($input['content_version_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$ver))return self::error('content_version_required',400);
  $manifest=MAD4B_Hub_Media_Manifest::read((string)$job['job_id'],$ver);
  if(is_wp_error($manifest)||!$manifest)return self::error('manifest_required',409);
  $company=sanitize_text_field((string)($input['company_id']??''));
  $site=strtolower(trim((string)($input['destination_site_uuid']??'')));
  $lang=sanitize_key((string)($input['language']??''));
  $type=sanitize_key((string)($input['target_post_type']??''));
  $post_id=absint($input['target_post_id']??0);
  if($company===''||strlen($company)>191||!preg_match('/^[a-f0-9-]{36}$/D',$site)
     ||!preg_match('/^[a-z0-9_-]{2,24}$/D',$lang)||!preg_match('/^[a-z0-9_-]{2,64}$/D',$type))
   return self::error('destination_identity_invalid',400);
  $local=class_exists('MAD4B_SCP_Site_Profile')&&hash_equals(
      (string)MAD4B_SCP_Site_Profile::site_uuid(),$site);
  $verified=false;$mode='remote_bridge_required';
  if($local){
   if($company!==$job['brand_id'])return self::error('local_brand_mismatch',403);
   $post=$post_id?get_post($post_id):null;
   if(!$post || $post->post_type!==$type || !current_user_can('edit_post',$post_id))
    return self::error('local_post_identity_invalid',409);
   if(in_array($post->post_status,['trash','auto-draft'],true))
    return self::error('local_post_not_previewable',409);
   $verified=true;$mode='local_wordpress';
  }else{
   // Only a trusted destination connector can assert another company's post.
   $proof=apply_filters('mad4b_hub_verify_publication_destination',null,[
    'job_id'=>$job['job_id'],'company_id'=>$company,'site_uuid'=>$site,
    'language'=>$lang,'post_type'=>$type,'post_id'=>$post_id,'content_sha256'=>$ver
   ]);
   $verified=is_array($proof)&&($proof['verified']??false)===true
      &&($proof['job_id']??'')===$job['job_id']
      &&($proof['company_id']??'')===$company
      &&($proof['site_uuid']??'')===$site
      &&absint($proof['post_id']??0)===$post_id
      &&($proof['post_type']??'')===$type
      &&($proof['language']??'')===$lang;
   if(!$verified)return self::error('remote_destination_verification_required',409);
   $mode='verified_remote_connector';
  }
  $rows=self::get($job['job_id']);if(is_wp_error($rows))return $rows;
  $existing=null;
  foreach($rows as $r)if($r['company_id']===$company &&
    $r['destination_site_uuid']===$site && $r['language']===$lang){$existing=$r;break;}
  $fingerprint=hash('sha256',wp_json_encode([
    $job['job_id'],$company,$site,$lang,$type,$post_id,$ver,$mode,
    (string)$manifest['manifest_sha256']
  ],JSON_UNESCAPED_SLASHES));
  return ['contract'=>self::CONTRACT,'job_id'=>$job['job_id'],
   'content_version_sha256'=>$ver,'company_id'=>$company,'destination_site_uuid'=>$site,
   'language'=>$lang,'target_post_type'=>$type,'target_post_id'=>$post_id,
   'verification'=>$mode,'verified'=>$verified,
   'expected_revision'=>(int)($existing['binding_revision']??0),
   'plan_sha256'=>$fingerprint,'publication_performed'=>false,'mutation_performed'=>false];
 }
 static function apply($input=[]){
  if(!self::allowed())return self::error('authority_required',403);
  $plan=self::plan($input);if(is_wp_error($plan))return $plan;
  $sha=(string)($input['expected_plan_sha256']??'');
  $revision=(int)($input['expected_revision']??-1);
  if(!hash_equals($plan['plan_sha256'],$sha)||$plan['expected_revision']!==$revision)
   return self::error('stale_destination_plan',409);
  global $wpdb;$t=self::table();$now=gmdate('Y-m-d H:i:s');
  $row=[
   'content_version_sha256'=>$plan['content_version_sha256'],
   'target_post_type'=>$plan['target_post_type'],
   'target_post_id'=>$plan['target_post_id'],
   'target_status'=>'draft',
   'source_verified'=>$plan['verification'],
   'updated_at'=>$now
  ];
  if($revision===0){
   $ok=$wpdb->insert($t,array_merge($row,[
    'job_id'=>$plan['job_id'],'company_id'=>$plan['company_id'],
    'destination_site_uuid'=>$plan['destination_site_uuid'],
    'language'=>$plan['language'],'binding_revision'=>1,'created_at'=>$now
   ]));
  }else{
   $row['binding_revision']=$revision+1;
   $ok=$wpdb->update($t,$row,[
    'job_id'=>$plan['job_id'],'company_id'=>$plan['company_id'],
    'destination_site_uuid'=>$plan['destination_site_uuid'],
    'language'=>$plan['language'],'binding_revision'=>$revision
   ]);
  }
  if($ok!==1)return self::error('destination_cas_conflict',409);
  $after=self::get($plan['job_id']);
  if(is_wp_error($after))return $after;
  foreach($after as $r)if($r['company_id']===$plan['company_id']&&
     $r['destination_site_uuid']===$plan['destination_site_uuid']&&
     $r['language']===$plan['language']){
    return ['contract'=>self::CONTRACT,'state'=>'bound',
     'job_id'=>$r['job_id'],'binding_revision'=>(int)$r['binding_revision'],
     'destination_site_uuid'=>$r['destination_site_uuid'],
     'target_post_id'=>(int)$r['target_post_id'],
     'publication_performed'=>false,'mutation_performed'=>true];
  }
  return self::error('readback_failed',500);
 }
 static function boot(){
  add_action('wp_abilities_api_init',function(){
   if(!function_exists('wp_register_ability'))return;
   foreach(['plan'=>false,'apply'=>true,'get'=>false] as $mode=>$write){
    $name='mad4b/hub-publication-targets-'.$mode;
    if(wp_has_ability($name))continue;
    wp_register_ability($name,[
     'label'=>'MAD4B hub-publication-targets '.ucfirst($mode),
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
   foreach(['plan'=>'POST','apply'=>'POST','get'=>'GET'] as $action=>$method){
    register_rest_route('mad4b-hub/v1','/publication-targets/'.$action,[
     'methods'=>$method,
     'callback'=>static function($req)use($action){
      $a=$req->get_params();return $action==='get'?self::get((string)($a['job_id']??'')):self::$action($a);
     },
     'permission_callback'=>[__CLASS__,'allowed']
    ]);
   }
  },65);
 }
}
