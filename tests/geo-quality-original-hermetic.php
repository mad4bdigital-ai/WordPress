<?php
/** Original geo Quality scanner; no geocoding, no site edits or external APIs. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code='source_unavailable'){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
class WP_Term {
 public $term_id,$name,$slug;
 function __construct($id,$name='Cairo',$slug='cairo'){
  $this->term_id=$id;$this->name=$name;$this->slug=$slug;
 }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_current_blog_id(){return 2;}
function post_type_exists($type){return true;}
function get_posts($query){
 $type=$query['post_type']??'';
 if(isset($query['meta_key']))return $GLOBALS['rate_lookup']??[];
 return $GLOBALS['posts'][$type]??[];
}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function get_post_type($id){return $GLOBALS['types'][$id]??null;}
function get_post_status($id){return 'publish';}
function has_filter($hook){return false;}
function taxonomy_exists($name){return $name==='destinations';}
function get_term($id,$tax){return new WP_Term($id);}
function get_terms($args){return [];}
function get_term_meta($term,$key,$single=true){return $GLOBALS['term_meta'][$term][$key]??'';}
function wp_get_object_terms($id,$taxonomy){return [];}
function apply_filters($hook,$value,...$args){return $value;}
function absint($value){return abs((int)$value);}
function sanitize_key($value){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$value));}
function geo_check($yes,$reason){
 if(!$yes){fwrite(STDERR,"FAIL: ".$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class TestWpDb {
 public $term_relationships='wp_term_relationships',$term_taxonomy='wp_term_taxonomy';
 function prepare($sql,...$args){return $sql;}
 function get_col($sql){return $GLOBALS['source_term_ids'];}
}
$wpdb=new TestWpDb();
$GLOBALS['posts']=[];
$GLOBALS['types']=[];
$GLOBALS['meta']=[];
$GLOBALS['term_meta']=[];
$GLOBALS['source_term_ids']=[];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-geo-quality.php';
$c='MAD4B_SCP_Geo_Quality';
$off=$c::discover(['geo_quality_enabled'=>false,'post_types'=>['stations']]);
geo_check($off['complete']===true &&count($off['issues'])===0,'Disabled scanner never guesses a missing station');
$GLOBALS['posts']['stations']=new WP_Error('db_issues');
$bad=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($bad['complete']===false &&count($bad['issues'])===0,
 'Original get_posts WP_Error must not crash PHP or yield fake missing stations');
$GLOBALS['posts']['stations']=false;
$bad=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($bad['complete']===false,
 'Untrusted WordPress post inventory cannot be cast into empty success');
$GLOBALS['posts']['stations']=range(1,3001);
$bad=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($bad['complete']===false,
 'Over 3000 local station candidates must stop rather than silently drop the rest');
$GLOBALS['posts']['stations']=[21];
$GLOBALS['meta'][21]=['station_active_status'=>'1','station_public_status'=>'1',
 'station_map_location'=>'31.23, 30.04'];
$GLOBALS['source_term_ids']=[];
$pending=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($pending['complete']===true && count($pending['issues'])===1
 &&$pending['issues'][0]['code']==='geo_station_destination_unlinked',
 'Published active station without verified term link needs review, no auto geocoding');
$GLOBALS['meta'][21]['station_map_location']='91.0, 188.0';
$invalid_location=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($invalid_location['complete']===true
 &&$invalid_location['issues'][0]['code']==='geo_station_coordinates_invalid'
 &&$invalid_location['issues'][1]['code']==='geo_station_destination_unlinked',
 'Invalid source-owned coordinates remain an issue but never receive guessed coordinates');
$GLOBALS['meta'][21]['station_map_location']='31.23, 30.04';
$GLOBALS['source_term_ids']=[301];
$GLOBALS['term_meta'][301]=['destination_type'=>'city','is_public'=>'1'];
$verified_city=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($verified_city['complete']===true&&count($verified_city['issues'])===0,
 'Verified source-owned City taxonomy satisfies a linked station without public query guessing');
$GLOBALS['term_meta'][301]=[
 'destination_type'=>'zone_area','is_public'=>'0',
 '_mad4b_station_locality_internal'=>'1'
];
$internal=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($internal['complete']===true&&count($internal['issues'])===0,
 'Company-owned station-locality Zone can be internal and still count as an exact source relation');
$GLOBALS['source_term_ids']=new WP_Error('wpdb_unavailable');
$bad=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['stations']]);
geo_check($bad['complete']===false &&count($bad['issues'])===0,
 'Untrusted source-owned station taxonomy query cannot invent missing linked terms');
$GLOBALS['posts']['transportations']=[31];
$GLOBALS['meta'][31]=[
 'trans_available_status'=>'1','trans_is_puplic'=>'1',
 'service_mode'=>'fixed_route_schedule',
 'trans_from_station_id'=>'21','trans_to_station_id'=>'21'
];
$GLOBALS['source_term_ids']=[];
$GLOBALS['posts']['transport-rates']=[];
$GLOBALS['rate_lookup']=new WP_Error('rate_query_failed');
$transport=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['transportations','transport-rates']]);
geo_check($transport['complete']===false &&count($transport['issues'])===0,
 'Unknown transport-rate relation must not be misreported as a missing quote');
$GLOBALS['rate_lookup']=[];
$transport=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['transportations','transport-rates']]);
geo_check($transport['complete']===true
 &&$transport['nonblocking_price_opportunities']===1
 &&count($transport['issues'])===2
 &&$transport['issues'][1]['severity']==='low',
 'Valid original empty quote is a nonblocking pricing opportunity, not a booking stop');
$GLOBALS['posts']['tour-rates']=new WP_Error('rate_registry_down');
$GLOBALS['posts']['properties']=[55];
$GLOBALS['meta'][55]=['pickup_point_required'=>'1','property_map'=>''];
$property=$c::discover(['geo_quality_enabled'=>true,'post_types'=>['properties','tour-rates']]);
geo_check($property['complete']===false &&count($property['issues'])===0,
 'Missing original Tour Rate inventory cannot assert cruise boarding applicability');
echo 'PASS: GEO_QUALITY_ORIGINAL '.($GLOBALS['checks']??0).
 ' first-party bounded scan and no-invented-location cases'.PHP_EOL;
