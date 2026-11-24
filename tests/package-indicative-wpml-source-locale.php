<?php
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Package_Indicative_Offer_V1',false) ||
   !class_exists('MAD4B_Smart_Itinerary_Choices_V1',false))
   throw new RuntimeException('provider_not_loaded');
$before=(string)apply_filters('wpml_current_language','');
$site=home_url();
$langs=$site==='https://wayfatours.com'?['en','es']:['en','es','fr','de','ar','it'];
$out=[];
try{
 foreach($langs as $lang){
   do_action('wpml_switch_language',$lang);
   $selected=(string)apply_filters('wpml_current_language','');
   $report=MAD4B_Package_Indicative_Offer_V1::inspect(60629,2354);
   $matrix=MAD4B_Smart_Itinerary_Choices_V1::matrix(60629);
   $r=array_values(array_filter((array)$report['market_offer_stages'],
       static fn($x)=>($x['kind']??'')==='resort'))[0]??[];
   $count=count((array)($r['web_offer_examples']??[]));
   $kind=(int)($matrix['hotels']['sharm']['resort']['count']??0);
   $actual=(string)apply_filters('wpml_current_language','');
   if($count!==7 || $kind!==1 ||
      $report['sale_price_total']!==null||
      $report['checkout_authorized']!==false ||
      $selected!==$actual)
       throw new RuntimeException('source_locale_regression_'.$lang);
   $out[$lang]=['offers'=>$count,'resort_choices'=>$kind,
               'editor_language_restored_after_query'=>true,
               'commercial_approval'=>false];
 }
}finally{
 do_action('wpml_switch_language',$before);
}
echo wp_json_encode(['site'=>$site,'checks'=>$out,'passed'=>count($out)],
  JSON_UNESCAPED_SLASHES).PHP_EOL;

