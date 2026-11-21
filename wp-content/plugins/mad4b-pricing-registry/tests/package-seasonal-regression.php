<?php
/** Non-mutating seasonal assignment regression. */
$checks=[];
$checks['module']=class_exists('MAD4B_Package_Seasonal_Assignment_V1',false);
$checks['date_helper']=function_exists('shared_product_booking_v2_rate_effective_window');
$checks['summer_start']=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2026-05-01')==='summer';
$checks['summer_end']=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2026-09-30')==='summer';
$checks['winter_start']=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2026-10-01')==='winter';
$checks['winter_cross_year']=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2027-01-01')==='winter';
$checks['winter_end']=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2027-04-30')==='winter';
$checks['invalid_day']=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2027-02-30')===null;
$catalog=MAD4B_Package_Seasonal_Assignment_V1::catalog(60623);
$checks['catalog']=is_array($catalog)&&count($catalog['options'])>0;
$checks['no_checkout']=is_array($catalog)&&$catalog['checkout_authorized']===false;
$review=MAD4B_Package_Stage_Rate_Guard_V1::review(60623,2354,'2026-12-01','2027-04-30');
$checks['native_guard_fail_closed']=empty($review['ready'])&&!empty($review['blockers']);
$checks['stage_seasonal_field']=isset($review['stages'][0]['season_rejected_property_ids']);
$report=MAD4B_Package_Component_Readiness_V1::report('2026-12-01','2027-04-30');
$checks['readiness']=is_array($report)&&$report['checkout_authorized']===false;
$failed=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.package-seasonal-regression.v1',
 'passed'=>count($checks)-count($failed),'total'=>count($checks),
 'failures'=>$failed,'no_mutations'=>true,'checkout_authorized'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($failed&&class_exists('WP_CLI'))WP_CLI::halt(1);

