<?php
/**
 * Real Staging source reads + policy-only quote contract. No quote is sold.
 * Zero database mutations, no checkout/form/public REST side effects.
 */
defined('ABSPATH')||exit;
// Private CCT provider rate reads require actual WordPress administrator
// authority; WP-CLI root is not an authenticated WordPress user.
$admin=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
if(!$admin)throw new RuntimeException('No Staging administrator for test');
wp_set_current_user((int)$admin[0]);
if(!class_exists('MAD4B_Hub_Price_Sources_V1') ||
   !class_exists('MAD4B_Hub_Company_Pricing_V1'))
    throw new RuntimeException('pricing modules unavailable');
$inventory=MAD4B_Hub_Price_Sources_V1::discover();
$descriptors=array_column($inventory['supported_type_descriptors'],null,'post_type');
foreach(array('tours-and-activities','properties','transportations','product') as $name){
 if(!isset($descriptors[$name])||!$descriptors[$name]['registered'])
  throw new RuntimeException('Missing provider descriptor: '.$name);
}
$historic=MAD4B_Hub_Price_Sources_V1::signedHistorical('tourism-almallahgroup',24900);
if(is_wp_error($historic))throw new RuntimeException('Signed stored historical source unavailable: '.$historic->get_error_code());
if($historic['source_mode']!=='signed_historical_nile_snapshot' ||
   $historic['all_rates_current_certified']!==false ||
   $historic['direct_prices_remote_contract_missing']!==true ||
   !$historic['rate_count'] ||
   !$historic['rates'][0]['bookable']===false)
  throw new RuntimeException('Historical source contract incorrect');
$first=$historic['rates'][0];
$rateId=$first['source_rate_id'];
$priceField=null;$oldAmount=null;$currency=null;
foreach($first['price_fields'] as $key=>$value){
 if(strpos($key,'egyptians_')!==0 && !empty($first['currency'])){
    $priceField=$key;$oldAmount=$value;$currency=$first['currency'];break;
 }
}
if(!$priceField)throw new RuntimeException('No suitable historic test rate amount');
$policy=array('source_company'=>'tourism-almallahgroup',
 'consumer_company'=>'all-royal-egypt',
 'product_type'=>'tours-and-activities',
 'source_product_id'=>24900,
 'enabled'=>true,
 'starting_rule'=>null,
 'rate_rules'=>array(array(
   'rate_id'=>$rateId,'field'=>$priceField,
   'mode'=>'percent_adjust','percent_basis_points'=>1250,
   'conditions'=>array('language'=>'en'))),
 'default_rate_rule'=>null);
$validated=MAD4B_Hub_Company_Pricing_V1::validate_policy($policy);
if(is_wp_error($validated))throw new RuntimeException('Company policy invalid: '.$validated->get_error_code());
$context=array('language'=>'en');
$quote=MAD4B_Hub_Company_Pricing_V1::quote($historic,'all-royal-egypt',$context,$policy);
if(is_wp_error($quote))throw new RuntimeException('Company quote invalid: '.$quote->get_error_code());
$transformed=null;$others=0;
foreach($quote['rate_entries'] as $item){
 if($item['source_rate_id']===$rateId){
  $transformed=$item['price_fields'][$priceField]??null;
 }else $others++;
}
if(!$transformed || $transformed['mode']!=='percent_adjust'||
   $transformed['currency']!==$currency||
   $transformed['source_amount']!==$oldAmount||
   $quote['checkout_ready']!==false ||
   $quote['source_rate_records_mutated']!==false)
 throw new RuntimeException('Company-specific rate adjustment did not preserve source');
$without=MAD4B_Hub_Company_Pricing_V1::quote($historic,'all-royal-egypt',
 array('language'=>'ar'),$policy);
if(is_wp_error($without) ||
   $without['rate_entries'][0]['price_fields'][$priceField]['mode']!=='inherit')
 throw new RuntimeException('Language-specific rule incorrectly applied to AR');
$generator=$policy;
$generator['rate_rules']=array();
$generator['default_rate_rule']=array('mode'=>'rate_generator',
 'percent_basis_points'=>2000,'conditions'=>array('language'=>'en'));
$gen=MAD4B_Hub_Company_Pricing_V1::quote($historic,'all-royal-egypt',$context,$generator);
if(is_wp_error($gen)||!$gen['rate_entries'][0]['generated_rate_preview']||
   !$gen['rate_generator_only_generates_preview']||
   $gen['checkout_ready']!==false)
 throw new RuntimeException('Rate Generator preview contract violated');
$badContext=MAD4B_Hub_Company_Pricing_V1::quote($historic,'all-royal-egypt',
 array('language'=>'en','date'=>'2026-02-30'),$policy);
if(!is_wp_error($badContext))throw new RuntimeException('Invalid date market context accepted');
$badContext=MAD4B_Hub_Company_Pricing_V1::quote($historic,'all-royal-egypt',
 array('nationality_country'=>'*'),$policy);
if(!is_wp_error($badContext))throw new RuntimeException('Wildcard nationality context accepted');
$wrong=$policy;$wrong['source_product_id']=24901;
if(!is_wp_error(MAD4B_Hub_Company_Pricing_V1::quote($historic,
 'all-royal-egypt',$context,$wrong)))
 throw new RuntimeException('Cross-product repricing was accepted');
$currencyMismatch=$policy;
$currencyMismatch['rate_rules'][0]['currency']=$currency==='USD'?'EGP':'USD';
$mismatch=MAD4B_Hub_Company_Pricing_V1::quote($historic,
 'all-royal-egypt',$context,$currencyMismatch);
if(!is_wp_error($mismatch) ||
   $mismatch->get_error_code()!=='mad4b_hub_company_price_currency_mismatch_requires_verified_fx')
 throw new RuntimeException('Unapproved currency conversion accepted');
