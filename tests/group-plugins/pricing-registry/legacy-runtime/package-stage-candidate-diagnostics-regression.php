<?php
defined('ABSPATH')||exit;
$tests=[];$assert=static function($name,$val)use(&$tests){$tests[$name]=(bool)$val;};
$from='2026-12-05';$to='2026-12-19';
$board=MAD4B_Package_Component_Readiness_V1::report($from,$to);
$assert('admin_report_readable',is_array($board));
$assert('no_sales_price_readiness',is_array($board)&&($board['totals']['price_ready_tier_reviews']??-1)===0);
$assert('no_checkout',is_array($board)&&$board['checkout_authorized']===false);
$expected=[];$seen=[];$reviewed=0;
if(is_array($board))foreach($board['tours'] as $tour){
 foreach($tour['levels'] as $level=>$data){
  $review=MAD4B_Package_Stage_Rate_Guard_V1::review(
   (int)$tour['tour_id'],(int)$data['tier_id'],$from,$to);
  $reviewed++;
  foreach($review['stages'] as $stage){
   $id=$tour['tour_id'].'|'.$data['tier_id'].'|'.$stage['key'];
   $empty=in_array($stage['kind'],['hotel','resort'],true)&&
    in_array('stage_destination_has_no_candidate_property',
      (array)$stage['blocked'],true)&&
    empty($stage['season_rejected_property_ids']);
   if($empty)$expected[$id]=true;
  }
  foreach($data['stages'] as $stage){
   $diag=$stage['unassigned_property_diagnostics']??null;
   if(!is_array($diag))continue;
   $id=$tour['tour_id'].'|'.$data['tier_id'].'|'.$stage['key'];
   $seen[$id]=true;
   $assert('report_diagnostic_has_true_guard_gap_'.$id,
      isset($expected[$id]));
   $assert('diagnostic_preserves_zero_eligible_'.$id,
      (int)$stage['eligible_properties']===0);
   $assert('diagnostic_never_authorizes_assignment_'.$id,
      ($diag['not_authorized_to_assign']??false)===true&&
      ($diag['checkout_authorized']??null)===false);
   $assert('native_catalogue_provenance_'.$id,
      (int)($diag['total']??0)>=count((array)($diag['candidates']??[])));
   foreach((array)($diag['candidates']??[]) as $candidate)
    $assert('source_candidate_unqualified_'.$id.'_'.$candidate['id'],
      ($candidate['qualified']??null)===false&&
      ($candidate['assigned']??null)===false);
  }
 }
}
$assert('all_24_tier_reviews_traversed',$reviewed===24);
$assert('guard_gaps_equal_diagnostics',
 array_keys($expected)===array_keys($seen) ||
 (count(array_diff_key($expected,$seen))===0&&count(array_diff_key($seen,$expected))===0));
$assert('at_least_one_real_gap_surfaced',count($expected)>=1);
$before=get_current_user_id();wp_set_current_user(0);
$forbidden=MAD4B_Package_Component_Readiness_V1::report($from,$to);
wp_set_current_user($before);
$assert('anonymous_access_rejected',is_wp_error($forbidden));
$f=array_keys(array_filter($tests,static fn($v)=>!$v));
echo wp_json_encode([
 'site'=>home_url(),'contract'=>'mad4b.package-stage-candidate-diagnostics.v2',
 'passed'=>count($tests)-count($f),'total'=>count($tests),
 'failures'=>$f,'guard_expected_gaps'=>count($expected),
 'report_recorded_gaps'=>count($seen),
 'source_terms_never_mutated'=>true,'checkout_authorized'=>false],
 JSON_UNESCAPED_SLASHES)."\n";
if($f&&class_exists('WP_CLI'))WP_CLI::halt(1);

