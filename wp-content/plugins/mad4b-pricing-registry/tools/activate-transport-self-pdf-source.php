<?php
/**
 * Explicit self-provider/source trust assignment to existing PDF Drafts.
 * Site-neutral; safe repeat; no published price, rate approval or checkout.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
$prod=$site==='https://wayfatours.com';
if($site!=='https://tours.dev.mad4b.com' &&
   !($prod && defined('MAD4B_SELF_SOURCE_WAYFA_APPROVED') &&
     MAD4B_SELF_SOURCE_WAYFA_APPROVED===true))WP_CLI::error('site_guard_rejected');
if(!class_exists('MAD4B_Transport_Self_Pricing_Controls_V1'))
    WP_CLI::error('transport_self_control_class_missing');
$cls='MAD4B_Transport_Self_Pricing_Controls_V1';
$s=$cls::source();
if(!$s||count($s['rows']??[])!==51||$s['source_sha256']!==$cls::SHA)
    WP_CLI::error('pdf_source_integrity_invalid');
$amounts=0;foreach($s['rows'] as $row)
    foreach($row['vehicle_rates_unverified'] as $v)if($v!==null)$amounts++;
if($amounts!==210)WP_CLI::error('source_amount_count_changed');
$langs=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
$expected=$prod?['en','es']:['ar','de','en','es','fr','it'];
sort($langs);sort($expected);
if($langs!==$expected)WP_CLI::error('site_language_set_changed');
$ids=get_posts(['post_type'=>'transportations','post_status'=>'draft',
    'posts_per_page'=>600,'fields'=>'ids','suppress_filters'=>true,
    'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>$cls::SHA]);
if(count($ids)!==51*count($langs))WP_CLI::error('draft_count_not_complete');
$backupKey='mad4b_transport_self_source_binding_backup_20261119_v1';
$existingBackup=get_option($backupKey,false);
$records=[];
foreach($ids as $id){
    $id=(int)$id;
    $key=get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
    $lang=get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
    $checkLang=apply_filters('wpml_post_language_details',null,$id);
    if(!preg_match('/^(Cairo|Aswan|Luxor):[0-9]{1,2}$/',$key) ||
       !in_array($lang,$langs,true)||($checkLang['language_code']??null)!==$lang ||
       get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=1)
       WP_CLI::error('draft_source_contract_changed_'.$id);
    $old=get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true);
    if(!in_array($old,['','self'],true))WP_CLI::error('provider_conflict_'.$id);
    $records[$id]=['source_key'=>$key,'lang'=>$lang,'prior_provider'=>$old];
}
$apply=defined('MAD4B_SELF_SOURCE_APPLY')&&MAD4B_SELF_SOURCE_APPLY===true;
if(!$apply){
    WP_CLI::log(wp_json_encode(['status'=>'dry_run','site'=>$site,
       'drafts'=>count($records),'provider'=>'self','source_verified'=>true,
       'currency_from_pdf'=>false,'raw_price_cells'=>210,
       'public_prices_changed'=>0,'rates_modified'=>0]));return;
}
if($existingBackup===false){
    if(!add_option($backupKey,['at'=>gmdate('c'),
        'prior_site_policy'=>get_option($cls::OPTION,false),
        'records'=>$records],'',false))
        WP_CLI::error('backup_write_failed');
}elseif(count($existingBackup['records']??[])!==count($records))
    WP_CLI::error('backup_contract_changed');
if(get_option($cls::OPTION,false)===false)
    if(!add_option($cls::OPTION,$cls::defaults(),'',false))
        WP_CLI::error('site_self_policy_write_failed');
$policy=$cls::current();
if($policy['provider']!=='self'||
   $policy['source_type']!=='trusted_original_pdf_2026'||
   $policy['source_sha256']!==$cls::SHA)
    WP_CLI::error('policy_readback_invalid');
$changed=0;
foreach($records as $id=>$record){
    if(get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)!=='self'){
        update_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id','self');
        $changed++;
    }
    update_post_meta($id,'_mad4b_transfer_pdf_2026_source_trust','trusted_original_pdf');
}
foreach($records as $id=>$record)
    if(get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)!=='self' ||
       get_post_meta($id,'_mad4b_transfer_pdf_2026_source_trust',true)!=='trusted_original_pdf')
        WP_CLI::error('provider_binding_readback_failed_'.$id);
WP_CLI::log(wp_json_encode([
    'status'=>'self_pdf_source_assigned','site'=>$site,
    'provider_id'=>'self','pdf_trust'=>'approved_original_source',
    'source_price_cells'=>210,'source_rows'=>51,'language_drafts'=>count($records),
    'metadata_updated'=>$changed,'policy_revision'=>$policy['revision'],
    'currency'=>$policy['currency'],'source_amount_role'=>$policy['price_role'],
    'published_rate_changes'=>0,'checkout_authorized'=>false,
    'rollback_backup'=>$backupKey]));
