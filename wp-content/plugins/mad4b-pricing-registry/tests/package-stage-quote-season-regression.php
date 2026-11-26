<?php
/** Selected stage actual stay nights -> matching supplier quote period. */
$checks=[];$test=static function($key,$ok)use(&$checks){$checks[$key]=(bool)$ok;};
$windows=[
 'summer'=>['2026-06-01','2026-06-15','summer'],
 'winter'=>['2026-12-01','2026-12-15','winter_2026_27'],
 'cross'=>['2026-09-25','2026-10-05',null],
 'future_year'=>['2027-06-01','2027-06-15',null],
];
foreach($windows as $key=>[$from,$to,$expected]){
 $r=MAD4B_Package_Component_Readiness_V1::report($from,$to);
 $test($key.'_readable',is_array($r));
 if(!is_array($r))continue;
 $first=null;
 foreach($r['tours'] as $tour){
  if((int)$tour['tour_id']!==60623)continue;
  foreach($tour['levels']['Gold']['stages'] as $s)
   if($s['key']==='hotel-cairo-01'){$first=$s;break;}
 }
 $test($key.'_stage_found',is_array($first));
 if(!is_array($first))continue;
 $test($key.'_period_correct',$first['sheet_quote_period']===$expected);
 $test($key.'_not_approved',$first['sheet_price_valid_for_dates']===false);
 $test($key.'_never_checkout',$r['checkout_authorized']===false&&$r['totals']['price_ready_tier_reviews']===0);
 if($expected===null){
  $test($key.'_no_false_quote_refs',(int)$first['sheet_quote_values']===0);
  $test($key.'_scope_explained',$first['sheet_quote_scope']==='out_of_sheet_season_or_cross_season');
 }else{
  $test($key.'_scope_reference_only',$first['sheet_quote_scope']==='base_sheet_period_match_unapproved');
  if($key==='summer')
   $test('summer_does_not_inherit_winter_quote_alias',$first['sheet_winter_quote_values']===0);
  if($key==='winter')
   $test('winter_alias_correct',$first['sheet_quote_values']===$first['sheet_winter_quote_values']);
 }
}
$f=array_keys(array_filter($checks,static fn($x)=>!$x));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.package-stage-quote-season-regression.v1',
 'passed'=>count($checks)-count($f),'total'=>count($checks),'failures'=>$f,
 'checkout_authorized'=>false,'writes_performed'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($f&&class_exists('WP_CLI'))WP_CLI::halt(1);

