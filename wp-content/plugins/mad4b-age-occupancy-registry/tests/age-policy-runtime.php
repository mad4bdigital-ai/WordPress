<?php
$boot=dirname(__DIR__,4).'/wp-load.php';
if(!defined('ABSPATH')) {if(!is_file($boot))exit(2);require $boot;}
$checks=[];
$checks['empty_policy_no_price_mutation']=MAD4B_Age_Occupancy_Registry::policy()['pricing_authorized']===false;
$cb=static function($rules,$level){
 if($level==='global')return ['bands'=>[['id'=>'infant','min_age'=>0,'max_age'=>2],['id'=>'child','min_age'=>3,'max_age'=>11],['id'=>'adult','min_age'=>12,'max_age'=>120]]];
 return $rules;
};
add_filter('mad4b/age_occupancy/policy_level',$cb,10,2);
$result=MAD4B_Age_Occupancy_Registry::classify([1,8,35]);
$checks['classifications']=is_array($result)&&array_column($result['travellers'],'band')===['infant','child','adult'];
$checks['no_checkout_authority']=$result['pricing_authorized']===false;
$invalid=MAD4B_Age_Occupancy_Registry::classify([-1]);
$checks['invalid_age_rejected']=is_wp_error($invalid)&&$invalid->get_error_code()==='age_value_invalid';
remove_filter('mad4b/age_occupancy/policy_level',$cb,10);
$overlap=static function($rules,$level){if($level==='global')return ['bands'=>[['id'=>'child','min_age'=>0,'max_age'=>12],['id'=>'teen','min_age'=>12,'max_age'=>17]]];return $rules;};
add_filter('mad4b/age_occupancy/policy_level',$overlap,10,2);
$invalid=MAD4B_Age_Occupancy_Registry::policy();
$checks['overlap_rejected']=is_wp_error($invalid)&&$invalid->get_error_code()==='age_band_overlap';
remove_filter('mad4b/age_occupancy/policy_level',$overlap,10);
foreach($checks as $name=>$pass)echo $name.'='.($pass?'PASS':'FAIL')."\n";
echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks)."\n";
exit(in_array(false,$checks,true)?1:0);
