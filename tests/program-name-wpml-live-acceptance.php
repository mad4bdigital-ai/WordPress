<?php
defined('ABSPATH')||exit;
$site=home_url();
$locales=$site==='https://wayfatours.com'?['en','es']:['en','es','fr','de','ar','it'];
$original=apply_filters('wpml_current_language','en');
$rows=[];$root=2351;
$sourceTerms=[2348,2349,2350];
$opt=get_option('icl_sitepress_settings',[]);
$copy=(array)($opt['translation-management']['custom_fields_translation']??[]);
if(($copy['related_program_terms']??null)!==1||($copy['program_term_id']??null)!==1||
   (string)($opt['taxonomies_sync_option']['programs']??'')!=='2')
  throw new RuntimeException('program_wm_id_fields_not_copy_or_taxonomy_not_translatable');
try {
 foreach($locales as $lang){
  do_action('wpml_switch_language',$lang);
  $before=apply_filters('wpml_current_language','');
  $actual=MAD4B_Nile_Program_First_Editor_V1::program_options(0);
  $after=apply_filters('wpml_current_language','');
  if(array_column($actual,'key')!==['2348','2349','2350'] || $before!==$after)
    throw new RuntimeException('program_option_names_or_canonical_ids_lost_'.$lang);
  $rootId=$lang==='en'?$root:(int)apply_filters('wpml_object_id',$root,'programs',false,$lang);
  $terms=[];
  foreach($sourceTerms as $sourceId){
   $termId=$lang==='en'?$sourceId:(int)apply_filters('wpml_object_id',$sourceId,'programs',false,$lang);
   $term=get_term($termId,'programs');
   if(!$term instanceof WP_Term||$term->parent!==$rootId)
      throw new RuntimeException('localized_program_root_hierarchy_not_synced_'.$lang.'_'.$termId);
   $terms[]=['source_id'=>$sourceId,'localized_id'=>$termId,'localized_label'=>$term->name,'parent'=>$term->parent];
  }
  $rows[$lang]=['editor_options'=>$actual,'parent_terms'=>$terms,'language_restored'=>true];
 }
}finally {do_action('wpml_switch_language',$original);}
$tour=get_post(60629);
ob_start();
MAD4B_Nile_Program_First_Editor_V1::metabox($tour);
$html=(string)ob_get_clean();
if(!str_contains($html,'Translate Programs names (WPML)')||
   !str_contains($html,'taxonomy=programs')||
   !str_contains($html,'Translate Program names, not numeric IDs.'))
  throw new RuntimeException('admin_translation_guidance_missing');
echo wp_json_encode([
 'site'=>$site,'language_count'=>count($rows),
 'all_languages_pass'=>true,
 'custom_fields_copy'=>['related_program_terms'=>$copy['related_program_terms'],
    'program_term_id'=>$copy['program_term_id']],
 'wpml_program_taxonomy_translatable'=>true,'admin_link_available'=>true,
 'languages'=>$rows,'saved_tour_program_fields_changed'=>false,
 'program_names_auto_translated'=>false,'bookings_changed'=>false
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;

