<?php
/**
 * Standalone, read-only, no-WordPress regression for canonical global pricing patterns.
 * Run: php tests/global-patterns-contract.php
 */
define('ABSPATH',__DIR__);
final class WP_Error {
 private $code;
 public function __construct($code){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function sanitize_key($value){return strtolower((string)preg_replace('/[^a-z0-9_\-]/','',(string)$value));}
function add_action($tag,$callback){return true;}
function add_filter($tag,$callback,$priority=10,$accepted=1){return true;}
function maybe_unserialize($value){return $value;}

require dirname(__DIR__).'/includes/admin.php';
require dirname(__DIR__).'/includes/jetengine-policy-bridge.php';

$checks=[];
$assert=static function($name,$condition)use(&$checks){$checks[$name]=(bool)$condition;};
$base=['age_reference'=>'service_start','age_pricing_enabled'=>1,'count_in_capacity'=>1,
 'bands'=>[['id'=>'child','min_age'=>'3','max_age'=>'17','price_factor'=>'0.5','count_in_capacity'=>1]]];
$legacy=MAD4B_Age_Occupancy_Admin::validate($base);
$assert('legacy_factor_unchanged',!is_wp_error($legacy)&&$legacy['bands'][0]['price_factor']===0.5);
$p=$base;$p['bands'][0]['price_mode']='percentage';$p['bands'][0]['price_percent']='37.5';
$r=MAD4B_Age_Occupancy_Admin::validate($p);
$assert('percentage_converted',!is_wp_error($r)&&$r['bands'][0]['price_factor']===0.375);
$p['bands'][0]['price_percent']='200';
$r=MAD4B_Age_Occupancy_Admin::validate($p);
$assert('maximum_percentage',!is_wp_error($r)&&$r['bands'][0]['price_factor']===2.0);
$p['bands'][0]['price_percent']='201';
$r=MAD4B_Age_Occupancy_Admin::validate($p);
$assert('reject_over_max',is_wp_error($r)&&$r->get_error_code()==='age_policy_percentage_invalid');
$p['bands'][0]['price_percent']='INF';
$r=MAD4B_Age_Occupancy_Admin::validate($p);
$assert('reject_nonfinite',is_wp_error($r));
foreach(['free'=>0.0,'adult'=>1.0] as $mode=>$expect){
 $p=$base;$p['bands'][0]['price_mode']=$mode;
 $r=MAD4B_Age_Occupancy_Admin::validate($p);
 $assert('preset_'.$mode,!is_wp_error($r)&&$r['bands'][0]['price_factor']===$expect);
}
$p=$base;$p['bands'][0]['price_mode']='multiplier';$p['bands'][0]['price_factor']='1.25';
$r=MAD4B_Age_Occupancy_Admin::validate($p);
$assert('advanced_factor',!is_wp_error($r)&&$r['bands'][0]['price_factor']===1.25);
$p['bands'][0]['price_mode']='php_eval';
$r=MAD4B_Age_Occupancy_Admin::validate($p);
$assert('no_executable_formula',is_wp_error($r)&&$r->get_error_code()==='age_policy_price_mode_invalid');
$assert('jetengine_price_page',MAD4B_Age_Occupancy_JetEngine_Bridge::is_pricing_page(['slug'=>'price-calculation','name'=>'Price Calculation']));
$assert('jetengine_financial_fields',MAD4B_Age_Occupancy_JetEngine_Bridge::is_pricing_page(['slug'=>'custom','meta_fields'=>[['name'=>'shared_mode_policy_rows']]]));
$assert('jetengine_unrelated_page_excluded',!MAD4B_Age_Occupancy_JetEngine_Bridge::is_pricing_page(['slug'=>'destinations','name'=>'Destinations']));
// No global age bands: supplied ages must not suppress the established Rate price.
class MAD4B_Age_Occupancy_Registry {
 public static function policy($context=[]){return ['version'=>'age-occupancy.v1','policy'=>['bands'=>[]]];}
}
require dirname(__DIR__).'/includes/age-aware-stay.php';
$noPolicy=MAD4B_Age_Aware_Stay::preview(['child_ages'=>[7]],0,0,99,2,1,1);
$checks['no_policy_does_not_block_legacy_price']=$noPolicy===null;
$badAges=MAD4B_Age_Aware_Stay::preview(['child_ages'=>[7,8]],0,0,99,2,1,1);
$checks['no_policy_still_validates_count']=is_wp_error($badAges)&&$badAges->get_error_code()==='stay_age_count_mismatch';
$badAge=MAD4B_Age_Aware_Stay::preview(['child_ages'=>[-1]],0,0,99,2,1,1);
$checks['no_policy_still_validates_age']=is_wp_error($badAge)&&$badAge->get_error_code()==='stay_age_value_invalid';
$multiple=MAD4B_Age_Aware_Stay::preview(['room_occupancy'=>[
 ['children'=>1,'child_ages'=>[2]],['children'=>1,'child_ages'=>[7]]
]],0,0,99,4,2,2);
$checks['no_policy_multir_room_fallback']=$multiple===null;
$wrongMultiple=MAD4B_Age_Aware_Stay::preview(['room_occupancy'=>[
 ['children'=>1,'child_ages'=>[2]],['children'=>1,'child_ages'=>[]]
]],0,0,99,4,2,2);
$checks['no_policy_multir_room_mismatch_rejected']=is_wp_error($wrongMultiple)
 &&$wrongMultiple->get_error_code()==='stay_age_assignment_mismatch';
foreach($checks as $name=>$pass)echo $name.'='.($pass?'PASS':'FAIL').PHP_EOL;
echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
exit(in_array(false,$checks,true)?1:0);
