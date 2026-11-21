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
  if(!class_exists('MAD4B_Package_Seasonal_Assignment_V1',false))
   return new WP_Error('supplier_quote_season_policy_missing');
  $seasons=MAD4B_Package_Seasonal_Assignment_V1::seasons();
  $policy=$seasons[$slug]??null;
  if(!is_array($policy))
   return new WP_Error('supplier_quote_season_term_disabled');
  $id=(int)$policy['term_id'];
  $policyId=(int)$policy['policy_term_id'];
  $startYear=(int)($doc['sheet_period_anchor_year']??0);
  if($startYear<2020||$startYear>2100)
   return new WP_Error('supplier_quote_season_year_missing');
  $sm=(int)$policy['sm'];
  $sd=(int)$policy['sd'];
  $em=(int)$policy['em'];
  $ed=(int)$policy['ed'];
  $cross=(bool)$policy['cross'];
  if(!checkdate($sm,$sd,$startYear)||!checkdate($em,$ed,$startYear+($cross?1:0)))
   return new WP_Error('supplier_quote_season_term_dates_invalid');
  return [
   'status'=>'base_calendar_from_existing_term_meta',
   'source'=>'season_taxonomy_term_meta',
   'term_id'=>$id,'policy_term_id'=>$policyId,'term_slug'=>$slug,
   'window_start'=>sprintf('%04d-%02d-%02d',$startYear,$sm,$sd),
   'window_end'=>sprintf('%04d-%02d-%02d',$startYear+($cross?1:0),$em,$ed),
   'cross_year'=>$cross,
   'term_priority'=>(int)$policy['priority'],
   'dates_directly_approved'=>false,
   'supplier_exception_notes_may_override'=>true
  ];
 }
 /**
  * Only tag ORIGINAL supplier note text for a human normalization queue.
  * A tag is neither a parsed date range nor a Cost Receipt.
  */
 private static function noteReviewFlags(string $text):array {
  $tags=[];
  if(preg_match('/\b\d{1,2}\s*[.\/-]\s*\d{1,2}\b/u',$text)||
     preg_match('/\b(?:january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|october|oct|november|nov|december|dec)\b/iu',$text)||
     preg_match('/\b(?:peak|winter|summer|christmas|new\s*year|easter)\b/iu',$text))
      $tags[]='date_or_season_mentioned';
  if(preg_match('/\b(?:obligator(?:y|ily)|compulsor(?:y|ily)|mandatory|required)\b/iu',$text))
      $tags[]='mandatory_item_mentioned';
  if(preg_match('/\b(?:optional|on\s+request)\b/iu',$text))
      $tags[]='optional_item_mentioned';
  if(preg_match('/\b(?:child(?:ren)?|infant(?:s)?|age(?:s)?|years\s+old)\b/iu',$text))
      $tags[]='child_policy_mentioned';
  if(preg_match('/\b(?:gala|dinner|lunch|breakfast|meal|supplement|half\s*board|full\s*board|all\s*inclusive)\b/iu',$text)||
     preg_match('/\bH\.?\s*B\.?\b/iu',$text))
      $tags[]='meal_or_supplement_mentioned';
  if(preg_match('/\b(?:stop\s*sale|blackout|sold\s*out|no\s+availability|not\s+available)\b/iu',$text))
      $tags[]='availability_restriction_mentioned';
  if(in_array('date_or_season_mentioned',$tags,true)&&
     !preg_match('/\b20\d{2}\b/u',$text))
      $tags[]='four_digit_year_not_in_this_cell';
  return array_values(array_unique($tags));
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
   'note_review_count'=>0,'note_flag_counts'=>[],
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
   $yearContextCells=[];
   foreach($noteCells as $note){
    if(preg_match('/\b20\d{2}\b/u',(string)($note['text']??'')))
     $yearContextCells[]=(string)$note['cell'];
   }
   foreach($noteCells as &$note){
    $flags=self::noteReviewFlags((string)($note['text']??''));
    $note['review_flags']=$flags;
    // Adjacent supplier rows form ONE property-level instruction: preserve
    // contextual year-cell references, but never silently merge distinct dates.
    $note['related_explicit_year_cells']=($flags&&
      !preg_match('/\b20\d{2}\b/u',(string)($note['text']??'')))?
       $yearContextCells:[];
    $note['commercial_rule_status']='original_source_requires_review';
    $note['cost_authority']=false;
    if($flags)$out['note_review_count']++;
    foreach($flags as $flag)
     $out['note_flag_counts'][$flag]=($out['note_flag_counts'][$flag]??0)+1;
   }
   unset($note);
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

