<?php
/**
 * Plugin Name: MAD4B WPML Technical Template Reconciler
 * Description: Governed WPML links for genuinely text-free Elementor/JetEngine structures. Never translates editorial strings.
 * Version: 0.1.0-staging
 */
defined('ABSPATH')||exit;
final class MAD4B_WPML_Technical_Copy_V1 {
 const REG='mad4b_wpml_technical_copy_registry_v1';
 const CONTRACT='mad4b.wpml.technical-copy.v1';
 private static function ready(){
  return class_exists('MAD4B_WPML_Translation_Need_V1')
   && MAD4B_WPML_Translation_Need_V1::ready()
   && class_exists('MAD4B_SCP_Policy')
   && function_exists('mad4b_wpml_auto_sync_allowed_v1');
 }
 private static function source($id,$lang){
  if(!self::ready())return new WP_Error('technical_copy_not_ready');
  $id=absint($id);$lang=sanitize_key((string)$lang);
  if(!in_array($lang,['ar','de','fr','es','it'],true))return new WP_Error('technical_copy_bad_locale');
  $post=get_post($id);
  if(!$post||$post->post_status!=='publish'||!in_array($post->post_type,['elementor_library','jet-engine'],true))
   return new WP_Error('technical_copy_ineligible_source');
  if(!mad4b_wpml_auto_sync_allowed_v1($id))return new WP_Error('technical_copy_master_or_post_exclusion');
  $lc=apply_filters('wpml_post_language_details',null,$id);
  if(($lc['language_code']??'')!==(string)apply_filters('wpml_default_language',null))return new WP_Error('technical_copy_not_default_source');
  $analysis=MAD4B_WPML_Translation_Need_V1::source($id);
  if(is_wp_error($analysis)||($analysis['classification']??'')!=='technical_only')
   return new WP_Error('technical_copy_has_fixed_text_or_uncertain_render');
  $trid=(int)apply_filters('wpml_element_trid',null,$id,'post_'.$post->post_type);
  if(!$trid)return new WP_Error('technical_copy_no_wpml_trid');
  $links=(array)apply_filters('wpml_get_element_translations',null,$trid,'post_'.$post->post_type);
  $existing=isset($links[$lang])?(int)($links[$lang]->element_id??0):0;
  if($existing||!mad4b_wpml_auto_sync_allowed_v1($id,$existing))return new WP_Error('technical_copy_existing_or_excluded');
  return ['post'=>$post,'lang'=>$lang,'trid'=>$trid,'analysis'=>$analysis];
 }
 public static function preview($id,$lang){
  $r=self::source($id,$lang);
  if(is_wp_error($r))return ['contract'=>self::CONTRACT,'eligible'=>false,'why'=>$r->get_error_code(),
   'source_id'=>(int)$id,'target_locale'=>sanitize_key((string)$lang),'mutation_performed'=>false];
  return ['contract'=>self::CONTRACT,'eligible'=>true,
   'source_id'=>(int)$id,'post_type'=>$r['post']->post_type,'target_locale'=>$lang,
   'source_sha256'=>$r['analysis']['audited_source_sha256'],
   'visible_literals'=>$r['analysis']['visible_literals'],
   'target_status'=>'draft_for_review','mutation_performed'=>false];
 }
 public static function create($id,$lang){
  if(!self::ready()||true!==MAD4B_SCP_Policy::can_mutate())
   return new WP_Error('technical_copy_authority_denied');
  global $wpdb;
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)','mad4b_wpml_technical_copy_v1'))!=='1')
   return new WP_Error('technical_copy_lock_busy');
  try{
   $r=self::source($id,$lang);if(is_wp_error($r))return $r;
   $source=$r['post'];$locale=$r['lang'];$trid=$r['trid'];
   $id=(int)$source->ID;
   $post_type=$source->post_type;
   $post_data=['post_type'=>$post_type,'post_status'=>'draft',
     'post_title'=>$source->post_title,'post_content'=>$source->post_content,
     'post_excerpt'=>$source->post_excerpt,'post_author'=>get_current_user_id(),
     'comment_status'=>'closed','ping_status'=>'closed'];
   $created=wp_insert_post(wp_slash($post_data),true);
   if(is_wp_error($created))return $created;
   $target=(int)$created;
   $meta=['_elementor_data','_elementor_edit_mode','_elementor_template_type',
     '_elementor_page_settings','_listing_data'];
   foreach($meta as $key){
    $raw=get_post_meta($id,$key,true);
    if($raw===''||$raw===null)continue;
    // Preserve Elementor JSON escaping, stored structure and provider metadata.
    update_post_meta($target,$key,is_string($raw)?wp_slash($raw):$raw);
   }
   update_post_meta($target,'_mad4b_wpml_technical_source_id',$id);
   update_post_meta($target,'_mad4b_wpml_technical_source_sha256',$r['analysis']['audited_source_sha256']);
   update_post_meta($target,'_mad4b_wpml_technical_copy_contract',self::CONTRACT);
   do_action('wpml_set_element_language_details',[
    'element_id'=>$target,'element_type'=>'post_'.$post_type,
    'trid'=>$trid,'language_code'=>$locale,
    'source_language_code'=>(string)apply_filters('wpml_default_language',null)
   ]);
   $details=apply_filters('wpml_post_language_details',null,$target);
   $target_trid=(int)apply_filters('wpml_element_trid',null,$target,'post_'.$post_type);
   if(($details['language_code']??'')!==$locale || $target_trid!==$trid
     || (int)get_post_meta($target,'_mad4b_wpml_technical_source_id',true)!==$id){
     // Do not publish unlinked content; retain the Draft for incident reconciliation.
     return new WP_Error('technical_copy_link_readback_failed','WPML link mismatch',[
      'draft_id'=>$target,'reconciliation_required'=>true]);
   }
   $reg=(array)get_option(self::REG,[]);
   $reg[$target]=['source'=>$id,'lang'=>$locale,'trid'=>$trid,
    'source_sha256'=>$r['analysis']['audited_source_sha256'],
    'state'=>'draft_verified','created_at'=>gmdate('c')];
   update_option(self::REG,$reg,false);
   if(get_option(self::REG,[])!==$reg)return new WP_Error('technical_copy_registry_uncertain','Audit mapping mismatch',[
    'draft_id'=>$target,'reconciliation_required'=>true]);
   return ['contract'=>self::CONTRACT,'created_draft_id'=>$target,
    'source_id'=>$id,'language'=>$locale,'trid'=>$trid,
    'wpml_link_verified'=>true,'status'=>'draft','published'=>false];
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)','mad4b_wpml_technical_copy_v1'));}
 }
 public static function cli($args){
  $mode=$args[0]??'preview';$src=(int)($args[1]??0);$lang=(string)($args[2]??'');
  if($mode==='preview')$r=self::preview($src,$lang);
  elseif($mode==='create-draft')$r=self::create($src,$lang);
  else WP_CLI::error('Usage: wp mad4b-technical-copy preview|create-draft <source_id> <locale>');
  if(is_wp_error($r))WP_CLI::error($r->get_error_code().': '.wp_json_encode($r->get_error_data()));
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot(){
  if(self::ready()&&defined('WP_CLI')&&WP_CLI)
   WP_CLI::add_command('mad4b-technical-copy',[__CLASS__,'cli']);
 }
}
add_action('plugins_loaded',['MAD4B_WPML_Technical_Copy_V1','boot'],119);
