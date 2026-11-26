<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\TourRateEligibility as Rate;
global $wpdb;$pass=0;$fail=[];
$ok=static function($v,$label)use(&$pass,&$fail){$v?$pass++:$fail[]=$label;};
$before=[];
foreach(['posts','postmeta','wc_orders','woocommerce_order_items','etg_booking_links'] as $x){
 $t=$wpdb->prefix.$x;$before[$x]=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
}
$old=get_current_user_id();
try{
 wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
 $q=Rate::inspect(60645,61158,'2026-11-15');
 $ok(!is_wp_error($q)&&$q['tour_id']===60645&&$q['rate_id']===61158,'original_rate_relation');
 $ok(!is_wp_error($q)&&
    $q['relationship_evidence']==='related_tour_id+_etg_last_synced_tour_id'&&
    $q['relationship_commercially_certified']===false,
    'two_meta_fields_not_jet_relation_or_commercial_quote');
 $ok(!is_wp_error($q)&&$q['woocommerce_product_id']===60645&&$q['season_slug']==='winter',
    'cpt_product_id_and_winter_taxonomy');
 $ok(!is_wp_error($q)&&$q['rate_selection_eligible']===true&&$q['blockers']===[],
    'winter_date_inside_verified_rate_and_season');
 $ok(!is_wp_error($q)&&$q['price_approved']===false&&$q['order_item_creation_authorized']===false
    &&$q['supplier_booking_authorized']===false,'rate_eligibility_never_approves_quote');
 $noDate=Rate::inspect(60645,61158,null);
 $ok(!is_wp_error($noDate)&&in_array('travel_date_required',$noDate['blockers'],true),
    'missing_travel_date_fails_closed');
 $wrong=Rate::inspect(60645,61080,'2026-08-15');
 $ok(is_wp_error($wrong)&&$wrong->get_error_code()==='rate_tour_relation_mismatch',
    'other_tour_rate_rejected_even_matching_date');
 $late=Rate::inspect(60645,61158,'2026-09-15');
 $ok(!is_wp_error($late)&&in_array('outside_rate_window',$late['blockers'],true)&&
     in_array('outside_season_window',$late['blockers'],true),
     'wrong_season_and_rate_window_rejected');
 $originalSummer=Rate::inspect(60437,61080,'2026-08-15');
 $ok(!is_wp_error($originalSummer)&&$originalSummer['rate_selection_eligible']===true,
    'original_summer_rate_includes_mid_august');
 $partial=Rate::inspect(60437,66840,'2026-08-15');
 $ok(!is_wp_error($partial)&&in_array('stop_sale',$partial['blockers'],true)&&
    in_array('qa_fixture_not_for_sale',$partial['blockers'],true),
    'partial_qa_rate_is_visible_for_diagnostics_but_never_sellable');
 $partialOutside=Rate::inspect(60437,66840,'2026-08-09');
 $ok(!is_wp_error($partialOutside)&&
    in_array('outside_rate_window',$partialOutside['blockers'],true),
    'partial_rate_excludes_day_before_start');
 $ok(Rate::withinWindow('2026-08-10',1786320000,1787270399)&&
     Rate::withinWindow('2026-08-20',1786320000,1787270399)&&
     !Rate::withinWindow('2026-08-21',1786320000,1787270399),
    'inclusive_real_partial_rate_day_boundaries');
 $ok(Rate::withinSeason('2026-10-01',2736)&&
     Rate::withinSeason('2027-04-30',2736)&&
     !Rate::withinSeason('2027-05-01',2736),
    'cross_year_winter_season_october_to_april');
 $ok(Rate::withinSeason('2026-05-01',2731)&&
     Rate::withinSeason('2026-09-30',2731)&&
     !Rate::withinSeason('2026-10-01',2731),
    'summer_season_boundaries');
 $bad=Rate::inspect(60645,61158,'2026-02-30');
 $ok(is_wp_error($bad)&&$bad->get_error_code()==='rate_travel_date_invalid',
    'invalid_calendar_date_rejected');
 $ok(!Rate::withinWindow('2026-08-01',1800000000,1780000000)&&
     !Rate::withinWindow('malformed',1786320000,1787270399),
    'invalid_or_reverse_interval_fail_closed');
 wp_set_current_user(0);
 $guest=Rate::inspect(60645,61158,'2026-11-15');
 $ok(is_wp_error($guest)&&$guest->get_error_code()==='rate_operator_required',
    'source_rate_preflight_denies_guests');
}finally{wp_set_current_user($old);}
$after=[];
foreach(['posts','postmeta','wc_orders','woocommerce_order_items','etg_booking_links'] as $x){
 $t=$wpdb->prefix.$x;$after[$x]=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
}
$ok($before===$after,'all_existing_posts_rates_orders_untouched');
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','checks'=>$pass,'failed'=>$fail,
  'original_rate_id'=>61158,'partial_test_rate_id'=>66840,
  'no_prices_or_order_items_written'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($fail)exit(2);
