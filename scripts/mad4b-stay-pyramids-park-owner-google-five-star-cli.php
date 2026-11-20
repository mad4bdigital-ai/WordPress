<?php
/**
 * Wayfatours owner selection of Google Hotels 5-star classification for
 * Pyramids Park Resort Cairo (#70732), WPML ES #71709.
 *
 * One-time source-reviewed editorial taxonomy amendment, with rollback data
 * stored in receipt. Not an official hotel/supplier accreditation nor a price.
 *
 * Usage: MAD4B_HOTEL_GOOGLE_STARS_MODE=plan|apply|verify wp eval-file ...
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) return;
global $wpdb;
$mode=getenv('MAD4B_HOTEL_GOOGLE_STARS_MODE')?:'plan';
if(!in_array($mode,['plan','apply','verify'],true)) WP_CLI::error('invalid_mode');
if(rtrim(home_url(),'/')!=='https://wayfatours.com') WP_CLI::error('wrong_site');
$ids=['en'=>70732,'es'=>71709];
$taxonomyIds=['en'=>490,'es'=>649];
$meta='_mad4b_hotel_star_source_review_v1';
$proof='https://www.google.com/travel/hotels/entity/CgoIwejS8Zm4vfcBEAE';
$expectedSubject='Pyramids Park Resort Cairo';
$records=[];$errors=[];
foreach($ids as $lang=>$id){
 $p=get_post($id);
 $details=$wpdb->get_row($wpdb->prepare(
 "SELECT trid,language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='post_properties' AND element_id=%d",$id),ARRAY_A);
 $starTerms=array_map('intval',$wpdb->get_col($wpdb->prepare(
 "SELECT tt.term_id FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND tt.taxonomy='stars' ORDER BY tt.term_id",$id)));
 $receipt=get_post_meta($id,$meta,true);
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||$p->post_title!==$expectedSubject
   ||!is_array($details)||$details['language_code']!==$lang
   ||(string)get_post_meta($id,'prop_use_in_tour_rate_generation',true)!=='1') $errors[]='identity_or_optin_'.$id;
 if(!is_array($receipt) ||
   ($receipt['schema']??'')!=='mad4b.hotel-star-source-review.v1' ||
   ($receipt['hotel_property_id']??null)!==70732 ||
   ($receipt['localized_property_id']??null)!==71709)$errors[]='receipt_identity_'.$id;
 $records[$lang]=['id'=>$id,'trid'=>$details['trid']??null,'before_stars'=>$starTerms,
  'receipt'=>$receipt];
}
if(($records['en']['trid']??null)!==($records['es']['trid']??null)||!$records['en']['trid'])$errors[]='wpml_pair_changed';
$starsTrids=[];
foreach($taxonomyIds as $lang=>$term){
 $record=$wpdb->get_row($wpdb->prepare(
 "SELECT i.trid,i.language_code,tt.term_taxonomy_id,t.name FROM {$wpdb->prefix}icl_translations i
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=i.element_id AND tt.taxonomy='stars'
 JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
 WHERE i.element_type='tax_stars' AND i.element_id=%d",$term),ARRAY_A);
 if(!$record||$record['language_code']!==$lang||!$record['term_taxonomy_id'])$errors[]='star_term_'.$lang;
 $starsTrids[$lang]=$record;
}
if(($starsTrids['en']['trid']??null)!==($starsTrids['es']['trid']??null))$errors[]='stars_wpml_translation_mismatch';
$editorial=get_option('mad4b_stay_editorial_publish_v1');
if(!is_array($editorial)||($editorial['catalog_activated']??true)!==false||
 (int)(wp_count_posts('property-rates')->publish??0)!==0||
 (int)(wp_count_posts('tour-rates')->publish??0)!==0)$errors[]='sales_guard';
$tiers=['en'=>[2353],'es'=>[2991]];
foreach($tiers as $lang=>$expected){
 $id=$ids[$lang];
 $actual=array_map('intval',$wpdb->get_col($wpdb->prepare(
  "SELECT tt.term_id FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND tt.taxonomy='package_levels' ORDER BY tt.term_id",$id)));
 if($actual!==$expected)$errors[]='silver_tier_changed_'.$id;
}
if($mode==='apply'||$mode==='plan'){
 foreach($records as $lang=>$r){
  if($r['before_stars']!==[])$errors[]='preexisting_stars_'.$r['id'];
  if(($r['receipt']['status']??null)!=='conflicting_sources_review_required'
    ||!array_key_exists('stars',$r['receipt'])||$r['receipt']['stars']!==null
    ||($r['receipt']['source_5_star']??null)!==$proof
    ||empty($r['receipt']['source_4_star']))
   $errors[]='expected_prior_dispute_missing_'.$r['id'];
 }
}else{
 foreach($records as $lang=>$r){
  if($r['before_stars']!==[$taxonomyIds[$lang]])$errors[]='stars_not_five_'.$r['id'];
  $receipt=$r['receipt'];
  if(($receipt['status']??null)!=='owner_approved_google_hotels_five_star'
    ||($receipt['stars']??null)!==5
    ||($receipt['owner_approved']??null)!==true
    ||($receipt['selected_source']??null)!==$proof
    ||($receipt['supplier_contract_stars_certified']??true)!==false
    ||($receipt['checkout_ready']??true)!==false
    ||($receipt['catalog_activated']??true)!==false
    ||!isset($receipt['previous_review'])
    ||($receipt['previous_review']['status']??null)!=='conflicting_sources_review_required')
    $errors[]='owner_provenance_incomplete_'.$r['id'];
 }
}
if($errors)WP_CLI::error('preflight_'.implode(',',$errors));
if($mode==='plan'){
 WP_CLI::line(wp_json_encode(['mode'=>'plan','property_ids'=>$ids,'selected_star'=>5,'source'=>$proof,
 'conflict_preserved'=>true,'before_star_terms'=>['en'=>[],'es'=>[]],'after_star_terms'=>$taxonomyIds,
 'package_levels_unchanged'=>true,'price_and_rate_writes'=>0,'catalog_activated'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return;
}
if($mode==='apply'){
 $time=gmdate('c');
 foreach($ids as $lang=>$id){
  $prior=$records[$lang]['receipt'];
  $review=array_merge($prior,[
   'status'=>'owner_approved_google_hotels_five_star',
   'stars'=>5,
   'selected_source'=>$proof,
   'source_type'=>'google_hotels_displayed_classification',
   'source_5_star'=>$proof,
   'source_confidence'=>'owner_approved_secondary_source',
   'owner_approved'=>true,
   'owner_approval_text'=>'اعتمد تصنيف جوجل',
   'owner_approval_at'=>$time,
   'secondary_source_disagreement_remains'=>true,
   'supplier_contract_stars_certified'=>false,
   'previous_review'=>$prior,
   'original_star_terms'=>[],
   'five_star_term_id'=>$taxonomyIds[$lang],
   'filters_include_as_five_stars'=>true,
   'filters_must_retain_unknown_option'=>false,
   'package_level_unchanged'=>true,
   'star_rating_not_package_tier'=>true,
   'rates_approved'=>false,
   'checkout_ready'=>false,
   'catalog_activated'=>false
  ]);
  $tt=(int)$starsTrids[$lang]['term_taxonomy_id'];
  if(!$wpdb->insert($wpdb->term_relationships,
   ['object_id'=>$id,'term_taxonomy_id'=>$tt,'term_order'=>0],['%d','%d','%d']))
   WP_CLI::error('term_insert_failed_'.$id);
  if(!update_post_meta($id,$meta,$review))
   WP_CLI::error('star_review_update_failed_'.$id);
  clean_object_term_cache($id,'properties');
  wp_cache_delete($id,'post_meta');
 }
 wp_update_term_count_now(array_map(static fn($x)=>(int)$x['term_taxonomy_id'],$starsTrids),'stars');
 WP_CLI::line(wp_json_encode(['mode'=>'apply','property_ids'=>$ids,'stars'=>5,
  'owner_selected_source'=>$proof,'terms_updated'=>2,'receipts_updated'=>2,
  'other_terms_modified'=>0,'rate_writes'=>0,'catalog_activated'=>false],
  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return;
}
$starCoverage=0;$cohort=[70732,36475,36316,36197,36209,36479,36473,36474,36477,36140,70733,36355,70734,70735,70736,70737,70738,70739];
foreach($cohort as $id){
 $n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND tt.taxonomy='stars'",$id));
 if($n===1)$starCoverage++;else $errors[]='cohort_missing_or_duplicate_stars_'.$id;
}
WP_CLI::line(wp_json_encode(['mode'=>'verify','error_count'=>count($errors),
 'errors'=>$errors,'stars_google_selected'=>5,'english'=>$records['en']['before_stars'],
 'spanish'=>$records['es']['before_stars'],'cohort_classified'=>$starCoverage,
 'cohort_total'=>count($cohort),'silver_tier_preserved'=>true,
 'old_disagreement_preserved'=>true,'published_property_rates'=>(int)(wp_count_posts('property-rates')->publish??0),
 'published_tour_rates'=>(int)(wp_count_posts('tour-rates')->publish??0),'catalog_activated'=>false],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if($errors)WP_CLI::error('owner_selected_hotel_stars_verification_failed');
