<?php
$boot=dirname(__DIR__,4).'/wp-load.php';
if(!defined('ABSPATH')) { if(!is_file($boot))exit(2); require $boot; }
$tests=[];
$tests['canonical_policy_loaded']=function_exists('shared_product_price_source_policy');
$tests['canonical_resolver_loaded']=function_exists('shared_product_resolve_price_sources');
$tests['woo_snapshot_unchanged']=function_exists('shared_product_quote_snapshot');
$tests['woo_config_verification_unchanged']=function_exists('shared_product_booking_configuration_matches');
$tests['registry_adapter_loaded']=class_exists('MAD4B_Pricing_Source_Arbitration');
if($tests['canonical_policy_loaded']&&$tests['canonical_resolver_loaded']&&$tests['registry_adapter_loaded']){
 foreach(['tour','activity','cruise_tour'] as $mode){
  $raw=shared_product_price_source_policy($mode);
  $read=MAD4B_Pricing_Source_Arbitration::inspect($mode);
  $tests['policy_parity_'.$mode]=!is_wp_error($raw)&&!is_wp_error($read)&&$raw===$read['policy'];
  $candidates=['direct'=>['amount'=>100,'currency'=>'USD'],'rate'=>['amount'=>120,'currency'=>'USD']];
  $direct=shared_product_resolve_price_sources($candidates,$mode);
  $delegated=MAD4B_Pricing_Source_Arbitration::choose($candidates,$mode);
  $tests['arbitration_parity_'.$mode]=!is_wp_error($direct)&&!is_wp_error($delegated)&&$direct===$delegated['result']&&!$delegated['checkout_authorized'];
 }
}
foreach($tests as $name=>$value)echo $name.'='.($value?'PASS':'FAIL')."\n";
echo 'TOTAL='.count(array_filter($tests)).'/'.count($tests)."\n";
exit(in_array(false,$tests,true)?1:0);
