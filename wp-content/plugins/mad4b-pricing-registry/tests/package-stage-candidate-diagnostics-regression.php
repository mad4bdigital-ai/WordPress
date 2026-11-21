<?php
$checks=[];$check=static function($label,$pass)use(&$checks){$checks[$label]=(bool)$pass;};
$r=MAD4B_Package_Component_Readiness_V1::report('2026-12-05','2026-12-19');
$check('report_admin_readable',is_array($r));
$check('no_price_readiness',is_array($r)&&$r['totals']['price_ready_tier_reviews']===0);
$check('no_checkout',is_array($r)&&$r['checkout_authorized']===false);
$diagnostics=[];
if(is_array($r)){
 foreach($r['tours'] as $tour)
  foreach($tour['levels'] as $tier=>$data)
   foreach($data['stages'] as $s)if(is_array($s['unassigned_property_diagnostics']??null))
     $diagnostics[]=['tour'=>(int)$tour['tour_id'],'tier'=>$tier,
      'stage'=>$s['key'],'details'=>$s['unassigned_property_diagnostics'],
      'eligible'=>$s['eligible_properties']];
}
$check('nine_stage_tier_diagnostics',count($diagnostics)===9);
$groups=[];foreach($diagnostics as $d)$groups[$d['stage']][]=$d;
$check('only_three_source_stage_gaps',count($groups)===3);
foreach(['hotel-luxor-02','resort-sharm-03','hotel-aswan-02'] as $stage){
 $records=$groups[$stage]??[];
 $check($stage.'_three_levels',count($records)===3);
 $check($stage.'_no_false_eligibility',
  count(array_filter($records,static fn($x)=>$x['eligible']!==0||
    ($x['details']['not_authorized_to_assign']??false)!==true))===0);
 $check($stage.'_catalogue_candidates_found',
  count(array_filter($records,static fn($x)=>($x['details']['total']??0)<1))===0);
 foreach($records as $record)
  foreach($record['details']['candidates'] as $candidate)
   if(($candidate['qualified']??null)!==false||
      ($candidate['assigned']??null)!==false)
     $checks['diagnostic_not_treated_as_assignment']=false;
}
$check('luxor_sheet_reference_present',!empty(array_filter(
 $groups['hotel-luxor-02'][0]['details']['candidates']??[],
 static fn($c)=>!empty($c['supplier_quote_reference']))));
$check('aswan_city_unverified_flagged',!empty(array_filter(
 $groups['hotel-aswan-02'][0]['details']['candidates']??[],
 static fn($c)=>($c['reason']??'')==='aswan_city_location_unverified')));
$check('sharm_tier_gap_identified',!empty(array_filter(
 $groups['resort-sharm-03'][0]['details']['candidates']??[],
 static fn($c)=>($c['reason']??'')==='property_tier_mismatch')));
$before=get_current_user_id();wp_set_current_user(0);
$denied=MAD4B_Package_Component_Readiness_V1::report('2026-12-05','2026-12-19');
wp_set_current_user($before);
$check('unprivileged_report_blocked',is_wp_error($denied));
$failed=array_keys(array_filter($checks,static fn($v)=>!$v));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.package-stage-candidate-diagnostics.v1',
 'passed'=>count($checks)-count($failed),'total'=>count($checks),
 'failures'=>$failed,'diagnostic_rows'=>count($diagnostics),
 'live_taxonomy_mutations'=>false,'price_or_checkout_activated'=>false],
 JSON_UNESCAPED_SLASHES)."\n";
if($failed&&class_exists('WP_CLI'))WP_CLI::halt(1);

