<?php
/** Read-only supplier exception triage and original note provenance QA. */
$checks=[];
$test=static function($key,$result)use(&$checks){$checks[$key]=(bool)$result;};
$peak=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','peak');
$test('peak_readable',is_array($peak));
if(is_array($peak)){
 $test('peak_not_global_term',array_key_exists('term_id',$peak['base_season'])&&$peak['base_season']['term_id']===null);
 $test('peak_no_checkout',$peak['supports_checkout']===false&&$peak['write_performed']===false);
 $test('flagged_count_consistent',$peak['note_review_count']<= $peak['note_cell_count']&&$peak['note_review_count']>0);
 $test('term_supplement_flags_present',($peak['note_flag_counts']['meal_or_supplement_mentioned']??0)>0);
 $test('mandatory_flags_present',($peak['note_flag_counts']['mandatory_item_mentioned']??0)>0);
 $rows=[];
 foreach($peak['rows'] as $r)$rows[(int)$r['source_row']]=$r;
 $barcelo=$rows[56]??[];
 $notes=[];
 foreach((array)($barcelo['source_note_cells']??[]) as $n)$notes[$n['cell']]=$n;
 $test('multirow_peak_source_columns',isset($notes['O56'],$notes['O57'],$notes['O58']));
 $test('barcelo_mandatory_flag',in_array('mandatory_item_mentioned',$notes['O58']['review_flags']??[],true));
 $test('barcelo_year_evidence_linked',
  in_array('O56',$notes['O58']['related_explicit_year_cells']??[],true)&&
  in_array('O57',$notes['O58']['related_explicit_year_cells']??[],true));
 if(home_url()==='https://wayfatours.com'){
  $park=$rows[8]??[];$d=[];
  foreach((array)($park['source_note_cells']??[]) as $n)$d[$n['cell']]=$n;
  $test('park_original_three_cells',isset($d['O8'],$d['O9'],$d['O10']));
  $test('park_christmas_optional_only',in_array('optional_item_mentioned',$d['O9']['review_flags']??[],true)&&
    !in_array('mandatory_item_mentioned',$d['O9']['review_flags']??[],true));
  $test('park_new_year_mandatory_only',in_array('mandatory_item_mentioned',$d['O10']['review_flags']??[],true)&&
    !in_array('optional_item_mentioned',$d['O10']['review_flags']??[],true));
  $test('park_year_context_same_source',in_array('O8',$d['O10']['related_explicit_year_cells']??[],true));
 }
}
$summer=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','summer');
$winter=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','winter_2026_27');
$test('summer_readable',is_array($summer)&&$summer['base_season']['window_start']==='2026-05-01');
$test('winter_readable',is_array($winter)&&$winter['base_season']['window_end']==='2027-04-30');
$coastal=MAD4B_Supplier_Sheet_Reference_V1::catalog('costal','peak');
$test('coastal_present',is_array($coastal)&&$coastal['base_season']['term_id']===null);
$before=get_current_user_id();wp_set_current_user(0);
$denied=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','peak');
wp_set_current_user($before);
$test('anonymous_rejected',is_wp_error($denied)&&$denied->get_error_code()==='quote_sheet_access_denied');
$fails=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.supplier-exception-triage.v1',
 'passed'=>count($checks)-count($fails),'total'=>count($checks),
 'failures'=>$fails,'no_automatic_dates'=>true,'cost_receipts_created'=>false,
 'checkout_authorized'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($fails&&class_exists('WP_CLI'))WP_CLI::halt(1);

