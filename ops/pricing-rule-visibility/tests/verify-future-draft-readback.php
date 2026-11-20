<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Pricing_Rules_Visibility_V1';
$mock=[
 ['id'=>'pl-draft-example','title'=>'Example Tour Type Rule',
  'status'=>'draft','revision'=>2,'strategy'=>'percent','value'=>95,
  'currency'=>'','rules'=>[
   ['id'=>'taxonomy:tour-types','key'=>'tour-types','values'=>['123'],'match'=>'any']]],
 ['id'=>'pl-staged-example','title'=>'Example Group Strategy',
  'status'=>'staged_for_native_review','revision'=>1,'strategy'=>'fixed','value'=>125,
  'currency'=>'USD','staged_rows'=>['native-row'],
  'rules'=>[['id'=>'taxonomy:package_levels','key'=>'package_levels','values'=>['2'],'match'=>'all']]]
];
$override=static fn($pre)=>$mock;
add_filter('pre_option_mad4b_jetengine_pricing_lab_presets_v1',$override,200,1);
$view=$cls::snapshot();
remove_filter('pre_option_mad4b_jetengine_pricing_lab_presets_v1',$override,200);
if(is_wp_error($view)||$view['strategy_count']!==2||
  count($view['by_dimension']['taxonomy:tour-types']??[])!==1||
  count($view['by_dimension']['taxonomy:package_levels']??[])!==1)
 WP_CLI::error('Snapshot did not resolve staged and draft strategies by dimension');
if(($view['unified_registry']['strategy_summaries']??null)!==$view['strategies'] ||
   ($view['unified_registry']['native_assignment_summary']??null)!==$view['native_assignments'])
 WP_CLI::error('Pricing Lab or Native preset readback is not sourced from the one registry snapshot');
if($view['by_dimension']['taxonomy:tour-types'][0]['status']!=='draft'||
   $view['by_dimension']['taxonomy:package_levels'][0]['status']!=='staged_for_native_review'||
   $view['by_dimension']['taxonomy:package_levels'][0]['authorizes_checkout']!==false)
 WP_CLI::error('Draft and staged distinction lost');
$actual=get_option('mad4b_jetengine_pricing_lab_presets_v1',null);
if($actual!==null&&$actual!==[])WP_CLI::error('Test changed actual saved Pricing Lab policies');
WP_CLI::log('PASS future Draft and staged rules display by dimension without activating Checkout');
WP_CLI::success('RULE_VISIBILITY_FUTURE_DRAFT_READBACK_ACCEPTED');
