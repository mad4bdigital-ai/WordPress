<?php
define('ABSPATH','/tmp');
class WP_Error {private $code; public function __construct($code,$message='',$data=[]){$this->code=$code;} public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function get_post_type($id){return $id===501?'tours-and-activities':($id===601?'properties':($id===602?'units':($id<300?'tour-rates':'property-rates')));}
function get_post_status($id){return in_array($id,[501,601,602,101,102,301,302],true)?'publish':'draft';}
function get_post_meta($id,$key,$single=true){
    if($key==='related_tour_id')return 501;
    if($key==='prop_related_property_id')return 601;
    if($key==='prop_related_unit_id')return 602;
    if($key==='stop_sale')return (string)($GLOBALS['stops'][$id]??'');
    if($key==='avilabile_status')return '1';
    if($key==='related_package_term_id')return $id===101?12:0;
    if($key==='_shared_generation_season_term_id')return $id===101?33:0;
    return '';
}
function get_posts($q){
    if($q['post_type']==='tour-rates')return $GLOBALS['tour_rates'];
    if($q['post_type']==='property-rates')return $GLOBALS['hotel_rates'];
    return [];
}
function wp_get_post_terms($id,$tax,$opts=[]){
    if($tax==='programs')return [99];
    if($tax==='season')return [];
    return [];
}
function shared_product_booking_v2_rate_covers_date($id,$date){return !in_array($id,$GLOBALS['out_of_date']??[],true);}
function shared_product_booking_v2_validate($p){return $p;}
function shared_product_booking_v2_preview($p){
    $id=(int)$p['rate_id'];
    if(in_array($id,$GLOBALS['native_fail']??[],true))return new WP_Error('native_rejected');
    return ['total'=>($id===101?100:120),'currency'=>'USD','checkout_ready'=>false];
}
function apply_filters($name,$value){return $value;}
function abk_accommodation_shadow_quote($p){
    if(in_array((int)$p['rate_id'],$GLOBALS['native_fail']??[],true))return new WP_Error('provider_rejected');
    return ['total'=>($p['rate_id']===301?250:350),'currency'=>'USD','checkout_ready'=>false];
}
function do_action($name,...$args){}
class MAD4B_Company_Policy_Registry_V2 {
  public static function pricing($tour,$hotel,$rate=0,$program=0,$season=0) {return ['strategy'=>'hybrid','hotel_component_strategy'=>'dynamic_hotel_rates','decision_receipts'=>[]];}
  public static function resolve($policy,$context=[],$defaults=[]) {
    return ['values'=>['mode'=>$GLOBALS['rate_choice_mode']??'unambiguous_only'],'receipt'=>['rule_id'=>'fixture']];
  }
}
$selector=getenv('MAD4B_TEST_POLICY_SELECTOR')
    ?:dirname(__DIR__).'/wp-content/mu-plugins/mad4b-company-pricing-native-selection-v2.php';
require $selector;
function run_case($title,$expected,$result){
    $actual=is_wp_error($result)?$result->get_error_code():($result['rate_id']??'unexpected');
    if((string)$actual!==(string)$expected)throw new Exception($title.': expected '.$expected.', got '.$actual);
    echo 'PASS '.$title.' '.$actual."\n";
}
$base=['schema_version'=>2,'tour_id'=>501,'source'=>'rate','travel_date'=>'2026-11-07',
       'audience'=>'general','occupancy'=>'double','quantities'=>['persons'=>2],
       'extras'=>[],'selections'=>[],'booking_intent'=>'quote'];
$GLOBALS['tour_rates']=[101];$GLOBALS['hotel_rates']=[301];$GLOBALS['stops']=[];
run_case('unique native rate',101,mad4b_policy_auto_rate_select_v2($base));
$GLOBALS['tour_rates']=[101,102];
run_case('equal candidates ambiguous','mad4b_rate_ambiguous',mad4b_policy_auto_rate_select_v2($base));
$GLOBALS['rate_choice_mode']='lowest_verified';
run_case('lowest audited native quote',101,mad4b_policy_auto_rate_select_v2($base));
$GLOBALS['rate_choice_mode']='highest_verified';
run_case('highest audited native quote',102,mad4b_policy_auto_rate_select_v2($base));
$GLOBALS['rate_choice_mode']='unambiguous_only';
$GLOBALS['native_fail']=[102];
run_case('native rejection excludes invalid',101,mad4b_policy_auto_rate_select_v2($base));
$GLOBALS['native_fail']=[];
$book=$base;$book['booking_intent']='instant';
run_case('booking requires explicit rate','mad4b_rate_explicit_required_for_booking',mad4b_policy_auto_rate_select_v2($book));
$GLOBALS['tour_rates']=[102];$GLOBALS['out_of_date']=[102];
run_case('invalid date refuses','mad4b_rate_no_eligible_candidate',mad4b_policy_auto_rate_select_v2($base));
$GLOBALS['tour_rates']=[101];$GLOBALS['out_of_date']=[];
$hotel=['property_id'=>601,'unit_id'=>602,'checkin'=>'2026-11-07','checkout'=>'2026-11-08','rooms'=>1,'adults'=>2,'children'=>0];
run_case('hotel component native quote',301,mad4b_policy_hotel_rate_select_v2($hotel));
$GLOBALS['hotel_rates']=[301,302];
run_case('hotel ambiguity','mad4b_hotel_rate_ambiguous',mad4b_policy_hotel_rate_select_v2($hotel));
$GLOBALS['rate_choice_mode']='lowest_verified';
run_case('lowest native hotel component',301,mad4b_policy_hotel_rate_select_v2($hotel));
$GLOBALS['rate_choice_mode']='highest_verified';
run_case('highest native hotel component',302,mad4b_policy_hotel_rate_select_v2($hotel));
$GLOBALS['rate_choice_mode']='unambiguous_only';
$bad=$hotel;$bad['checkout']='2026-11-07';
run_case('invalid stay date','mad4b_hotel_context_invalid',mad4b_policy_hotel_rate_select_v2($bad));
echo "PASS 12 TESTS\n";
