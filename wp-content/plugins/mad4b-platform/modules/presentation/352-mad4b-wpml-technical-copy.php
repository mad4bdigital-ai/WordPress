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
  $policy=class_exists('MAD4B_WPML_Design_Reconciliation_V1')
    ?MAD4B_WPML_Design_Reconciliation_V1::required_languages_readback():[];
  if(!in_array($lang,(array)($policy['required']??[]),true)||
     $lang===($policy['default']??''))return new WP_Error('technical_copy_bad_locale');
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
     '_elementor_page_settings','_listing_data','_listing_type'];
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
 /** Publish only engine-owned, unchanged text-free technical Drafts. */
 public static function publish_verified($source_id,$locale){
  if(!self::ready()||!MAD4B_SCP_Policy::can_mutate()||
     !current_user_can('manage_options'))
   return new WP_Error('technical_publish_operator_denied');
  $source_id=absint($source_id);$locale=sanitize_key((string)$locale);
  $source=get_post($source_id);
  if(!$source||$source->post_status!=='publish'||
     !mad4b_wpml_auto_sync_allowed_v1($source_id))
   return new WP_Error('technical_publish_source_not_eligible');
  $kind=MAD4B_WPML_Translation_Need_V1::source($source_id);
  if(is_wp_error($kind)||($kind['classification']??'')!=='technical_only')
   return new WP_Error('technical_publish_editorial_content_not_allowed');
  $policy=MAD4B_WPML_Design_Reconciliation_V1::required_languages_readback();
  if(!in_array($locale,$policy['required'],true)||
     $locale===$policy['default'])
   return new WP_Error('technical_publish_locale_not_active');
  $target_id=absint(apply_filters('wpml_object_id',$source_id,
      $source->post_type,false,$locale));
  $target=$target_id?get_post($target_id):null;
  $trid=(int)apply_filters('wpml_element_trid',null,$source_id,
      'post_'.$source->post_type);
  $target_trid=$target_id?(int)apply_filters('wpml_element_trid',
      null,$target_id,'post_'.$source->post_type):0;
  $link=$target_id?(array)apply_filters('wpml_post_language_details',null,$target_id):[];
  if(!$target||$target->post_type!==$source->post_type||
     $target->post_status!=='draft'||!$trid||$target_trid!==$trid||
     ($link['language_code']??'')!==$locale||
     !mad4b_wpml_auto_sync_allowed_v1($source_id,$target_id))
   return new WP_Error('technical_publish_wpml_link_or_draft_invalid');
  if(get_post_meta($target_id,'_mad4b_wpml_technical_copy_contract',true)!==self::CONTRACT||
     (int)get_post_meta($target_id,'_mad4b_wpml_technical_source_id',true)!==$source_id||
     get_post_meta($target_id,'_mad4b_wpml_technical_source_sha256',true)!==
       $kind['audited_source_sha256'])
   return new WP_Error('technical_publish_source_revision_changed');
  foreach(['_listing_data','_listing_type','_elementor_data',
       '_elementor_page_settings','_elementor_template_type'] as $key){
   $orig=get_post_meta($source_id,$key,true);
   $copy=get_post_meta($target_id,$key,true);
   $equal=$key==='_elementor_data'
      ?json_decode((string)$orig,true)===json_decode((string)$copy,true)
      :$orig===$copy;
   if(!$equal)return new WP_Error('technical_publish_meta_not_inherited_'.$key);
  }
  $registry=(array)get_option(self::REG,[]);
  if((int)($registry[$target_id]['source']??0)!==$source_id||
     ($registry[$target_id]['lang']??'')!==$locale||
     ($registry[$target_id]['state']??'')!=='draft_verified')
   return new WP_Error('technical_publish_registry_readback_missing');
  $published=wp_update_post(['ID'=>$target_id,'post_status'=>'publish'],true);
  if(is_wp_error($published)||get_post_status($target_id)!=='publish')
   return new WP_Error('technical_publish_wp_status_not_confirmed');
  $next=(int)apply_filters('wpml_object_id',$source_id,
      $source->post_type,false,$locale);
  if($next!==$target_id||
     (int)apply_filters('wpml_element_trid',null,$target_id,
       'post_'.$source->post_type)!==$trid){
    wp_update_post(['ID'=>$target_id,'post_status'=>'draft']);
    return new WP_Error('technical_publish_link_failed_draft_restored');
  }
  $cssProof=class_exists('MAD4B_Elementor_CSS_Native_Reconcile_CLI')
    ?MAD4B_Elementor_CSS_Native_Reconcile_CLI::after_wpml_publication($target_id)
    :new WP_Error('mad4b_original_css_owner_not_loaded');
  if(is_wp_error($cssProof)){
    wp_update_post(['ID'=>$target_id,'post_status'=>'draft']);
    return new WP_Error('technical_publish_css_failed_and_draft_restored',
      $cssProof->get_error_code());
  }
  $registry[$target_id]['state']='published_native_readback';
  $registry[$target_id]['published_at']=gmdate('c');
  update_option(self::REG,$registry,false);
  return ['contract'=>'mad4b.wpml.technical-publish.v1',
   'source_id'=>$source_id,'target_id'=>$target_id,'lang'=>$locale,
   'wpml_link_verified'=>true,'technical_source_sha256'=>$kind['audited_source_sha256'],
   'post_status'=>'publish',
   'css_post_publish_readback'=>$cssProof,
   'browser_render_interaction_qa_pending'=>true,
   'production_mutation_performed'=>false];
 }
 public static function cli($args){
  $mode=$args[0]??'preview';$src=(int)($args[1]??0);$lang=(string)($args[2]??'');
  if($mode==='preview')$r=self::preview($src,$lang);
  elseif($mode==='create-draft')$r=self::create($src,$lang);
  elseif($mode==='publish-verified')$r=self::publish_verified($src,$lang);
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
