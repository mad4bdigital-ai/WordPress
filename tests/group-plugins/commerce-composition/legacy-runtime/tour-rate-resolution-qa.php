<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\TourRateResolution as Resolver;
global $wpdb;$n=0;$fail=[];
$ok=static function($v,$name)use(&$n,&$fail){$v?$n++:$fail[]=$name;};
$count=static fn($t)=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$t");
$before=['posts'=>$count('posts'),'postmeta'=>$count('postmeta'),
 'wc_orders'=>$count('wc_orders'),'woocommerce_order_items'=>$count('woocommerce_order_items')];
$old=get_current_user_id();
try{
 wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
 $winter=Resolver::inspect(60645,'2026-11-15');
 $ok(!is_wp_error($winter)&&$winter['status']==='explicit_rate_selection_required'&&
     count($winter['eligible_rate_ids'])===2,'winter_multiple_eligible_requires_selection');
 $ok(!is_wp_error($winter)&&in_array(61148,$winter['eligible_rate_ids'],true)&&
     in_array(61158,$winter['eligible_rate_ids'],true),'winter_two_distinct_rates');
 $select=Resolver::inspect(60645,'2026-11-15',61158);
 $ok(!is_wp_error($select)&&$select['selected_rate_id']===61158&&
    $select['selection_source']==='original_saved_request'&&
    $select['status']==='explicit_eligible_rate','saved_rate_determines_selected');
 $ok(!is_wp_error($select)&&!$select['pricing_quote_certified']&&
    !$select['order_item_creation_authorized']&&
    !$select['supplier_booking_authorized'],'eligible_rate_never_auto_authorizes_quote_or_booking');
 $other=Resolver::inspect(60645,'2026-11-15',61080);
 $ok(is_wp_error($other)&&$other->get_error_code()==='rate_resolution_selected_not_for_tour',
    'cross_tour_selected_rate_rejected');
 $summer=Resolver::inspect(60645,'2026-08-15');
 $ok(!is_wp_error($summer)&&count($summer['eligible_rate_ids'])===2&&
    in_array(61128,$summer['eligible_rate_ids'],true)&&
    in_array(61138,$summer['eligible_rate_ids'],true),
    'summer_multiple_prices_same_package_never_choose_first');
 $wrongSeason=Resolver::inspect(60645,'2026-08-15',61158);
 $ok(is_wp_error($wrongSeason)&&
    $wrongSeason->get_error_code()==='rate_resolution_selected_not_eligible',
    'winter_rate_cannot_be_selected_for_summer_date');
 $qa=Resolver::inspect(60437,'2026-08-15',66840);
 $ok(is_wp_error($qa)&&$qa->get_error_code()==='rate_resolution_selected_not_eligible',
    'blocked_qa_partial_rate_cannot_override_flag');
 $otherTour=Resolver::inspect(60437,'2026-08-15');
 $ok(!is_wp_error($otherTour)&&in_array(61080,$otherTour['eligible_rate_ids'],true)&&
    !in_array(66840,$otherTour['eligible_rate_ids'],true),
    'stopped_qa_rate_excluded_from_eligible_candidates');
 $absent=Resolver::inspect(60645,'2028-06-15');
 $ok(!is_wp_error($absent)&&$absent['status']==='no_eligible_rate'&&
    $absent['selected_rate_id']===null,'no_valid_rate_no_fake_approval');
 $invalid=Resolver::inspect(60645,'2026-02-30',61158);
 $ok(is_wp_error($invalid)&&$invalid->get_error_code()==='rate_resolution_selected_not_eligible',
     'invalid_date_cannot_approve_saved_rate');
 wp_set_current_user(0);
 $anon=Resolver::inspect(60645,'2026-11-15',61158);
 $ok(is_wp_error($anon)&&$anon->get_error_code()==='rate_resolution_operator_required',
   'unauthorized_caller_no_rate_resolver');
}finally{wp_set_current_user($old);}
$after=['posts'=>$count('posts'),'postmeta'=>$count('postmeta'),
 'wc_orders'=>$count('wc_orders'),'woocommerce_order_items'=>$count('woocommerce_order_items')];
$ok($before===$after,'read_only_resolver_preserves_orders_and_rates');
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','checks'=>$n,'failed'=>$fail,
 'original_tour'=>60645,'winter_rates'=>[61148,61158],
 'order_items_written'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($fail)exit(2);
