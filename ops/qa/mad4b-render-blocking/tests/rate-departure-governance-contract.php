<?php
define('ABSPATH','/');
$rate=['related_tour_id'=>801,'show_rate_departure_details'=>'0',
 'related_day_of_week_term_id'=>234,'related_day_time_term_id'=>236,
 'related_origin_destination_term_id'=>2306];
$tour=['tour_origin_location_term'=>123,'tour_origin_address'=>''];
function add_action(...$args){}function add_filter(...$args){}
function wp_get_environment_type(){return 'staging';}
function wp_parse_url($value,$part){return parse_url($value,$part);}
function home_url(){return 'https://tours.dev.mad4b.com';}
function get_option($name,$default=[]){return $name==='mad4b_scp_brand_context_profile_v1'?['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a']:$default;}
function get_post_type($id){return $id===701?'tour-rates':($id===801?'tours-and-activities':null);}
function get_post_status($id){return in_array($id,[701,801])?'publish':'draft';}
function get_post_meta($id,$key,$one=true){global $rate,$tour;return $id===701?($rate[$key]??''):($id===801?($tour[$key]??''):'');}
function get_term($id,$tax=null){$data=[
 123=>['name'=>'Cairo','taxonomy'=>'destinations','parent'=>2305],
 2306=>['name'=>'Africa','taxonomy'=>'destinations','parent'=>0],
 234=>['name'=>'Friday','taxonomy'=>'schudle-days-of-weak','parent'=>0],
 236=>['name'=>'Afternoon','taxonomy'=>'day-times','parent'=>0]
 ];if(!isset($data[$id]))return null;return (object)(['term_id'=>(int)$id]+$data[$id]);}
function get_term_meta($id,$key,$single=true){return $id===2306?'continent':($id===123?'state_governorate':'');}
function is_wp_error($o){return false;}
function is_admin(){return false;}
function apply_filters($name,$value,...$rest){if($name==='wpml_current_language')return 'en';if($name==='wpml_object_id')return $value;return $value;}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function wp_strip_all_tags($s){return strip_tags($s);}
class FakeRate {public $ID=701;}
class FakeListData {function get_current_object(){return new FakeRate();}}
function jet_engine(){return (object)['listings'=>(object)['data'=>new FakeListData()]];}
class FakeWidget {private $id;function __construct($id){$this->id=$id;}function get_id(){return $this->id;}}
require dirname(__DIR__,4).'/wp-content/mu-plugins/313-mad4b-rate-departure-governance.php';
function assert_ok($cond,$reason){if(!$cond){fwrite(STDERR,'FAIL '.$reason."\n");exit(1);}echo 'PASS '.$reason."\n";}
$source='<div class="jet-listing-dynamic-field__content">Africa</div>';
assert_ok(MAD4B_Rate_Departure_Details_V1::current_rate()===701,'resolve linked JetEngine Rate');
assert_ok(MAD4B_Rate_Departure_Details_V1::valid_origin_term(2306)===null,'continent fails canonical origin check');
assert_ok(MAD4B_Rate_Departure_Details_V1::origin(701)==='Cairo','Origin resolves from Tour meta, not Rate continent');
assert_ok(!MAD4B_Rate_Departure_Details_V1::is_enabled(701),'toggle defaults to OFF');
foreach(array_keys(MAD4B_Rate_Departure_Details_V1::WIDGETS) as $id){
 assert_ok(MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeWidget($id))==='','unconfirmed departure widget suppressed '.$id);
}
class FakeTranslatedField extends FakeWidget {
 function get_name(){return 'jet-listing-dynamic-field';}
 function get_settings_for_display($field){
  return ['dynamic_field_post_meta_custom'=>'related_origin_destination_term_id',
          'dynamic_field_format'=>'via %s'][$field]??'';
 }
}
assert_ok(MAD4B_Rate_Departure_Details_V1::widget_type(new FakeTranslatedField('wpml-new-id'))==='origin',
 'new WPML Elementor widget ID inherits canonical meta-field rule');
assert_ok(MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeTranslatedField('wpml-new-id'))==='',
 'unconfirmed translated cloned widget is hidden too');
$rate['show_rate_departure_details']='1';
assert_ok(MAD4B_Rate_Departure_Details_V1::is_enabled(701),'True allows explicitly confirmed rate display');
$origin=MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeWidget('08da455'));
assert_ok(str_contains($origin,'Cairo')&&!str_contains($origin,'Africa'),'widget replaces legacy Africa with Tour Cairo');
assert_ok(str_contains(MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeTranslatedField('wpml-new-id')),'via Cairo'),'translated widget preserves its own localized formatting');
assert_ok(str_contains(MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeWidget('2c7a06c')),'Friday'),'explicit weekday appears only after opt-in');
assert_ok(str_contains(MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeWidget('fb0a8c1')),'at Afternoon'),'confirmed time uses existing formatting');
$tour['tour_origin_location_term']=2306;
assert_ok(MAD4B_Rate_Departure_Details_V1::origin(701)==='','continent must not survive through Tour metadata either');
assert_ok(MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeWidget('08da455'))==='','no fabricated origin when Tour source is invalid');
$tour['tour_origin_location_term']='';
assert_ok(MAD4B_Rate_Departure_Details_V1::origin(701)==='','missing Tour origin is neutral');
$rate['show_rate_departure_details']='0';
assert_ok(MAD4B_Rate_Departure_Details_V1::widget_content($source,new FakeWidget('08da455'))==='','False hides even saved origin/days/times');
