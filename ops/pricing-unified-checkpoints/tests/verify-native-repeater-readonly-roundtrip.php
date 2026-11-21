<?php
/**
 * Pure serialization and native JetEngine field-contract verification.
 *
 * No database write, fake login session, vendor Save or temporary pricing
 * option. Exercises original nested field sanitization + WP serialize/unserialize
 * from the actual installed JetEngine Options Page factory.
 */
if(!defined('ABSPATH') || !defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$factory=null;
foreach((array)jet_engine()->options_pages->registered_pages as $candidate)
 if($candidate instanceof Jet_Engine_Options_Page_Factory &&
    $candidate->slug==='etg-dynamic-pricing-controls'){
  $factory=$candidate;break;
 }
if(!$factory)WP_CLI::error('Original JetEngine Pricing factory unavailable');
$before=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if(is_wp_error($before))WP_CLI::error($before->get_error_code());
$all=$factory->get_prepared_fields();
$scenarios=[
 'shared_mode_policy_rows'=>[
  'item-0'=>['mode'=>'tour','strategy'=>'Rate Occupancy','enabled'=>'true',
    'minimum_travelers'=>'2','maximum_travelers'=>'8'],
  'item-1'=>['mode'=>'cruise_tour','strategy'=>'Rate Occupancy','enabled'=>'false',
    'minimum_travelers'=>'1','maximum_travelers'=>'30']
 ],
 'shared_package_pricing_rows'=>[
  'item-0'=>['package_term_id'=>'101','scope'=>'all','tour_id'=>'0',
    'rate_id'=>'0','percent'=>'10','enabled'=>'true']
 ],
 'shared_gateway_availability_rows'=>[
  'item-0'=>['method'=>'stripe','enabled'=>'true','country_basis'=>'billing',
   'allowed_countries'=>'EG,US','excluded_countries'=>'','allowed_currencies'=>'EGP,USD']
 ],
 'mad4b_provider_source_policy_rows'=>[
  'item-0'=>['rule_id'=>'cp-qa-fixture','provider_id'=>'self',
    'scope'=>'company','priority'=>'10','source'=>'self',
    'fallback'=>'none','term_ids'=>'','tour_ids'=>'','rate_ids'=>'','preview_enabled'=>'false']
 ]
];
$result=[];
foreach($scenarios as $key=>$rows){
 $field=$all[$key]??null;
 if(!is_array($field)||($field['type']??null)!=='repeater'||
   !is_array($field['fields']??null))
   WP_CLI::error('Original JetEngine repeater missing '.$key);
 foreach($rows as $row)foreach($row as $name=>$unused)
   if(!isset($field['fields'][$name]))
     WP_CLI::error('Missing native child field '.$key.'::'.$name);
 $sanitized=$factory->maybe_apply_sanitize_callback($rows,$field);
 $serialized=maybe_serialize($sanitized);
 $roundtrip=maybe_unserialize($serialized);
 if(!is_array($roundtrip)||count($roundtrip)!==count($rows))
   WP_CLI::error('Native repeater round-trip mismatched '.$key);
 foreach($rows as $rowId=>$row)
   foreach($row as $fieldId=>$value)
     if((string)($roundtrip[$rowId][$fieldId]??'')!==$value)
       WP_CLI::error('Native repeater child changed '.$key.'::'.$fieldId);
 $result[$key]=['rows'=>count($rows),'subfields'=>count($field['fields']),
   'native_sanitizer_pass'=>true,'wp_roundtrip_pass'=>true];
}
$after=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if(is_wp_error($after)||!hash_equals($before,$after))
 WP_CLI::error('Read-only test changed actual saved pricing source revision');
echo 'JETENGINE_NATIVE_REPEATER_READONLY_ROUNDTRIP_PASS '.wp_json_encode([
 'site'=>home_url(),'scenarios'=>$result,
 'actual_saved_options_changed'=>false,
 'vendor_writer_executed'=>false
],JSON_UNESCAPED_SLASHES).PHP_EOL;
