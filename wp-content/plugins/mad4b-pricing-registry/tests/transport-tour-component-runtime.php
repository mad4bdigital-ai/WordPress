<?php
/** Read-only status, WPML and scoped-selection regression for each site. */
defined('ABSPATH')||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true)){
 echo 'STATUS=SKIP';return;
}
$cls='MAD4B_Transport_Tour_Component_Eligibility_V1';
$checks=[];
$check=static function($name,$value)use(&$checks){$checks[$name]=(bool)$value;};
global $wpdb;
$row=$wpdb->get_row($wpdb->prepare(
 "SELECT meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug=%s",
 'transportations'),ARRAY_A);
$defs=(array)maybe_unserialize($row['meta_fields']??'');
$check('jetengine_cpt_has_70_fields',count($defs)===70);
$check('field_immediately_below_description',
  ($defs[10]['name']??'')==='trans_description' &&
  ($defs[11]['name']??'')===$cls::FIELD);
$check('hotel_like_yes_no_glossary',
  ($defs[11]['type']??'')==='radio' &&
  ($defs[11]['options_source']??'')==='glossary' &&
  ($defs[11]['glossary_id']??'')==='321' &&
  ($defs[11]['default_val']??'')==='0');
$opt=get_option('icl_sitepress_settings',[]);
$check('wpml_field_copy',($opt['translation-management']['custom_fields_translation'][$cls::FIELD]??null)===1);
$check('wpml_canonical_mode_copy',
 ($opt['translation-management']['custom_fields_translation']['trans_mode']??null)===1);
$all=get_posts(['post_type'=>'transportations','post_status'=>['publish','draft'],
 'posts_per_page'=>1000,'fields'=>'ids','suppress_filters'=>true]);
$expect=$site==='https://wayfatours.com'?131:345;
$check('all_transportations_covered',count($all)===$expect);
$enabled=0;$qualified=0;$invalid=[];
foreach($all as $id){
 $value=(string)get_post_meta($id,$cls::FIELD,true);
 if(!in_array($value,['0','1'],true))$invalid[]=$id;
 if($value==='1')$enabled++;
 if($cls::eligible((int)$id))$qualified++;
}
$check('all_statuses_valid',!$invalid);
$expectedPdfYes=$site==='https://wayfatours.com'?102:306;
$check('only_pdf_source_has_yes',$enabled===$expectedPdfYes);
$check('no_tour_components_auto_qualified',$qualified===0);
$sourceDrafts=get_posts([
 'post_type'=>'transportations','post_status'=>'draft',
 'posts_per_page'=>600,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256',
 'meta_value'=>'c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd'
]);
$expectedSource=$site==='https://wayfatours.com'?102:306;
$check('source_draft_count_unchanged',count($sourceDrafts)===$expectedSource);
$pdfYes=0;$pdfDraftsStillDraft=true;$pdfEligible=0;
foreach($sourceDrafts as $draft){
 if((string)get_post_meta($draft,$cls::FIELD,true)==='1')$pdfYes++;
 if(get_post_status($draft)!=='draft')$pdfDraftsStillDraft=false;
 if($cls::eligible((int)$draft))$pdfEligible++;
}
$check('all_pdf_languages_opted_in',$pdfYes===$expectedSource);
$check('pdf_rows_still_drafts',$pdfDraftsStillDraft);
$check('no_unapproved_pdf_commercial_eligibility',$pdfEligible===0);
$check('qa_only_not_auto_opted_in',
 (string)get_post_meta(67115,$cls::FIELD,true)==='0' &&
 (string)get_post_meta(67117,$cls::FIELD,true)==='0');
$allCanonical=true;
foreach($sourceDrafts as $draft)
 if((int)get_post_meta($draft,'trans_mode',true)!==568)$allCanonical=false;
$check('source_drafts_use_canonical_road_mode',$allCanonical);
$translationId=$site==='https://wayfatours.com'?71111:70520;
$native='Shared_Product_Presentation_Transport_Context_v1';
if(method_exists($native,'mode_identity')){
    $identity=$native::mode_identity($translationId,'vehicles');
    $check('translated_mode_identity_valid',!empty($identity['ok']) &&
      (int)$identity['mode_term_id']===568);
}else{
    // Older company Presentation adapters still read the canonical mode.
    $check('translated_mode_identity_valid',
       (int)get_post_meta($translationId,'trans_mode',true)===568 &&
       has_term(568,'transportation-types',$translationId));
}
$check('presentation_filter_registered',has_filter(
 'shared_product_tour_rate_search_results',
 [$cls,'filter_tour_rate_results'])===25);
