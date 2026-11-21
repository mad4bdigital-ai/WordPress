<?php
/** Season Term Meta + supplier note provenance, no mutations. */
$checks=[];$fail=[];
$assert=static function($k,$ok)use(&$checks,&$fail){$checks[$k]=(bool)$ok;if(!$ok)$fail[]=$k;};
$base=get_option('mad4b_supplier_quote_sheet_reference_v1',false);
$assert('private_quote_option_exists',is_array($base));
$assert('2026_quote_anchor',(int)($base['sheet_period_anchor_year']??0)===2026);
$assert('existing_quote_approval_still_false',
 is_array($base)&&($base['supplier_contract_verified']??true)===false&&
 ($base['cost_receipt_approved']??true)===false&&
 ($base['checkout_authorized']??true)===false);
$expected=home_url()==='https://tours.dev.mad4b.com'?[38,188]:
 (home_url()==='https://wayfatours.com'?[43,213]:[0,0]);
$sum=0;$entityCount=0;$hasRefs=true;
foreach((array)($base['records']??[]) as $record){
 $entityCount++;
 foreach((array)($record['source_note_cells']??[]) as $note){
  $sum++;
  if(!preg_match('/^[EFJKO]\d+$/D',(string)($note['cell']??''))||
     !in_array($note['period']??null,['summer','winter_2026_27','peak'],true)||
     empty($note['text']))$hasRefs=false;
 }
}
$assert('original_note_row_refs_preserved',$hasRefs);
$assert('matched_property_count_stable',$entityCount===$expected[0]);
$assert('all_sheet_note_cells_read_back',$sum===$expected[1]);
$summer=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','summer');
$winter=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','winter_2026_27');
$peak=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','peak');
$assert('summer_catalog_available',is_array($summer));
$assert('winter_catalog_available',is_array($winter));
$assert('peak_catalog_available',is_array($peak));
if(is_array($summer)&&is_array($winter)&&is_array($peak)){
 $s=$summer['base_season'];$w=$winter['base_season'];$p=$peak['base_season'];
 $assert('summer_uses_term_meta',$s['source']==='season_taxonomy_term_meta'&&
    $s['window_start']==='2026-05-01'&&$s['window_end']==='2026-09-30'&&$s['cross_year']===false);
 $assert('winter_uses_term_meta',$w['source']==='season_taxonomy_term_meta'&&
    $w['window_start']==='2026-10-01'&&$w['window_end']==='2027-04-30'&&$w['cross_year']===true);
 $assert('live_term_ids_unmodified',(int)$s['term_id']===(int)get_term_by('slug','summer','season')->term_id&&
   (int)$w['term_id']===(int)get_term_by('slug','winter','season')->term_id);
 $assert('no_global_peak_window',$p['term_id']===null&&$p['window_start']===null&&
   $p['status']==='supplier_specific_peak_not_a_global_term');
 $barcelo=array_values(array_filter($peak['rows'],static fn($x)=>(int)$x['source_row']===56));
 $cells=$barcelo?array_column($barcelo[0]['source_note_cells'],'cell'):[];
 $assert('barcelo_peak_notes_three_rows',
   in_array('O56',$cells,true)&&in_array('O57',$cells,true)&&in_array('O58',$cells,true));
 $assert('peak_gala_note_preserved',
   $barcelo&&str_contains(implode(' ',array_column($barcelo[0]['source_note_cells'],'text')),'130'));
 $assert('no_quote_grant',!$summer['supports_checkout']&&!$winter['supports_checkout']&&!$peak['supports_checkout']);
 $assert('no_quote_writes',!$summer['write_performed']&&!$winter['write_performed']&&!$peak['write_performed']);
 if(home_url()==='https://wayfatours.com'){
  $park=array_values(array_filter($peak['rows'],static fn($x)=>(int)$x['source_row']===8));
  $pcells=$park?array_column($park[0]['source_note_cells'],'cell'):[];
  $assert('pyramids_park_optional_and_mandatory_preserved',
   in_array('O8',$pcells,true)&&in_array('O9',$pcells,true)&&in_array('O10',$pcells,true));
 }
}
$uid=get_current_user_id();wp_set_current_user(0);
$deny=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','winter_2026_27');
wp_set_current_user($uid);
$assert('non_admin_denied',is_wp_error($deny)&&$deny->get_error_code()==='quote_sheet_access_denied');
echo wp_json_encode(['site'=>home_url(),
 'contract'=>'mad4b.supplier-sheet-season-note-regression.v1',
 'passed'=>count($checks)-count($fail),'total'=>count($checks),
 'failures'=>$fail,'checks'=>$checks,'prices_updated'=>false,
 'checkout_enabled'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($fail){if(class_exists('WP_CLI'))WP_CLI::halt(1);exit(1);}
