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

 /**
  * Authoritative general calendar: read live Season Term Meta, not a copy
  * of term IDs or a calendar added by quotation imports.
  * Peak remains supplier-specific even when its dates fall inside Winter.
  */
 private static function baseSeasonContext(string $period,array $doc){
  if($period==='peak')return [
    'status'=>'supplier_specific_peak_not_a_global_term',
    'term_id'=>null,'window_start'=>null,'window_end'=>null,
    'source'=>'original_supplier_notes',
    'dates_directly_approved'=>false
  ];
  $slug=$period==='summer'?'summer':'winter';
  $term=get_term_by('slug',$slug,'season');
  if(!$term instanceof WP_Term)
   return new WP_Error('supplier_quote_season_term_missing');
  $id=(int)$term->term_id;
  if(get_term_meta($id,'season_enabled',true)!=='1')
   return new WP_Error('supplier_quote_season_term_disabled');
  $startYear=(int)($doc['sheet_period_anchor_year']??0);
  if($startYear<2020||$startYear>2100)
   return new WP_Error('supplier_quote_season_year_missing');
  $sm=(int)get_term_meta($id,'season_start_month',true);
  $sd=(int)get_term_meta($id,'season_start_day',true);
  $em=(int)get_term_meta($id,'season_end_month',true);
  $ed=(int)get_term_meta($id,'season_end_day',true);
  $cross=get_term_meta($id,'season_cross_year',true)==='1';
  if(!checkdate($sm,$sd,$startYear)||!checkdate($em,$ed,$startYear+($cross?1:0)))
   return new WP_Error('supplier_quote_season_term_dates_invalid');
  return [
   'status'=>'base_calendar_from_existing_term_meta',
   'source'=>'season_taxonomy_term_meta',
   'term_id'=>$id,'term_slug'=>$term->slug,
   'window_start'=>sprintf('%04d-%02d-%02d',$startYear,$sm,$sd),
   'window_end'=>sprintf('%04d-%02d-%02d',$startYear+($cross?1:0),$em,$ed),
   'cross_year'=>$cross,
   'term_priority'=>(int)get_term_meta($id,'season_priority',true),
   'dates_directly_approved'=>false,
   'supplier_exception_notes_may_override'=>true
  ];
 }
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
  $calendar=self::baseSeasonContext($period,$doc);
  if(is_wp_error($calendar))return $calendar;
  $out=['contract'=>self::CONTRACT,'site'=>home_url('/'),
   'base_season'=>$calendar,'note_cell_count'=>0,
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
   $noteCells=array_values(array_filter((array)($record['source_note_cells']??[]),
     static fn($n)=>is_array($n)&&($n['period']??'')===$period));
   $out['note_cell_count']+=count($noteCells);
   $gid=(int)$record['gid'];
   $row=(int)$record['source_entity_row'];
   $out['rows'][]=[
    'property_id'=>(int)$record['property_id'],
    'title'=>(string)$record['expected_title'],
    'sheet_entity'=>(string)$record['source_entity'],
    'source_row'=>$row,
    'source_note_cells'=>$noteCells,
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
