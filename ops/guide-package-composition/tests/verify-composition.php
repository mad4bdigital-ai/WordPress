<?php
if(!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
$cls='MAD4B_Guide_Service_Composition_V1';
$source=$cls::source(64871);
if (is_wp_error($source)) WP_CLI::error('SOURCE '.$source->get_error_code());
$statuses=[];foreach(array_keys($source['days']) as $key)$statuses[$key]='none';
$keys=array_keys($statuses);
$statuses[$keys[0]]='full_day';
$statuses[$keys[1]]='full_day';
$statuses[$keys[2]]='full_day';
$statuses[$keys[3]]='optional';
$policy=['schema'=>1,'source_hash'=>$source['source_hash'],
    'statuses'=>$statuses,'half_day_factor'=>null,'revision'=>7,'status'=>'explicit_human_review'];
$GLOBALS['mad4b_guide_test_policy']=$policy;
add_filter('get_post_metadata',static function($value,$id,$key,$single)use($source){
 if((int)$id===$source['source_id'] && $key===$GLOBALS['mad4b_guide_meta_key']) return [$GLOBALS['mad4b_guide_test_policy']];
 return $value;
},200,4);
$GLOBALS['mad4b_guide_meta_key']=$cls::META;
function check_guide($r,$label,$expected) {
 if(is_wp_error($r))WP_CLI::error($label.' unexpected error='.$r->get_error_code());
 if(abs((float)$r['cost']-$expected)>0.001)WP_CLI::error($label.' expected='.$expected.' actual='.$r['cost']);
 WP_CLI::log('PASS '.$label.'='.$r['cost'].' EGP');
}
check_guide($cls::guide(64871,'english'), 'english_3_full_days', 7500);
$arabic=$cls::guide(64871,'arabic');
if(!is_wp_error($arabic)||$arabic->get_error_code()!=='guide_language_not_offered_by_tour')
 WP_CLI::error('Unsupported tour guide language was accepted');
WP_CLI::log('PASS language_not_offered_blocked');
check_guide($cls::guide(64871,'french'), 'french_3_full_days', 9000);
check_guide($cls::guide(64871,'english',0,2), 'two_guides', 15000);
$package=$cls::breakdown(64871,'english');
if($package['guide_cost_egp']!==7500.0&&$package['guide_cost_egp']!==7500)
  WP_CLI::error('Shadow cost mismatch');
if($package['total_component_cost']!==null || $package['rate_generation_ready']!==false
    || $package['retail_price_changed']!==false || $package['checkout_ready']!==false)
  WP_CLI::error('Uncertified retail or generation readiness');
WP_CLI::log('PASS package_shadow_no_retail');
$delta=$cls::breakdown(64871,'french',0,1,0,'english');
if((float)$delta['guide_cost_delta_egp']!==1500.0 ||
 $delta['total_component_cost']!==null || $delta['retail_price_changed']!==false)
 WP_CLI::error('Guide language cost differential was not isolated from retail');
WP_CLI::log('PASS guide_language_cost_delta_internal_only');
$statuses[$keys[0]]='unknown';$policy['statuses']=$statuses;
$GLOBALS['mad4b_guide_test_policy']=$policy;
$unknown=$cls::guide(64871,'english');
if(!is_wp_error($unknown)||$unknown->get_error_code()!=='guide_days_unreviewed')
 WP_CLI::error('Unknown day should fail closed');
WP_CLI::log('PASS unknown_day_blocked');
$statuses[$keys[0]]='half_day';$policy['statuses']=$statuses;
$GLOBALS['mad4b_guide_test_policy']=$policy;
$half=$cls::guide(64871,'english');
if(!is_wp_error($half)||$half->get_error_code()!=='guide_half_day_policy_required')
 WP_CLI::error('Unapproved half-day factor accepted');
WP_CLI::log('PASS half_day_factor_required');
$policy['half_day_factor']=0.5;$GLOBALS['mad4b_guide_test_policy']=$policy;
check_guide($cls::guide(64871,'english'),'two_full_one_half',6250);
$policy['source_hash']=str_repeat('a',64);$GLOBALS['mad4b_guide_test_policy']=$policy;
$invalid=$cls::guide(64871,'english');
if(!is_wp_error($invalid)||$invalid->get_error_code()!=='guide_coverage_source_changed')
 WP_CLI::error('Stale itinerary was accepted');
WP_CLI::log('PASS itinerary_hash_mismatch_blocked');
$policy['source_hash']=$source['source_hash'];
$policy['statuses']=array_fill_keys(array_keys($source['days']),'none');
$policy['half_day_factor']=null;
$GLOBALS['mad4b_guide_test_policy']=$policy;
$noGuide=$cls::guide(64871,'');
if(is_wp_error($noGuide)||(float)$noGuide['cost']!==0.0||
   ($noGuide['tier']??null)!=='not_required'||($noGuide['guide_count']??null)!==0)
 WP_CLI::error('No-guide itinerary incorrectly charged or requested a language');
WP_CLI::log('PASS explicitly_no_guide_cost_zero_without_language');
$policy['statuses'][$keys[0]]='optional';
$GLOBALS['mad4b_guide_test_policy']=$policy;
$optionalGuide=$cls::guide(64871,'');
if(is_wp_error($optionalGuide)||(float)$optionalGuide['cost']!==0.0||
 ($optionalGuide['optional_days_excluded']??null)!==1)
 WP_CLI::error('Optional-only guide was included in package base');
WP_CLI::log('PASS optional_only_guide_excluded_from_base');
remove_all_filters('get_post_metadata',200);
$source2=$cls::source(60433);
if(is_wp_error($source2)||$source2['source_kind']!=='term'||count($source2['days'])!==5)
 WP_CLI::error('Reusable cruise program mismatch');
WP_CLI::log('PASS cruise_program_5_days_source');
$cruiseStatuses=[];foreach(array_keys($source2['days']) as $key)$cruiseStatuses[$key]='none';
$cruiseKeys=array_keys($cruiseStatuses);
$cruiseStatuses[$cruiseKeys[0]]='full_day';
$cruiseStatuses[$cruiseKeys[1]]='full_day';
$cruiseRecord=['schema'=>1,'source_hash'=>$source2['source_hash'],
    'statuses'=>$cruiseStatuses,'half_day_factor'=>null,'revision'=>1];
add_filter('get_term_metadata',static function($value,$id,$key,$single)use($source2,$cruiseRecord){
 if((int)$id===$source2['source_id']&&$key===MAD4B_Guide_Service_Composition_V1::META)
  return [$cruiseRecord];
 return $value;
},200,4);
check_guide($cls::guide(60433,'arabic'),'arabic_cruise_two_guided_days',4000);
remove_all_filters('get_term_metadata',200);
$shadow=apply_filters('mad4b/rate_generator/plan',
 ['schema'=>'mad4b-rate-generator-hybrid-context.v1'],
 ['kind'=>'tour','parent_id'=>64871],['kind'=>'tour']);
if(!is_array($shadow)||empty($shadow['guide_cost_preflight'])||
 ($shadow['guide_cost_preflight']['retail_amount_modified']??true)!==false ||
 ($shadow['guide_cost_preflight']['component_cost_based_generation_allowed']??true)!==false)
 WP_CLI::error('Generator advisory hook unsafe');
WP_CLI::log('PASS native_rate_generation_unchanged');
wp_set_current_user(0);
$r=new WP_REST_Request('GET','/mad4b-guide/v1/package-cost');
$r->set_param('tour_id',64871);$r->set_param('language','english');
$resp=rest_do_request($r);
if($resp->get_status()<400)WP_CLI::error('Anonymous user can see self supplier cost');
WP_CLI::log('PASS anonymous_cost_access_denied_http_'.$resp->get_status());
WP_CLI::success('GUIDE_COMPOSITION_ACCEPTANCE_PASSED');
