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

 /** Strict date-token evidence parser for original supplier note text.
  * Date order and source cell stay visible; no signed tariff/eligibility. */
 private static function explicitDateTokens(string $source):array {
  $month='jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
  $re='~(?<![\d])(?:\d{1,2}[./-]\d{1,2}[./-]20\d{2}|\d{1,2}(?:st|nd|rd|th)?[.\s-]+(?:'.$month.')[.,\s-]+20\d{2}|(?:'.$month.')[.\s-]+\d{1,2}(?:st|nd|rd|th)?[,]?\s+20\d{2})(?!\d)~iu';
  preg_match_all($re,$source,$m,PREG_OFFSET_CAPTURE);
  $dates=[];
  foreach((array)($m[0]??[]) as $entry){
   $raw=trim((string)$entry[0]);$offset=(int)$entry[1];
   $day=0;$monthNumber=0;$year=0;
   if(preg_match('~^(\d{1,2})[./-](\d{1,2})[./-](20\d{2})$~D',$raw,$a)){
    $day=(int)$a[1];$monthNumber=(int)$a[2];$year=(int)$a[3];
   }else{
    $months=['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
     'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    if(preg_match('~^(\d{1,2})(?:st|nd|rd|th)?[.\s-]+([A-Za-z]+)[.,\s-]+(20\d{2})$~Di',$raw,$a)){
     $day=(int)$a[1];$monthNumber=$months[strtolower(substr($a[2],0,3))]??0;$year=(int)$a[3];
    }elseif(preg_match('~^([A-Za-z]+)[.\s-]+(\d{1,2})(?:st|nd|rd|th)?[,]?\s+(20\d{2})$~Di',$raw,$a)){
     $day=(int)$a[2];$monthNumber=$months[strtolower(substr($a[1],0,3))]??0;$year=(int)$a[3];
    }
   }
   $iso=checkdate($monthNumber,$day,$year)?sprintf('%04d-%02d-%02d',$year,$monthNumber,$day):null;
   $dates[]=['raw'=>$raw,'iso'=>$iso,'offset'=>$offset];
  }
  return $dates;
 }
 private static function dateEvidence(string $source,array $flags):array {
  $tokens=self::explicitDateTokens($source);
  $dates=array_values(array_filter($tokens,static fn($x)=>$x['iso']!==null));
  $errors=[];
  if(count($tokens)!==count($dates))$errors[]='malformed_explicit_date';
  $windows=[];
  for($i=0;$i+1<count($dates);$i+=2){
   $a=$dates[$i]['iso'];$b=$dates[$i+1]['iso'];
   $begin=new DateTimeImmutable($a,new DateTimeZone('UTC'));
   $end=new DateTimeImmutable($b,new DateTimeZone('UTC'));
   $days=(int)$begin->diff($end)->days;
   $backwards=$a>$b;
   $tooLong=!$backwards&&$days>120;
   if($backwards)$errors[]='end_precedes_start';
   if($tooLong)$errors[]='unusually_long_exception_over_120_days';
   $windows[]=['start'=>$a,'end'=>$b,'days_between'=>$days,
     'requires_review'=>true,'plausible_date_order'=>!$backwards&&!$tooLong,
     'end_inclusive_proven'=>false];
  }
  if(count($dates)%2===1){
   $errors[]=count($dates)===1?'single_day_event_or_partial_window':'unpaired_date_token';
  }
  if(!$tokens&&in_array('date_or_season_mentioned',$flags,true))
   $errors[]='no_complete_year_bearing_date_token';
  // Yearless month-name tokens may coexist with a valid full-year pair
  // in the SAME cell (e.g. Xmas and Easter); keep the latter unapproved
  // until the second exception is disambiguated.
  $month='jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
  $withoutYear='~(?:\\b\\d{1,2}\\s+(?:'.$month.')\\b(?!\\s*[,.\\/ -]*20\\d{2})|\\b(?:'.$month.')\\s+\\d{1,2}\\b(?!\\s*[,.\\/ -]*20\\d{2}))~iu';
  if(preg_match($withoutYear,$source))
   $errors[]='month_name_date_missing_explicit_year';
  // A complete-looking pair does not prove that all other dates in the
  // note have a year. Never propagate contextual year automatically.
  if(preg_match('~(?<!\d)\d{1,2}\s*[./-]\s*\d{1,2}(?!\d)(?!\s*[./-]\s*20\d{2})~u',$source)||
    in_array('four_digit_year_not_in_this_cell',$flags,true)){
   $errors[]='partial_date_could_need_year_review';
  }
  return ['kind'=>count($dates)===1?'event_or_partial':($windows?'window_candidate':'no_safe_window'),
   'tokens'=>$tokens,'windows'=>$windows,'review_blockers'=>array_values(array_unique($errors)),
   'auto_apply_allowed'=>false,'commercially_approved'=>false];
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
   'date_window_candidate_count'=>0,'date_window_review_blocker_count'=>0,
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
    $note['date_evidence']=self::dateEvidence((string)($note['text']??''),$flags);
    $out['date_window_candidate_count']+=count($note['date_evidence']['windows']);
    if($note['date_evidence']['review_blockers'])
      $out['date_window_review_blocker_count']++;
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