$invalid=$policy;
$invalid['rate_rules'][0]['percent_basis_points']=30001;
if(!is_wp_error(MAD4B_Hub_Company_Pricing_V1::validate_policy($invalid)))
 throw new RuntimeException('Unbounded markup accepted');
$invalid=$policy;
$invalid['starting_rule']=array('mode'=>'fixed','amount'=>'0.01',
 'currency'=>'EUR','field'=>'start_price');
$valid=MAD4B_Hub_Company_Pricing_V1::validate_policy($invalid);
if(is_wp_error($valid))throw new RuntimeException('Valid fixed direct rule was rejected');
$invalid=$policy;
$invalid['rate_rules'][0]['conditions']=array('nationality_country'=>'*');
if(!is_wp_error(MAD4B_Hub_Company_Pricing_V1::validate_policy($invalid)))
 throw new RuntimeException('Invalid nationality rule accepted');
$localTour=MAD4B_Hub_Price_Sources_V1::local(64948,'tours-and-activities');
if(is_wp_error($localTour))throw new RuntimeException('Local tour source read failed: '.$localTour->get_error_code());
$localTransport=MAD4B_Hub_Price_Sources_V1::local(39707,'transportations');
if(is_wp_error($localTransport))throw new RuntimeException('Local transport source read failed: '.$localTransport->get_error_code());
$cct=array_values(array_filter($localTransport['rates'],static function($rate){
    return ($rate['source_rate_post_type']??'')==='jet_cct_transport_rates';
}));
if(count($cct)!==2 ||
   $cct[0]['source_rate_key']!=='jet_cct_transport_rates:1' ||
   $cct[1]['source_rate_key']!=='jet_cct_transport_rates:2' ||
   $cct[0]['bookable']!==false)
 throw new RuntimeException('Transport CCT native rate mapping missing');
$transportPolicy=array('source_company'=>'mad4b-central',
 'consumer_company'=>'egypt-tour-gates',
 'product_type'=>'transportations','source_product_id'=>39707,
 'enabled'=>true,
 'starting_rule'=>null,
 'rate_rules'=>array(array(
  'rate_key'=>$cct[0]['source_rate_key'],
  'field'=>'base_price',
  'mode'=>'rate_generator',
  'percent_basis_points'=>1500)),
 'default_rate_rule'=>null);
$tp=MAD4B_Hub_Company_Pricing_V1::quote($localTransport,
 'egypt-tour-gates',array(),$transportPolicy);
if(is_wp_error($tp))throw new RuntimeException('CCT override rejected: '.$tp->get_error_code());
$cctQuote=array_values(array_filter($tp['rate_entries'],static function($row){
    return $row['source_rate_key']==='jet_cct_transport_rates:1';
}));
if(count($cctQuote)!==1 ||
   !$cctQuote[0]['generated_rate_preview'] ||
   ($cctQuote[0]['price_fields']['base_price']['mode']??'')!=='rate_generator' ||
   $tp['checkout_ready']!==false)
 throw new RuntimeException('CCT override did not select provider-qualified rate');
$native=MAD4B_Hub_Company_Pricing_V1::native_generator_preview(array(
 'consumer_company'=>'all-royal-egypt',
 'generator_input'=>array('kind'=>'transport','parent_id'=>39707,
  'amount'=>'125','currency'=>'USD','price_unit'=>'per_day',
  'valid_from'=>'2026-11-01','valid_to'=>'2026-11-30','rate_scope'=>'both')));
if(is_wp_error($native) ||
   $native['provider']!=='Shared_Product_Rate_Generation' ||
   $native['native_schema']!=='shared-rate-generation.v1' ||
   $native['native_post_type']!=='transport-rates' ||
   $native['creation_performed']!==false ||
   $native['provider_create_invoked']!==false ||
   $native['native_draft_plan']['post_status']!=='draft' ||
   $native['checkout_ready']!==false)
 throw new RuntimeException('Existing native rate generator preview bridge failed');
$wrongType=MAD4B_Hub_Price_Sources_V1::local(64948,'properties');
if(!is_wp_error($wrongType))throw new RuntimeException('Incorrect post type not rejected');
$missing=MAD4B_Hub_Price_Sources_V1::signedHistorical('tourism-almallahgroup',9999999);
if(!is_wp_error($missing))throw new RuntimeException('Unknown source historical price accepted');
echo wp_json_encode(array(
 'contract'=>MAD4B_Hub_Company_Pricing_V1::CONTRACT,
 'source_providers_detected'=>array_keys($descriptors),
 'native_pricing_registry_found'=>$inventory['pricing_registry_present'],
 'historic_tour_rates_for_selected_product'=>$historic['rate_count'],
 'historic_all_rates_reference_only'=>true,
 'remote_start_price_field_feed_status'=>'NOT_YET_AVAILABLE',
 'local_tour_direct_candidates'=>count($localTour['direct_starting_prices']),
 'local_transport_direct_candidates'=>count($localTransport['direct_starting_prices']),
 'jetengine_transport_cct_rates_found'=>count($cct),
 'provider_qualified_rate_generator'=>'PASS',
 'native_shared_product_rate_generator_bridge'=>'PASS',
 'native_draft_post_type'=>$native['native_post_type'],
 'native_create_invoked'=>false,
 'company_rate_override'=>'PASS',
 'per_language_rule'=>'PASS',
 'rate_generator_preview'=>'PASS',
 'source_currency_preserved'=>true,
 'unverified_fx_rejected'=>true,
 'cross_product_policy_denied'=>true,
 'invalid_policies_denied'=>true,
 'checkout_ready'=>false,
 'database_mutations'=>0
),JSON_UNESCAPED_SLASHES),PHP_EOL;
