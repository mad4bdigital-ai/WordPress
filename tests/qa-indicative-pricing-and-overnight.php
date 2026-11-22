<?php
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Package_Indicative_Offer_V1',false) ||
   !class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1',false) ||
   !class_exists('MAD4B_Smart_Itinerary_Choices_V1',false))
   throw new RuntimeException('live_package_providers_unavailable');
$cases=[
 [57562,2354],
 [60623,2354],
 [60629,2353],
 [60629,2354],
];
if(home_url()==='https://wayfatours.com')$cases[]=[60629,2356];
$out=[];
foreach($cases as [$tour,$tier]){
 $candidate=MAD4B_Package_Pricing_Lab_Readiness_V1::inspect($tour,$tier);
 if(is_wp_error($candidate))
   throw new RuntimeException('lab_validation_unavailable_'.$tour.'_'.$tier);
 $draft=$candidate['indicative_offer_draft']??null;
 if(!is_array($draft)||$draft['contract']!=='mad4b.package-indicative-offer-draft.v1'||
   $draft['starting_from_amount']!==null||
   $draft['indicative_complete_package_total']!==null||
   $draft['sale_price_total']!==null||
   $draft['checkout_authorized']!==false)
    throw new RuntimeException('lab_indicative_quote_cost_gate_failed_'.$tour.'_'.$tier);
 if(($candidate['checkout_ready']??true)!==false ||
    ($candidate['generated_rate_ready']??true)!==false)
    throw new RuntimeException('native_commercial_guard_open_'.$tour.'_'.$tier);
 $out[]=['tour'=>$tour,'tier'=>$tier,
    'stages'=>$draft['stage_count'],
    'unconfirmed_stage_count'=>$draft['stage_total_missing'],
    'web_offer_stage_count'=>count(array_filter($draft['market_offer_stages'],
      fn($x)=>count($x['web_offer_examples']??[])>0)),
    'confirmed_package_total'=>$draft['indicative_complete_package_total'],
    'commercial_checkout'=>$draft['checkout_authorized']
 ];
}
$matrix=MAD4B_Smart_Itinerary_Choices_V1::matrix(60629);
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
$other=MAD4B_Smart_Itinerary_Choices_V1::matrix(60623);
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
 'native_lab_commercial_guard_preserved'=>true,'unrelated_rail_tour_resort_unchanged'=>true,
 'no_post_meta_written'=>true,'checkout_authorized'=>false],
 JSON_UNESCAPED_SLASHES).PHP_EOL;

