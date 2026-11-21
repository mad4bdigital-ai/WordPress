<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Unified_Pricing_Policy_Registry_V1';
$original=get_option('etg-dynamic-pricing-controls_shared_package_pricing_rows',null);
$source='etg-dynamic-pricing-controls_shared_package_pricing_rows';
$rows=[
 ['package_term_id'=>2353,'scope'=>'global','tour_id'=>0,'rate_id'=>0,'enabled'=>'1','percent'=>110],
 ['package_term_id'=>str_contains(home_url(),'wayfatours.com')?2991:2353,'scope'=>'global','tour_id'=>0,'rate_id'=>0,'enabled'=>'1','percent'=>125],
];
$mock=static fn($value)=>$rows;
add_filter('pre_option_'.$source,$mock,25000,1);
$registry=$cls::snapshot();
remove_filter('pre_option_'.$source,$mock,25000);
if(is_wp_error($registry))WP_CLI::error('Mock registry failed');
$items=array_values(array_filter($registry['rows'],
 static fn($r)=>$r['family']==='package_level' &&
  (int)($r['data']['term_id']??0)===2353));
if(count($items)!==1||
   $items[0]['status']!=='blocked_source_or_conflict' ||
   $items[0]['commercial_active']!==false)
 WP_CLI::error('WPML split-rate conflict was not blocked');
if(get_option($source,null)!==$original)
 WP_CLI::error('Mock created an actual WordPress pricing policy write');
WP_CLI::log('PASS WPML contradictory prices blocked, not silently merged');
WP_CLI::success('MAD4B_UNIFIED_CONFLICT_MOCK_ACCEPTED');
