<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Guide_Taxonomy_Pricing_V1';
$core='MAD4B_Guide_Service_Composition_V1';
if(!class_exists($cls,false))WP_CLI::error('Guide taxonomy module missing');
$all=get_option('jet_engine_meta_boxes',[]);
if(!$cls::registryIsPresent((array)$all))WP_CLI::error('JetEngine native Guide Language term box not installed');
echo 'PASS native JetEngine Term Meta Box 9 fields\n';
$defaults=['arabic'=>2000,'english'=>2500,'french'=>3000,'german'=>3000,'spanish'=>3000];
foreach($defaults as $slug=>$amount){
 $v=$cls::resolved($slug);
 if(is_wp_error($v) || $v['source_currency']!=='EGP' || $v['unit_cost']!=$amount ||
   $v['policy_source']!=='global_fallback')
    WP_CLI::error('Default Guide cost parity failed '.$slug.' '.(is_wp_error($v)?$v->get_error_code():'incorrect price'));
 WP_CLI::log('PASS '.$slug.' = '.$amount.' EGP fallback');
}
$guides=$core::guide(60451,'english');
if(is_wp_error($guides)||$guides['cost']!=2500 || $guides['source_currency']!=='EGP' ||
   $guides['checkout_ready']!==false)
    WP_CLI::error('Approved Tour 60451 baseline changed');
WP_CLI::log('PASS existing approved Guide one-day source=EGP amount=2500');
$noguide=$core::guide(55260,'');
if(is_wp_error($noguide)||$noguide['cost']!=0||$noguide['checkout_ready']!==false)
    WP_CLI::error('Owner no-guide Tour 55260 regression');
WP_CLI::log('PASS owner-approved Nile Dinner Guide cost = 0');
$incomplete=$core::guide(60443,'english');
if(!is_wp_error($incomplete) ||
    $incomplete->get_error_code()!=='guide_coverage_not_reviewed')
    WP_CLI::error('Unknown Guide days became priced');
WP_CLI::log('PASS 60443 still unresolved');
$before=get_option('mad4b_guide_daily_base_cost_v1',[]);
if(($before['amounts']['en']??0)!==2500)WP_CLI::error('Unexpected base policy');
$invalid=$cls::save(['scope'=>'global','expected_hash'=>str_repeat('0',64),
 'amounts'=>['ar'=>'1000','en'=>'1000','other'=>'1000']]);
if(!is_wp_error($invalid)||$invalid->get_error_code()!=='guide_global_policy_changed')
 WP_CLI::error('Stale Global Defaults overwritten');
WP_CLI::log('PASS stale Global Defaults edit denied');
WP_CLI::success('GUIDE_TAXONOMY_SCHEMA_AND_FALLBACK_ACCEPTED');
