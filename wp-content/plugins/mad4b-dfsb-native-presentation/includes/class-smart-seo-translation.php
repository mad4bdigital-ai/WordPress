<?php
namespace MAD4B\DFSB\NativePresentation;
if (!defined('ABSPATH')) exit;

/**
 * Translation repair with native WPML IDs, per-language status, and CAS records.
 * Content is NEVER auto-copied from English into another language.
 */
final class SmartSeoTranslation {
 const CONTRACT='mad4b.seo.translation-repair.v1';
 const PREFIX='mad4b_seo_translation_v1_';
 const MAX_BATCH=75;

 public static function languages(): array {
  $wpml=(array)get_option('icl_sitepress_settings',[]);
  $langs=(array)($wpml['active_languages']??array());
  $allowed=array();
  foreach($langs as $code) if(is_string($code) && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/i',$code))
    $allowed[]=$code;
  return array_values(array_unique($allowed));
 }
 public static function withLanguage(string $lang,callable $callback) {
  $current=(string)apply_filters('wpml_current_language','en');
  if(!in_array($lang,self::languages(),true))
   return new \WP_Error('unsupported_locale','Inactive WPML locale',array('status'=>422));
  if($lang!==$current)do_action('wpml_switch_language',$lang);
  try {return $callback();}
  finally {if($lang!==$current)do_action('wpml_switch_language',$current);}
 }
 public static function translationId(int $source,string $taxonomy,string $language): int {
  $id=self::withLanguage($language,static function()use($source,$taxonomy,$language) {
   return apply_filters('wpml_object_id',$source,$taxonomy,false,$language);
  });
  if(is_wp_error($id)||!is_numeric($id))return 0;
  $translated=(int)$id;
  $actual=apply_filters('wpml_element_language_code',null,array(
     'element_id'=>$translated,'element_type'=>'tax_'.$taxonomy));
  return $actual===$language?$translated:0;
 }
 public static function readTerm(int $id,string $taxonomy,string $language) {
  return self::withLanguage($language,static function()use($id,$taxonomy) {
   $term=get_term($id,$taxonomy);
   if(!$term instanceof \WP_Term)return null;
   $desc=(string)term_description($term->term_id,$taxonomy);
   $clean=trim(wp_strip_all_tags($desc));
   return array(
     'term_id'=>(int)$term->term_id,'name'=>(string)$term->name,
     'slug'=>(string)$term->slug,'description'=>$desc,
     'description_text'=>$clean,'description_chars'=>mb_strlen($clean),
     'description_sha256'=>hash('sha256',$desc),
     'content_sha256'=>hash('sha256',$clean),
   );
  });
 }
 public static function inspect(string $taxonomy,int $sourceId,string $language) {
  if(!taxonomy_exists($taxonomy))return new \WP_Error('unknown_taxonomy',
    'Taxonomy not registered',array('status'=>404));
  $sourceLang='en';
  $sourceActual=apply_filters('wpml_element_language_code',null,array(
     'element_id'=>$sourceId,'element_type'=>'tax_'.$taxonomy));
  if($sourceActual!==$sourceLang)
    return new \WP_Error('source_not_english','English source term required',array('status'=>422));
  $original=self::readTerm($sourceId,$taxonomy,$sourceLang);
  if(!is_array($original))return new \WP_Error('source_term_missing','Source term unavailable',array('status'=>404));
  $targetId=self::translationId($sourceId,$taxonomy,$language);
  $target=$targetId?self::readTerm($targetId,$taxonomy,$language):null;
  $status='missing_term_translation';
  if(is_array($target)) {
   if($target['description_chars']===0)$status='missing_description';
   elseif($language!=='en' && $original['content_sha256']===$target['content_sha256'])
     $status='source_text_copied';
   elseif($target['description_chars']<180)$status='short_content_review';
   else $status='candidate_for_editorial_review';
  }
  if($language==='en')$status='source';
  return array(
   'contract'=>self::CONTRACT,'taxonomy'=>$taxonomy,
   'source_id'=>$sourceId,'source_language'=>'en','target_language'=>$language,
   'source'=>$original,'target_id'=>$targetId,'target'=>$target,
   'status'=>$status,'editorial_reviewed'=>false,
   'automatic_english_fallback_allowed'=>false,
   'can_apply_to_existing_term'=>is_array($target)&&$language!=='en',
   'review_required'=>true,
  );
 }
 public static function taskId(string $taxonomy,int $sourceId,string $language): string {
  return hash('sha256',get_current_blog_id().'|'.$taxonomy.'|'.$sourceId.'|'.$language);
 }
 public static function recordKey(string $id): string {return self::PREFIX.$id;}
 public static function revision(array $record): string {return hash('sha256',wp_json_encode($record));}
 public static function get(string $id) {
  if(!preg_match('/^[a-f0-9]{64}$/D',$id))return new \WP_Error('invalid_translation_id','Invalid ID',array('status'=>400));
  $record=get_option(self::recordKey($id),null);
  return is_array($record)?$record:new \WP_Error('translation_task_missing','Task not found',array('status'=>404));
 }
 public static function write(string $id,$expected,array $next) {
  global $wpdb;
  $key=self::recordKey($id);
  $raw=$wpdb->get_var($wpdb->prepare(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
  if($expected===null && !is_string($raw)) {
   if(!add_option($key,$next,'','no'))
    return new \WP_Error('translation_task_conflict','Task already exists',array('status'=>409));
  }elseif(is_string($raw)){
   $previous=maybe_unserialize($raw);
   if(!is_array($previous)||!is_string($expected)||!hash_equals(self::revision($previous),$expected))
    return new \WP_Error('translation_revision_stale','Reload task before update',array('status'=>409));
   if(!SmartSeoNativeStore::compareAndSwapOption($key,$raw,maybe_serialize($next)))
    return new \WP_Error('translation_concurrent_write','Concurrent edit detected',array('status'=>409));
  }else return new \WP_Error('translation_task_missing','Expected record missing',array('status'=>404));
  return array('record'=>$next,'revision'=>self::revision($next));
 }
 public static function discover(string $taxonomy,int $sourceId,array $languages): array {
  $out=array();
  foreach($languages as $language){
   $item=self::inspect($taxonomy,$sourceId,$language);
   $out[$language]=is_wp_error($item)?array('error'=>$item->get_error_code()):$item;
  }
  return $out;
 }
 public static function listTasks(int $limit=50): array {
  global $wpdb;
  $limit=max(1,min(100,$limit));
  $names=$wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",
    $wpdb->esc_like(self::PREFIX).'%', $limit));
  $records=array();
  foreach($names as $name){
   $record=get_option($name,null);
   if(is_array($record))$records[]=array('revision'=>self::revision($record),'record'=>$record);
  }
  return $records;
 }
}
