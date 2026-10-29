<?php
/**
 * Market policy + QueryPlan acceptance. No real registry writes or rate imports.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Hub_Market_Policy') ||
 !class_exists('MAD4B_Hub_Discovery_Planner'))throw new RuntimeException('Market planner missing');
$today=gmdate('Y-m-d');
$registry=array(
 'egypt-consumer'=>array(
    'status'=>'active','source_company'=>'tourism-almallahgroup',
    'valid_from'=>'2026-01-01','valid_until'=>'2028-12-31',
    'languages'=>array('en','ar'),
    'nationality_countries'=>array('EG','SA'),
    'residence_countries'=>array('EG','SA'),
    'point_of_sale_countries'=>array('EG'),
    'destination_countries'=>array('EG'),
    'channels'=>array('web','b2b'),
    'currencies'=>array('EGP','USD'),
    'source_record_ids'=>array(24900,24901)
 ),
 'gcc-consumer'=>array(
    'status'=>'active','source_company'=>'tourism-almallahgroup',
    'valid_from'=>'2026-01-01','valid_until'=>'2028-12-31',
    'languages'=>array('en','ar'),
    'nationality_countries'=>array('SA','AE'),
    'residence_countries'=>array('SA','AE'),
    'point_of_sale_countries'=>array('SA','AE'),
    'destination_countries'=>array('EG'),
    'channels'=>array('b2b'),
    'currencies'=>array('SAR','AED'),
    'source_record_ids'=>array(24900)
 )
);
add_filter('pre_option_mad4b_hub_market_registry_v1',
 static function()use($registry){return $registry;},10);
$layers=array(
 'platform'=>array('deny'=>array('active_pricing'),'allow_read'=>false,
   'market_limits'=>array('destination_countries'=>array('EG'))),
 'source_company'=>array('deny'=>array(),'allow_read'=>true,
   'market_limits'=>array('market_ids'=>array('egypt-consumer','gcc-consumer'))),
 'consumer_company'=>array('deny'=>array(),
   'market_limits'=>array('point_of_sale_countries'=>array('EG','SA'))),
 'provider'=>array('deny'=>array()),
 'content_type'=>array('deny'=>array()),
 'market'=>array('deny'=>array(),
   'market_limits'=>array('market_ids'=>array('egypt-consumer'),
    'channels'=>array('web'),'currencies'=>array('EGP'),
    'nationality_countries'=>array('EG'))),
 'selection'=>array('deny'=>array())
);
$market=array(
 'market_id'=>'egypt-consumer',
 'valid_from'=>'2026-10-01','valid_until'=>'2027-10-31',
 'languages'=>array('en'),
 'nationality_countries'=>array('EG'),
 'residence_countries'=>array('EG'),
 'point_of_sale_countries'=>array('EG'),
 'destination_countries'=>array('EG'),
 'channels'=>array('web'),
 'currencies'=>array('EGP'),
 'source_record_ids'=>array(24900),
 'pricing_authority'=>'reference_only',
 'import_strategy'=>'remote_reference',
 'commercial_status'=>'not_bookable'
);
$plan=array(
 'contract'=>MAD4B_Hub_Discovery_Planner::CONTRACT,
 'mode'=>'read_only_dry_run',
 'provider'=>'jet-engine',
 'source_company'=>'tourism-almallahgroup',
 'source_site_uuid'=>'a947380c-812c-42d8-a351-46f817b02a41',
 'consumer_site_uuid'=>'bea3d219-5970-491c-84c1-ecf80f795a63',
 'selection'=>array(
   'source_record_ids'=>array(24900),
   'canonical_category_ids'=>array(1863),
   'canonical_taxonomy'=>'tour-types',
   'query_ids'=>array(193,254,255),
   'listing_ids'=>array(25917),
   'filter_ids'=>array(33503),
   'languages'=>array('en','ar'),
   'fields'=>array('title','summary'),
   'option_fields'=>array()
 ),
 'markets'=>array($market),
 'policy_layers'=>$layers
);
$ok=MAD4B_Hub_Discovery_Planner::validate($plan);
if(is_wp_error($ok))throw new RuntimeException('Allowed market draft rejected: '.$ok->get_error_code());
if(($ok['market_plan']['status']??'')!=='MARKET_SCOPED_DRY_RUN' ||
   ($ok['market_plan']['scopes'][0]['dimensions']['currencies']??array())!==array('EGP') ||
   $ok['runtime_approved']!==false ||
   $ok['market_plan']['scopes'][0]['source_grant_verified']!==false ||
   !in_array('verify_source_company_market_grant',$ok['pending_proofs'],true))
    throw new RuntimeException('Market plan leaked grant or lost narrowed dimensions');
$without=$plan;
unset($without['markets']);
$uns=MAD4B_Hub_Discovery_Planner::validate($without);
if(is_wp_error($uns) ||
   ($uns['market_plan']['status']??'')!=='UNSCOPED_DENY' ||
   $uns['market_scoped']!==false)
    throw new RuntimeException('Missing markets incorrectly treated as global');
$invalid=array(
 'other_market_not_in_source_registry'=>array('market_id'=>'unregistered-market'),
 'other_source_company'=>array('market_id'=>'gcc-consumer'),
 'currency_exceeds_market'=>array('currencies'=>array('USD')),
 'nationality_exceeds_inherited_policy'=>array('nationality_countries'=>array('SA')),
 'residence_exceeds_registry'=>array('residence_countries'=>array('FR')),
 'point_of_sale_exceeds_registry'=>array('point_of_sale_countries'=>array('SA')),
 'channel_exceeds_policy'=>array('channels'=>array('b2b')),
 'destination_outside_policy'=>array('destination_countries'=>array('FR')),
 'locale_not_in_manifest'=>array('languages'=>array('fr')),
 'record_not_in_manifest'=>array('source_record_ids'=>array(24901)),
 'implicit_records_forbidden'=>array('source_record_ids'=>array()),
 'invalid_date'=>array('valid_until'=>'2027-02-30'),
 'date_exceeds_registry'=>array('valid_until'=>'2029-01-01'),
 'commercial_sale_forbidden'=>array('pricing_authority'=>'active_sale'),
 'import_by_database_copy_forbidden'=>array('import_strategy'=>'bulk_copy'),
 'automatic_booking_forbidden'=>array('commercial_status'=>'bookable'),
 'wrong_country_syntax'=>array('nationality_countries'=>array('eg')),
 'wildcard_forbidden'=>array('point_of_sale_countries'=>array('*'))
);
foreach($invalid as $name=>$mod){
 $candidate=$plan;
 $candidate['markets']=array(array_replace($market,$mod));
 $x=MAD4B_Hub_Discovery_Planner::validate($candidate);
 if(!is_wp_error($x))throw new RuntimeException('Unsafe market policy accepted: '.$name);
}
$dupe=$plan;$dupe['markets']=array($market,$market);
if(!is_wp_error(MAD4B_Hub_Discovery_Planner::validate($dupe)))
 throw new RuntimeException('Duplicate market accepted');
$outside=$plan;$outside['policy_layers']['market']['market_limits']['market_ids']=array('gcc-consumer');
if(!is_wp_error(MAD4B_Hub_Discovery_Planner::validate($outside)))
 throw new RuntimeException('Market inheritance widening accepted');
$claim=$plan;
$claim['policy_layers']['source_company']['allow_read']=true;
$claimed=MAD4B_Hub_Discovery_Planner::validate($claim);
if(is_wp_error($claimed)||$claimed['policy']['source_read_proven']!==false)
 throw new RuntimeException('Manifest pretended to sign source authority');
$unrelated=$plan;
$unrelated['source_company']='allroyalegypt';
if(!is_wp_error(MAD4B_Hub_Discovery_Planner::validate($unrelated)))
 throw new RuntimeException('Cross-company registry access allowed');
remove_all_filters('pre_option_mad4b_hub_market_registry_v1');
echo wp_json_encode(array(
 'contract'=>MAD4B_Hub_Market_Policy::CONTRACT,
 'market_scope'=>$ok['market_plan']['scopes'][0]['market_id'],
 'market_status'=>$ok['market_plan']['status'],
 'valid_market_selection'=>'PASS_DRY_RUN',
 'unscoped_market'=>'DENY',
 'negative_market_cases'=>count($invalid)+3,
 'source_signed_grant_required'=>true,
 'currency_commercial_authority'=>'reference_only',
 'specific_source_records'=>array(24900),
 'rate_activation'=>false,
 'database_mutations'=>0
),JSON_UNESCAPED_SLASHES),PHP_EOL;
