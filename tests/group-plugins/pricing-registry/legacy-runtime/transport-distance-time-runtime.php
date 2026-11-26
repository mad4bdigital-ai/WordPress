<?php
/** Safe live runtime: pure calculations + temporary option override only. */
defined('ABSPATH')||exit(2);
$c='MAD4B_Transport_Distance_Time_V1';
$checks=[];$add=static function($name,$ok)use(&$checks){$checks[$name]=(bool)$ok;};
$add('class_present',class_exists($c));
$default=$c::defaults();
$add('default_currency_EGP',$default['currency']==='EGP');
$add('confirmed_cost_role',$default['role']==='self_cost');
$add('commercial_gate_closed',$default['mode']==='simulation_only');
$raw=[];
foreach(['profiles','dispatch_policy','global_markup_pct','vehicle_markups','service_markups',
 'route_markups','road','night_factor','peak_factor',
 'discount_pct','time_share'] as $key)$raw[$key]=$default[$key];
$v=$c::validate($raw);
$add('default_valid',is_array($v));
$bad=$raw;$bad['profiles']['limousine']['per_km']=-5;
$add('negative_kilometer_rejected',is_wp_error($c::validate($bad)));
$bad=$raw;$bad['road']['highway']['speed']=0;
$add('zero_road_speed_rejected',is_wp_error($c::validate($bad)));
$bad=$raw;$bad['vehicle_markups']['bus']['mode']='eval';
$add('unknown_markup_mode_rejected',is_wp_error($c::validate($bad)));
$bad=$raw;$bad['route_markups']['example://bad']=['mode'=>'override','pct'=>40];
$add('malformed_route_key_rejected',is_wp_error($c::validate($bad)));
$cal=$c::calibrate();
$add('source_has_six_vehicle_categories',count($cal['profiles']??[])===6);
$add('source_marked_confirmed_cost',($cal['source_role']??'')==='actual_company_cost');
$add('outliers_excluded',($cal['profiles']['bus']['excluded']??0)>0);
$add('H1_not_unfounded_rate',array_key_exists('proposal',$cal['profiles']['h1'])&&$cal['profiles']['h1']['proposal']===null);
$add('limousine_has_proposal',($cal['profiles']['limousine']['proposal']['per_km']??0)>0);
$add('Luxor_anomaly_excluded',in_array('Luxor:7_anomalous_vehicle_prices',$cal['excluded']??[],true));
$reference=MAD4B_Transport_Self_Pricing_Controls_V1::source();
$src=[];foreach($reference['rows'] as $r)$src[$r['source_key']]=$r;
$add('all_51_pdf_rows',count($reference['rows'])===51);
$add('Cairo_1_Hiace_cost_1400',$src['Cairo:1']['vehicle_rates_unverified']['hiace']===1400);
$add('Cairo_1_Luxury7_cost_1600',$src['Cairo:1']['vehicle_rates_unverified']['luxury_7_seats']===1600);
$add('original_pdf_sha_unchanged',$reference['source_sha256']===$c::SHA);
$sample=$default;
$sample['profiles']['limousine']=[
 'fixed'=>100.0,'per_km'=>10.0,'per_drive_min'=>2.0,
 'per_wait_min'=>1.0,'overnight'=>500.0,'min_fare'=>1000.0,
 'deadhead_pct'=>10.0,'source'=>'manual_draft','samples'=>0];
$sample['global_markup_pct']=20.0;$sample['discount_pct']=5.0;
$usesTerms=class_exists('MAD4B_Transport_Vehicle_Term_Profiles_V1') &&
 MAD4B_Transport_Vehicle_Term_Profiles_V1::active();
$mockTermProfile=$sample['profiles']['limousine'];
$termInterceptor=null;
if($usesTerms){
 $t=MAD4B_Transport_Vehicle_Term_Profiles_V1::profile('limousine');
 $termId=$t['term_id'];
 $fieldLookup=array_flip(MAD4B_Transport_Vehicle_Term_Profiles_V1::META);
 $termInterceptor=function($value,$id,$key,$single)
   use($termId,$fieldLookup,&$mockTermProfile){
  if((int)$id!==$termId)return $value;
  if(isset($fieldLookup[$key]))return [$mockTermProfile[$fieldLookup[$key]]];
  if($key==='mad4b_vehicle_cost_basis')return [$mockTermProfile['source']];
  if($key==='mad4b_vehicle_cost_samples')return [$mockTermProfile['samples']];
  return $value;
 };
 add_filter('get_term_metadata',$termInterceptor,10,4);
}

$override=function()use($sample){return $sample;};
add_filter('pre_option_'. $c::OPTION,$override,10,0);
$quote=$c::calculate([
 'vehicle'=>'limousine','service'=>'transfer','road'=>'highway',
 'km'=>100,'drive_min'=>120,'wait_min'=>30,
 'overnight_units'=>0,'tolls'=>0,'parking'=>0,'extras'=>0,
 'night'=>false,'peak'=>false
]);
$add('manual_route_calculated',is_array($quote));
$add('operating_cost_not_double_counted',is_array($quote)&&
 abs($quote['estimated_operating_cost_egp']-1370)<0.001);
$add('selling_markup_once',is_array($quote)&&
 abs($quote['indicative_selling_scenario_egp']-1561.8)<0.001);
$add('callout_default_disabled_no_surcharge',is_array($quote)&&
 $quote['dispatch_base']['enabled']===false&&
 $quote['dispatch_base']['billed_additional_surcharge_egp']===0.0);
