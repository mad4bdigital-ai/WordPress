<?php
/** Idempotent admin-owned Property First Standard base policy activation; dry-run by default. */
defined('ABSPATH')||exit;
if(!current_user_can('manage_options'))throw new RuntimeException('admin_required');
if(!in_array(home_url(),['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 throw new RuntimeException('unexpected_site');
if(!class_exists('MAD4B_Property_Standard_Base_V1',false))
 throw new RuntimeException('standard_base_provider_not_loaded');
$key=MAD4B_Property_Standard_Base_V1::OPTION;
$mode=MAD4B_Property_Standard_Base_V1::MODE;
$before=get_option($key,'__MISSING__');
if($before!=='__MISSING__'&&$before!==$mode)
 throw new RuntimeException('operator_mode_override_requires_explicit_review');
$apply=getenv('MAD4B_PROPERTY_STANDARD_APPLY')==='yes';
if($apply&&$before==='__MISSING__'){
 if(!add_option($key,$mode,'','no'))
  throw new RuntimeException('failed_to_persist_property_first_mode');
}
$after=get_option($key,'__MISSING__');
if($apply&&$after!==$mode)throw new RuntimeException('readback_failed');
$fields=MAD4B_Property_Standard_Base_V1::admin_fields([],(object)['slug'=>'etg-dynamic-pricing-controls']);
if(count($fields)!==1||($fields[0]['name']??'')!=='property_standard_rate_mode')
 throw new RuntimeException('dynamic_pricing_control_field_missing');
echo wp_json_encode([
 'site'=>home_url(),'option'=>$key,'previous_mode'=>$before,
 'applied'=>$apply,'effective_mode'=>MAD4B_Property_Standard_Base_V1::mode(),
 'stored_mode'=>$after,'standard_weight'=>1.0,'ui_field'=>'property_standard_rate_mode',
 'supplier_rates_modified'=>false,'native_rates_modified'=>false,'orders_created'=>0,
 'status'=>'PASS'
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

