<?php
defined('ABSPATH')||exit;
require_once '/tmp/mad4b-package-indicative-offer-v1.php';
require_once '/tmp/qa-package-pricing-lab.php';
require_once '/tmp/qa-smart-itinerary.php';
$cases=[
 [57562,2354],
 [60623,2354],
 [60629,2353],
 [60629,2354],
];
if(home_url()==='https://wayfatours.com')$cases[]=[60629,2356];
$out=[];
foreach($cases as [$tour,$tier]){
 $original=MAD4B_Package_Pricing_Lab_Readiness_V1::inspect($tour,$tier);
 $candidate=MAD4B_Package_Pricing_Lab_Readiness_QA_V1::inspect($tour,$tier);
 if(is_wp_error($original)||is_wp_error($candidate))
   throw new RuntimeException('lab_validation_unavailable_'.$tour.'_'.$tier);
 $draft=$candidate['indicative_offer_draft']??null;
 if(!is_array($draft)||$draft['contract']!=='mad4b.package-indicative-offer-draft.v1'||
   $draft['starting_from_amount']!==null||
   $draft['indicative_complete_package_total']!==null||
   $draft['sale_price_total']!==null||
   $draft['checkout_authorized']!==false)
    throw new RuntimeException('lab_indicative_quote_cost_gate_failed_'.$tour.'_'.$tier);
 unset($candidate['indicative_offer_draft']);
 if($candidate!==$original)
   throw new RuntimeException('pricing_lab_original_report_changed_'.$tour.'_'.$tier);
 $out[]=['tour'=>$tour,'tier'=>$tier,
    'stages'=>$draft['stage_count'],
    'unconfirmed_stage_count'=>$draft['stage_total_missing'],
    'web_offer_stage_count'=>count(array_filter($draft['market_offer_stages'],
      fn($x)=>count($x['web_offer_examples']??[])>0)),
    'confirmed_package_total'=>$draft['indicative_complete_package_total'],
    'commercial_checkout'=>$draft['checkout_authorized']
 ];
}
$matrix=MAD4B_Smart_Itinerary_Choices_QA_V1::matrix(60629);
$existing=MAD4B_Smart_Itinerary_Choices_V1::matrix(60629);
$new=$matrix['hotels']['sharm']['resort']??[];
$scout=$matrix['planning_resort_scout']??[];
if(($new['count']??0)!==1||
   ($scout['all_web_offer_property_count']??0)!==7||
   ($scout['operations_tier_matched_count']??0)!==1||
   ($scout['unassigned_tier_property_count']??0)!==6||
   ($new['planning_only']??false)!==true||
   ($matrix['checkout_authorized']??true)!==false||
   ($matrix['inventory_verified']??true)!==false)
 throw new RuntimeException('smart_itinerary_noncommercial_resort_pool_wrong');
if(($existing['hotels']['sharm']['resort']['count']??0)!==0)
 throw new RuntimeException('baseline_smart_matrix_unexpectedly_modified');
$other=MAD4B_Smart_Itinerary_Choices_QA_V1::matrix(60623);
if(($other['planning_resort_scout']??null)!==[])
 throw new RuntimeException('resort_stage_leaked_into_train_tour');
echo wp_json_encode([
 'site'=>home_url(),'lab_cases'=>$out,'test_count'=>count($out),
 'smart_resort'=>[
  'count'=>$new['count'],'property_ids'=>$new['property_ids'],
  'tier_ids'=>$new['tiers'],'planning_only'=>$new['planning_only'],
  'all_web_offers'=>$scout['all_web_offer_property_count'],
  'unassigned_tier'=> $scout['unassigned_tier_property_count']
 ],
 'unchanged_base_lab_report'=>true,'unrelated_rail_tour_resort_unchanged'=>true,
 'no_post_meta_written'=>true,'checkout_authorized'=>false],
 JSON_UNESCAPED_SLASHES).PHP_EOL;

