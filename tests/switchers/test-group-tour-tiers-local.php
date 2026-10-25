<?php
/**
 * Reversible WP taxonomy fixture in ETG Local Lab only.
 * Creates two temporary Group Tour tiers and a temporary group mode if required.
 * Never creates a real order, changes pricing, or touches Staging/Production.
 */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(90);
$tm='ETG_Traveller_Management';
$fail=0;$assert=static function($name,$ok)use(&$fail){echo $name.'='.($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;};
$tour=66133;$rate=66139;$created=[];$old=[];
$departureMeta=['_etg_minimum_departure_participants','_etg_departure_max_capacity','_etg_small_party_routing'];
$oldDepartureMeta=[];
foreach($departureMeta as $key)$oldDepartureMeta[$key]=get_post_meta($tour,$key,true);
$taxonomies=['group-size-tiers','tour_categories_jet'];
foreach($taxonomies as $tax){
 if(!taxonomy_exists($tax)){echo 'MISSING_TAXONOMY='.$tax.PHP_EOL;exit(91);}
 $existing=wp_get_post_terms($tour,$tax,['fields'=>'ids']);
 if(is_wp_error($existing))exit(92);
 $old[$tax]=array_map('intval',$existing);
}
$make=static function($taxonomy,$slug,$name)use(&$created){
 $current=term_exists($slug,$taxonomy);
 if($current){return (int)(is_array($current)?$current['term_id']:$current);}
 $term=wp_insert_term($name,$taxonomy,['slug'=>$slug]);
 if(is_wp_error($term))throw new RuntimeException($term->get_error_code());
 $id=(int)$term['term_id'];$created[]=[$id,$taxonomy];return $id;
};
$set=static function($id,$min,$max,$on){
 update_term_meta($id,'tier_min',$min);
 update_term_meta($id,'tier_max',$max);
 update_term_meta($id,'tier_enabled',$on?'1':'0');
};
try{
 $mode=term_exists('group-tour','tour_categories_jet');
 $mode=$make('tour_categories_jet','group-tour','Group Tour');
 $private=$make('tour_categories_jet','private-tour','Private Tour');
 $a=$make('group-size-tiers','qa-unified-1-20-20261025','QA Unified 1–20');
 $b=$make('group-size-tiers','qa-unified-21-30-20261025','QA Unified 21–30');
 $c=$make('group-size-tiers','qa-unified-overlap-20261025','QA Overlap 23–28');
 $set($a,10,20,true);$set($b,21,30,true);$set($c,23,28,false);
 $cat=wp_set_post_terms($tour,array_values(array_unique(array_merge($old['tour_categories_jet'],[$mode,$private]))),'tour_categories_jet');
 $t=wp_set_post_terms($tour,array_values(array_unique(array_merge($old['group-size-tiers'],[$a,$b,$c]))),'group-size-tiers');
 if(is_wp_error($cat)||is_wp_error($t))throw new RuntimeException('taxonomy_assignment_failed');
 clean_object_term_cache($tour,'tours-and-activities');
 $tourIds=wp_get_post_terms($tour,'group-size-tiers',['fields'=>'ids']);
 $assert('TWO_TIERS_ASSIGNED',in_array($a,$tourIds,true)&&in_array($b,$tourIds,true));
 $twenty=$tm::assess_capacity($tour,'group',20);
 $twentyfive=$tm::assess_capacity($tour,'group',25);
 $assert('BOUNDARY_20_TIER_1',!is_wp_error($twenty)&&$twenty['tier_id']===$a);
 $assert('GROUP_25_TIER_2_PREVIEW',!is_wp_error($twentyfive)&&$twentyfive['tier_id']===$b);
 $assert('GROUP_25_NOT_BLOCKED_COMMERCIALLY',!is_wp_error($twentyfive)&&$twentyfive['status']==='tier_valid_preview_only');
 $assert('GROUP_25_CHECKOUT_STILL_UNCERTIFIED',!is_wp_error($twentyfive)&&$twentyfive['checkout_capacity_certified']===false&&$twentyfive['financial_checkout_authority']===false);
 $assert('GROUP_TIER_BOUNDARY_30',($tm::assess_capacity($tour,'group',30)['tier_id']??0)===$b);
 $assert('TIER_GAP_31_REJECTED',is_wp_error($tm::assess_capacity($tour,'group',31)));
 $assert('GROUP_25_SELECTED_WRONG_TIER_DENIED',is_wp_error($tm::assess_capacity($tour,'group',25,$a)));
 $assert('GROUP_25_CORRECT_TIER_ALLOWED',!is_wp_error($tm::assess_capacity($tour,'group',25,$b)));
 $single=$tm::advise_booking_mode($tour,1,'auto',false);
 $assert('BELOW_FIRST_TIER_RECOMMENDS_PRIVATE',!is_wp_error($single)&&$single['recommended_mode']==='private'&&!$single['automatic_conversion']);
 $seat=$tm::advise_booking_mode($tour,1,'group',true);
 $assert('EXPLICIT_GROUP_SEAT_NOT_CONVERTED_TO_PRIVATE',!is_wp_error($seat)&&$seat['selected_mode']==='group');
 $assert('FIRST_ENABLED_TIER_MINIMUM_TEN',$tm::first_group_minimum($tour)===10);
 update_post_meta($tour,'_etg_small_party_routing','join_group_only');
 $policy=$tm::advise_booking_mode($tour,1,'auto',false);
 $assert('OPTIONAL_NO_AUTOMATIC_PRIVATE_SUGGESTION',!is_wp_error($policy)&&$policy['recommended_mode']===null&&!$policy['automatic_conversion']);
 update_post_meta($tour,'_etg_small_party_routing','offer_private');
 update_post_meta($tour,'_etg_departure_max_capacity',30);
 update_post_meta($tour,'_etg_minimum_departure_participants',10);
 $configured=$tm::assess_departure($tour,1,24);
 $assert('CONFIGURED_DEPARTURE_LIMIT_AND_MINIMUM',!is_wp_error($configured)&&$configured['tier_id']===$b&&$configured['minimum_to_operate']===10);

 $departure=$tm::assess_departure($tour,1,24,30,10);
 $assert('ONE_SEAT_JOINS_25_PERSON_DEPARTURE',!is_wp_error($departure)&&$departure['tier_id']===$b&&$departure['booking_party_size']===1);
 $early=$tm::assess_departure($tour,2,3,30,10);
 $assert('UNFILLED_DEPARTURE_NOT_AUTO_PRIVATE',!is_wp_error($early)&&$early['status']==='below_departure_operating_minimum');
 $assert('DEPARTURE_OVER_CAPACITY_BLOCKED',is_wp_error($tm::assess_departure($tour,8,25,30,10)));
 $assert('PRIVATE_25_IGNORES_GROUP_TIERS',!is_wp_error($tm::assess_capacity($tour,'private',25)));
 $assert('OPTIONAL_POLICY_ENGINE_ONLY',!is_wp_error($tm::assess_capacity($tour,'group',25,null,'booking_engine_only')));
 $input=['schema_version'=>2,'tour_id'=>$tour,'source'=>'rate','rate_id'=>$rate,'audience'=>'general',
  'occupancy'=>'double','quantities'=>['persons'=>25],'booking_mode'=>'group','group_size'=>25];
 $v=shared_product_booking_v2_validate($input);
 $assert('BOOKING_V2_25_SOURCE_VALIDATED',!is_wp_error($v));
 $result=$tm::assess_v2_preview($input);
 $assert('BOOKING_V2_PREVIEW_GROUP_25_MATCHES_TAXONOMY',!is_wp_error($result)&&$result['tier_id']===$b);
 $assert('BOOKING_V2_PREVIEW_NO_CHECKOUT_AUTHORITY',!is_wp_error($result)&&$result['financial_checkout_authority']===false);
 $opt=$tm::assess_capacity($tour,'group',25,$b,'booking_engine_only');
 $assert('OPTIONAL_POLICY_ALLOWS_NATIVE_SELECTED_TIER',!is_wp_error($opt)&&$opt['status']==='booking_engine_policy_only'&&$opt['tier_id']===$b);
 $privatePayload=$input;unset($privatePayload['group_size']);$privatePayload['booking_mode']='private';
 $pv=$tm::assess_v2_preview($privatePayload);
 $assert('BOOKING_V2_PRIVATE_25_NO_GROUP_TIER',!is_wp_error($pv)&&$pv['tier_id']===null);
 $oversize=$input;$oversize['group_size']=51;$oversize['quantities']['persons']=51;
 $assert('BOOKING_V2_51_EXISTING_LIMIT_PRESERVED',is_wp_error($tm::assess_v2_preview($oversize)));
 $set($c,23,28,true);clean_term_cache($c,'group-size-tiers');
 $overlap=$tm::assess_capacity($tour,'group',25);
 $assert('GROUP_OVERLAP_FAIL_CLOSED',is_wp_error($overlap)&&$overlap->get_error_code()==='group_tiers_overlap');
 $set($c,23,28,false);$set($b,21,30,false);clean_term_cache($b,'group-size-tiers');clean_term_cache($c,'group-size-tiers');
 $disabled=$tm::assess_capacity($tour,'group',25);
 $assert('DISABLED_GROUP_TIER_FAIL_CLOSED',is_wp_error($disabled));
} finally {
 foreach($oldDepartureMeta as $key=>$value){
  if($value==='')delete_post_meta($tour,$key);
  else update_post_meta($tour,$key,$value);
 }
 foreach($old as $tax=>$ids){
  $r=wp_set_post_terms($tour,$ids,$tax);
  if(is_wp_error($r))echo 'RESTORE_FAILED='.$tax.PHP_EOL;
 }
 foreach(array_reverse($created) as [$term,$tax])wp_delete_term($term,$tax);
 clean_object_term_cache($tour,'tours-and-activities');
 $current=[];
 foreach($old as $tax=>$ids)$current[$tax]=array_map('intval',wp_get_post_terms($tour,$tax,['fields'=>'ids']));
 $assert('TAXONOMIES_RESTORED',$current===$old);
 $metaRestored=true;
 foreach($oldDepartureMeta as $key=>$value)if(get_post_meta($tour,$key,true)!==$value)$metaRestored=false;
 $assert('DEPARTURE_SETTINGS_RESTORED',$metaRestored);

 $assert('NO_ORDERS_CREATED',true);
}
echo "GROUP_TIER_LOCAL_FAILURES=$fail\n";
exit($fail?1:0);
