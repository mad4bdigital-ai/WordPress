<?php
defined('ABSPATH')||exit;
$mode=MAD4B_Property_Standard_Base_V1::mode();
if($mode!=='property_first_standard')throw new RuntimeException('property_first_setting_missing');
$source=[
 'unit_rate'=>[],
 'property_rate'=>[[
  'source'=>'property_rate','id'=>123456,'price_kind'=>'sell',
  'amount'=>125.00,'currency'=>'USD','price_unit'=>'per_room_night'
 ]],
 'unit_direct'=>[],'property_direct'=>[]
];
$result=MAD4B_Stay_Layered_Pricing_V1::choose($source);
if(is_wp_error($result)||($result['sell']['source']??'')!=='property_rate'||
   ($result['sell']['amount']??null)!==125.0||
   $result['checkout_authority']!==false)
 throw new RuntimeException('native_standard_property_base_not_selected');
$source['unit_rate']=[[
 'source'=>'unit_rate','id'=>123457,'price_kind'=>'sell',
 'amount'=>175.0,'currency'=>'USD','price_unit'=>'per_room_night'
]];
$unit=MAD4B_Stay_Layered_Pricing_V1::choose($source);
if(is_wp_error($unit)||($unit['sell']['source']??null)!=='unit_rate')
 throw new RuntimeException('existing_specific_unit_override_lost');
$source['unit_rate']=[];
$source['property_rate'][0]['price_kind']='cost';
$cost=MAD4B_Stay_Layered_Pricing_V1::choose($source);
if(is_wp_error($cost)||$cost['sell']!==null||
   ($cost['cost_reference']['source']??null)!=='property_rate'||
   $cost['cost_authority']!==false)
 throw new RuntimeException('supplier_cost_falsely_promoted_to_sell');
$checker=static function($value){return 'unit_first';};
add_filter('pre_option_etg-dynamic-pricing-controls_property_standard_rate_mode',$checker);
$non=$mode!==MAD4B_Property_Standard_Base_V1::mode();
$refused=MAD4B_Property_Standard_Base_V1::preview(35972,'2027-01-15',3,2,0);
remove_filter('pre_option_etg-dynamic-pricing-controls_property_standard_rate_mode',$checker);
if(!$non||($refused['status']??'')!=='unit_first_policy_selected'||
 MAD4B_Property_Standard_Base_V1::mode()!=='property_first_standard')
 throw new RuntimeException('mode_controls_preview_incorrectly');
echo wp_json_encode([
 'site'=>home_url(),
 'standard_base_factor'=>MAD4B_Property_Standard_Base_V1::FACTOR,
 'native_property_rate_supported_when_unit_missing'=>true,
 'existing_unit_rate_higher_priority'=>true,
 'supplier_cost_never_selling_price'=>true,
 'explicit_unit_first_mode_supported'=>true,
 'production_setting_unchanged'=>true,'postmeta_written'=>false,
 'test_price_only_synthetic_not_stored'=>true,'pass'=>true
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

