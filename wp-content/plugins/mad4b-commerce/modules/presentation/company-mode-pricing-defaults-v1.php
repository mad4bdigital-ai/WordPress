<?php
/** Native mode defaults initialization. No prices, FX, payments or checkout writes. */
defined('ABSPATH')||exit;
final class MAD4B_Company_Mode_Pricing_Defaults_V1 {
 const VERSION='1.0.0';
 const RECEIPT='mad4b_company_mode_defaults_receipt_v1';
 public static function initialize(){
  if(!function_exists('shared_product_mode_policy_defaults_v1')||!function_exists('jet_engine')||!isset(jet_engine()->options_pages->data))return ['state'=>'PROVIDER_PENDING'];
  $defaults=shared_product_mode_policy_defaults_v1();$owner=null;
  foreach(jet_engine()->options_pages->data->get_items() as $page){$fields=maybe_unserialize($page['meta_fields']??[]);if(!is_array($fields)||!in_array('shared_mode_policy_rows',array_column($fields,'name'),true)||!in_array('shared_financial_rules_json',array_column($fields,'name'),true))continue;$args=maybe_unserialize($page['args']??[]);if(($args['storage_type']??'')!=='separate'||empty($args['option_prefix']))continue;$owner=$page['slug'].'_shared_mode_policy_rows';break;}
  if(!$owner)return ['state'=>'NATIVE_SCHEMA_PENDING','effective_defaults_version'=>self::VERSION];
  $raw=get_option($owner,null);$rows=$raw;if(is_string($rows))$rows=maybe_unserialize($rows);
  if($rows===null||$rows==='')$rows=[];
  if(!is_array($rows))return ['state'=>'INVALID_CONFIG_PRESERVED'];
  $seen=[];foreach($rows as $row){if(!is_array($row)||empty($row['mode']))return ['state'=>'INVALID_CONFIG_PRESERVED'];$mode=(string)$row['mode'];if(isset($seen[$mode]))return ['state'=>'DUPLICATE_CONFIG_PRESERVED'];$seen[$mode]=true;}
  $added=[];foreach($defaults as $mode=>$row){if(isset($seen[$mode]))continue;$row=['mode'=>$mode]+$row;$row['enabled']=$row['enabled']?'1':'0';$row['tax_percent_override']='';$row['service_fee_percent_override']='';$rows[]=$row;$added[]=$mode;}
  if($added)update_option($owner,$rows,false);
  $after=get_option($owner,[]);if(is_string($after))$after=maybe_unserialize($after);
  if($after!==$rows)return ['state'=>'READBACK_FAILED'];
  $receipt=['contract'=>'mad4b.company-mode-defaults.v1','version'=>self::VERSION,'state'=>'READY','owner_option'=>$owner,'mode_count'=>count($rows),'explicit_rows_preserved'=>true,'prices_changed'=>false,'checkout_enabled'=>false];
  $previous=get_option(self::RECEIPT,[]);if($previous!==$receipt)update_option(self::RECEIPT,$receipt,false);
  return $receipt+['added_modes'=>$added];
 }
 public static function status(){
  $policies=function_exists('shared_product_mode_policies')?shared_product_mode_policies():null;
  return ['contract'=>'mad4b.company-mode-defaults-readiness.v1','version'=>self::VERSION,'effective_mode_count'=>is_array($policies)?count($policies):0,'provider_error'=>is_wp_error($policies)?$policies->get_error_code():null,'initialization'=>get_option(self::RECEIPT,null),'prices_changed'=>false];
 }
}
add_action('init',[MAD4B_Company_Mode_Pricing_Defaults_V1::class,'initialize'],40);
add_action('mad4b_company_content_reconciled',static function(){MAD4B_Company_Mode_Pricing_Defaults_V1::initialize();},20);
add_filter('mad4b_company_reconcile_component_readiness',static function($providers){if(!is_array($providers))$providers=[];$providers['pricing_mode_defaults']=MAD4B_Company_Mode_Pricing_Defaults_V1::status();return $providers;},25);
if(defined('WP_CLI')&&WP_CLI){WP_CLI::add_command('mad4b pricing-mode-defaults',static function($args){$value=($args[0]??'status')==='initialize'?MAD4B_Company_Mode_Pricing_Defaults_V1::initialize():MAD4B_Company_Mode_Pricing_Defaults_V1::status();WP_CLI::line(wp_json_encode($value));});}

require_once __DIR__.'/company-component-pricing-defaults-v1.php';
