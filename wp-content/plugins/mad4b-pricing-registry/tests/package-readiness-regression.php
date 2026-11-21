<?php
if(!class_exists('MAD4B_Package_Component_Readiness_V1'))throw new RuntimeException('not_loaded');
$fail=[];$checks=[];
$expect=static function($name,$condition)use(&$checks,&$fail){$checks[$name]=(bool)$condition;if(!$condition)$fail[]=$name;};
$report=MAD4B_Package_Component_Readiness_V1::report('2026-12-01','2027-04-30');
$expect('authorized_report',is_array($report));
if(is_array($report)){
 $expect('eight_tours',count($report['tours'])===8);
 $expect('twenty_eight_stages',$report['totals']['stage_count']===28);
 $expect('nineteen_hotel_resort',$report['totals']['hotel_resort_count']===19);
 $expect('seven_cruises',$report['totals']['cruise_count']===7);
 $expect('two_sleeper_trains',$report['totals']['sleeper_train_count']===2);
 $expect('twenty_four_tier_reviews',$report['totals']['reviewed_tier_combinations']===24);
 $expect('no_commercial_tier_ready',$report['totals']['price_ready_tier_reviews']===0);
 $expect('twenty_eight_unpriced',$report['totals']['unpriced_stages']===28);
 $expect('no_checkout',$report['checkout_authorized']===false);
 $expect('no_write',$report['write_performed']===false);
 $expect('no_new_rate',$report['rate_created']===false);
 $expect('eight_topologies',count(array_filter($report['tours'],static fn($p)=>$p['topology_ready']))===8);
}
$first=$report['tours'][0]['levels']['Silver']['stages'][0]??[];
$expect('unit_candidate_status_in_contract',
 is_array($first)&&array_key_exists('unbookable_draft_unit_candidates',$first)&&
 array_key_exists('published_but_unavailable_unit_candidates',$first));
$expectedDraft=str_contains(home_url(),'tours.dev.mad4b.com')?12:0;
$expect('unbookable_unit_staging_readback',
 (int)($first['unbookable_draft_unit_candidates']??-1)===$expectedDraft);
$invalid=MAD4B_Package_Component_Readiness_V1::report('2026-02-30','2027-01-01');
$expect('invalid_date_rejected',is_wp_error($invalid)&&$invalid->get_error_code()==='package_readiness_date_window_invalid');
$window=MAD4B_Package_Component_Readiness_V1::report('2026-12-01','2029-12-01');
$expect('huge_date_window_rejected',is_wp_error($window)&&$window->get_error_code()==='package_readiness_date_window_invalid');
$originalUser=get_current_user_id();
wp_set_current_user(0);
$unauthorized=MAD4B_Package_Component_Readiness_V1::report('2026-12-01','2027-04-30');
wp_set_current_user($originalUser);
$expect('unauthorized_user_rejected',is_wp_error($unauthorized)&&$unauthorized->get_error_code()==='package_readiness_forbidden');
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.package-component-regression.v1',
 'passed'=>count($checks)-count($fail),'total'=>count($checks),'failures'=>$fail,
 'checkout_authorized'=>false,'mutation_performed'=>false,'checks'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
if($fail){if(class_exists('WP_CLI'))WP_CLI::halt(1);exit(1);}
