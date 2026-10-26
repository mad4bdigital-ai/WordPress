<?php
/** Hermetic CI unit tests, PHP only, no WordPress database. */
define('ABSPATH','/tmp/');
$GLOBALS['unit_options']=[];
$GLOBALS['unit_meta']=[];
$GLOBALS['unit_hooks']=[];
class WP_Error {public $code;public function __construct($code='error') {$this->code=$code;} public function get_error_code(){return $this->code;}}
class WC_Product {
 private $id;
 public function __construct($id) {$this->id=(int)$id;}
 public function get_id(){return $this->id;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function add_action(...$args){}
function get_option($key,$fallback=[]){return $GLOBALS['unit_options'][$key]??$fallback;}
function update_option($key,$v,$autoload=false){$GLOBALS['unit_options'][$key]=$v;return true;}
function delete_option($key){unset($GLOBALS['unit_options'][$key]);return true;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['unit_meta'][$id][$key]??'';}
function sanitize_text_field($str){return trim((string)$str);}
function sanitize_key($str){return preg_replace('/[^a-z0-9_\\-]/','',strtolower((string)$str));}
function wp_salt($mode='auth'){return 'mock-canonical-test-secret';}
function get_post_type($id){return [7=>'tour-rates',8=>'tours-and-activities',9=>'properties',10=>'transportations',11=>'product'][ $id ]??null;}
function shared_product_allowed_optional_tour_ids($id){return $id===7?[8]:[];}
function wp_get_post_terms($id,$taxonomy,$opt=[]){return ($id===8&&$taxonomy==='group-size-tiers')?[101,102]:[];}
function get_term_meta($id,$key,$single=true){
 if($id===101)return ['tier_min'=>10,'tier_max'=>20,'tier_enabled'=>'1'][$key]??'';
 if($id===102)return ['tier_min'=>21,'tier_max'=>30,'tier_enabled'=>'1'][$key]??'';
 return '';
}
function shared_product_booking_modes_from_taxonomy($id){
 return ['modes'=>($GLOBALS['mock_no_modes']??false)?[]:['group','private']];
}
function shared_product_group_tier_from_taxonomy($tour,$people){
 if($tour!==8)return new WP_Error('group_tier_not_configured');
 if(($GLOBALS['mock_disabled']??false)===true)return new WP_Error('group_tier_not_configured');
 if(($GLOBALS['mock_overlap']??false)===true && $people>=21 && $people<=30)return new WP_Error('group_tiers_overlap');
 if($people>=10&&$people<=20)return 101;
 if($people>=21&&$people<=30)return 102;
 return new WP_Error('group_tier_not_configured');
}

function shared_product_booking_configuration_matches($saved) {
 if(($saved['status']??'')!=='server_validated'||($saved['rate_id']??0)!==7)return new WP_Error('not_verified');
 return $saved;
}
function add_filter($tag,$callback,$priority=10,$accepted=1){$GLOBALS['unit_hooks'][$tag][]=$callback;}
function remove_all_filters($tag){unset($GLOBALS['unit_hooks'][$tag]);}
function apply_filters($tag,$value,...$args){
 foreach($GLOBALS['unit_hooks'][$tag]??[] as $callback)$value=$callback($value,...$args);
 return $value;
}
require __DIR__.'/../../wp-content/plugins/etg-traveller-nationality-switcher/includes-traveller-management.php';
$tm='Travel_Booking_Traveller_Management';$fail=0;
$test=static function($name,$bool)use(&$fail){echo "$name=".($bool?'PASS':'FAIL').PHP_EOL;if(!$bool)$fail++;};
$test('FIVE_FIXED_BUILTINS',count($tm::builtins())===5);
$test('DEFAULT_IS_VERIFIED_SOURCE',$tm::state()['default']==='automatic');
$test('NO_FINANCIAL_OPTION_WRITES',array_keys($GLOBALS['unit_options'])===[]);
$a=$tm::mutate('clone','automatic',['source_preset'=>'automatic','new_id'=>'business_trip','label'=>'Business Trip'],0);
$test('PRESET_CLONE',!is_wp_error($a)&&isset($tm::presets()['business_trip']));
$a=$tm::mutate('save','business_trip',['label'=>'VIP','source'=>'verified','inherit'=>false,'nationality'=>'service','max'=>50],1);
$test('CUSTOM_EDIT_AND_CAPACITY_REQUEST',!is_wp_error($a)&&$tm::presets()['business_trip']['max']===50);
$a=$tm::mutate('global','business_trip',[],2);
$test('GLOBAL_PRESET_OVERRIDE',!is_wp_error($a)&&$tm::preset_for(8)['id']==='business_trip');
$test('NO_20_PERSON_GLOBAL_CAP',$tm::certified_limit(8)===999);
$a=$tm::mutate('reset','business_trip',[],3);
$test('RESET_CUSTOM_TO_BUILTIN',!is_wp_error($a)&&$tm::presets()['business_trip']['inherit']);
$a=$tm::mutate('save','tour',['label'=>'Edited Tour','source'=>'verified','inherit'=>false,'nationality'=>'required','max'=>20],4);
$test('EDIT_BUILTIN_WITH_OPTIONAL_OVERLAY',!is_wp_error($a)&&$tm::presets()['tour']['label']==='Edited Tour');
$a=$tm::mutate('reset','tour',[],5);
$test('RESET_BUILTIN_DEFAULT',!is_wp_error($a)&&$tm::presets()['tour']['label']==='Tours & Activities');
$test('REJECT_STALE_REVISION',is_wp_error($tm::mutate('global','tour',[],1)));
$GLOBALS['unit_meta'][8][$tm::META]='tour';
$test('PER_PRODUCT_PRESET_OVERRIDE',$tm::preset_for(8)['id']==='tour');
$good=['data'=>new WC_Product(7),'product_id'=>7,'quantity'=>1,'shared_booking_configuration'=>[
 'status'=>'server_validated','rate_id'=>7,'quantities'=>['persons'=>3],'options'=>[]]];
$test('VERIFY_TOUR_RATE_PERSONS',$tm::resolve($good)['count']===3);
$wrong=$good;$wrong['quantity']=3;
$test('NOT_WOO_UNIT_QUANTITY',$tm::resolve($wrong)['count']===0);
$wrong=$good;$wrong['shared_booking_configuration']['rate_id']=9;
$test('SOURCE_PRODUCT_MISMATCH_BLOCK',$tm::resolve($wrong)['count']===0);
$wrong=$good;$wrong['shared_booking_configuration']['options']['travelers']=2;
$test('CONFLICTING_PASSENGER_COUNT_BLOCK',$tm::resolve($wrong)['count']===0);
$wrong=$good;$wrong['shared_product_quote']=['schema'=>1];
$test('MULTIPLE_PRICE_AUTHORITIES_BLOCK',$tm::resolve($wrong)['count']===0);
foreach([8=>'tour',9=>'stay',10=>'transport',11=>'ordinary'] as $id=>$type){
 $line=['product_id'=>$id,'quantity'=>4,'data'=>new WC_Product($id)];
 $test('NO_INFERRED_PASSENGERS_'.$type,$tm::resolve($line)['count']===0);
}
$faux=['product_id'=>8,'quantity'=>1,'data'=>new WC_Product(8)];
$hook=static function($unused,$line,$type)use($tm){
 return ['contract'=>$tm::COUNT_CONTRACT,'authority'=>'server_verified','product_id'=>$line['product_id'],
  'cart_quantity'=>$line['quantity'],'source'=>'booking_v2_certified','checkout_ready'=>false,'count'=>4];
};
add_filter('travel_booking_traveller_management/verified_count',$hook);
$test('DENY_NON_READY_ADAPTER',$tm::resolve($faux)['count']===0);
remove_all_filters('travel_booking_traveller_management/verified_count');
$hook=static function($unused,$line,$type)use($tm){
 return ['contract'=>$tm::COUNT_CONTRACT,'authority'=>'server_verified','product_id'=>$line['product_id'],
  'cart_quantity'=>$line['quantity'],'source'=>'booking_v2_certified','checkout_ready'=>true,
  'tour_id'=>$line['product_id'],'booking_mode'=>'private','count'=>4];
};
add_filter('travel_booking_traveller_management/verified_count',$hook);
$test('CERTIFIED_FUTURE_ADAPTER_ALLOWED',$tm::resolve($faux)['count']===4);
$test('ADAPTER_NOT_VALID_FOR_ACCOMMODATION',$tm::resolve(['product_id'=>9,'quantity'=>1,'data'=>new WC_Product(9)])['count']===0);
$groupCertified=static function($unused,$line,$type)use($tm){
 return ['contract'=>$tm::COUNT_CONTRACT,'authority'=>'server_verified','product_id'=>$line['product_id'],
  'cart_quantity'=>$line['quantity'],'source'=>'booking_v2_certified','checkout_ready'=>true,
  'tour_id'=>$line['product_id'],'booking_mode'=>'group','group_size'=>15,'tier_id'=>101,'count'=>15];
};
remove_all_filters('travel_booking_traveller_management/verified_count');
add_filter('travel_booking_traveller_management/verified_count',$groupCertified);
$test('CERTIFIED_GROUP_TIER_EXACT_MATCH',$tm::resolve($faux)['count']===15);
remove_all_filters('travel_booking_traveller_management/verified_count');
$badGroup=static function($unused,$line,$type)use($tm){
 return ['contract'=>$tm::COUNT_CONTRACT,'authority'=>'server_verified','product_id'=>$line['product_id'],
  'cart_quantity'=>$line['quantity'],'source'=>'booking_v2_certified','checkout_ready'=>true,
  'tour_id'=>$line['product_id'],'booking_mode'=>'group','group_size'=>14,'tier_id'=>101,'count'=>15];
};
add_filter('travel_booking_traveller_management/verified_count',$badGroup);
$test('CERTIFIED_GROUP_PERSONS_MISMATCH_DENIED',$tm::resolve($faux)['count']===0);
remove_all_filters('travel_booking_traveller_management/verified_count');
remove_all_filters('travel_booking_traveller_management/verified_count');
$cart=['tour'=>$good,'optional'=>$faux];
$root=$tm::registry($cart);
$test('BOOKING_REGISTRY_ONLY_ONE_ROOT',count($root['bookings'])===1 && count($root['bookings']['tour']['people'])===3);
$test('UNLINKED_EXTRAS_ARE_NOT_ADDED',count($root['linked_services'])===0);
$hook=static fn()=>[['contract'=>$tm::CONTRACT,'authority'=>'server_verified','booking_key'=>'tour','service_key'=>'optional','traveller_indices'=>[0,2]]];
add_filter('travel_booking_traveller_management/verified_service_links',$hook);
$linked=$tm::registry($cart)['linked_services']['optional']??[];
$test('ASSIGN_SUBSET_WITHOUT_DUPLICATION',($linked['count']??0)===2&&count(array_unique($linked['traveller_ids']??[]))===2);
$test('NO_NATIONALITY_IN_REGISTRY',strpos(json_encode($tm::registry($cart)),'nationality')===false);
remove_all_filters('travel_booking_traveller_management/verified_service_links');
$group=$tm::assess_capacity(8,'group',25);
$test('GROUP_25_MATCHES_CANONICAL_TIER',!is_wp_error($group)&&$group['tier_id']===102&&
 $group['checkout_capacity_certified']===false&&$group['booking_v2_persons_supported']===true);
$test('GROUP_NOT_REJECTED_BY_FORM_BATCH',($group['status']??'')==='tier_valid_preview_only');
$test('GROUP_SELECTED_TIER_MISMATCH_REJECTED',is_wp_error($tm::assess_capacity(8,'group',25,101)));
$test('GROUP_EXACT_TIER_ACCEPTED',!is_wp_error($tm::assess_capacity(8,'group',25,102)));
$GLOBALS['mock_overlap']=true;
$test('OVERLAPPING_TIERS_FAIL_CLOSED',is_wp_error($tm::assess_capacity(8,'group',25)));
$GLOBALS['mock_overlap']=false;
$GLOBALS['mock_disabled']=true;
$test('DISABLED_TIERS_FAIL_CLOSED',is_wp_error($tm::assess_capacity(8,'group',25)));
$GLOBALS['mock_disabled']=false;
$test('UNCONFIGURED_GROUP_SIZE_FAIL_CLOSED',is_wp_error($tm::assess_capacity(8,'group',45)));
$test('PRIVATE_25_IGNORES_GROUP_TIERS',!is_wp_error($tm::assess_capacity(8,'private',25)));
$test('PRIVATE_CANNOT_FORCE_GROUP_TIER',is_wp_error($tm::assess_capacity(8,'private',25,102)));
$test('GROUP_POLICY_OPT_OUT_ACCEPTS_NO_TIER',!is_wp_error($tm::assess_capacity(8,'group',25,null,'booking_engine_only')));
$test('GROUP_POLICY_OPT_OUT_ACCEPTS_NATIVE_TIER',!is_wp_error($tm::assess_capacity(8,'group',25,102,'booking_engine_only')));
$test('CAPACITY_51_EXCEEDS_V2_PREVIEW_NOT_TAXONOMY_LIMIT',
 ($tm::assess_capacity(8,'private',51)['booking_v2_persons_supported']??true)===false);
$test('ORDINARY_PRODUCT_NEVER_HAS_GROUP_CAPACITY',is_wp_error($tm::assess_capacity(11,'group',25)));
$test('SMALL_PARTY_GROUP_TIER_NOT_AUTOMATIC',is_wp_error($tm::assess_capacity(8,'group',8)));
$solo=$tm::advise_booking_mode(8,1,'auto',false);
$test('SINGLE_SEAT_PRIVATE_ONLY_SUGGESTED',!is_wp_error($solo)&&$solo['recommended_mode']==='private'&&!$solo['automatic_conversion']);
$join=$tm::advise_booking_mode(8,1,'group',true);
$test('SINGLE_SEAT_JOINS_GROUP_EXPLICITLY',!is_wp_error($join)&&$join['selected_mode']==='group');
$departure=$tm::assess_departure(8,1,24,30,10);
$test('DEPARTURE_25_HAS_DISTINCT_PARTY_SIZE',!is_wp_error($departure)&&$departure['booking_party_size']===1&&$departure['departure_total_after_booking']===25&&$departure['tier_id']===102);
$test('DEPARTURE_NOT_PAID_AUTHORITY',!is_wp_error($departure)&&$departure['financial_checkout_authority']===false&&!$departure['inventory_reserved']);
$below=$tm::assess_departure(8,2,3,30,10);
$test('DEPARTURE_BELOW_MIN_NOT_PRIVATE',!is_wp_error($below)&&$below['status']==='below_departure_operating_minimum');
$test('DEPARTURE_OVERBOOKED_REJECTED',is_wp_error($tm::assess_departure(8,8,25,30,10)));
$test('PRIVATE_MODE_NO_TIER_REQUIRED',($tm::advise_booking_mode(8,1,'private',false)['selected_mode']??null)==='private');
$test('ENTRY_BATCHES_FOR_55',($tm::assess_capacity(8,'private',55)['nationality_entry_batches']??0)===3);
$untracked=$tm::assess_departure(8,1);
$test('NO_INVENTORY_DEFAULT_ALLOWS_BOOKING_REQUEST',!is_wp_error($untracked)
 &&$untracked['status']==='request_allowed_occupancy_unknown'
 &&$untracked['remaining_seats_after_booking']===null);
$GLOBALS['unit_meta'][8]['_travel_booking_departure_max_capacity']=30;
$stillUntracked=$tm::assess_departure(8,1);
$test('OPTIONAL_CAPACITY_FIELD_NOT_FORCED',!is_wp_error($stillUntracked)
 &&$stillUntracked['departure_capacity']===null);
$GLOBALS['unit_meta'][8]['_travel_booking_departure_inventory_mode']='capacity_preview';
$pending=$tm::assess_departure(8,1);
$test('NO_CONFIRMED_GUESTS_NOT_ASSUMED_ZERO',!is_wp_error($pending)
 &&$pending['departure_capacity']===30&&$pending['remaining_seats_after_booking']===null);
$preview=$tm::assess_departure(8,1,24);
$test('OPTIONAL_CAPACITY_PREVIEW_NOT_STOCK_RECEIPT',!is_wp_error($preview)
 &&$preview['remaining_seats_after_booking']===5
 &&$preview['availability_verified']===false
 &&$preview['inventory_reserved']===false);
$requested=$tm::assess_booking_request(8,1,'group');
$test('GROUP_SEAT_WITHOUT_INVENTORY',$requested['request_allowed_without_stock']===true
 &&$requested['seat_confirmation']==='requires_separate_booking_confirmation');
unset($GLOBALS['unit_meta'][8]['_travel_booking_departure_inventory_mode'],$GLOBALS['unit_meta'][8]['_travel_booking_departure_max_capacity']);
$test('NO_GROUP_MINIMUM_HARDCODED',$tm::group_booking_minimum(8)===10);
$GLOBALS['unit_meta'][8]['_travel_booking_group_booking_minimum']=12;
$test('GROUP_MINIMUM_OVERRIDE_TWELVE',$tm::group_booking_minimum(8)===12);
$party=$tm::advise_booking_mode(8,11,'auto',false);
$test('MINIMUM_PARTY_ELEVEN_SUGGESTS_PRIVATE',!is_wp_error($party)&&$party['recommended_mode']==='private');
$party=$tm::advise_booking_mode(8,12,'auto',false);
$test('MINIMUM_PARTY_TWELVE_SUGGESTS_GROUP',!is_wp_error($party)&&$party['recommended_mode']==='group');
$test('ONE_SEAT_EXPLICIT_GROUP_NOT_FORCED_PRIVATE',$tm::advise_booking_mode(8,1,'group',true)['selected_mode']==='group');
unset($GLOBALS['unit_meta'][8]['_travel_booking_group_booking_minimum']);
$test('CLEAR_GROUP_MINIMUM_INHERITS_TIER',$tm::group_booking_minimum(8)===10);
$test('SUGGESTION_DEFAULT_FIRST',$tm::builtins()['automatic']['suggestion']==='first');
$test('SUGGESTION_NONE_ALLOWED',!is_wp_error($tm::clean_fields([
 'label'=>'Manual','source'=>'verified','nationality'=>'required','max'=>20,
 'group_capacity'=>'booking_engine_only','suggestion'=>'none'])));
$test('UNKNOWN_SUGGESTION_BLOCKED',is_wp_error($tm::clean_fields([
 'label'=>'Untrusted','source'=>'verified','nationality'=>'required','max'=>20,'suggestion'=>'trusted_automatic'])));
$GLOBALS['mock_no_modes']=true;
$mode=$tm::assess_booking_request(8,1,'group');
$GLOBALS['mock_no_modes']=false;
$test('NO_MODES_MEANS_INQUIRY_ONLY',!($mode['booking_mode_verified']??true)
 &&($mode['request_status']??'')==='pending_mode_configuration');
$test('ONLY_TRAVELLER_OPTION_CHANGED',array_keys($GLOBALS['unit_options'])===[$tm::OPTION]);
echo "UNIFIED_HERMETIC_FAILURES=$fail\n";exit($fail?1:0);
