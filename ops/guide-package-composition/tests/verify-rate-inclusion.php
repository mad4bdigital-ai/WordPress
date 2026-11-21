<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_Guide_Service_Composition_V1';
$rate=getenv('MAD4B_GUIDE_TEST_RATE_ID')?(int)getenv('MAD4B_GUIDE_TEST_RATE_ID'):68966;
$tour=(int)get_post_meta($rate,'related_tour_id',true);
$program=(int)get_post_meta($rate,'related_program_term_id',true);
$source=$cls::source($tour,$program);
if(is_wp_error($source))WP_CLI::error('Rate lacks structured itinerary: '.$source->get_error_code());
$keys=array_keys($source['days']);
if(count($keys)<3) WP_CLI::error('Rate itinerary too short');
$statuses=array_fill_keys($keys,'none');
foreach(array_slice($keys,0,3) as $key) $statuses[$key]='full_day';
$GLOBALS['guide_test_source_meta']=['schema'=>1,'source_hash'=>$source['source_hash'],
    'statuses'=>$statuses,'half_day_factor'=>null,'revision'=>11];
$GLOBALS['guide_test_rate_meta']=[
    'schema'=>1,'status'=>'included','baseline_language'=>'english',
    'source_hash'=>$source['source_hash'],
    'rate_pricing_hash'=>$cls::ratePricingHash($rate),
    'evidence'=>'A reviewed supplier cost allocation: licensed Guide cost is already inside this Rate',
    'reviewer'=>1,'reviewed_at'=>'2026-11-19T00:00:00+00:00'];
add_filter('get_post_metadata',static function($value,$id,$key,$single)use($source,$cls,$rate){
 if((int)$id===$source['source_id']&&$key===$cls::META) return [$GLOBALS['guide_test_source_meta']];
 if((int)$id===$rate&&$key===$cls::RATE_META)return [$GLOBALS['guide_test_rate_meta']];
 return $value;
},10000,4);
$receipt=$cls::rateReceipt($rate);
if(is_wp_error($receipt)||$receipt['status']!=='included')WP_CLI::error('Inclusion receipt did not validate');
WP_CLI::log('PASS owner_reviewed_rate_receipt');
$included=$cls::breakdown($tour,'french',$program,1,$rate,'spanish');
if($included['existing_rate_guide_inclusion']!=='included'||
    (float)$included['guide_incremental_cost_reference_egp']!==1500.0 ||
    (float)$included['guide_cost_delta_egp']!==1500.0 ||
    $included['baseline_language_code']!=='en'||
    $included['retail_price_changed']!==false)WP_CLI::error('Included rate re-added guide or trusted user-provided baseline');
WP_CLI::log('PASS included_rate_replaces_difference_only_1500_EGP');
$GLOBALS['guide_test_rate_meta']['status']='excluded';
$GLOBALS['guide_test_rate_meta']['baseline_language']=null;
$excluded=$cls::breakdown($tour,'french',$program,1,$rate);
if($excluded['existing_rate_guide_inclusion']!=='excluded' ||
    (float)$excluded['guide_incremental_cost_reference_egp']!==9000.0 ||
    $excluded['total_component_cost']!==null)WP_CLI::error('Excluded Rate cost not isolated');
WP_CLI::log('PASS excluded_rate_guide_cost_reference_9000_EGP');
$GLOBALS['guide_test_rate_meta']['status']='partial';
$partial=$cls::breakdown($tour,'french',$program,1,$rate);
if($partial['guide_incremental_cost_reference_egp']!==null||
    $partial['existing_rate_guide_inclusion']!=='partial')
 WP_CLI::error('Partly included guide cost incorrectly added');
WP_CLI::log('PASS partial_rate_does_not_double_charge');
$GLOBALS['guide_test_rate_meta']['status']='included';
$GLOBALS['guide_test_rate_meta']['baseline_language']='english';
$GLOBALS['guide_test_rate_meta']['rate_pricing_hash']=str_repeat('0',64);
$stale=$cls::rateReceipt($rate);
if(!is_wp_error($stale)||$stale->get_error_code()!=='guide_rate_pricing_changed')
 WP_CLI::error('Rate cost inclusion not invalidated after pricing edit');
WP_CLI::log('PASS changed_rate_prices_invalidate_cost_inclusion_receipt');
$GLOBALS['guide_test_rate_meta']['rate_pricing_hash']=$cls::ratePricingHash($rate);
$GLOBALS['guide_test_rate_meta']['baseline_language']='arabic';
$wrongLanguage=$cls::rateReceipt($rate);
if(!is_wp_error($wrongLanguage) ||
 $wrongLanguage->get_error_code()!=='guide_rate_baseline_language_invalid')
 WP_CLI::error('Rate accepted a guide baseline language not assigned to its Tour');
WP_CLI::log('PASS unoffered_rate_baseline_rejected');
remove_all_filters('get_post_metadata',10000);
WP_CLI::success('RATE_GUIDE_DOUBLE_COUNT_PROTECTION_PASSED');
