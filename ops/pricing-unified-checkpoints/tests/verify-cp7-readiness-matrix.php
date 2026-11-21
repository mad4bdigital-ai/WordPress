<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
global $wpdb;
$site=home_url();$isWayfa=str_contains($site,'wayfatours.com');
$snapshotNames=[
 'etg-dynamic-pricing-controls_shared_package_pricing_rows',
 'mad4b_dynamic_dimension_markup_v1',
 'mad4b_jetengine_pricing_lab_presets_v1',
 'booking_experience_pricing_preset_rows_v1',
 'mad4b_transport_self_pricing_controls_v1',
];
$before=[];
foreach($snapshotNames as $key)$before[$key]=get_option($key,null);
$rateBefore=wp_count_posts('tour-rates');
$ids=get_posts([
 'post_type'=>'tours-and-activities','post_status'=>'publish',
 'fields'=>'ids','posts_per_page'=>300,'suppress_filters'=>true
]);
$previews=[];$perLang=[];$termsChecked=0;$wpmlTranslations=0;
$errors=[];
foreach($ids as $tour){
 $terms=array_map('intval',(array)$wpdb->get_col($wpdb->prepare(
    "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
     WHERE tr.object_id=%d AND tt.taxonomy='package_levels' ORDER BY tt.term_id",
     (int)$tour)));
 $details=apply_filters('wpml_post_language_details',null,(int)$tour);
 $lang=is_array($details)?(string)($details['language_code']??'unknown'):'unknown';
 foreach($terms as $term){
    $termsChecked++;
    $check=MAD4B_Dynamic_Dimensions_V1::package_for_tour((int)$tour,$term);
    if(is_wp_error($check)||(int)$check!==$term)
      $errors[]="Native relation term rejected: tour=$tour term=$term";
    $canonical=apply_filters('wpml_object_id',$term,'package_levels',false,'en');
    if(is_numeric($canonical)&&(int)$canonical!==$term){
       $mapped=MAD4B_Dynamic_Dimensions_V1::package_for_tour((int)$tour,(int)$canonical);
       $wpmlTranslations++;
       if(is_wp_error($mapped)||(int)$mapped!==$term)
          $errors[]="WPML canonical failed: tour=$tour term=$term canonical=$canonical";
    }
    $price=MAD4B_Dynamic_Dimensions_V1::preview((int)$tour,$term);
    $state=is_wp_error($price)?$price->get_error_code():'preview_ok';
    $previews[$state]=($previews[$state]??0)+1;
    $perLang[$lang][$state]=($perLang[$lang][$state]??0)+1;
    if($isWayfa && !in_array($state,[
       'preview_ok','package_level_pricing_disabled',
       'dimension_base_price_unavailable'],true))
         $errors[]="Unexpected Wayfa preview state: ".$state." tour=".$tour;
    if(!$isWayfa && !in_array($state,[
       'dimension_policy_not_configured','preview_ok'],true))
         $errors[]="Unexpected Staging state: ".$state." tour=".$tour;
 }
}
$stay=MAD4B_Stay_Commercial_Readiness_V1::audit(['paged'=>1,'scope'=>'all']);
if(is_wp_error($stay))$errors[]='stay_audit_failed:'.$stay->get_error_code();
$staySummary=is_array($stay)?(array)($stay['summary']??[]):[];
if((bool)($stay['checkout_ready']??false)!==false)
  $errors[]='Stay component unexpectedly claims commercial checkout readiness';
$transport=MAD4B_Transport_Self_Pricing_Controls_V1::catalog();
if(is_wp_error($transport))$errors[]='transport_catalog_failed:'.$transport->get_error_code();
$mode= MAD4B_JetEngine_Field_Modes_V1::pages();
$pricingFields=count($mode['etg-dynamic-pricing-controls']['fields']??[]);
if($pricingFields<53)$errors[]='Pricing Controls field discovery regressed';
$rateAfter=wp_count_posts('tour-rates');
foreach(['publish','draft','auto-draft','trash'] as $status)
 if((int)($rateAfter->$status??0)!==(int)($rateBefore->$status??0))
  $errors[]='Tour Rate status changed during read-only QA:'.$status;
foreach($snapshotNames as $name)if(get_option($name,null)!==$before[$name])
 $errors[]='Native pricing source mutated during QA:'.$name;
if($errors)WP_CLI::error(wp_json_encode(array_slice($errors,0,15),JSON_UNESCAPED_SLASHES));
$readiness=[
 'wpml_relation_correct'=>true,
 'native_preview_source_count'=>array_sum($previews),
 'certified_stay_commercial'=>false,
 'supplier_transport_component_ready'=>false,
 'complete_package_supplier_cost'=>false,
 'verified_fx_rate_receipt'=>false,
 'child_infant_selling_policy_certified'=>false,
 'jetengine_native_save_cas'=>false,
 'logged_in_responsive_ui_acceptance'=>false,
 'checkout_activation_approved'=>false,
];
echo 'CP7_MATRIX '.wp_json_encode([
 'site'=>$site,'published_tours'=>count($ids),
 'package_term_relationships'=>$termsChecked,'translation_mappings'=>$wpmlTranslations,
 'preview_outcomes'=>$previews,'by_language'=>$perLang,
 'stay_readiness'=>[
     'units_reviewed'=>$staySummary['units_total']??null,
     'commercial_ready'=>$staySummary['commercial_ready']??null,
     'checkout_ready'=>(bool)($stay['checkout_ready']??false)
 ],
 'transport_role'=>get_option('mad4b_transport_self_pricing_controls_v1',[])['price_role']??null,
 'pricing_fields'=>$pricingFields,
 'rate_status'=>[
   'publish'=>(int)($rateAfter->publish??0),
   'draft'=>(int)($rateAfter->draft??0),
   'auto_draft'=>(int)($rateAfter->{'auto-draft'}??0),
 ],
 'readiness_gates'=>$readiness,'read_only'=>true,'issues'=>$errors
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('CP7_READ_ONLY_MATRIX_ACCEPTED_WITH_COMMERCIAL_BLOCKERS');
