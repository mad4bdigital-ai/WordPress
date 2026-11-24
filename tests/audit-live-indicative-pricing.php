<?php
defined('ABSPATH')||exit;
$site=home_url();
$expected=($site==='https://wayfatours.com')?[2353,2354,2356]:[2353,2354];
$result=['site'=>$site,'checks'=>[]];
foreach([57562,60623,60629] as $tour){
  $r=MAD4B_Company_Package_Levels_V1::resolve($tour);
  $tier=(int)$r['effective_tier_ids'][0];
  $report=MAD4B_Package_Pricing_Lab_Readiness_V1::inspect($tour,$tier);
  if(is_wp_error($report))throw new RuntimeException('native_pricing_lab_unavailable_'.$tour);
  $d=$report['indicative_offer_draft']??[];
  if(($d['contract']??'')!=='mad4b.package-indicative-offer-draft.v1'||
       $d['quote_can_be_prepared_for_staff']!==true ||
       $d['starting_from_amount']!==null ||
       $d['indicative_complete_package_total']!==null||
       $d['checkout_authorized']!==false||
       $report['checkout_ready']!==false||
       $report['generated_rate_ready']!==false)
      throw new RuntimeException('commercial_rate_gate_invalid_'.$tour);
  $result['checks']['tour_'.$tour]=[
    'tier_id'=>$tier,'tier_source'=>$r['source'],
    'draft'=>$d['status'],'stay_stage_count'=>$d['stage_count'],
    'unpriced_stages'=>$d['stage_total_missing'],
    'candidate_web_offer_stages'=>count(array_filter($d['market_offer_stages'],
      static fn($s)=>count($s['web_offer_examples']??[])>0)),
    'completed_package_amount'=>$d['indicative_complete_package_total'],
    'checkout_authorized'=>$d['checkout_authorized']
  ];
}
$m=MAD4B_Smart_Itinerary_Choices_V1::matrix(60629);
$sr=$m['hotels']['sharm']['resort']??[];
$scout=$m['planning_resort_scout']??[];
if(($sr['count']??0)!==1 || ($sr['property_ids']??[])!==[36084] ||
     ($sr['planning_only']??false)!==true||
     ($scout['all_web_offer_property_count']??0)!==7||
     ($scout['unassigned_tier_property_count']??0)!==6||
     ($m['checkout_authorized']??true)!==false)
    throw new RuntimeException('sharm_planning_catalog_wrong');
$tiers=$sr['tiers']??[];
sort($tiers);
$want=$site==='https://wayfatours.com'?[2354,2356]:[2354];
if($tiers!==$want)throw new RuntimeException('operator_tier_precedence_mismatch');
$bad=MAD4B_Package_Indicative_Offer_V1::inspect(60629,2355);
if($bad['status']!=='unavailable_for_user_or_tier')
   throw new RuntimeException('forbidden_platinum_tier_allowed');
$base=MAD4B_Package_Indicative_Offer_V1::inspect(60629,2354);
$ref=array_values(array_filter($base['market_offer_stages'],
  static fn($s)=>$s['kind']==='resort'))[0];
$web=$ref['web_offer_examples'];
if(count($web)!==7||count(array_filter($web,
  static fn($p)=>$p['planning_eligible_by_operator_tier']))!==1||
  array_sum(array_map(fn($p)=>(int)$p['commercial_rate_authorized'],$web))!==0)
 throw new RuntimeException('web_pool_or_commercial_authority_bad');
$result['checks']['resort_planning']=[
 'operationally_eligible_property_ids'=>$sr['property_ids'],
 'tier_ids'=>$tiers,'total_web_offer_samples'=>count($web),
 'unclassified_properties'=>6,'full_price'=>null,
 'all_samples_supplier_cost_approved'=>false
];
$result['checks']['rail']=[];
foreach([54552,54553] as $id){
 $p=MAD4B_Transport_Start_Price_Roles_V1::inspect($id);
 $result['checks']['rail'][]=['id'=>$id,
  'public_tariff_reference'=>is_array($p['published_public_tariff_evidence']??null),
  'cost_source'=>$p['planning_cost_source'],
  'mode'=>$p['mode'],'checkout'=>$p['commercial_price_or_checkout_authorized']
 ];
 if(!is_array($p['published_public_tariff_evidence']??null)||
   $p['planning_cost_source']!==null||$p['commercial_price_or_checkout_authorized']!==false)
    throw new RuntimeException('rail_public_offer_promoted_to_cost');
}
$result['checks']['bookable']=[];
foreach([60623,60629] as $id){
 $r=MAD4B_Package_Stage_Rate_Guard_V1::review($id,2354);
 $result['checks']['bookable'][$id]=$r['checkout_ready'];
 if($r['checkout_ready']!==false)throw new RuntimeException('public_checkout_open');
}
$result['checks']['booking_flag']=get_option('etg-dynamic-pricing-controls_booking_enabled');
if($result['checks']['booking_flag']!=='false')throw new RuntimeException('booking_changed');
echo wp_json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;

