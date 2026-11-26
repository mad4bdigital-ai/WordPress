<?php
$boot=dirname(__DIR__,4).'/wp-load.php';
if(!defined('ABSPATH')){if(!is_file($boot))exit(2);require $boot;}
$checks=[];
$input=['schema_version'=>2,'tour_id'=>60629,'source'=>'rate','rate_id'=>60820,'travel_date'=>'2026-11-15','quantities'=>['persons'=>3]];
$native=shared_product_booking_v2_preview($input);
$registry=MAD4B_Pricing_Tour_Preview::preview($input);
$checks['positive_tour_parity']=is_array($native)&&is_array($registry)&&$native===$registry['quote']&&$registry['checkout_authorized']===false;
$invalid=$input;$invalid['rate_id']=999999;
$native=shared_product_booking_v2_preview($invalid);
$registry=MAD4B_Pricing_Tour_Preview::preview($invalid);
$checks['invalid_rate_same_error']=is_wp_error($native)&&is_wp_error($registry)&&$native->get_error_code()===$registry->get_error_code();
$invalid=$input;$invalid['client_price']=1;
$registry=MAD4B_Pricing_Tour_Preview::preview($invalid);
$checks['untrusted_input_denied']=is_wp_error($registry);
foreach($checks as $name=>$pass)echo $name.'='.($pass?'PASS':'FAIL')."\n";
echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks)."\n";
exit(in_array(false,$checks,true)?1:0);
