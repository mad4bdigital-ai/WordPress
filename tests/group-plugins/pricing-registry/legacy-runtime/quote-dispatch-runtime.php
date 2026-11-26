<?php
$boot = dirname(__DIR__,4).'/wp-load.php';
if (!defined('ABSPATH')) { if (!is_file($boot)) exit(2); require $boot; }
$checks=[];
$a=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',['rate_id'=>0]);
$b=\BookingExperience\unified_accommodation_preview(['rate_id'=>0]);
$checks['stay_provider_parity']=is_wp_error($a)&&is_wp_error($b)&&$a->get_error_code()===$b->get_error_code();
$a=MAD4B_Pricing_Quote_Dispatch::preview('transport_direct',['transport_id'=>0]);
$b=\BookingExperience\unified_transport_preview(0);
$checks['transport_provider_parity']=is_wp_error($a)&&is_wp_error($b)&&$a->get_error_code()===$b->get_error_code();
$r=MAD4B_Pricing_Quote_Dispatch::preview('unknown_source',[]);
$checks['unknown_denied']=is_wp_error($r)&&$r->get_error_code()==='pricing_registry_source_unavailable';
$r=MAD4B_Pricing_Quote_Dispatch::preview('transport_direct',['transport_id'=>0,'untrusted_price'=>10]);
$checks['untrusted_price_denied']=is_wp_error($r)&&$r->get_error_code()==='pricing_registry_unknown_input';

// Positive, environment-neutral parity: use existing published rate data, never create fixtures.
$rate_ids=get_posts(['post_type'=>'property-rates','post_status'=>'publish','fields'=>'ids','posts_per_page'=>3,'orderby'=>'ID','order'=>'ASC']);
foreach($rate_ids as $rate_id){
 $input=['rate_id'=>(int)$rate_id,'checkin'=>'2026-12-01','checkout'=>'2026-12-03','rooms'=>1,'adults'=>2,'children'=>0];
 $original=\BookingExperience\unified_accommodation_preview($input);
 $dispatched=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',$input);
 if(is_wp_error($original)||is_wp_error($dispatched)){
  $same=is_wp_error($original)&&is_wp_error($dispatched)&&$original->get_error_code()===$dispatched->get_error_code();
 }else{
  $same=is_array($original)&&is_array($dispatched)&&isset($original['preview_total'],$dispatched['quote']['preview_total'])
   &&abs((float)$original['preview_total']-(float)$dispatched['quote']['preview_total'])<0.001
   &&($dispatched['checkout_authorized']??null)===false;
 }
 $checks['published_rate_'.(int)$rate_id]=$same;
}
if(!$rate_ids)echo "PUBLISHED_RATE_PARITY=SKIP (no published rates)\n";
foreach ($checks as $key=>$pass) echo $key.'='.($pass?'PASS':'FAIL')."\n";
echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks)."\n";
exit(in_array(false,$checks,true)?1:0);
