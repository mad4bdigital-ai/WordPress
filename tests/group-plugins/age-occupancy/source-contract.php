<?php
// Isolated WordPress API fixtures; never load a site, database or network.
define('ABSPATH', __DIR__); define('HOUR_IN_SECONDS',3600);
class WP_Error {public function __construct(private string $code){} public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function is_admin(){return false;}
function add_action(...$a){}
function add_filter($n,$fn,$priority=10,$args=1){$GLOBALS['filters'][$n][$priority][]=[$fn,$args];}
function apply_filters($n,$value,...$args){$group=$GLOBALS['filters'][$n]??[];ksort($group);foreach($group as $rows)foreach($rows as [$fn,$count])$value=$fn(...array_slice([$value,...$args],0,$count));return $value;}
function get_option($key,$default=false){return $GLOBALS['opts'][$key]??$default;}
function get_post_meta($id,$key,$single=false){return $GLOBALS['meta'][$id][$key]??'';}
function absint($v){return abs((int)$v);}
function wp_json_encode($v){return json_encode($v);}
function get_post($id){return $id===11?(object)['post_type'=>'tours-and-activities','post_status'=>'publish','post_password'=>'']:null;}
function wp_salt($scheme){return 'hermetic-fixture-only';}
require dirname(__DIR__,3).'/wp-content/plugins/mad4b-age-occupancy-registry/mad4b-age-occupancy-registry.php';
$n=0;function check($ok,$msg){global $n;if(!$ok)throw new RuntimeException($msg);++$n;}
$c=MAD4B_Age_Occupancy_Registry::class;$f=MAD4B_Age_Fluent_Forms::class;
foreach([0,2,120,'1.25',2.5] as $a)check(!is_wp_error($c::normalize_age($a)),'valid decimal age');
foreach([-1,121,true,'1e1','2.345','01',[]] as $a)check(is_wp_error($c::normalize_age($a)),'invalid age rejected');
$GLOBALS['opts']['mad4b_age_occupancy_policy_global_v1']=['bands'=>[['id'=>'infant','min_age'=>0,'max_age'=>2.99],['id'=>'child','min_age'=>3,'max_age'=>11.99],['id'=>'adult','min_age'=>12,'max_age'=>120]]];
check(array_column($c::classify([1.25,7.5,35])['travellers'],'band')===['infant','child','adult'],'decimal classification');
$GLOBALS['opts'][$f::OPTION]=[51=>['children'=>'children','infants'=>'infants','strict'=>true,'context_mode'=>'collect_only']];
$input=['children'=>'1','infants'=>'1'];$raw=$input+['mad4b_age_contract'=>$f::VERSION,'mad4b_child_ages'=>['7.5'],'mad4b_infant_ages'=>['1.25']];
$r=$f::receipt(51,$input,$raw);check(is_array($r)&&$r['child_ages']===[7.5,1.25]&&$r['pricing_authorized']===false,'real form receipt');
$ctx=['domain'=>'tour','product_id'=>11];$e=$c::booking_evidence($r,$ctx,3);check(is_array($e)&&$e['age_pricing_status']==='not_certified','form to booking evidence');
check($c::verify_booking_evidence($e,$ctx,3),'exact evidence verification');
$bad=$e;$bad['child_ages']=[8,1.25];check(!$c::verify_booking_evidence($bad,$ctx,3),'tamper rejected');
$bad=$r;$bad['pricing_authorized']=true;check(is_wp_error($c::booking_evidence($bad,$ctx,3)),'pricing authority denied');
$bad=$r;$bad['child_ages']=[1.25,7.5];check(is_wp_error($c::booking_evidence($bad,$ctx,3)),'infant mismatch rejected');
$bad=$r;$bad['untrusted_price']=1;check(is_wp_error($c::booking_evidence($bad,$ctx,3)),'unknown field rejected');
$bad=$r;$bad['classification_status']='approved';$v=$c::booking_evidence($bad,$ctx,3);check($v===$e,'form metadata cannot change authority');
check(is_wp_error($c::booking_evidence($r,$ctx,1)),'person count enforced');
check(is_wp_error($f::receipt(51,$input,array_replace($raw,['mad4b_child_ages'=>[]]))),'missing age rejected');
$GLOBALS['meta'][77][MAD4B_Age_Aware_Stay::META]=['age_reference'=>'checkin'];check($c::policy(['rate_id'=>77])['policy']['age_reference']==='checkin','rate policy precedence');
echo "AGE_OCCUPANCY_SOURCE_CONTRACT: PASS $n\n";
