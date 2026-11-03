<?php
/** Exact first-party Geo/Transport Readiness source, hermetic and read-only. */
define('ABSPATH','/isolated/');
final class WP_Error {
 private $code;
 function __construct($code){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
final class WP_Term {
 public $term_id,$name,$slug;
 function __construct($id,$name,$slug){
  $this->term_id=$id;$this->name=$name;$this->slug=$slug;
 }
}
class MockWPDB {
 public $prefix='wp_',$term_relationships='wp_term_relationships',
        $term_taxonomy='wp_term_taxonomy';
 function prepare($query,...$args){return ['query'=>$query,'args'=>$args];}
 function get_col($request) {
  $id=(int)($request['args'][0]??0);
  return $id===102?['22']:[];
 }
}
$GLOBALS['wpdb']=new MockWPDB;
$GLOBALS['posts']=[
 'stations'=>[101,102],'transport-rates'=>[201],'transportations'=>[301],
 'properties'=>[401,402,403],'tour-rates'=>[501],
];
$GLOBALS['meta']=[
 101=>['station_active_status'=>'1','station_public_status'=>'1','station_map_location'=>'123, 193'],
 102=>['station_active_status'=>'1','station_public_status'=>'1','station_map_location'=>'30.01, 31.01'],
 201=>['valid_to'=>'2025-02-01','source_post_id'=>301],
 301=>['trans_available_status'=>'1','trans_is_puplic'=>'1','service_mode'=>'fixed_route_schedule',
        'trans_from_station_id'=>101,'trans_to_station_id'=>999],
 401=>['boarding_point_required'=>'yes','cruise_boarding_point'=>'','property_map'=>''],
 402=>['pickup_point_required'=>'yes','property_pickup_location'=>'','property_map'=>''],
 403=>[],
 501=>['related_properties_id'=>'403'],
];
$GLOBALS['term_meta']=[
 22=>['destination_type'=>'governorate','is_public'=>'1'],
 33=>['destination_type'=>'city','is_public'=>'1','google_location'=>'30.02,31.02'],
];
function get_current_blog_id(){return 2;}
function absint($x){return abs((int)$x);}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$x));}
function has_filter($name){return false;}
function apply_filters($name,$value,...$args){return $value;}
function post_type_exists($x){return isset($GLOBALS['posts'][$x]);}
function taxonomy_exists($x){return in_array($x,['property-types','destinations'],true);}
function get_posts($args) {
 $ids=$GLOBALS['posts'][$args['post_type']]??[];
 if(($args['post_type']??'')==='transport-rates' &&
    ($args['meta_key']??'')==='source_post_id')
  $ids=array_values(array_filter($ids,static fn($id)=>
    (int)get_post_meta($id,'source_post_id',true)===(int)$args['meta_value']));
 return array_slice($ids,0,(int)($args['posts_per_page']??10000));
}
function get_post_meta($post_id,$key,$single=true){
 return $GLOBALS['meta'][$post_id][$key]??'';
}
function get_post_status($id){
 foreach($GLOBALS['posts'] as $ids)if(in_array($id,$ids,true))return 'publish';
 return false;
}
function get_post_type($id){
 foreach($GLOBALS['posts'] as $name=>$ids)if(in_array($id,$ids,true))return $name;
 return false;
}
function get_term($id,$taxonomy){
 if($taxonomy==='destinations' && $id===22)return new WP_Term(22,'Giza','giza');
 if($taxonomy==='destinations' && $id===33)return new WP_Term(33,'Cairo','cairo');
 return null;
}
function get_terms($args){return [new WP_Term(33,'Cairo','cairo')];}
function get_term_meta($id,$key,$single=true){return $GLOBALS['term_meta'][$id][$key]??'';}
function wp_get_object_terms($post_id,$taxonomy,$args=[]){
 if($taxonomy==='property-types' && in_array($post_id,[401,403],true))
  return [new WP_Term(99,'Nile Cruise','nile-cruise')];
 return [];
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-geo-quality.php';
$cases=[];$assert=static function($name,$ok)use(&$cases){$cases[$name]=(bool)$ok;};
$empty=MAD4B_SCP_Geo_Quality::discover(['geo_quality_enabled'=>false,'post_types'=>array_keys($GLOBALS['posts'])]);
$assert('off_by_default_when_disabled',($empty['complete']??false) && !$empty['issues']);
$scoped=MAD4B_SCP_Geo_Quality::discover(['geo_quality_enabled'=>true,'post_types'=>[]]);
$assert('no_unconfigured_post_types',($scoped['complete']??false) && !$scoped['issues']);
$cfg=['geo_quality_enabled'=>true,'post_types'=>array_keys($GLOBALS['posts'])];
$result=MAD4B_SCP_Geo_Quality::discover($cfg);
$issues=$result['issues']??[];
$assert('complete_first_party_discovery',($result['complete']??false)===true);
$assert('exact_nine_issues',count($issues)===9);
$assert('deduplicated_issue_ids',count(array_unique(array_column($issues,'id')))==count($issues));
$expect=[
 'geo_station_coordinates_invalid'=>101,
 'geo_station_destination_unlinked'=>101,
 'geo_station_locality_unverified'=>102,
 'geo_transport_rate_expired'=>201,
 'geo_transport_route_stations_invalid'=>301,
 'geo_transport_price_evidence_unverified'=>301,
 'geo_cruise_boarding_unverified'=>401,
 'geo_cruise_boarding_applicability_unverified'=>403,
 'geo_property_pickup_unverified'=>402,
];
foreach($expect as $code=>$object){
 $matched=array_values(array_filter($issues,static fn($x)=>
 ($x['code']??null)===$code&&($x['object_id']??0)===$object));
 $assert('detect_'.$code,count($matched)===1);
}
$prices=array_values(array_filter($issues,static fn($x)=>
 ($x['code']??'')==='geo_transport_price_evidence_unverified'));
$assert('price_candidate_not_booking_authorization',
 count($prices)===1 && $prices[0]['severity']==='low' &&
 ($prices[0]['source_evidence']['nonblocking']??false)===true &&
 ($result['nonblocking_price_opportunities']??0)===1);
$maybe=array_values(array_filter($issues,static fn($x)=>
 ($x['code']??'')==='geo_station_locality_unverified'));
$assert('nearby_geography_never_auto_written',
 count($maybe)===1 &&
 ($maybe[0]['source_evidence']['auto_write_authorized']??true)===false);
$linked=array_values(array_filter($issues,static fn($x)=>
 ($x['code']??'')==='geo_cruise_boarding_applicability_unverified'));
$assert('cruise_boarding_only_candidate',
 count($linked)===1&&
 ($linked[0]['source_evidence']['no_assumed_berth']??false)===true);
$failed=array_keys(array_filter($cases,static fn($x)=>!$x));
foreach($failed as $name)echo 'FAIL '.$name,PHP_EOL;
echo 'SUMMARY checks='.count($cases).' failures='.count($failed),PHP_EOL;
if($failed)exit(1);
