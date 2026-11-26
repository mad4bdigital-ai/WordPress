<?php
/**
 * Read-only source-bound publication check. Editorial Published is distinct
 * from transport availability, approved Rate, checkout, photo/fleet approval.
 */
defined('ABSPATH')||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
    return;
$wayfa=$site==='https://wayfatours.com';
$langs=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
sort($langs);
$expected=$wayfa?['en','es']:['ar','de','en','es','fr','it'];
$tests=[];$pass=static function($k,$yes)use(&$tests){$tests[$k]=(bool)$yes;};
$pass('active_languages',$langs===$expected);
$source=require WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-localized.php';
$sha='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
$pass('source_sha',($source['source_sha256']??'')===$sha);
$pass('source_rows_51',count($source['rows']??[])===51);
$ids=get_posts([
 'post_type'=>'transportations','post_status'=>['draft','publish'],
 'posts_per_page'=>650,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>$sha
]);
$pass('source_matrix_total',count($ids)===51*count($langs));
$records=[];$trids=[];$published=[];$draft=[];$unsafe=[];$meta=[];
foreach($ids as $id){
 $id=(int)$id;$p=get_post($id);
 $key=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
 $lang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
 $trid=(int)apply_filters('wpml_element_trid',null,$id,'post_transportations');
 $details=apply_filters('wpml_post_language_details',null,$id);
 $records[$key][$lang][]=$id;$trids[$trid][$lang][]=$id;
 $isPublished=$p->post_status==='publish';
 if($isPublished)$published[]=$id;else $draft[]=$id;
 $sourceProtected=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)==='1' &&
    get_post_meta($id,'trans_use_in_tour_rate_generation',true)==='1' &&
    get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)==='self' &&
    get_post_meta($id,'_mad4b_transfer_pdf_2026_source_trust',true)==='trusted_original_pdf' &&
    get_post_meta($id,'trans_available_status',true)==='' &&
    get_post_meta($id,'trans_start_price',true)==='' &&
    get_post_meta($id,'trans_base_currency',true)==='' &&
    get_post_meta($id,'trans_type',true)==='' &&
    get_post_thumbnail_id($id)===0 &&
    ($details['language_code']??'')===$lang;
 $isEditorial=$isPublished &&
    get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true)==='mad4b.transport-pdf-editorial.v1' &&
    in_array('noindex',(array)get_post_meta($id,'rank_math_robots',true),true) &&
    trim($p->post_content)!=='' && !str_contains($p->post_excerpt,'Draft') &&
    trim((string)get_post_meta($id,'trans_description',true))!=='';
 $isDraft=$p->post_status==='draft' &&
    get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true)==='';
 if(!$sourceProtected || !($isEditorial||$isDraft))$unsafe[]=[
    'id'=>$id,'key'=>$key,'lang'=>$lang,'status'=>$p->post_status,
    'source_protected'=>$sourceProtected,'editorial'=>$isEditorial,'draft'=>$isDraft
 ];
}
$pass('no_unsafe_source_records',!$unsafe);
$pass('51_source_groups',count($records)===51);
$pass('51_translation_groups',count($trids)===51);
foreach($records as $key=>$group){
 $pass('source_group_'.$key,count($group)===count($langs) &&
    !array_diff($langs,array_keys($group)) &&
    array_sum(array_map('count',$group))===count($langs));
}
foreach($trids as $key=>$group){
 $pass('wpml_group_'.$key,count($group)===count($langs) &&
    !array_diff($langs,array_keys($group)) &&
    array_sum(array_map('count',$group))===count($langs));
}
$pass('all_posts_accounted',count($published)+count($draft)===count($ids));
$pass('old_transport_rate_66570_unchanged',get_post_status(66570)==='draft');
$pass('old_transport_rate_66571_unchanged',get_post_status(66571)==='draft');
$pass('still_tour_only',function_exists('service_channel_mode') &&
    service_channel_mode('transportations')==='tour_only');
$pass('not_standalone_channel',function_exists('service_channel_allowed') &&
    !service_channel_allowed('transportations','standalone'));
$sourceReviewer=class_exists('MAD4B_Transport_PDF_Review_V1');
$pass('source_reviewer_available',$sourceReviewer);
if($sourceReviewer){
 foreach($langs as $lang){
    $review=MAD4B_Transport_PDF_Review_V1::snapshot('',$lang);
    $pass('review_51_'.$lang,($review['count']??0)===51);
    $pass('review_no_missing_'.$lang,($review['review_issues']??999)===0);
    $pass('review_not_selling_'.$lang,($review['checkout_authorized']??true)===false);
 }
}
$failed=array_keys(array_filter($tests,static fn($ok)=>!$ok));
echo wp_json_encode([
 'site'=>$site,'tests'=>count($tests),'passed'=>count($tests)-count($failed),
 'failed'=>$failed,'source_posts'=>count($ids),
 'editorial_published'=>count($published),'remaining_drafts'=>count($draft),
 'unsafe'=>array_slice($unsafe,0,6),
 'currency_confirmed'=>false,'standalone_enabled'=>false,
 'checkout_authorized'=>false
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if($failed)exit(1);
