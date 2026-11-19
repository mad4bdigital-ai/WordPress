<?php
/**
 * Normalize 2026 PDF draft trans_mode canonical cache for WPML Tour Components.
 * Native Shared Product presentation validates original Road term 568, while
 * localized importer previously wrote translated term ID to trans_mode.
 *
 * Only exact immutable-source Draft posts are in scope; term relations,
 * source data, service mode/type, prices and status are never altered.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
    WP_CLI::error('site_not_allowed');
if($site==='https://wayfatours.com' &&
   !(defined('MAD4B_WAYFA_PDF_MODE_APPROVED') &&
     MAD4B_WAYFA_PDF_MODE_APPROVED===true))
    WP_CLI::error('production_guard_missing');
$sha='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
$modeKey='trans_mode';
$backupKey='mad4b_transport_pdf_mode_canonical_20261119_v1';
$apply=defined('MAD4B_PDF_MODE_APPLY')&&MAD4B_PDF_MODE_APPLY===true;
$rollback=defined('MAD4B_PDF_MODE_ROLLBACK')&&MAD4B_PDF_MODE_ROLLBACK===true;
$posts=get_posts(['post_type'=>'transportations','post_status'=>'draft',
 'posts_per_page'=>600,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>$sha]);
$active=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
$expected=$site==='https://wayfatours.com'?['en','es']:['ar','de','en','es','fr','it'];
sort($active);sort($expected);
if($active!==$expected || count($posts)!==count($active)*51)
    WP_CLI::error('source_locales_or_count_changed');
$options=get_option('icl_sitepress_settings',[]);
$oldCopy=$options['translation-management']['custom_fields_translation'][$modeKey]??null;
$fix=[];$unchanged=[];$langs=[];
foreach($posts as $post_id){
    $id=(int)$post_id;
    $lang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
    $current=(int)get_post_meta($id,$modeKey,true);
    $wpml=apply_filters('wpml_post_language_details',null,$id);
    $translated=(int)apply_filters('wpml_object_id',568,'transportation-types',false,$lang);
    $sourceKey=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
    if(!in_array($lang,$active,true) ||
       ($wpml['language_code']??'')!==$lang ||
       !preg_match('/^(Cairo|Aswan|Luxor):[1-9][0-9]?$/D',$sourceKey) ||
       get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=1)
        WP_CLI::error('draft_identity_not_safe_'.$id);
    if(!has_term(568,'transportation-types',$id))
        WP_CLI::error('canonical_taxonomy_missing_'.$id);
    if($current===568){$unchanged[]=$id;continue;}
    if($translated===0 || $current!==$translated || $lang==='en')
        WP_CLI::error('unexpected_noncanonical_mode_'.$id);
    $fix[$id]=$current;
    $langs[$lang]=($langs[$lang]??0)+1;
}
$backup=get_option($backupKey,false);
if($rollback){
    if(!is_array($backup)||count($backup['prior_values']??[])<1)
        WP_CLI::error('rollback_backup_missing');
    foreach($backup['prior_values'] as $id=>$old){
        if((string)get_post_meta((int)$id,$modeKey,true)!=='568')
            WP_CLI::error('rollback_conflict_'.$id);
    }
    foreach($backup['prior_values'] as $id=>$old) update_post_meta((int)$id,$modeKey,$old);
    $settings=get_option('icl_sitepress_settings',[]);
    if(($settings['translation-management']['custom_fields_translation'][$modeKey]??null)!==1)
        WP_CLI::error('wpml_copy_rollback_conflict');
    if($backup['prior_wpml_copy']===null)
        unset($settings['translation-management']['custom_fields_translation'][$modeKey]);
    else
        $settings['translation-management']['custom_fields_translation'][$modeKey]=$backup['prior_wpml_copy'];
    update_option('icl_sitepress_settings',$settings);
    WP_CLI::log(wp_json_encode(['status'=>'rolled_back','records'=>count($backup['prior_values'])]));
    return;
}
if(!$apply){
    WP_CLI::log(wp_json_encode(['status'=>'dry_run','site'=>$site,
        'source_records'=>count($posts),'mode_to_fix'=>count($fix),
        'already_canonical'=>count($unchanged),'by_language'=>$langs,
        'wpml_copy_after'=>1,'source_rates_mutated'=>0,
        'component_status_unchanged'=>true]));
    return;
}
if($backup!==false){
    if(array_diff_key($fix,$backup['prior_values']??[]) ||
       count($backup['prior_values']??[])<count($fix))
        WP_CLI::error('prior_backup_conflicts_with_current_state');
}else{
    if(!add_option($backupKey,['at'=>gmdate('c'),'prior_values'=>$fix,
        'prior_wpml_copy'=>$oldCopy], '',false)) WP_CLI::error('backup_store_failed');
}
$admin=get_user_by('id',1);
if(!$admin||!user_can($admin,'manage_options'))WP_CLI::error('admin_missing');
wp_set_current_user((int)$admin->ID);
foreach($fix as $id=>$old) update_post_meta((int)$id,$modeKey,568);
$options['translation-management']['custom_fields_translation'][$modeKey]=1;
update_option('icl_sitepress_settings',$options);
$check=get_option('icl_sitepress_settings',[]);
if(($check['translation-management']['custom_fields_translation'][$modeKey]??null)!==1)
    WP_CLI::error('wpml_copy_readback_failed');
foreach($posts as $id)
    if((int)get_post_meta((int)$id,$modeKey,true)!==568)
        WP_CLI::error('post_mode_readback_failed_'.$id);
WP_CLI::log(wp_json_encode(['status'=>'canonicalized',
    'source_posts'=>count($posts),'updated'=>count($fix),
    'already_canonical'=>count($unchanged),
    'canonical_mode_id'=>568,'wpml_mode_copy'=>1,
    'taxonomy_relations_changed'=>0,'pricing_changed'=>false,
    'tour_component_status_changed'=>false,'checkout_authorized'=>false]));
