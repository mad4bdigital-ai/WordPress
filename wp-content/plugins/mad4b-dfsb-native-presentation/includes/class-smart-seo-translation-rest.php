<?php
namespace MAD4B\DFSB\NativePresentation;
if(!defined('ABSPATH'))exit;
final class SmartSeoTranslationRest {
 const NS='mad4b/v1';
 public static function allowed():bool{return current_user_can('manage_options');}
 public static function register():void {
  foreach([
   ['/seo-intelligence/translations/inspect','GET','inspect'],
   ['/seo-intelligence/translations/tasks','GET','tasks'],
   ['/seo-intelligence/translations/tasks','POST','prepare'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})','GET','detail'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/submit','PUT','submit'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/review','POST','review'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/apply','POST','apply'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/rollback','POST','rollback']
  ]as $route)register_rest_route(self::NS,$route[0],[
   'methods'=>$route[1],'permission_callback'=>[__CLASS__,'allowed'],
   'callback'=>[__CLASS__,$route[2]]]);
 }
 public static function input(\WP_REST_Request $r):array {
  $d=$r->get_json_params();return is_array($d)?$d:[];
 }
 public static function locked(\WP_REST_Request $r) {
  $old=SmartSeoTranslation::get((string)$r['id']);
  if(is_wp_error($old))return $old;
  if(!hash_equals(SmartSeoTranslation::revision($old),
     (string)(self::input($r)['expected_revision']??'')))
   return new \WP_Error('revision_stale','Reload task',['status'=>409]);
  return $old;
 }
 public static function save(array $old,array $next) {
  $out=SmartSeoTranslation::write($old['id'],SmartSeoTranslation::revision($old),$next);
  return is_wp_error($out)?$out:rest_ensure_response($out);
 }
 public static function inspect(\WP_REST_Request $r) {
  $taxonomy=sanitize_key((string)$r->get_param('taxonomy'));
  $source=(int)$r->get_param('source_id');
  $selection=(string)($r->get_param('languages')??'');
  $langs=$selection!==''?array_map('trim',explode(',',$selection)):
   array_values(array_diff(SmartSeoTranslation::languages(),['en']));
  if($source<=0||count($langs)>12||array_diff($langs,SmartSeoTranslation::languages()))
   return new \WP_Error('scan_invalid','Invalid source/language list',['status'=>400]);
  return rest_ensure_response(['taxonomy'=>$taxonomy,'source_id'=>$source,
   'locales'=>SmartSeoTranslation::discover($taxonomy,$source,$langs),
   'mutated'=>false]);
 }
 public static function tasks(\WP_REST_Request $r) {
  return rest_ensure_response(['tasks'=>SmartSeoTranslation::listTasks(
   (int)($r->get_param('limit')??50))]);
 }
 public static function prepare(\WP_REST_Request $r) {
  $b=self::input($r);$tax=sanitize_key((string)($b['taxonomy']??''));
  $source=(int)($b['source_id']??0);$lang=(string)($b['language']??'');
  if($source<=0||$lang==='en')return new \WP_Error('prepare_invalid',
    'English source and non-English target required',['status'=>400]);
  $info=SmartSeoTranslation::inspect($tax,$source,$lang);
  if(is_wp_error($info))return $info;
  if(!$info['can_apply_to_existing_term'])return new \WP_Error(
    'target_missing','Create WPML term first',['status'=>409]);
  $id=SmartSeoTranslation::taskId($tax,$source,$lang);
  if(!is_wp_error(SmartSeoTranslation::get($id)))
   return new \WP_Error('task_exists','Reuse existing task',['status'=>409]);
  $record=['contract'=>SmartSeoTranslation::CONTRACT,'id'=>$id,
   'resource'=>'wpml_taxonomy_description','site'=>home_url('/'),
   'taxonomy'=>$tax,'source_id'=>$source,'target_id'=>$info['target_id'],
   'source_language'=>'en','target_language'=>$lang,
   'source_sha256'=>$info['source']['description_sha256'],
   'target_sha256'=>$info['target']['description_sha256'],
   'target_before_description'=>$info['target']['description'],
   'discovery_status'=>$info['status'],'status'=>'pending_translation',
   'candidate'=>null,'review'=>null,'translator'=>null,'created_at'=>gmdate('c')];
  $out=SmartSeoTranslation::write($id,null,$record);
  return is_wp_error($out)?$out:new \WP_REST_Response($out,201);
 }
 public static function detail(\WP_REST_Request $r) {
  $record=SmartSeoTranslation::get((string)$r['id']);
  return is_wp_error($record)?$record:rest_ensure_response([
   'record'=>$record,'revision'=>SmartSeoTranslation::revision($record)]);
 }
 public static function submit(\WP_REST_Request $r) {
  $old=self::locked($r);if(is_wp_error($old))return $old;
  if(!in_array($old['status'],['pending_translation','draft','rejected'],true))
   return new \WP_Error('invalid_state','Task not editable',['status'=>409]);
  $b=self::input($r);
  if(!is_string($b['description']??null))
   return new \WP_Error('description_required','Translation text required',['status'=>400]);
  $copy=wp_kses_post($b['description']);
  $plain=trim(wp_strip_all_tags($copy));
  if(mb_strlen($plain)<100||mb_strlen($plain)>15000)
   return new \WP_Error('invalid_length','Translation length 100–15000',['status'=>422]);
  if($old['target_language']==='ar'&&!preg_match('/[\x{0600}-\x{06FF}]/u',$plain))
   return new \WP_Error('script_invalid','Arabic script required',['status'=>422]);
  $live=SmartSeoTranslation::inspect($old['taxonomy'],(int)$old['source_id'],$old['target_language']);
  if(is_wp_error($live))return $live;
  if($live['source']['description_sha256']!==$old['source_sha256']||
   $live['target']['description_sha256']!==$old['target_sha256'])
   return new \WP_Error('content_drift','Source or target changed',['status'=>409]);
  if(hash('sha256',$plain)===$live['source']['content_sha256'])
   return new \WP_Error('copied_source','English copy is not a translation',['status'=>422]);
  $provider=(string)($b['provider']??'manual');
  if(!in_array($provider,['manual','wpml_ate','custom_provider'],true))
   return new \WP_Error('invalid_provider','Unknown translation provider',['status'=>422]);
  $next=$old;$next['candidate']=$copy;$next['provider']=$provider;
  $next['review']=null;$next['status']='draft';$next['updated_at']=gmdate('c');
  return self::save($old,$next);
 }
 public static function review(\WP_REST_Request $r) {
  $old=self::locked($r);if(is_wp_error($old))return $old;
  $b=self::input($r);
  if($old['status']!=='draft'||!is_bool($b['approved']??null)||!$old['candidate'])
   return new \WP_Error('review_invalid','Review current draft',['status'=>409]);
  $next=$old;$next['status']=$b['approved']?'reviewed':'rejected';
  $next['review']=['approved'=>$b['approved'],
   'reviewed_by'=>get_current_user_id(),
   'candidate_sha256'=>hash('sha256',$old['candidate']),'at'=>gmdate('c')];
  $next['updated_at']=gmdate('c');
  return self::save($old,$next);
 }
}
