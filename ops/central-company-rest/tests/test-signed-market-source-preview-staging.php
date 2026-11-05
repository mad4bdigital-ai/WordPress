<?php
/**
 * Acceptance of signed read-only Tourism source preview + market scope.
 * Uses in-process fake market registry ONLY, never persists approval.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Hub_Signed_Source_Preview'))throw new RuntimeException('Missing adapter');
$state=MAD4B_Hub_Signed_Source_Preview::state();
if($state['source_market_grants_verified']!==false ||
   $state['public_jetengine_feed_enabled']!==false)throw new RuntimeException('Grant preapproved');
$source='tourism-almallahgroup';
$layers=array(
 'platform'=>array('deny'=>array()),
 'source_company'=>array('deny'=>array(),'allow_read'=>true),
 'consumer_company'=>array('deny'=>array()),
 'provider'=>array('deny'=>array()),
 'content_type'=>array('deny'=>array()),
 'market'=>array('deny'=>array()),
 'selection'=>array('deny'=>array()),
);
$market=array(
 'market_id'=>'qa-readonly-egypt-test',
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
 'commercial_status'=>'not_bookable',
);
$entry=array(
 'source_company'=>$source,'status'=>'active',
 'valid_from'=>'2026-01-01','valid_until'=>'2028-12-31',
 'languages'=>array('en','ar'),
 'nationality_countries'=>array('EG','SA'),
 'residence_countries'=>array('EG','SA'),
 'point_of_sale_countries'=>array('EG'),
 'destination_countries'=>array('EG'),
 'channels'=>array('web'),
 'currencies'=>array('EGP','USD'),
 'source_record_ids'=>array(24900),
);
$manifest=array(
 'contract'=>MAD4B_Hub_Discovery_Planner::CONTRACT,
 'mode'=>'read_only_dry_run',
 'provider'=>'jet-engine',
 'source_company'=>$source,
 'source_site_uuid'=>'a947380c-812c-42d8-a351-46f817b02a41',
 'consumer_site_uuid'=>'bea3d219-5970-491c-84c1-ecf80f795a63',
 'selection'=>array(
    'source_record_ids'=>array(24900),
    'canonical_category_ids'=>array(1865),
    'canonical_taxonomy'=>'tour-types',
    'query_ids'=>array(193),
    'listing_ids'=>array(25917),
    'filter_ids'=>array(33503),
    'languages'=>array('en'),
    'fields'=>array('title','summary')),
 'markets'=>array($market),
 'policy_layers'=>$layers
);
$denied=MAD4B_Hub_Signed_Source_Preview::preview($manifest);
if(!is_wp_error($denied)||$denied->get_error_code()!=='mad4b_market_registry_unconfigured')
 throw new RuntimeException('No-market-registry guard failed');
add_filter('pre_option_mad4b_hub_market_registry_v1',
 static function()use($entry){return array('qa-readonly-egypt-test'=>$entry);},10);
$allowed=MAD4B_Hub_Signed_Source_Preview::preview($manifest);
if(is_wp_error($allowed))throw new RuntimeException('Signed preview failed: '.$allowed->get_error_code());
if(count($allowed['items'])!==1||$allowed['items'][0]['source_id']!==24900 ||
 $allowed['items'][0]['language']!=='en'||
 !in_array(1865,$allowed['items'][0]['canonical_term_ids'],true)||
 $allowed['source_signed_transport_verified']!==true ||
 $allowed['source_signed_market_license_verified']!==false ||
 $allowed['public_rendering_allowed']!==false ||
 $allowed['source_catalog_count']!==35 ||
 $allowed['source_pages_verified']!==2 ||
 $allowed['items'][0]['source_publish_allowed']!==false ||
 $allowed['items'][0]['bookable']!==false)
 throw new RuntimeException('Signed selective preview did not match source contract');
foreach(array('price','sale_price','checkout_url','customer_email','account_key') as $prohibited)
 if(array_key_exists($prohibited,$allowed['items'][0]))
  throw new RuntimeException('Private/price field leaked: '.$prohibited);
$wrong=$manifest;$wrong['selection']['source_record_ids']=array(99999999);
$notFound=MAD4B_Hub_Signed_Source_Preview::preview($wrong);
if(!is_wp_error($notFound))throw new RuntimeException('Unknown source record admitted');
$other=$manifest;$other['source_company']='all-royal-egypt';
if(!is_wp_error(MAD4B_Hub_Signed_Source_Preview::preview($other)))
 throw new RuntimeException('Cross-company source authorized');
$public=MAD4B_Hub_Signed_Source_Preview::public_deny();
if(!is_wp_error($public)||$public->get_error_data()['status']!==403)
 throw new RuntimeException('Unauthorised public provider endpoint exposed');
remove_all_filters('pre_option_mad4b_hub_market_registry_v1');
echo wp_json_encode(array(
 'contract'=>MAD4B_Hub_Signed_Source_Preview::CONTRACT,
 'source_signed_transport'=>'VERIFIED_REAL_TOURISM_P256',
 'source_signed_market_license'=>'NOT_VERIFIED',
 'source_catalog_count'=>35,
 'selective_record_ids'=>array(24900),
 'verified_source_pages'=>2,
 'canonical_term_id'=>1865,
 'safe_preview_count'=>1,
 'without_market_registry'=>'DENIED',
 'cross_tenant_and_unknown_records'=>'DENIED',
 'public_jetengine_distribution'=>'DENIED',
 'prices_and_pii'=>'EXCLUDED',
 'database_writes'=>0
),JSON_UNESCAPED_SLASHES),PHP_EOL;
