<?php
/**
 * User-provided quotation sheet references. Never a supplier cost receipt.
 * Private, read-only, non-autoloaded option; admin-only AJAX contract.
 */
defined('ABSPATH') || exit;

final class MAD4B_Supplier_Sheet_Reference_V1 {
 public const OPTION='mad4b_supplier_quote_sheet_reference_v1';
 public const CONTRACT='mad4b.supplier-quote-sheet-view.v1';
 private const TABS=['hotels','cruises','costal'];
 private const PERIODS=['summer','winter_2026_27','peak'];

 private static function document(){
  if(!current_user_can('manage_options'))
   return new WP_Error('quote_sheet_access_denied');
  $doc=get_option(self::OPTION,false);
  if(!is_array($doc)||($doc['schema']??'')!=='mad4b.supplier-tariff-reference-ledger.v1'||
     ($doc['supplier_contract_verified']??true)!==false||
     ($doc['cost_receipt_approved']??true)!==false||
     ($doc['checkout_authorized']??true)!==false||
     !preg_match('~^https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+/edit$~D',
       (string)($doc['source_url']??'')))
   return new WP_Error('quote_sheet_unavailable_or_unsafe');
  return $doc;
 }
 public static function countsByProperty($period='winter_2026_27'){
  $doc=self::document();if(is_wp_error($doc))return $doc;
  if(!in_array($period,self::PERIODS,true))
   return new WP_Error('quote_sheet_period_invalid');
  $index=[];
  foreach($doc['records'] as $record){
   if(!is_array($record))continue;
   $id=(int)($record['property_id']??0);
   if($id<1)continue;
   $count=0;
   foreach((array)($record['tariffs']??[]) as $row)
    if(($row['period_label']??'')===$period&&
     ($row['commercial_activation_allowed']??true)===false)$count++;
   $index[$id]=['quotation_count'=>$count,'date_validated'=>false,'commercially_approved'=>false];
  }
  return $index;
 }
 public static function catalog($tab='hotels',$period='winter_2026_27'){
  if(!in_array($tab,self::TABS,true))
   return new WP_Error('quote_sheet_tab_invalid');
  if(!in_array($period,self::PERIODS,true))
   return new WP_Error('quote_sheet_period_invalid');
  $doc=self::document();if(is_wp_error($doc))return $doc;
  $out=['contract'=>self::CONTRACT,'site'=>home_url('/'),
   'tab'=>$tab,'period'=>$period,'source_url'=>$doc['source_url'],
   'matched_entities'=>0,'unmapped_entities'=>0,'tariff_values'=>0,
   'rows'=>[],'unmapped'=>[],'commercial_approved'=>false,
   'supplier_contract_verified'=>false,'period_dates_validated'=>false,
   'supports_checkout'=>false,'write_performed'=>false];
  foreach($doc['records'] as $record){
   if(($record['source_tab']??'')!==$tab)continue;
   $out['matched_entities']++;
   $tariffs=[];
   foreach((array)($record['tariffs']??[]) as $t){
    if(($t['period_label']??'')!==$period)continue;
    $tariffs[]=[
     'occupancy'=>(string)$t['occupancy'],
     'amount'=>(string)$t['amount'],
     'currency'=>(string)$t['currency'],
     'meal'=>(string)$t['meal_plan'],
     'basis'=>(string)$t['price_basis'],
     'notes'=>(array)($t['notes']??[]),
     'cell_ref'=>(string)$t['cell_ref'],
     'valid_date_window_confirmed'=>false,
     'per_night_contract_verified'=>false
    ];
   }
   $out['tariff_values']+=count($tariffs);
   $gid=(int)$record['gid'];
   $row=(int)$record['source_entity_row'];
   $out['rows'][]=[
    'property_id'=>(int)$record['property_id'],
    'title'=>(string)$record['expected_title'],
    'sheet_entity'=>(string)$record['source_entity'],
    'source_row'=>$row,
    'source_link'=>esc_url_raw($doc['source_url']).'?gid='.$gid.'#range=A'.$row.':O'.($row+2),
    'tariffs'=>$tariffs,'supplier_quote_status'=>'reference_only_not_verified',
    'commercially_approved'=>false
   ];
  }
  foreach($doc['unmapped_entities'] as $entity){
   if(($entity['tab']??'')!==$tab)continue;
   $out['unmapped_entities']++;
   $out['unmapped'][]=['entity'=>(string)$entity['name'],
     'source_row'=>(int)$entity['row'],
     'issue'=>'no verified local property identity'];
  }
  return $out;
 }
}

