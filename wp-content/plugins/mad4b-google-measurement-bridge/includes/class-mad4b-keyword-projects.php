<?php
if (!defined('ABSPATH')) exit;
/** Site/profile/user-bound keyword research project drafts. No external publishing. */
final class MAD4B_Keyword_Projects {
 const CONTRACT='mad4b.keyword-projects.v1';
 const OPTION='mad4b_keyword_projects_v1';
 public static function boot(){add_action('admin_post_mad4b_keyword_project_save',array(__CLASS__,'save_admin'));}
 public static function save_admin(){
  if(!current_user_can('manage_options')||!check_admin_referer('mad4b_keyword_project_save'))wp_die('Owner authorization required.');
  $raw=isset($_POST['keywords'])?sanitize_textarea_field(wp_unslash($_POST['keywords'])):'';
  $words=array_values(array_filter(array_map('trim',preg_split('/\\r?\\n/',$raw))));
  $result=self::run(array('action'=>'save','title'=>isset($_POST['title'])?sanitize_text_field(wp_unslash($_POST['title'])):'',
   'keywords'=>$words,'nonce'=>wp_create_nonce('mad4b_keyword_project_save')));
  if(is_wp_error($result))wp_die(esc_html($result->get_error_message()));
  wp_safe_redirect(admin_url('tools.php?page=mad4b-google-measurement&keyword_saved=1'));exit;
 }

 private static function context(){
  if(!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('keyword_project_permission','Owner site permission required.');
  $site=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():array();
  if(!is_array($site)||empty($site['site_uuid'])||empty($site['profile_digest'])||empty($site['origin_match'])||empty($site['environment_match']))
   return new WP_Error('keyword_project_site','Exact site identity required.');
  return array('site_uuid'=>(string)$site['site_uuid'],'digest'=>(string)$site['profile_digest'],
   'user_id'=>(int)get_current_user_id());
 }
 private static function key($ctx){return hash('sha256',$ctx['site_uuid'].':'.$ctx['digest'].':'.$ctx['user_id']);}
 private static function records($ctx){
  $all=get_option(self::OPTION,array());$key=self::key($ctx);
  return is_array($all)&&isset($all[$key])&&is_array($all[$key])?$all[$key]:array();
 }
 public static function run($input=array()){
  $ctx=self::context();if(is_wp_error($ctx))return $ctx;
  $action=(string)($input['action']??'list');
  $records=self::records($ctx);
  if($action==='list'){
   $items=array();
   foreach($records as $id=>$record)$items[]=array('id'=>$id,'title'=>$record['title'],
    'updated_at'=>$record['updated_at'],'keywords_count'=>count($record['keywords']));
   return array('contract'=>self::CONTRACT,'projects'=>$items,'read_only'=>true);
  }
  $id=(string)($input['project_id']??'');
  if($action==='get'){
   if(!isset($records[$id]))return new WP_Error('keyword_project_missing','Project not found.');
   return array('contract'=>self::CONTRACT,'project'=>$records[$id],'read_only'=>true);
  }
  if($action!=='save')return new WP_Error('keyword_project_action','Unknown action.');
  // Writes are local owner-initiated WordPress drafts, never external Google/WP content publication.
  if(!current_user_can('manage_options')||empty($input['nonce'])||
      !wp_verify_nonce((string)$input['nonce'],'mad4b_keyword_project_save'))
   return new WP_Error('keyword_project_approval','Owner form nonce required for saving.');
  $title=trim((string)($input['title']??''));
  if(strlen($title)<3||strlen($title)>120||preg_match('/[<>\x00-\x1f]/',$title))
   return new WP_Error('keyword_project_title','Invalid project title.');
  $keywords=$input['keywords']??array();
  if(!is_array($keywords)||count($keywords)>500)return new WP_Error('keyword_project_keywords','Too many keywords.');
  $safe=array();
  foreach($keywords as $word){
   if(!is_string($word)||strlen(trim($word))<2||strlen($word)>160||preg_match('/[<>\x00-\x1f]/',$word))
    return new WP_Error('keyword_project_keyword','Invalid keyword.');
   $safe[]=trim($word);
  }
  $safe=array_values(array_unique($safe));
  $id=($id!==''&&isset($records[$id]))?$id:'research_'.substr(hash('sha256',$title.':'.microtime(true).':'.$ctx['user_id']),0,20);
  if(count($records)>=25&&!isset($records[$id]))return new WP_Error('keyword_project_quota','At most 25 projects per site and operator.');
  $records[$id]=array('id'=>$id,'title'=>$title,'keywords'=>$safe,'site_uuid'=>$ctx['site_uuid'],
   'updated_at'=>gmdate('c'),'status'=>'draft','published'=>false,'ads_mutation_performed'=>false);
  $all=get_option(self::OPTION,array());if(!is_array($all))$all=array();
  $all[self::key($ctx)]=$records;
  update_option(self::OPTION,$all,false);
  return array('contract'=>self::CONTRACT,'project_id'=>$id,'saved'=>true,'published'=>false,'ads_mutation_performed'=>false);
 }
 /** Ability-specific dispatch prevents read surfaces from selecting a write action. */
 public static function read_ability($input=array()){
  if(!is_array($input))return new WP_Error('keyword_project_action','Invalid read request.');
  $action=(string)($input['action']??'list');
  if(!in_array($action,array('list','get'),true))return new WP_Error('keyword_project_action','Read ability cannot save a project.');
  return self::run($input);
 }
 public static function save_ability($input=array()){
  if(!is_array($input)||($input['action']??'')!=='save')
   return new WP_Error('keyword_project_action','Write ability requires explicit save action.');
  return self::run($input);
 }
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  foreach(array('mad4b/keyword-projects-read'=>'read','mad4b/keyword-projects-save'=>'save') as $name=>$mode){
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   $read=$mode==='read';
   wp_register_ability($name,array('label'=>$name,'description'=>'Site-bound keyword research project '.($read?'inventory':'draft storage'),
    'category'=>$read?'mad4b-read':'mad4b-admin','execute_callback'=>array(__CLASS__,$read?'read_ability':'save_ability'),
    'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
    'input_schema'=>array('type'=>'object','additionalProperties'=>false,
     'properties'=>array('action'=>array('type'=>'string','enum'=>$read?array('list','get'):array('save')),
      'project_id'=>array('type'=>'string','maxLength'=>40),'nonce'=>array('type'=>'string','maxLength'=>100),
      'title'=>array('type'=>'string','maxLength'=>120),
      'keywords'=>array('type'=>'array','maxItems'=>500,'items'=>array('type'=>'string','maxLength'=>160)))),
    'output_schema'=>array('type'=>'object','additionalProperties'=>true),
    'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$read?'read':'write','production_mutation_allowed'=>false),
     'annotations'=>array('readonly'=>$read,'destructive'=>!$read,'idempotent'=>$read))));
  }
 }
}
