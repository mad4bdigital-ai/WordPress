<?php
/**
 * Company-Self trusted 2026 PDF transportation component opt-in.
 *
 * Site-bound; changes only trans_use_in_tour_rate_generation on exact-source
 * Draft Transportation posts. No publish/availability/price/checkout actions.
 *
 * Dry: wp eval-file /tmp/enable-pdf-transport-tour-components.php
 * Apply: wp eval 'define("MAD4B_PDF_COMPONENT_YES_APPLY",true);require "/tmp/...";'
 * Wayfa: additionally MAD4B_PDF_COMPONENT_WAYFA_APPROVED=true
 * Rollback: MAD4B_PDF_COMPONENT_YES_ROLLBACK=true
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
$isWayfa=$site==='https://wayfatours.com';
if ($site!=='https://tours.dev.mad4b.com' && !$isWayfa)
    WP_CLI::error('site_not_allowed');
if ($isWayfa && !(defined('MAD4B_PDF_COMPONENT_WAYFA_APPROVED')
    && MAD4B_PDF_COMPONENT_WAYFA_APPROVED===true))
    WP_CLI::error('wayfa_approval_guard_missing');

const MAD4B_PDF_COMPONENT_SHA='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
const MAD4B_PDF_COMPONENT_KEY='trans_use_in_tour_rate_generation';
$expectedLanguages=$isWayfa?['en','es']:['ar','de','en','es','fr','it'];
$languageItems=(array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
$languages=array_keys($languageItems);
sort($languages);
if($languages!==$expectedLanguages)
    WP_CLI::error('wpml_active_languages_changed');
$settings=get_option('icl_sitepress_settings',[]);
if(($settings['translation-management']['custom_fields_translation'][MAD4B_PDF_COMPONENT_KEY]??null)!==1)
    WP_CLI::error('component_wpml_copy_not_enabled');

$ids=get_posts([
 'post_type'=>'transportations','post_status'=>'draft','posts_per_page'=>600,
 'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256',
 'meta_value'=>MAD4B_PDF_COMPONENT_SHA
]);
if(count($ids)!==51*count($languages))
    WP_CLI::error('source_post_total_changed');

$sourceIndex=[];$tridIndex=[];$current=[];$original=[];
foreach($ids as $id) {
    $id=(int)$id;
    $key=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
    $lang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
    $details=apply_filters('wpml_post_language_details',null,$id);
    $trid=apply_filters('wpml_element_trid',null,$id,'post_transportations');
    $value=(string)get_post_meta($id,MAD4B_PDF_COMPONENT_KEY,true);
    if(!preg_match('/^(Cairo|Aswan|Luxor):[1-9][0-9]?$/D',$key) ||
        !in_array($lang,$languages,true) ||
        ($details['language_code']??null)!==$lang || !$trid ||
        get_post_status($id)!=='draft' ||
        (string)get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)!=='self' ||
        (string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_trust',true)!=='trusted_original_pdf' ||
        (string)get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=='1' ||
        (int)get_post_meta($id,'trans_mode',true)!==568 ||
        !in_array($value,['0','1'],true)) WP_CLI::error('source_guard_failed_'.$id);
    $sourceIndex[$key][$lang][]=$id;
    $tridIndex[(int)$trid][$lang][]=$id;
    $current[$id]=$value;
    $original[$id]=[
       'value'=>$value,'source_key'=>$key,'language'=>$lang,'trid'=>(int)$trid
    ];
}
if(count($sourceIndex)!==51 || count($tridIndex)!==51)
    WP_CLI::error('source_or_translation_group_count_changed');
foreach($sourceIndex as $key=>$items){
    if(count($items)!==count($languages) ||
       array_keys($items)!==$languages && array_diff(array_keys($items),$languages))
        WP_CLI::error('incomplete_source_languages_'.$key);
    foreach($languages as $lang)
        if(count($items[$lang]??[])!==1)
            WP_CLI::error('duplicate_source_language_'.$key.'_'.$lang);
}
foreach($tridIndex as $trid=>$items){
    if(count($items)!==count($languages))WP_CLI::error('trid_incomplete_'.$trid);
    foreach($languages as $lang)
        if(count($items[$lang]??[])!==1)WP_CLI::error('trid_duplicate_'.$trid.'_'.$lang);
}
$backupKey='mad4b_pdf_transport_component_yes_backup_20261119_v1';
$backup=get_option($backupKey,false);
$rollback=defined('MAD4B_PDF_COMPONENT_YES_ROLLBACK') && MAD4B_PDF_COMPONENT_YES_ROLLBACK===true;
$apply=defined('MAD4B_PDF_COMPONENT_YES_APPLY') && MAD4B_PDF_COMPONENT_YES_APPLY===true;
if($rollback){
    if(!is_array($backup)||count($backup['original']??[])!==count($ids))
        WP_CLI::error('rollback_backup_missing');
    $restored=0;
    foreach($backup['original'] as $id=>$record){
        if(!isset($original[$id]) ||
            $record['source_key']!==$original[$id]['source_key'] ||
            $record['language']!==$original[$id]['language'] ||
            $record['trid']!==$original[$id]['trid'])
            WP_CLI::error('rollback_target_changed_'.$id);
    }
    foreach($backup['original'] as $id=>$record){
        $v=$current[$id];
        if($v==='1' && $record['value']==='0'){
            update_post_meta($id,MAD4B_PDF_COMPONENT_KEY,'0');
            ++$restored;
        }
    }
    WP_CLI::log(wp_json_encode([
        'status'=>'rolled_back','site'=>$site,'restored'=>$restored,
        'source_posts'=>count($ids),'commercial_changes'=>0
    ]));
    return;
}
$no=array_keys(array_filter($current,static fn($v)=>$v==='0'));
$yes=count($ids)-count($no);
if(!$apply){
    WP_CLI::log(wp_json_encode([
        'status'=>'dry_run','site'=>$site,'provider'=>'self',
        'source_operations'=>count($sourceIndex),'wpml_groups'=>count($tridIndex),
        'localized_posts'=>count($ids),'to_enable'=>count($no),
        'already_yes'=>$yes,'scope'=>'PDF Drafts only',
        'old_qa_rows_untouched'=>true,'rates_changed'=>0,
        'checkout_authorized'=>false
    ]));
    return;
}
$admin=get_user_by('id',1);
if(!$admin || !user_can($admin,'edit_others_posts'))
    WP_CLI::error('authorized_wp_admin_missing');
wp_set_current_user($admin->ID);
if($backup===false){
    if($yes!==0)WP_CLI::error('partial_prior_state_without_backup');
    if(!add_option($backupKey,[
        'created_at'=>gmdate('c'),'site'=>$site,
        'source_sha256'=>MAD4B_PDF_COMPONENT_SHA,'original'=>$original
    ],'',false))WP_CLI::error('backup_write_failed');
}else{
    if(($backup['source_sha256']??'')!==MAD4B_PDF_COMPONENT_SHA ||
       count($backup['original']??[])!==count($original) ||
       array_diff_key($original,$backup['original']))
        WP_CLI::error('existing_backup_conflict');
}
$changed=0;
foreach($no as $id){
    update_post_meta($id,MAD4B_PDF_COMPONENT_KEY,'1');
    ++$changed;
}
$notYes=[];$notDraft=[];$qualified=0;
foreach($ids as $id) {
    if((string)get_post_meta($id,MAD4B_PDF_COMPONENT_KEY,true)!=='1')$notYes[]=$id;
    if(get_post_status($id)!=='draft')$notDraft[]=$id;
    if(class_exists('MAD4B_Transport_Tour_Component_Eligibility_V1') &&
       MAD4B_Transport_Tour_Component_Eligibility_V1::eligible((int)$id))++$qualified;
}
if($notYes||$notDraft||$qualified)
    WP_CLI::error('readback_or_commercial_eligibility_failure');
WP_CLI::log(wp_json_encode([
    'status'=>'pdf_tour_components_opted_in','site'=>$site,
    'provider'=>'self','source_operations'=>51,'wpml_groups'=>51,
    'localized_yes'=>count($ids),'changed'=>$changed,
    'already_yes'=>$yes,'published'=>0,
    'commercially_qualified'=>0,'transport_rates_changed'=>0,
    'checkout_authorized'=>false,'backup'=>$backupKey
]));
