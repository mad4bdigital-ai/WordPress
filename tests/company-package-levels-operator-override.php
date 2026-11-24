<?php
defined('ABSPATH')||exit;
$site=home_url();
$original=MAD4B_Company_Policy_Registry_V2::state();
$id='company_package_levels_from_operations';
$actual=$original['rules'][$id]??null;
if(!is_array($actual)||$actual['state']!=='active')throw new RuntimeException('baseline_missing');
$origPost=get_post_meta(57562,'_mad4b_package_accommodation_source_plan_v1',true);
$operationalBefore=MAD4B_Company_Package_Levels_V1::operationalAssignments();
$before=MAD4B_Company_Package_Levels_V1::resolve(57562);
$platinumBefore=MAD4B_Company_Package_Levels_V1::resolve(60439);

$fake=$original;
$fake['revision']=(int)$fake['revision']+100;
$fake['rules'][$id]['values']['tier_mask']='mask_15';
$fake['rules'][$id]['revision']=(int)$fake['rules'][$id]['revision']+1;
$callback=static fn($prev,$option,$default)=>$fake;
add_filter('pre_option_'.MAD4B_Company_Policy_Registry_V2::OPTION,$callback,10,3);
$expanded=MAD4B_Company_Package_Levels_V1::resolve(57562);
$platinumExpanded=MAD4B_Company_Package_Levels_V1::resolve(60439);
remove_filter('pre_option_'.MAD4B_Company_Policy_Registry_V2::OPTION,$callback,10);

$paused=$original;
$paused['revision']=(int)$paused['revision']+101;
$paused['rules'][$id]['state']='paused';
$callback2=static fn($prev,$option,$default)=>$paused;
add_filter('pre_option_'.MAD4B_Company_Policy_Registry_V2::OPTION,$callback2,10,3);
$pausedDec=MAD4B_Company_Package_Levels_V1::resolve(57562);
$pausedPlatinum=MAD4B_Company_Package_Levels_V1::resolve(60439);
$pausedGuard=MAD4B_Package_Stage_Rate_Guard_V1::review(57562,2353);
remove_filter('pre_option_'.MAD4B_Company_Policy_Registry_V2::OPTION,$callback2,10);

$restored=MAD4B_Company_Package_Levels_V1::resolve(57562);
$after=MAD4B_Company_Policy_Registry_V2::state();
$opsAfter=MAD4B_Company_Package_Levels_V1::operationalAssignments();
if($after!==$original || $opsAfter!==$operationalBefore ||
    get_post_meta(57562,'_mad4b_package_accommodation_source_plan_v1',true)!==$origPost ||
    $restored!==$before)
  throw new RuntimeException('read_only_synthetic_company_policy_test_changed_live_data');
$source=$site==='https://tours.dev.mad4b.com';
$assertions=[
 'original_source_is_expected'=>$source ? $before['source']==='company_default_inherited'
       : $before['source']==='explicit_operational_tour_terms',
 'platinum_remains_explicit'=>in_array(2355,$platinumBefore['effective_tier_ids'],true),
 'active_mask_supports_all_4'=>count(MAD4B_Company_Package_Levels_V1::idsFromMask('mask_15'))===4,
 'explicit_tour_not_changed_by_company_mask'=>$expanded['effective_tier_ids']===$before['effective_tier_ids'] || $source,
 'explicit_platinum_not_changed_by_mask'=>$platinumExpanded['effective_tier_ids']===$platinumBefore['effective_tier_ids'],
 'paused_company_policy_keeps_explicit_platinum'=>$pausedPlatinum['effective_tier_ids']===$platinumBefore['effective_tier_ids'],
 'registry_and_tour_data_unchanged'=>$after===$original&&$opsAfter===$operationalBefore
];
if($source){
 $assertions['inherited_level_changes_on_policy_revision']=
    count($expanded['effective_tier_ids'])===4
     && $expanded['source']==='company_default_inherited';
 $assertions['paused_fallback_fail_closed']=
    $pausedDec['source']==='unassigned_company_policy_missing'
    && !$pausedDec['effective_tier_ids']
    && in_array('company_package_default_levels_not_configured',$pausedGuard['blockers'],true);
}else{
 $assertions['explicit_gold_diamond_stays_without_global_rule']=
    $pausedDec['effective_tier_ids']===[2354,2356] &&
    $pausedDec['source']==='explicit_operational_tour_terms';
}
if(in_array(false,$assertions,true))
 throw new RuntimeException('synthetic_policy_reconciliation_failed:'.wp_json_encode($assertions));
echo wp_json_encode([
 'site'=>$site,'checks'=>$assertions,'pass_count'=>count($assertions),
 'live_company_policy_unchanged'=>true,'operator_tour_taxonomy_unchanged'=>true,
 'no_commercial_write'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;

