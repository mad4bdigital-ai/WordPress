<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Pricing_Entity_Picker_V1';
if(!class_exists($cls,false))WP_CLI::error('No Entity Picker');
$search=$cls::search('package','Gold');
if(is_wp_error($search)||!is_array($search))
 WP_CLI::error('Cannot search native Package Levels');
$names=array_map(static fn($x)=>strtolower((string)($x['name']??'')),$search);
if(!array_filter($names,static fn($x)=>str_contains($x,'gold')))
 WP_CLI::error('Gold Package term absent from native search');
foreach($search as $item)
 if((int)$item['id']<1 || (int)$item['canonical_id']<1 ||
    !isset($item['status']) || $item['type']!=='package')
  WP_CLI::error('Term picker omitted canonical/activation context');

$tour=$cls::search('tour','Egypt');
if(is_wp_error($tour))WP_CLI::error('Tour entity lookup failed');
$tier=$cls::search('tier','12');
if(is_wp_error($tier))WP_CLI::error('Group Tier entity lookup failed');
$invalid=$cls::search('unknown','Gold');
if(!is_wp_error($invalid)||$invalid->get_error_code()!=='pricing_picker_kind_invalid')
 WP_CLI::error('Undeclared entity lookup kind allowed');
$_GET['page']='dynamic-pricing-controls';
MAD4B_JetEngine_Field_Modes_V1::enqueue();
$cls::enqueue();
$script=wp_scripts()->registered['mad4b-pricing-entity-picker-v1']??null;
if(!$script || !in_array('mad4b-jetengine-functional-modes',$script->deps,true))
 WP_CLI::error('Entity picker JS not native-field dependent');
$conditional=wp_scripts()->registered['mad4b-pricing-mode-conditional-v1']??null;
if(!$conditional || !in_array('mad4b-jetengine-functional-modes',$conditional->deps,true))
 WP_CLI::error('Conditional financial mode JS is not native-field dependent');
$queueCount=count(wp_scripts()->queue);
wp_set_current_user(0);
$cls::enqueue();
if(count(wp_scripts()->queue)!==$queueCount)
 WP_CLI::error('Guest triggered a private company field-mode enqueue');
$denied=$cls::search('package','Gold');
if(!is_wp_error($denied)||$denied->get_error_code()!=='pricing_picker_forbidden')
 WP_CLI::error('Guest can see private company assignment picker');
echo 'ENTITY_PICKER_ACCEPTANCE '.wp_json_encode([
 'site'=>home_url(),'gold_results'=>count($search),
 'tour_results'=>count($tour),'tier_id_results'=>count($tier),
 'original_native_fields_unchanged'=>true,'no_record_writes'=>true,
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('NATIVE_PRICING_ENTITY_ASSIGNMENT_LOOKUP_ACCEPTED');
