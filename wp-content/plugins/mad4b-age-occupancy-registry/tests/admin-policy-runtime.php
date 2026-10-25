<?php
define('WP_ADMIN',true);
require '/var/www/html/wp-load.php';
$checks=[];
$good=['age_reference'=>'checkin','age_pricing_enabled'=>1,'count_in_capacity'=>1,'bands'=>[['id'=>'infant','min_age'=>'0','max_age'=>'2','price_factor'=>'0','count_in_capacity'=>0],['id'=>'child','min_age'=>'3','max_age'=>'11','price_factor'=>'0.5','count_in_capacity'=>1]]];
$valid=MAD4B_Age_Occupancy_Admin::validate($good);
$checks['valid_admin_policy']=!is_wp_error($valid)&&count($valid['bands'])===2;
$checks['infant_free_capacity_rule']=!is_wp_error($valid)&&$valid['bands'][0]['price_factor']===0.0&&$valid['bands'][0]['count_in_capacity']===false;
$bad=$good;$bad['bands'][1]['min_age']='2';$out=MAD4B_Age_Occupancy_Admin::validate($bad);
$checks['overlap_rejected']=is_wp_error($out)&&$out->get_error_code()==='age_band_overlap';
$bad=$good;$bad['bands'][1]['price_factor']='-2';$out=MAD4B_Age_Occupancy_Admin::validate($bad);
$checks['negative_price_factor_rejected']=is_wp_error($out)&&$out->get_error_code()==='age_policy_price_factor_invalid';
$checks['admin_menu_registered']=has_action('admin_menu',[MAD4B_Age_Occupancy_Admin::class,'menu'])!==false;
$checks['admin_save_endpoint_registered']=has_action('admin_post_mad4b_age_policy_save',[MAD4B_Age_Occupancy_Admin::class,'save'])!==false;
foreach($checks as $name=>$ok)echo $name.'='.($ok?'PASS':'FAIL').PHP_EOL;
echo 'SUMMARY='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
exit(in_array(false,$checks,true)?1:0);
