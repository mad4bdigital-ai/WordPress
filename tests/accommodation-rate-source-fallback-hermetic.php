<?php
define('ABSPATH','/');
class WP_Error{public function __construct(public string $code){}public function get_error_code():string{return $this->code;}}
function add_action(...$a){}
function current_user_can(...$a){return true;}
function get_post_type($id){return $GLOBALS['types'][$id]??'';}
function get_post_status($id){return $GLOBALS['statuses'][$id]??'';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function get_posts($args){return $GLOBALS['rates']??[];}
class FakeDB{public string $prefix='wp_';public int $relationCount=1;public function prepare($s,...$a){return $s;}public function get_var($q){return $this->relationCount;}}
$wpdb=new FakeDB();
require_once __DIR__.'/../wp-content/mu-plugins/mad4b-accommodation-weight-policies-v1.php';
$cls='MAD4B_Accommodation_Rate_Source_Preview_V1';
$types=[100=>'properties',101=>'units'];$statuses=[100=>'publish',101=>'publish'];
$meta=[101=>['_mad4b_room_source_receipt_v1'=>['property_id'=>100], 'unit_max_occupancy'=>2]];
$rates=[];
$input=['property_id'=>100,'unit_id'=>101,'from'=>'2027-02-01','to'=>'2027-02-10','currency'=>'USD','price_basis'=>'per_room_night'];
function mk(int $id, int $unit, string $kind='cost', string $from='2027-01-01',string $to='2027-03-01'){
 $GLOBALS['types'][$id]='property-rates';$GLOBALS['statuses'][$id]='publish';
 $GLOBALS['meta'][$id]=['prop_related_unit_id'=>(string)$unit,'mad4b_rate_price_kind_v1'=>$kind,'stop_sale'=>'0','avilabile_status'=>'1','currency'=>'USD','prop_price_unit'=>'per_room_night','base_amount'=>'100.00','valid_from'=>$from,'valid_to'=>$to];
}
$done=0;
function checkit(bool $pass,string $message){global $done; $done++; if(!$pass){echo "FAIL $message\n";exit(1);}}
$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','no rates blocked');
mk(201,101);$rates=[201];
$r=$cls::inspect($input);checkit($r['source']==='unit_cost_rate'&&$r['rate_id']===201,'unit rate precedence');
checkit($r['supplier_cost_receipt_approved']===false&&$r['checkout_ready']===false,'no approval');
mk(202,0);$rates=[201,202];
$r=$cls::inspect($input);checkit($r['source']==='unit_cost_rate','unit wins over property');
$rates=[202];$r=$cls::inspect($input);checkit($r['source']==='property_cost_rate_fallback'&&$r['commercial_ready']===false,'property fallback preview-only');
$statuses[202]='draft';$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','draft rejected');$statuses[202]='publish';
$meta[202]['stop_sale']='1';$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','stop sale rejected');$meta[202]['stop_sale']='0';
$meta[202]['mad4b_rate_price_kind_v1']='selling';$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','retail not cost');$meta[202]['mad4b_rate_price_kind_v1']='cost';
$meta[202]['currency']='EUR';$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','currency mismatch');$meta[202]['currency']='USD';
$meta[202]['prop_price_unit']='per_person_night';$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','basis mismatch');$meta[202]['prop_price_unit']='per_room_night';
$meta[202]['valid_to']='2027-02-05';$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','period partial rejected');$meta[202]['valid_to']='2027-03-01';
mk(203,0);$rates=[202,203];$r=$cls::inspect($input);checkit($r['status']==='ambiguous_property_rates_review_required','ambiguous same scope');
$rates=[202];$r=$cls::inspect($input+[]);checkit($r['status']==='property_rate_fallback_candidate_requires_receipt','fallback requires receipts');
$r=$cls::inspect(array_replace($input,['to'=>'2027-01-01']));checkit($r instanceof WP_Error&&$r->get_error_code()==='accommodation_rate_source_window_invalid','reverse period denied');
$wpdb->relationCount=0;$r=$cls::inspect($input);checkit($r['status']==='unit_source_relation_or_capacity_unverified','relation denied');$wpdb->relationCount=1;
$statuses[101]='draft';$r=$cls::inspect($input);checkit($r['status']==='property_or_unit_unpublished','draft unit denied');$statuses[101]='publish';
$meta[202]['_qa_disallow_public_checkout']=1;$r=$cls::inspect($input);checkit($r['status']==='no_published_matching_cost_rate','qa flags denied');
checkit($r['rate_written']===false&&$r['supplier_cost_receipt_approved']===false,'no supplier or rate write');
echo "PASS $done/$done hermetic Property Rate fallback preview and fail-closed contracts\n";
