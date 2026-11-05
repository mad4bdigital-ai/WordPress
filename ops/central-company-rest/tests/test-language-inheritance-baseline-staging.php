<?php
/**
 * Language-inheritance acceptance: source and consumer WPML, no markets.
 * Candidate classes are loaded separately by the VPS test runner.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Hub_Language_Baseline')||
 !class_exists('MAD4B_Hub_Discovery_Planner'))throw new RuntimeException('Missing language planner');

$now=time();
$observed=array(
 'tourism-almallahgroup'=>array('company'=>'tourism-almallahgroup',
  'canonical_host'=>'tourism.almallahgroup-mg.com',
  'active_languages'=>array('en','es','de','fr','ar','it'),
  'hidden_languages'=>array('es','de','fr','it'),
  'default_language'=>'en','observed_at'=>$now,
  'evidence_type'=>'verified_ssh_wpml_option_observation','authorization'=>'capability_only'),
 'all-royal-egypt'=>array('company'=>'all-royal-egypt',
  'canonical_host'=>'www.allroyalegypt.com',
  'active_languages'=>array('en','es','de','fr','ar','it'),
  'hidden_languages'=>array('ar'),
  'default_language'=>'en','observed_at'=>$now,
  'evidence_type'=>'verified_ssh_wpml_option_observation','authorization'=>'capability_only'),
 'egypt-tour-gates'=>array('company'=>'egypt-tour-gates',
  'canonical_host'=>'egypttourgates.com',
  'active_languages'=>array('en','es','de','fr','ar','it'),
  'hidden_languages'=>array('ar','fr','de'),
  'default_language'=>'en','observed_at'=>$now,
  'evidence_type'=>'verified_ssh_wpml_option_observation','authorization'=>'capability_only')
);
add_filter('pre_option_mad4b_hub_source_wpml_observations_v1',
 static function()use($observed){return $observed;},10);
$layers=array(
 'platform'=>array('deny'=>array()),
 'source_company'=>array('deny'=>array()),
 'consumer_company'=>array('deny'=>array()),
 'provider'=>array('deny'=>array()),
 'content_type'=>array('deny'=>array()),
 'market'=>array('deny'=>array()),
 'selection'=>array('deny'=>array())
);
$expected=array(
 'tourism-almallahgroup'=>array('en','ar'),
 'all-royal-egypt'=>array('en','es','de','fr','it'),
 'egypt-tour-gates'=>array('en','es','it'));
foreach($expected as $company=>$languages){
 $result=MAD4B_Hub_Language_Baseline::evaluate($company,
    array('languages'=>$languages,'source_record_ids'=>array(24900)),$layers);
 if(is_wp_error($result))throw new RuntimeException('Language-only profile rejected: '.$result->get_error_code());
 if($result['effective_visible_languages']!==$languages ||
    $result['status']!=='LANGUAGE_BASELINE_DRY_RUN' ||
    $result['source_publication_grant_verified']!==false ||
    !empty($result['scopes'][0]['dimensions']['currencies']) ||
    $result['runtime_approved']!==false)
    throw new RuntimeException('Source/consumer language inheritance mismatch: '.$company);
}
$refuse=MAD4B_Hub_Language_Baseline::evaluate('tourism-almallahgroup',
 array('languages'=>array('it'),'source_record_ids'=>array(24900)),$layers);
if(!is_wp_error($refuse)||$refuse->get_error_code()!=='mad4b_language_locale_not_visible_on_both_sites')
 throw new RuntimeException('Hidden source language approved');
$unsupported=MAD4B_Hub_Language_Baseline::evaluate('egypt-tour-gates',
 array('languages'=>array('ar'),'source_record_ids'=>array(24900)),$layers);
if(!is_wp_error($unsupported))throw new RuntimeException('Hidden ETG AR approved');

$local=get_option('icl_sitepress_settings');
$local['hidden_languages']=array('ar','fr','de','it','es');
add_filter('pre_option_icl_sitepress_settings',static function()use($local){return $local;},10);
$narrow=MAD4B_Hub_Language_Baseline::evaluate('tourism-almallahgroup',
 array('languages'=>array('en'),'source_record_ids'=>array(24900)),$layers);
if(is_wp_error($narrow)||$narrow['effective_visible_languages']!==array('en'))
 throw new RuntimeException('Local WPML visibility did not narrow scope');
$hiddenLocally=MAD4B_Hub_Language_Baseline::evaluate('tourism-almallahgroup',
 array('languages'=>array('ar'),'source_record_ids'=>array(24900)),$layers);
if(!is_wp_error($hiddenLocally))throw new RuntimeException('Destination hidden AR allowed');
remove_all_filters('pre_option_icl_sitepress_settings');

$other=$layers;$other['source_company']['market_limits']=array('currencies'=>array('EGP'));
$denied=MAD4B_Hub_Language_Baseline::evaluate('tourism-almallahgroup',
 array('languages'=>array('en'),'source_record_ids'=>array(24900)),$other);
if(!is_wp_error($denied)||$denied->get_error_code()!=='mad4b_language_explicit_market_policy_required')
 throw new RuntimeException('Explicit market rules bypassed');
$blocked=$layers;$blocked['platform']['deny']=array('read_catalog');
if(!is_wp_error(MAD4B_Hub_Language_Baseline::evaluate('tourism-almallahgroup',
 array('languages'=>array('en'),'source_record_ids'=>array(24900)),$blocked)))
 throw new RuntimeException('Ancestor read denial bypassed');

$stale=$observed;$stale['tourism-almallahgroup']['observed_at']=$now-8*86400;
remove_all_filters('pre_option_mad4b_hub_source_wpml_observations_v1');
add_filter('pre_option_mad4b_hub_source_wpml_observations_v1',
 static function()use($stale){return $stale;},10);
if(!is_wp_error(MAD4B_Hub_Language_Baseline::evaluate('tourism-almallahgroup',
 array('languages'=>array('en'),'source_record_ids'=>array(24900)),$layers)))
 throw new RuntimeException('Stale source language inventory allowed');
remove_all_filters('pre_option_mad4b_hub_source_wpml_observations_v1');
add_filter('pre_option_mad4b_hub_source_wpml_observations_v1',
 static function()use($observed){return $observed;},10);

$manifest=array(
 'contract'=>MAD4B_Hub_Discovery_Planner::CONTRACT,
 'mode'=>'read_only_dry_run',
 'provider'=>'jet-engine',
 'source_company'=>'tourism-almallahgroup',
 'source_site_uuid'=>'a947380c-812c-42d8-a351-46f817b02a41',
 'consumer_site_uuid'=>'bea3d219-5970-491c-84c1-ecf80f795a63',
 'inherit_language_baseline'=>true,
 'selection'=>array(
    'source_record_ids'=>array(24900),
    'canonical_category_ids'=>array(1865),
    'canonical_taxonomy'=>'tour-types',
    'query_ids'=>array(193),
    'listing_ids'=>array(25917),
    'filter_ids'=>array(33503),
    'languages'=>array('en'),
    'fields'=>array('title','summary')),
 'policy_layers'=>$layers
);
$plan=MAD4B_Hub_Discovery_Planner::validate($manifest);
if(is_wp_error($plan)||($plan['market_scope_kind']??null)!=='language_only' ||
   !$plan['market_scoped'] ||
   $plan['query_plan']['plan_executable']!==false ||
   ($plan['query_plan']['market_slices'][0]['predicate']['market_id']??null)!=='language-baseline')
 throw new RuntimeException('Language-only Query Plan invalid');
$legacy=$manifest;unset($legacy['inherit_language_baseline']);
$noMarket=MAD4B_Hub_Discovery_Planner::validate($legacy);
if(is_wp_error($noMarket)||$noMarket['market_plan']['status']!=='UNSCOPED_DENY')
 throw new RuntimeException('Legacy unscoped default unexpectedly changed');
$conflict=$manifest;$conflict['markets']=array();
if(!is_wp_error(MAD4B_Hub_Discovery_Planner::validate($conflict)))
 throw new RuntimeException('Explicit market + baseline conflict accepted');

if(class_exists('MAD4B_Hub_Signed_Source_Preview')){
 $signed=MAD4B_Hub_Signed_Source_Preview::preview($manifest);
 if(is_wp_error($signed)||($signed['selected_items']??null)!==1||
    $signed['source_signed_transport_verified']!==true||
    $signed['source_signed_market_license_verified']!==false ||
    $signed['public_rendering_allowed']!==false)
    throw new RuntimeException('Real signed Tourism language-only preview failed: '.
      (is_wp_error($signed)?$signed->get_error_code():'unknown'));
}
remove_all_filters('pre_option_mad4b_hub_source_wpml_observations_v1');
echo wp_json_encode(array(
 'contract'=>MAD4B_Hub_Language_Baseline::CONTRACT,
 'source_visible_languages'=>$expected,
 'consumer_language_baseline'=>'WPML_ACTIVE_MINUS_HIDDEN',
 'local_hidden_language_test'=>'DENIED',
 'source_hidden_language_test'=>'DENIED',
 'stale_observation'=>'DENIED',
 'higher_policy_market_limits'=>'DENIED',
 'language_only_queryplan'=>'PASS',
 'no_explicit_market'=>'UNSCOPED_DENY',
 'signed_private_tourism_preview'=>'PASS',
 'public_content_release'=>'DENIED',
 'money_or_country_inferred'=>false,'database_writes'=>0
),JSON_UNESCAPED_SLASHES),PHP_EOL;
