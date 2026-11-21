<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Package_Supplier_Admission_V1';
if(!class_exists($cls,false))WP_CLI::error('Package supplier admission unavailable');
$isWayfa=str_contains(home_url(),'wayfatours.com');
$english=[60629,60627,60623,60443,60441,60439,60437,57562];
$ids=$isWayfa?$english:array_map('intval',(array)get_posts([
 'post_type'=>'tours-and-activities','post_status'=>'publish','posts_per_page'=>8,'fields'=>'ids'
]));
$cases=0;$errors=[];$statuses=[];$langs=[];$perTour=[];$rates=wp_count_posts('tour-rates');
$before=get_option('mad4b_transport_self_pricing_controls_v1',null);
foreach($ids as $tour){
 $translations=[$tour];
 if($isWayfa){
  $es=apply_filters('wpml_object_id',$tour,'tours-and-activities',false,'es');
  if(is_numeric($es)&&$es>0&&$es!=$tour)$translations[]=(int)$es;
 }
 foreach(array_unique($translations) as $id){
  $lang=$id===$tour?'en':'es';
  foreach(['EGP','USD','EUR','GBP'] as $currency)foreach([
      ['adults'=>2,'children'=>0,'infants'=>0],
      ['adults'=>2,'children'=>1,'infants'=>1]
  ] as $party){
   $result=$cls::inspect($id,'2026-12-15',$currency,
      $party['adults'],$party['children'],$party['infants']);
   $cases++;
   if(is_wp_error($result)){
     $errors[]="$id $currency ".$result->get_error_code();continue;
   }
   if($result['rate_generation_ready']!==false ||
      $result['checkout_ready']!==false ||
      $result['total_cost_minor']!==null ||
      $result['customer_price_changed']!==false)
     $errors[]="False commercial readiness: $id $currency";
   foreach(['accommodation','transportation','guide','extras'] as $kind)
     if(!isset($result['components'][$kind]))
       $errors[]="Missing native component $kind $id";
   if($currency!=='EGP' &&
       !in_array('certified_fx_receipt_missing',$result['blockers'],true))
       $errors[]="Unapproved FX not blocked $id $currency";
   if($party['children'] && !in_array('child_infant_component_costs_not_certified',
         $result['blockers'],true))
       $errors[]="Child/infant cost not blocked $id";
   foreach($result['blockers'] as $code)
      $statuses[$code]=($statuses[$code]??0)+1;
   $langs[$lang]=($langs[$lang]??0)+1;
   $perTour[$tour]['scenarios']=($perTour[$tour]['scenarios']??0)+1;
   $perTour[$tour]['stay_stages']=max($perTour[$tour]['stay_stages']??0,
     (int)($result['components']['accommodation']['binding_count']??0));
  }
 }
}
if($errors)WP_CLI::error(wp_json_encode(array_slice($errors,0,15)));
$now=wp_count_posts('tour-rates');
foreach(['publish','draft','auto-draft'] as $s)
 if((int)($now->$s??0)!==(int)($rates->$s??0))
  WP_CLI::error('Tour Rates changed during QA '.$s);
if(get_option('mad4b_transport_self_pricing_controls_v1',null)!==$before)
 WP_CLI::error('Supplier policy changed during read-only Package test');
wp_set_current_user(0);
$guest=$cls::inspect($ids[0],'2026-12-15','EGP',2);
if(!is_wp_error($guest)||$guest->get_error_code()!=='package_admission_forbidden')
 WP_CLI::error('Supplier admission exposed privately to guests');
echo 'EIGHT_PACKAGE_ADMISSION '.wp_json_encode([
 'site'=>home_url(),'canonical_en_tours'=>count($ids),
 'scenario_count'=>$cases,'scenarios_by_language'=>$langs,
 'per_tour'=>$perTour,'blocker_counts'=>$statuses,
 'commercially_eligible'=>0,'rates_created'=>0,
 'checkout_activated'=>false,'error_count'=>count($errors),
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('NATIVE_EIGHT_PACKAGE_READ_ONLY_ADMISSION_TESTED');
