<?php
// Standalone behavioral contract; no WordPress bootstrap or database writes.
if(PHP_SAPI!=='cli'||$argc!==3)exit(2);
define('ABSPATH','/isolated/');
function add_action(...$args){}
function admin_url($path){return 'https://example.invalid/wp-admin/'.$path;}
function add_query_arg($k,$v,$url){return $url.(str_contains($url,'?')?'&':'?').rawurlencode($k).'='.rawurlencode($v);}
require $argv[1].'/includes/class-mad4b-scp-content-readiness-alerts.php';
require $argv[2].'/mad4b-generic-cost-composition-v1.php';
require $argv[2].'/mad4b-dynamic-cost-alerts-v1.php';
function check($ok,$label){if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(3);}echo "PASS $label\n";}
$m=new ReflectionMethod('MAD4B_SCP_Content_Readiness_Alerts','rule_enabled_for');
$type='tours-and-activities';
foreach([[],['complete'=>true],['coverage_complete'=>true]] as $i=>$scope)
 check($m->invoke(null,['source'=>'mad4b-dynamic-cost-readiness'],$scope)===false,'cost preservation scope '.$i);
check($m->invoke(null,['source'=>'native','post_type'=>$type,'code'=>'origin_missing'],['post_types'=>[$type],'origin_fields'=>[$type=>'origin']])===true,'ordinary origin rule remains enabled');
check($m->invoke(null,['source'=>'mad4b-rate-planner'],[])===false,'planner remains source-scoped');
$c='MAD4B_Dynamic_Cost_Alerts_V1';
check($c::costLink(['source'=>'mad4b-dynamic-cost-readiness'])===true,'dynamic cost link');
check($c::costLink(['source'=>'mad4b-package-cost-readiness'])===true,'package cost link');
check($c::costLink(['source'=>'mad4b-rate-planner','code'=>'rate_plan_price_missing_abc'])===true,'pricing finding link');
check($c::costLink(['source'=>'unknown','code'=>'rate_plan_price_missing_abc'])===false,'unrelated source excluded');
check($c::costLink(['source'=>'mad4b-rate-planner','code'=>'rate_plan_unrelated_abc'])===false,'unrelated rate finding excluded');
$url=$c::url(57562,['currency'=>'EUR','adults'=>2,'date'=>'2027-01-03','untrusted'=>'x']);
parse_str(parse_url($url,PHP_URL_QUERY),$q);
check(($q['page']??'')==='mad4b-product-cost-review'&&($q['product_id']??'')==='57562','exact product review route');
check(($q['currency']??'')==='EUR'&&($q['adults']??'')==='2'&&($q['date']??'')==='2027-01-03','context retained');
check(!isset($q['untrusted']),'unrecognized context excluded');