$check('jet_engine_filter_registered',has_filter(
 'jet-engine/query-builder/query/items',
 [$cls,'filter_tour_query_items'])===25);
$sample=45202;
$before=$cls::inspection($sample);
$check('sample_real_availability_independent',
 $before['post_status']==='publish' &&
 $before['availability']==='1' &&
 !$before['opted_in']);
$check('sample_not_eligible_default',!$before['qualifies_as_tour_component']);
$pretend=static function($v,$id,$key,$single)use($cls,$sample){
 if((int)$id===$sample && $key===$cls::FIELD)return '1';
 return $v;
};
add_filter('get_post_metadata',$pretend,10,4);
$pretendStatus=$cls::inspection($sample);
$check('positive_tour_component_status_works',
 $pretendStatus['opted_in'] &&
 $pretendStatus['qualifies_as_tour_component'] &&
 $pretendStatus['status']==='component_qualified');
$check('positive_no_price_or_stock_authority',
 !$pretendStatus['rate_approved'] &&
 !$pretendStatus['stock_verified'] &&
 !$pretendStatus['checkout_authorized']);
$mock=['results'=>[
 ['rate_id'=>100,'related_options'=>['transportation'=>[
   'train'=>[['id'=>45202,'name'=>'train'],['id'=>54554,'name'=>'other']],
   'vehicle'=>[['id'=>39707,'name'=>'car']]
 ]]],
 ['rate_id'=>101,'related_options'=>['transportation'=>['train'=>[]]]]
]];
$out=$cls::filter_tour_rate_results($mock);
$check('only_qualified_rate_option_survives',
 count($out['results'][0]['related_options']['transportation']['train'])===1 &&
 (int)$out['results'][0]['related_options']['transportation']['train'][0]['id']===45202 &&
 count($out['results'][0]['related_options']['transportation']['vehicle'])===0);
$check('unrelated_rate_not_removed',count($out['results'])===2);
$expectedQuery=new stdClass();
$expectedQuery->id=259;
$expectedQuery->query_type='posts';
$othersQuery=new stdClass();
$othersQuery->id=999;
$othersQuery->query_type='posts';
$filtered=$cls::filter_tour_query_items(
 [get_post(45202),get_post(54554)],$expectedQuery);
$unrelated=$cls::filter_tour_query_items(
 [get_post(45202),get_post(54554)],$othersQuery);
$check('native_transport_listing_scoped',
 count($filtered)===1 && (int)$filtered[0]->ID===45202);
$check('unrelated_jetengine_query_not_filtered',count($unrelated)===2);
remove_filter('get_post_metadata',$pretend,10);
$check('test_did_not_mutate_status',
 (string)get_post_meta($sample,$cls::FIELD,true)==='0');
$check('draft_pdf_records_not_eligible',!$cls::eligible(
 $site==='https://wayfatours.com'?70992:70519));
$panel=MAD4B_Transport_Self_Pricing_Controls_V1::catalog('Cairo','en');
$check('admin_source_rows_13',count($panel['items']??[])===13);
$check('admin_payload_component_status',
 isset($panel['items'][0]['component']['reason_codes']) &&
 $panel['items'][0]['component']['opted_in']===true &&
 !$panel['items'][0]['component']['qualifies_as_tour_component'] &&
 $panel['tour_component_field_location']==='below_transport_description');
$check('source_catalog_reports_yes',
 $panel['items'][0]['component']['opted_in']===true);
$check('no_auto_pricing_activation',!$panel['checkout_authorized'] &&
 !$panel['public_price_written']);
$fail=array_keys(array_filter($checks,static fn($pass)=>!$pass));
echo wp_json_encode(['site'=>$site,'tested'=>count($checks),
 'passed'=>count($checks)-count($fail),'failed'=>$fail,
 'transport_posts'=>count($all),'opted_in'=>$enabled,
 'tour_components_qualified'=>$qualified,'test_db_mutations'=>0,
 'checkout_activated'=>false],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if($fail)exit(1);
