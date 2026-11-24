<?php
/** A company policy/source basis event must wake existing planner only. */
define('ABSPATH','/');
$GLOBALS['hooks']=[];$GLOBALS['passed']=0;
function add_action($hook,$callback,$priority=10,$argc=1){$GLOBALS['hooks'][$hook][]=$callback;return true;}
function mad4b_rate_authoring_enabled(){return $GLOBALS['authoring']??true;}
function check($condition,$label){$GLOBALS['passed']++;if(!$condition)throw new RuntimeException($label);}
class MAD4B_Rate_Planner{public static int $runs=0;public static function schedule(){self::$runs++;}}
require __DIR__.'/../wp-content/mu-plugins/mad4b-rate-plan-company-policy-recheck-v1.php';
$c='MAD4B_Rate_Plan_Company_Policy_Recheck_V1';
check(count($GLOBALS['hooks'])===3,'exact three native option hooks');
check($c::relevant('mad4b_company_policy_registry_v2'),'global profit registry included');
check($c::relevant('mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1'),'source basis included');
check($c::relevant('mad4b_guide_daily_base_cost_v1'),'original guide cost dependency included');
check(!$c::relevant('mad4b_rate_authoring_enabled'),'no unrelated feature flag edits');
check(!$c::relevant('commerce_request_review_board'),'existing independent workflow retained');
$c::updated('mad4b_company_policy_registry_v2',['x'=>1],['x'=>1]);
check(MAD4B_Rate_Planner::$runs===0,'unchanged policy no schedule');
$c::updated('mad4b_company_policy_registry_v2',['x'=>1],['x'=>2]);
check(MAD4B_Rate_Planner::$runs===1,'profit change wakes original planner');
$c::added('mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1',[]);
check(MAD4B_Rate_Planner::$runs===2,'new cost basis wakes original planner');
$c::deleted('mad4b_company_policy_registry_v2');
check(MAD4B_Rate_Planner::$runs===3,'missing profit policy wakes original planner for fail closed');
$c::updated('mad4b_guide_daily_base_cost_v1',['en'=>100],['en'=>120]);
check(MAD4B_Rate_Planner::$runs===4,'guide unit cost change wakes original planner');
$c::updated('unrelated_option',1,2);
check(MAD4B_Rate_Planner::$runs===4,'unrelated option unchanged');
$GLOBALS['authoring']=false;
check($c::queue('mad4b_company_policy_registry_v2')===false,'existing authoring disable remains authoritative');
check(MAD4B_Rate_Planner::$runs===4,'disabled existing planner not bypassed');
$GLOBALS['authoring']=true;
check($c::queue('mad4b_company_policy_registry_v2')===true,'original scheduler reused');
check(MAD4B_Rate_Planner::$runs===5,'delegation only no independent cron');
echo 'PASS '.$GLOBALS['passed'].'/'.$GLOBALS['passed'].' existing rate planner policy dependency bridge'.PHP_EOL;
