<?php
/** Regression: universal private Sheet quote registry, no site-specific prices. */
$fail=[];$checks=[];
$expect=static function($label,$yes)use(&$checks,&$fail){
 $checks[$label]=(bool)$yes;if(!$yes)$fail[]=$label;
};
$stored=get_option(MAD4B_Supplier_Sheet_Reference_V1::OPTION,false);
$expect('import_option_present',is_array($stored));
$expect('private_schema',is_array($stored)&&
 ($stored['schema']??'')==='mad4b.supplier-tariff-reference-ledger.v1');
$expect('source_url_is_google_sheet',is_array($stored)&&
 strpos((string)$stored['source_url'],'https://docs.google.com/spreadsheets/d/')===0);
$expect('never_approved',is_array($stored)&&
 ($stored['cost_receipt_approved']??true)===false&&
 ($stored['supplier_contract_verified']??true)===false&&
 ($stored['checkout_authorized']??true)===false);
$tab='hotels';$period='winter_2026_27';
$catalog=MAD4B_Supplier_Sheet_Reference_V1::catalog($tab,$period);
$expect('catalog_available',is_array($catalog));
if(is_array($catalog)&&is_array($stored)){
 $expectedItems=array_values(array_filter($stored['records'],
  static fn($x)=>($x['source_tab']??'')===$tab));
 $expectedRows=0;foreach($expectedItems as $rec)
  foreach((array)($rec['tariffs']??[]) as $t)
   if(($t['period_label']??'')===$period)$expectedRows++;
 $expectedUnmapped=count(array_filter($stored['unmapped_entities'],
  static fn($x)=>($x['tab']??'')===$tab));
 $expect('matched_count_matches_option',$catalog['matched_entities']===count($expectedItems));
 $expect('quoted_count_matches_option',$catalog['tariff_values']===$expectedRows);
 $expect('unmatched_count_matches_option',$catalog['unmapped_entities']===$expectedUnmapped);
 $expect('no_commercial_grant',$catalog['commercial_approved']===false&&
   $catalog['supports_checkout']===false&&$catalog['supplier_contract_verified']===false);
 $expect('no_date_validation',$catalog['period_dates_validated']===false);
 $expect('no_write_during_review',$catalog['write_performed']===false);
 $safe=true;$quoteCount=0;$unpriced=0;$sourceLinks=0;
 foreach($catalog['rows'] as $row){
  if(strpos((string)($row['source_link']??''),
    'https://docs.google.com/spreadsheets/d/')!==0)$safe=false;
  $sourceLinks++;
  foreach((array)($row['tariffs']??[]) as $quote){
   $quoteCount++;
   if(!is_numeric($quote['amount'])||
     !in_array($quote['currency'],['USD','EUR'],true)||
     !empty($quote['valid_date_window_confirmed'])||
     !empty($quote['per_night_contract_verified']))$safe=false;
  }
  if(!$row['tariffs'])$unpriced++;
 }
 $expect('values_preserve_origins_and_currency',$safe);
 $expect('no_blank_price_imputed',$sourceLinks===count($catalog['rows']));
 $expect('quoted_values_not_inferred',$quoteCount===$catalog['tariff_values']);
}
$index=MAD4B_Supplier_Sheet_Reference_V1::countsByProperty($period);
$expect('property_index_available',is_array($index));
$expect('invalid_period_rejected',is_wp_error(
 MAD4B_Supplier_Sheet_Reference_V1::catalog($tab,'unbounded')) );
$expect('invalid_tab_rejected',is_wp_error(
 MAD4B_Supplier_Sheet_Reference_V1::catalog('anything',$period)) );
$cruise=MAD4B_Supplier_Sheet_Reference_V1::catalog('cruises',$period);
$expect('cruise_listing',is_array($cruise));
$costal=MAD4B_Supplier_Sheet_Reference_V1::catalog('costal',$period);
$expect('coastal_listing',is_array($costal));
$readiness=MAD4B_Package_Component_Readiness_V1::report('2026-12-01','2027-04-30');
$expect('package_readiness_is_readable',is_array($readiness));
if(is_array($readiness)){
 $row=$readiness['tours'][0]['levels']['Silver']['stages'][0]??[];
 $expect('package_quote_linkage_exists',array_key_exists('sheet_winter_quote_values',$row));
 $expect('no_rate_checkout_grant',$readiness['rate_created']===false&&
 $readiness['checkout_authorized']===false);
}
$old=get_current_user_id();wp_set_current_user(0);
$deny=MAD4B_Supplier_Sheet_Reference_V1::catalog($tab,$period);
wp_set_current_user($old);
$expect('non_admin_denied',is_wp_error($deny)&&$deny->get_error_code()==='quote_sheet_access_denied');
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.supplier-sheet-regression.v2',
 'passed'=>count($checks)-count($fail),'total'=>count($checks),
 'failures'=>$fail,'commercial_grant'=>false,'checkout'=>false,
 'mutation_performed'=>false,'checks'=>$checks])."\n";
if($fail){if(class_exists('WP_CLI'))WP_CLI::halt(1);exit(1);}
