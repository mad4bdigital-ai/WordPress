<?php
/**
 * Governed Staging-only exact localized dynamic-label repair.
 * Copies listing 45927 to a WPML target locale and changes ONLY encoded
 * JetEngine jet-query-count "after" and "custom_format" strings, then remaps
 * the matching parent Elementor listing ID in template 25909's translation.
 * Usage through wp eval-file, env MAD4B_TERM_TARGET=ar and MAD4B_TERM_APPLY=yes.
 */
if(!defined('ABSPATH'))exit(1);
$policy=[
 'ar'=>['after'=>' رحلات','custom_format'=>'عرض [start-item]–[end-item] من [total] عناصر'],
 'fr'=>['after'=>' circuits','custom_format'=>'Affichage de [start-item] à [end-item] sur [total] éléments'],
 'de'=>['after'=>' Touren','custom_format'=>'Anzeige [start-item]–[end-item] von [total] Einträgen'],
 'es'=>['after'=>' excursiones','custom_format'=>'Mostrando [start-item]–[end-item] de [total] elementos'],
 'it'=>['after'=>' tour','custom_format'=>'Visualizzazione di [start-item]–[end-item] su [total] elementi'],
];
$lang=strtolower(trim((string)getenv('MAD4B_TERM_TARGET')));
$apply=getenv('MAD4B_TERM_APPLY')==='yes';
function lc_error($code,$detail=[]){
 echo wp_json_encode(['contract'=>'mad4b.wpml.localized-term-count.v1',
 'error'=>$code,'detail'=>$detail,'finished'=>false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
 if(defined('WP_CLI')&&WP_CLI)WP_CLI::error($code);
 exit(1);
}
if(!isset($policy[$lang]))lc_error('unsupported_locale');
if(wp_get_environment_type()!=='staging'
 || home_url()!=='https://tours.dev.mad4b.com'
 || get_current_user_id()!==26
 || !MAD4B_SCP_Policy::can_mutate())lc_error('site_or_authority_not_allowed');
$scope=MAD4B_SCP_Operational_Integrity::capture();
if(is_wp_error($scope)||($scope['scope']['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')
 lc_error('site_identity_mismatch');
$source=45927;$parent=25909;
if(MAD4B_WPML_Translation_Need_V1::source($source)['classification']!=='translation_required'
 || !mad4b_wpml_auto_sync_allowed_v1($source,$parent))lc_error('source_not_approved_for_localization');
$parent_trid=(int)apply_filters('wpml_element_trid',null,$parent,'post_elementor_library');
$parent_tr=(array)apply_filters('wpml_get_element_translations',null,$parent_trid,'post_elementor_library');
$parent_target=(int)($parent_tr[$lang]->element_id??0);
if(!$parent_target||get_post_status($parent_target)!=='publish'
 || !mad4b_wpml_auto_sync_allowed_v1($parent,$parent_target))lc_error('translated_parent_not_ready');
$source_trid=(int)apply_filters('wpml_element_trid',null,$source,'post_jet-engine');
if(!$source_trid)lc_error('listing_missing_wpml_source');
$source_tr=(array)apply_filters('wpml_get_element_translations',null,$source_trid,'post_jet-engine');
$target=(int)($source_tr[$lang]->element_id??0);
if($target && (int)get_post_meta($target,'_mad4b_wpml_technical_source_id',true)!==$source)
 lc_error('existing_unowned_translation_requires_review',['id'=>$target]);
$raw=get_post_meta($source,'_elementor_data',true);
$tree=is_string($raw)?json_decode($raw,true):$raw;
if(!is_array($tree)||!$tree)lc_error('empty_source_tree');
$count_tags=0;
$translate=function(&$nodes)use(&$translate,$policy,$lang,&$count_tags){
 foreach($nodes as &$node){
  if(!is_array($node))continue;
  if(($node['id']??'')==='402c8ca'){
   $tag=(string)($node['settings']['__dynamic__']['field_fallback']??'');
   if(!preg_match('/settings="([^"]+)"/',$tag,$m))lc_error('missing_encoded_query_count');
   $settings=json_decode(rawurldecode($m[1]),true);
   if(!is_array($settings)||($settings['after']??'')!==' Tours'
    ||($settings['custom_format']??'')!=='Showing [start-item]-[end-item] of [total] items')
    lc_error('unexpected_source_dynamic_values',$settings);
   $settings['after']=$policy[$lang]['after'];
   $settings['custom_format']=$policy[$lang]['custom_format'];
   $encoded=rawurlencode(wp_json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   $node['settings']['__dynamic__']['field_fallback']=str_replace($m[1],$encoded,$tag);
   $count_tags++;
  }
  if(isset($node['elements'])&&is_array($node['elements']))$translate($node['elements']);
 }
 unset($node);
};
$translate($tree);
if($count_tags!==1)lc_error('dynamic_count_widget_not_unique',['found'=>$count_tags]);
$parent_raw=get_post_meta($parent_target,'_elementor_data',true);
$parent_tree=is_string($parent_raw)?json_decode($parent_raw,true):$parent_raw;
if(!is_array($parent_tree)||!$parent_tree)lc_error('missing_parent_tree');
$old_ref_count=0;
$inspect_parent=function(&$nodes,$new_id=0)use(&$inspect_parent,&$old_ref_count){
 foreach($nodes as &$node){
  if(!is_array($node))continue;
  if(($node['id']??'')==='b25dbff'){
   if((string)($node['settings']['lisitng_id']??'')!=='45927')lc_error('parent_reference_not_canonical');
   if($new_id)$node['settings']['lisitng_id']=(string)$new_id;
   $old_ref_count++;
  }
  if(isset($node['elements'])&&is_array($node['elements']))$inspect_parent($node['elements'],$new_id);
 }
 unset($node);
};
$inspect_parent($parent_tree);
if($old_ref_count!==1)lc_error('parent_listing_widget_mismatch');
if(!$apply){
 echo wp_json_encode(['contract'=>'mad4b.wpml.localized-term-count.v1',
 'language'=>$lang,'source_listing'=>$source,'source_trid'=>$source_trid,
 'existing_translated_listing'=>$target,'translated_parent'=>$parent_target,
 'literal_suffix'=>$policy[$lang]['after'],'dynamic_count_field_confirmed'=>true,
 'parent_widget_confirmed'=>true,'apply'=>false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
 return;
}
// Work is owned by a verified operator; preserve replay-safe provenance.
if($target===0){
 $post=get_post($source);
 $new=wp_insert_post(wp_slash([
  'post_type'=>$post->post_type,'post_status'=>'draft',
  'post_title'=>$post->post_title,'post_content'=>$post->post_content,
  'post_excerpt'=>$post->post_excerpt,'post_author'=>get_current_user_id()
 ]),true);
 if(is_wp_error($new))lc_error($new->get_error_code());
 $target=(int)$new;
 foreach(['_elementor_edit_mode','_elementor_template_type','_elementor_page_settings','_listing_data'] as $key){
  $meta=get_post_meta($source,$key,true);
  if($meta!==''&&$meta!==null)
   update_post_meta($target,$key,is_string($meta)?wp_slash($meta):$meta);
 }
 update_post_meta($target,'_mad4b_wpml_technical_source_id',$source);
 update_post_meta($target,'_mad4b_wpml_localized_count_contract','mad4b.wpml.localized-term-count.v1');
 do_action('wpml_set_element_language_details',[
   'element_id'=>$target,'element_type'=>'post_jet-engine',
   'trid'=>$source_trid,'language_code'=>$lang,'source_language_code'=>'en'
 ]);
}
$link=(array)apply_filters('wpml_post_language_details',null,$target);
if(($link['language_code']??'')!==$lang
 ||(int)apply_filters('wpml_element_trid',null,$target,'post_jet-engine')!==$source_trid)
 lc_error('target_link_readback_failed',['draft_id'=>$target]);
$marker='mad4b_wpml_terms_localized_45927_'.$lang.'_20261102';
$backup=['source'=>$source,'target'=>$target,'lang'=>$lang,'parent'=>$parent_target,
 'target_before'=>get_post_meta($target,'_elementor_data',true),
 'parent_before'=>$parent_raw,'created_at'=>gmdate('c')];
if(get_option($marker,false)===false){
 add_option($marker,$backup,false);
 if(get_option($marker,false)===false)lc_error('backup_persistence_failed');
}
$json=wp_json_encode($tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
update_post_meta($target,'_elementor_data',wp_slash($json));
$verify=get_post_meta($target,'_elementor_data',true);
if(json_decode((string)$verify,true)!==$tree)lc_error('target_dynamic_tag_readback_failed',['target'=>$target]);
$result=wp_update_post(['ID'=>$target,'post_status'=>'publish'],true);
if(is_wp_error($result)||get_post_status($target)!=='publish')
 lc_error('target_publish_readback_failed',['target'=>$target]);
$remapped=0;
$rewrite=function(&$nodes)use(&$rewrite,$target,&$remapped){
 foreach($nodes as &$node){
  if(!is_array($node))continue;
  if(($node['id']??'')==='b25dbff'){
   $node['settings']['lisitng_id']=(string)$target;$remapped++;
  }
  if(isset($node['elements'])&&is_array($node['elements']))$rewrite($node['elements']);
 }unset($node);
};
$rewrite($parent_tree);
if($remapped!==1)lc_error('parent_update_mismatch');
update_post_meta($parent_target,'_elementor_data',wp_slash(wp_json_encode($parent_tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
if(json_decode((string)get_post_meta($parent_target,'_elementor_data',true),true)!==$parent_tree)
 lc_error('parent_reference_readback_failed',['target'=>$target,'parent'=>$parent_target]);
update_option('mad4b_wpml_terms_count_localized_'.$lang,[
 'source_listing'=>45927,'translated_listing'=>$target,'parent'=>$parent_target,
 'suffix'=>$policy[$lang]['after'],'at'=>gmdate('c'),
 'source_sha256'=>hash('sha256',(string)get_post_meta($source,'_elementor_data',true))
],false);
echo wp_json_encode(['contract'=>'mad4b.wpml.localized-term-count.v1',
 'language'=>$lang,'source_listing'=>45927,'translated_listing'=>$target,
 'translated_parent'=>$parent_target,'status'=>get_post_status($target),
 'list_wpml_link_verified'=>true,'dynamic_suffix'=>$policy[$lang]['after'],
 'parent_reference_verified'=>true,'applied'=>true,
 'rollback_option'=>$marker],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
