<?php
/** Supplier calendar exception candidates are REVIEW ONLY; no rates or meta writes. */
$checks=[];$check=static function($key,$ok)use(&$checks){$checks[$key]=(bool)$ok;};
$hotel=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','peak');
$cruise=MAD4B_Supplier_Sheet_Reference_V1::catalog('cruises','winter_2026_27');
$check('catalog_read',is_array($hotel)&&is_array($cruise));
if(is_array($hotel)&&is_array($cruise)){
 $h=[];$c=[];
 foreach($hotel['rows'] as $r)foreach($r['source_note_cells'] as $n)$h[$n['cell']]=$n;
 foreach($cruise['rows'] as $r)foreach($r['source_note_cells'] as $n)$c[$n['cell']]=$n;
 $w1=$h['O56']['date_evidence']['windows'][0]??[];
 $w2=$h['O57']['date_evidence']['windows'][0]??[];
 $check('barcelo_first_explicit_window',($w1['start']??'')==='2026-12-23'&&($w1['end']??'')==='2027-01-03');
 $check('barcelo_second_explicit_window',($w2['start']??'')==='2027-03-25'&&($w2['end']??'')==='2027-04-02');
 $check('barcelo_not_auto_approved',($h['O56']['date_evidence']['auto_apply_allowed']??true)===false&&($h['O57']['date_evidence']['commercially_approved']??true)===false);
 $check('cruise_backwards_date_caught',in_array('end_precedes_start',$c['K78']['date_evidence']['review_blockers']??[],true));
 $check('cruise_long_exception_caught',in_array('unusually_long_exception_over_120_days',$c['K94']['date_evidence']['review_blockers']??[],true));
 $check('cruise_suspect_windows_not_plausible',array_filter((array)($c['K94']['date_evidence']['windows']??[]),
  static fn($w)=>empty($w['plausible_date_order']))!==[]);
 $check('calendar_no_granted_checkout',($hotel['supports_checkout']??true)===false&&($cruise['supports_checkout']??true)===false);
 $check('source_note_cells_preserved',($hotel['note_cell_count']??0)>0&&($cruise['note_cell_count']??0)>0);
 $check('review_windows_counted',($hotel['date_window_candidate_count']??0)>=2&&($cruise['date_window_candidate_count']??0)>=2);
 if(home_url()==='https://wayfatours.com'){
  $park=$h['O8']['date_evidence']??[];
  $check('park_complete_christmas_window_detected',($park['windows'][0]['start']??'')==='2026-12-25'&&($park['windows'][0]['end']??'')==='2027-01-02');
  $check('park_march_missing_year_not_inferred',in_array('month_name_date_missing_explicit_year',$park['review_blockers']??[],true));
  $check('park_multiple_peak_periods_await_review',($park['auto_apply_allowed']??true)===false);
 }
}
$actor=get_current_user_id();wp_set_current_user(0);
$blocked=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','peak');
wp_set_current_user($actor);
$check('unauthenticated_denied',is_wp_error($blocked)&&$blocked->get_error_code()==='quote_sheet_access_denied');
$failed=array_keys(array_filter($checks,static fn($v)=>!$v));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.supplier-explicit-date-review.v1',
 'passed'=>count($checks)-count($failed),'total'=>count($checks),'failures'=>$failed,
 'approved_costs'=>0,'write_performed'=>false,'checkout_authorized'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($failed&&class_exists('WP_CLI'))WP_CLI::halt(1);