$sampleActive=$sample;
$sampleActive['dispatch_policy']['enabled']=true;
$activeOverride=function()use($sampleActive){return $sampleActive;};
remove_filter('pre_option_'.$c::OPTION,$override,10);
add_filter('pre_option_'.$c::OPTION,$activeOverride,10,0);
$longTrip=$c::calculate(['vehicle'=>'limousine','service'=>'transfer',
 'km'=>100,'drive_min'=>120,'wait_min'=>30,
 'night'=>false,'peak'=>false]);
$add('long_route_callout_fully_covered_not_added',
 is_array($longTrip)&&
 abs($longTrip['estimated_operating_cost_egp']-1370)<0.001&&
 abs($longTrip['dispatch_base']['covered_by_movement_egp']-100)<0.001&&
 $longTrip['dispatch_base']['floor_difference_egp']===0.0);
$shortTrip=$c::calculate(['vehicle'=>'limousine','service'=>'transfer',
 'km'=>1,'drive_min'=>1,'wait_min'=>0,
 'night'=>false,'peak'=>false]);
$add('short_route_uses_base_as_minimum',
 is_array($shortTrip)&&
 abs($shortTrip['estimated_operating_cost_egp']-100)<0.001&&
 $shortTrip['dispatch_base']['floor_difference_egp']>0&&
 $shortTrip['dispatch_base']['billed_additional_surcharge_egp']===0.0);
$add('refund_policy_never_autoducted',
 is_array($shortTrip)&&
 $shortTrip['dispatch_base']['cancellation_retention_auto_applied']===false&&
 $shortTrip['dispatch_base']['requires_booking_terms_and_supplier_assignment']===true);
remove_filter('pre_option_'.$c::OPTION,$activeOverride,10);
add_filter('pre_option_'.$c::OPTION,$override,10,0);
$add('always_noncommercial',is_array($quote)&&
 $quote['checkout_authorized']===false&&
 $quote['native_transport_rate_approved']===false);
$manualCustom=$c::calculate([
 'vehicle'=>'limousine','service'=>'transfer','road'=>'highway',
 'km'=>45,'drive_min'=>65,'from_label'=>'Custom Hotel A',
 'to_label'=>'Station B','night'=>false,'peak'=>false
]);
$add('unlisted_route_manual_dist_time',
 is_array($manualCustom)&&$manualCustom['distance_km']===45.0&&
 $manualCustom['operator_origin_label']==='Custom Hotel A'&&
 $manualCustom['checkout_authorized']===false);
$geoCustom=$c::calculate([
 'vehicle'=>'limousine','service'=>'transfer','road'=>'highway',
 'from_lat_lon'=>[30.0444,31.2357],
 'to_lat_lon'=>[31.2001,29.9187],
 'km'=>'','drive_min'=>'','night'=>false,'peak'=>false
]);
$add('coordinate_route_approximation_not_road_verified',
 is_array($geoCustom)&&$geoCustom['distance_km']>100&&
 $geoCustom['geometry']['geometry_verified']===false&&
 $geoCustom['checkout_authorized']===false);
$badGeo=$c::calculate([
 'vehicle'=>'limousine','service'=>'transfer','road'=>'highway',
 'from_lat_lon'=>[100,31.2357],'to_lat_lon'=>[31.2,29.91],
 'km'=>'','drive_min'=>''
]);
$add('invalid_coordinate_blocked',is_wp_error($badGeo));
$add('unknown_vehicle_rejected',is_wp_error($c::calculate([
 'vehicle'=>'plane','km'=>100,'drive_min'=>120])));
$add('missing_duration_rejected',is_wp_error($c::calculate([
 'vehicle'=>'limousine','service'=>'transfer','km'=>100,'drive_min'=>''])));
$add('missing_profile_rejected',is_wp_error($c::calculate([
 'vehicle'=>'h1','service'=>'transfer','km'=>100,'drive_min'=>120])));
$sample['profiles']['limousine']['min_fare']=2000.0;
$mockTermProfile=$sample['profiles']['limousine'];
$overrideMin=function()use($sample){return $sample;};
remove_filter('pre_option_'.$c::OPTION,$override,10);
add_filter('pre_option_'.$c::OPTION,$overrideMin,10,0);
$floor=$c::calculate(['vehicle'=>'limousine','service'=>'transfer',
 'km'=>100,'drive_min'=>120,'wait_min'=>0,'overnight_units'=>0,
 'night'=>false,'peak'=>false]);
$add('minimum_fare_survives_discount',
 is_array($floor)&&$floor['indicative_selling_scenario_egp']===2000.0);
remove_filter('pre_option_'.$c::OPTION,$overrideMin,10);
if($termInterceptor)remove_filter('get_term_metadata',$termInterceptor,10);
$actual=$c::current();
$add('vehicle_source_term_or_legacy',
 in_array($actual['vehicle_profile_owner']??'legacy_company_option',
 ['jetengine_taxonomy_term_meta','legacy_company_option'],true));
$add('original_policy_unmodified',
 $actual['source_sha256']===$c::SHA&&$actual['mode']==='simulation_only');
$add('Tour_Rate_publication_untouched',!service_channel_allowed('transportations','standalone'));
$fail=array_keys(array_filter($checks,static fn($x)=>!$x));
echo wp_json_encode(['site'=>home_url(),'passed'=>count($checks)-count($fail),
 'total'=>count($checks),'failed'=>$fail,'fixture_original_cost_currency'=>'EGP',
 'real_orders_created'=>0,'checkout_enabled'=>false])."\n";
if($fail)exit(1);
