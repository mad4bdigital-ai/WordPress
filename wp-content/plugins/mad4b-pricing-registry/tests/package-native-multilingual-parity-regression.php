<?php
defined('ABSPATH') || exit;
$original=apply_filters('wpml_current_language',null);
$langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
$checks=[];$bad=[];
$baseline=null;
try {
 foreach(array_keys((array)$langs) as $lang){
  do_action('wpml_switch_language',$lang);
  $review=MAD4B_Package_Stage_Rate_Guard_V1::review(60623,2354,'2026-12-10','2026-12-10');
  $first=$review['stages'][0]??[];
  $scenario=MAD4B_Package_Component_Readiness_V1::scenario([
   'tour_id'=>60623,'tier_id'=>2354,'stage_key'=>'hotel-cairo-01',
   'property_id'=>36140,'travel_date'=>'2026-12-10',
   'adults'=>2,'children'=>0,'assume_per_person_per_night'=>true]);
  $report=MAD4B_Package_Component_Readiness_V1::report('2026-12-10','2026-12-10');
  $record=[
    'applies'=>($review['applies']??null)===true,
    'not_sellable'=>($review['ready']??null)===false,
    'source_stages'=>count((array)($review['stages']??[]))===5,
    'tier_hotel_eligibility'=>in_array(36140,(array)($first['eligible_property_ids']??[]),true),
    'source_terms_inherit'=>($first['scope']['status']??'')==='mapped',
    'scenario_present'=>is_array($scenario)&&$scenario['source_rate']['calendar_from']==='2026-10-01',
    'math_hypothetical_only'=>is_array($scenario)&&(float)abs((float)$scenario['hypothetical_total_usd']-
      round((float)$scenario['source_rate']['source_amount']*2*(int)$scenario['nights'],2))<0.001&&
      $scenario['checkout_authorized']===false,
    'locale_restored'=>apply_filters('wpml_current_language',null)===$lang,
    'full_board_no_phantom_ready'=>is_array($report)&&count($report['tours'])===8&&
      ($report['totals']['price_ready_tier_reviews']??null)===0,
    'report_composition'=>(int)($report['totals']['reviewed_tier_combinations']??0)===24
  ];
  if($baseline===null)$baseline=[$review['source_digest'],
    array_column($review['stages'],'key')];
  $record['canonical_source_digest']=($review['source_digest']??null)===$baseline[0];
  $record['canonical_stage_keys']=array_column($review['stages'],'key')===$baseline[1];
  foreach($record as $name=>$pass){
   $checks[$lang.':'.$name]=(bool)$pass;
   if(!$pass)$bad[]=$lang.':'.$name;
  }
 }
} finally {
 if(is_string($original)&&$original!=='')do_action('wpml_switch_language',$original);
}
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.package-native-multilingual-read-parity.v1',
 'locales'=>array_keys((array)$langs),'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad,'saved_source_language_restored'=>
   apply_filters('wpml_current_language',null)===$original,
 'checkout_authorized'=>false,'write_performed'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($bad&&class_exists('WP_CLI'))WP_CLI::halt(1);

