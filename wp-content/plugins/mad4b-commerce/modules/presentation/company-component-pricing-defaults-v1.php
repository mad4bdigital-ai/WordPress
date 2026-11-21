<?php
/**
 * Company-owned component pricing defaults, read-only quoting scope.
 * Unit prices and original native calculator policies are never cloned.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Component_Pricing_Defaults_V1 {
 const VERSION='1.1.0';
 const OPTION='mad4b_company_component_pricing_defaults_v1';
 const RECEIPT='mad4b_company_component_pricing_receipt_v1';
 public static function defaults(): array {
  return [
   'accommodation'=>['enabled'=>true,'strategy'=>'native_property_rate_price_only',
      'rate_post_type'=>'property-rates','unit_policy'=>'native_property_unit_occupancy',
      'price_source'=>'native_provider','checkout_enabled'=>false],
   'transport'=>['enabled'=>true,'strategy'=>'native_transport_rate_cpt',
      'rate_post_type'=>'transport-rates','unit_policy'=>'native_transport_pricing_type',
      'price_source'=>'native_provider','checkout_enabled'=>false],
   'extras'=>['enabled'=>true,'strategy'=>'native_extra_unit',
      'rate_post_type'=>'source_component','unit_policy'=>'native_extra_price_basis',
      'price_source'=>'native_provider','checkout_enabled'=>false]
  ];
 }
 private static function merged($raw) {
  if(!is_array($raw))return new WP_Error('component_policy_invalid_preserved');
  foreach(self::defaults() as $key=>$row) {
   if(!array_key_exists($key,$raw)){$raw[$key]=$row;continue;}
   if(!is_array($raw[$key]))return new WP_Error('component_policy_row_invalid_preserved',$key);
   $raw[$key]=array_replace($row,$raw[$key]);
  }
  return $raw;
 }
 public static function initialize(): array {
  $original=get_option(self::OPTION,null);
  $rows=self::merged($original===null?[]:$original);
  if(is_wp_error($rows))return ['state'=>'INVALID_CONFIG_PRESERVED','error'=>$rows->get_error_code()];
  $added=[];$filled=[];
  foreach(self::defaults() as $key=>$fields){
   if(!is_array($original)||!array_key_exists($key,$original))$added[]=$key;
   elseif($rows[$key]!==$original[$key])$filled[]=$key;
  }
  if($original===null){if(!add_option(self::OPTION,$rows,'',false))return ['state'=>'INSERT_CONFLICT'];}
  elseif($rows!==$original && !update_option(self::OPTION,$rows,false))return ['state'=>'SAVE_FAILED'];
  $actual=get_option(self::OPTION,null);
  if($actual!==$rows)return ['state'=>'READBACK_MISMATCH'];
  $receipt=['contract'=>'mad4b.company-component-pricing-defaults.v1',
    'version'=>self::VERSION,'state'=>'CONFIGURED',
    'component_count'=>count(self::defaults()),
    'policy_sha256'=>hash('sha256',wp_json_encode($actual)),
    'native_rate_prices_changed'=>false,'checkout_enabled_by_reconcile'=>false];
  if(get_option(self::RECEIPT,null)!==$receipt)update_option(self::RECEIPT,$receipt,false);
  return $receipt+['added_components'=>$added,'filled_components'=>$filled];
 }
 public static function status(): array {
  $raw=get_option(self::OPTION,null);$effective=self::merged($raw);
  $ready=is_array($effective) && get_option(self::RECEIPT,null) !==null;
  return ['contract'=>'mad4b.company-component-pricing-readback.v1',
   'version'=>self::VERSION,'state'=>$ready?'CONFIGURED':'PENDING',
   'configured'=>$ready,'policies'=>is_array($effective)?$effective:[],
   'receipt'=>get_option(self::RECEIPT,null),'native_quotes_verified'=>false,
   'prices_changed'=>false,'checkout_enabled_by_reconcile'=>false];
 }
}
add_action('init',[MAD4B_Company_Component_Pricing_Defaults_V1::class,'initialize'],45);
add_action('mad4b_company_content_reconciled',static function($context=[]){
 if(!is_array($context)||!empty($context['read_only']))return;
 MAD4B_Company_Component_Pricing_Defaults_V1::initialize();
},25,1);
add_filter('mad4b_company_reconcile_component_readiness',static function($providers){
 if(!is_array($providers))$providers=[];
 $providers['pricing_component_defaults']=MAD4B_Company_Component_Pricing_Defaults_V1::status();
 return $providers;
},30);
