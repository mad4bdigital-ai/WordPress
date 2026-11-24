<?php
/** Fail-closed, deterministic source-cost candidate selection. */
define('ABSPATH','/');
$GLOBALS['passes']=0;
function add_filter($name,$callback,$priority=10,$argc=1){return true;}
function verify($truth,$message){$GLOBALS['passes']++;if(!$truth)throw new RuntimeException($message);}
require __DIR__.'/../wp-content/mu-plugins/mad4b-package-hotel-stage-cost-preview-v1.php';
$c='MAD4B_Package_Hotel_Stage_Cost_Preview_V1';
$valid=[
 ['property_id'=>301,'rate_id'=>2001,'preliminary_cost'=>150.0],
 ['property_id'=>299,'rate_id'=>2002,'preliminary_cost'=>100.0],
 ['property_id'=>302,'rate_id'=>2003,'preliminary_cost'=>125.0],
];
verify(($c::cheapestPricingDefault($valid)['property_id']??null)===299,'lowest regardless of source order');
verify(($c::cheapestPricingDefault(array_reverse($valid))['property_id']??null)===299,'reverse order same');
verify($c::cheapestPricingDefault([])===null,'empty never selects');
verify($c::cheapestPricingDefault([['property_id'=>1,'rate_id'=>2,'preliminary_cost'=>0]])===null,'zero cost excluded');
verify($c::cheapestPricingDefault([['property_id'=>1,'rate_id'=>2,'preliminary_cost'=>-1]])===null,'negative cost excluded');
verify($c::cheapestPricingDefault([['property_id'=>1,'rate_id'=>2,'preliminary_cost'=>'NaN']])===null,'non-numeric cost excluded');
verify($c::cheapestPricingDefault([['property_id'=>0,'rate_id'=>2,'preliminary_cost'=>1]])===null,'invalid property excluded');
verify($c::cheapestPricingDefault([['property_id'=>1,'rate_id'=>0,'preliminary_cost'=>1]])===null,'invalid rate excluded');
verify(($c::cheapestPricingDefault(array_merge($valid,[['property_id'=>11,'rate_id'=>9,'preliminary_cost'=>100.0]]))['property_id']??null)===11,'equal price tie uses stable property ID');
verify(($c::cheapestPricingDefault(array_merge($valid,[['property_id'=>299,'rate_id'=>1999,'preliminary_cost'=>100.0]]))['rate_id']??null)===1999,'equal property tie uses stable rate ID');
verify(($c::cheapestPricingDefault(array_merge($valid,[['property_id'=>10,'rate_id'=>20,'preliminary_cost'=>INF]]))['property_id']??null)===299,'infinite cost excluded');
verify(($c::cheapestPricingDefault(array_merge($valid,[['property_id'=>10,'rate_id'=>20,'preliminary_cost'=>null]]))['property_id']??null)===299,'null cost excluded');
echo 'PASS '.$GLOBALS['passes'].'/'.$GLOBALS['passes'].' cheapest eligible cost reference selection'.PHP_EOL;
