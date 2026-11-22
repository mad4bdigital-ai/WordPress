<?php
/**
 * Company local fallback presets derived from EXISTING published Package
 * Tour assignments, never from another tenant's taxonomies or stage templates.
 * This is a pricing-strategy eligibility policy, not checkout authorization.
 */
defined('ABSPATH') || exit;
$phase=(string)(getenv('MAD4B_PACKAGE_DEFAULT_PHASE')?:'preflight');
if(!in_array($phase,['preflight','apply','verify'],true))throw new RuntimeException('invalid_phase');
if(!current_user_can('manage_options')||!current_user_can('commerce_manage_pricing'))
  throw new RuntimeException('company_strategy_admin_required');
$expected=[
 'https://tours.dev.mad4b.com'=>[
    'mask'=>'mask_03','assigned'=>7,'total'=>8,
    'missing'=>[57562],
    'digest'=>'8f6544cf368754342d2fd235b1cf3b922c3c195abeb84b0c05d504e4708a859d'
 ],
 'https://wayfatours.com'=>[
    'mask'=>'mask_07','assigned'=>8,'total'=>8,
    'missing'=>[],
    'digest'=>'605c8e5253eed3616736f66ae284082b08e0908a0c2d22d94931159a1bf89416'
 ]
];
$site=home_url();
if(!isset($expected[$site]))throw new RuntimeException('not_enrolled_company_site');
$ex=$expected[$site];
$a=MAD4B_Company_Package_Levels_V1::operationalAssignments();
if($a['majority_mask']!==$ex['mask'] ||
    $a['published_package_tours']!==$ex['total']||
    $a['explicitly_assigned_package_tours']!==$ex['assigned']||
    $a['unassigned_tour_ids']!==$ex['missing']||
    $a['source_assignments_sha256']!==$ex['digest'])
 throw new RuntimeException('operator_assignment_snapshot_changed_review_required');
$policy='pricing.package.levels';
$id='company_package_levels_from_operations';
$state=MAD4B_Company_Policy_Registry_V2::state();
$matches=[];
foreach($state['rules'] as $row)
 if(is_array($row) && ($row['policy_id']??'')===$policy)
 $matches[]=$row;
if($phase==='preflight'||$phase==='apply'){
 if($matches)throw new RuntimeException('existing_package_level_rules_must_not_be_overridden');
 if(isset($state['rules'][$id]))throw new RuntimeException('unexpected_rule_id_collision');
}
$revisionBefore=(int)$state['revision'];
if($phase==='apply'){
 $saved=MAD4B_Company_Policy_Registry_V2::save([
   'id'=>$id,'provider_id'=>MAD4B_Company_Package_Levels_V1::PROVIDER,
   'scope'=>'company','scope_id'=>0,'state'=>'active',
   'values'=>['tier_mask'=>$ex['mask']],
   'priority'=>0,'valid_from'=>'','valid_to'=>'',
 ]);
 if(is_wp_error($saved))
   throw new RuntimeException('registry_policy_save_failed:'.$saved->get_error_code());
 $state=MAD4B_Company_Policy_Registry_V2::state();
}
if($phase==='verify'||$phase==='apply'){
 $rule=$state['rules'][$id]??null;
 if(!is_array($rule)||($rule['policy_id']??'')!==$policy||
    ($rule['scope']??'')!=='company'||($rule['state']??'')!=='active'||
    ($rule['values']['tier_mask']??'')!==$ex['mask'])
    throw new RuntimeException('policy_readback_wrong_or_missing');
}
$result=[];
foreach([57562,60437,60439,60441,60443,60623,60627,60629] as $tour){
 $x=MAD4B_Company_Package_Levels_V1::resolve($tour);
 $terms=wp_get_object_terms($tour,'package_levels',['fields'=>'ids']);
 $saved=is_wp_error($terms)?[]:array_map('intval',$terms);
 sort($saved);
 if($x['explicit_tier_ids']!==$saved)
   throw new RuntimeException('saved_operator_tour_assignment_disagrees:'.$tour);
 if($phase!=='preflight'){
  $expect=$saved?:MAD4B_Company_Package_Levels_V1::idsFromMask($ex['mask']);
  sort($expect);
  if($x['effective_tier_ids']!==$expect)
    throw new RuntimeException('fallback_overrode_or_dropped_explicit_levels:'.$tour);
  if($saved && $x['source']!=='explicit_operational_tour_terms')
    throw new RuntimeException('operator_assignment_not_precedent:'.$tour);
  if(!$saved && ($x['source']!=='company_default_inherited'||$x['company_rule_id']!==$id))
    throw new RuntimeException('company_fallback_unbound:'.$tour);
 }
 $result[]=[
   'tour'=>$tour,'saved_explicit_tiers'=>$saved,
   'effective_tiers'=>$x['effective_tier_ids'],
   'source'=>$x['source'],'company_rule_id'=>$x['company_rule_id'],
   'checkout_authorized'=>$x['checkout_authorized']
 ];
}
$after=MAD4B_Company_Policy_Registry_V2::state();
echo wp_json_encode([
 'phase'=>$phase,'site'=>$site,'original_operational_majority'=>$a,
 'policy_id'=>$policy,'policy_rule_id'=>$id,
 'company_default_mask'=>$ex['mask'],
 'registry_revision_before'=>$revisionBefore,
 'registry_revision_after'=>(int)$after['revision'],
 'other_existing_registry_rules_preserved'=>true,
 'explicit_taxonomy_write_count'=>0,
 'commercial_rate_write_count'=>0,
 'checkout_authorized'=>false,
 'tours'=>$result
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

