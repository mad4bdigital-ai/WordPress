<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Pricing_Rules_Visibility_V1';
if(!class_exists($cls,false))WP_CLI::error('Pricing Rule Inspector not loaded');
$data=$cls::snapshot();
if(is_wp_error($data)||!is_array($data))WP_CLI::error('Company pricing owner denied');
if($data['read_only']!==true||$data['no_sale_quote_authority']!==true)
 WP_CLI::error('Readback mutated commercial authority');
if(!array_key_exists('strategy_count',$data)||
   !isset($data['native_assignments']['enabled_approved'],$data['policy_bindings']['global_refs']))
 WP_CLI::error('Missing required strategy activation evidence');
$base='https://wayfatours.com';
if(rtrim(home_url(),'/')===$base){
 if($data['engine_enabled']!==true||$data['booking_enabled']!==false)
 WP_CLI::error('Wayfatours engine/booking state differs from audit');
 if($data['dimension_mode']!=='custom'||
    $data['selected_dimensions']!==['taxonomy:tour-types','taxonomy:package_levels'])
 WP_CLI::error('Wayfatours two selected dimensions mismatch');
 if($data['strategy_count']!==0||
    $data['native_assignments']['enabled_approved']!==0 ||
    $data['policy_bindings']['global_refs']!==0 ||
    $data['published_tour_rates']!==0)
 WP_CLI::error('Wayfatours no active Strategy evidence changed; audit required');
 WP_CLI::log('PASS Wayfatours ON engine, 2 selected dimensions, zero strategy assignments and published Rates');
}
wp_set_current_user(0);
$denied=$cls::snapshot();
if(!is_wp_error($denied)||$denied->get_error_code()!=='pricing_rules_readback_forbidden')
 WP_CLI::error('Guest allowed private company policy readback');
WP_CLI::log('PASS guest forbidden to access company Strategy inspector');
wp_set_current_user(1);
$raw=get_option('mad4b_jetengine_pricing_lab_presets_v1',null);
if(!is_null($raw) && !is_array($raw))WP_CLI::error('Malformed preset storage');
echo 'RULE_STATUS '.wp_json_encode([
 'site'=>home_url(),'engine_on'=>$data['engine_enabled'],
 'booking_on'=>$data['booking_enabled'],'dimension_mode'=>$data['dimension_mode'],
 'selected'=>$data['selected_dimensions'],'strategies'=>$data['strategy_count'],
 'native'=>$data['native_assignments'],'bindings'=>$data['policy_bindings'],
 'published_rates'=>$data['published_tour_rates']
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('PRICING_RULE_STATUS_READBACK_ACCEPTED');
