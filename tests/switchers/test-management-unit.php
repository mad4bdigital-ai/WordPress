<?php
/** Hermetic CI unit tests, PHP only, no WordPress database. */
define('ABSPATH','/tmp/');
$GLOBALS['unit_options']=[];
$GLOBALS['unit_meta']=[];
$GLOBALS['unit_hooks']=[];
class WP_Error {public function __construct(public string $code='error') {}}
class WC_Product {
 public function __construct(private int $id) {}
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
$tm='ETG_Traveller_Management';$fail=0;
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
$test('CERTIFIED_MAX_NEVER_EXCEEDED',$tm::certified_limit(8)===20);
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
add_filter('etg_traveller_management/verified_count',$hook);
$test('DENY_NON_READY_ADAPTER',$tm::resolve($faux)['count']===0);
remove_all_filters('etg_traveller_management/verified_count');
$hook=static function($unused,$line,$type)use($tm){
 return ['contract'=>$tm::COUNT_CONTRACT,'authority'=>'server_verified','product_id'=>$line['product_id'],
  'cart_quantity'=>$line['quantity'],'source'=>'booking_v2_certified','checkout_ready'=>true,'count'=>4];
};
add_filter('etg_traveller_management/verified_count',$hook);
$test('CERTIFIED_FUTURE_ADAPTER_ALLOWED',$tm::resolve($faux)['count']===4);
$test('ADAPTER_NOT_VALID_FOR_ACCOMMODATION',$tm::resolve(['product_id'=>9,'quantity'=>1,'data'=>new WC_Product(9)])['count']===0);
remove_all_filters('etg_traveller_management/verified_count');
$cart=['tour'=>$good,'optional'=>$faux];
$root=$tm::registry($cart);
$test('BOOKING_REGISTRY_ONLY_ONE_ROOT',count($root['bookings'])===1 && count($root['bookings']['tour']['people'])===3);
$test('UNLINKED_EXTRAS_ARE_NOT_ADDED',count($root['linked_services'])===0);
$hook=static fn()=>[['contract'=>$tm::CONTRACT,'authority'=>'server_verified','booking_key'=>'tour','service_key'=>'optional','traveller_indices'=>[0,2]]];
add_filter('etg_traveller_management/verified_service_links',$hook);
$linked=$tm::registry($cart)['linked_services']['optional']??[];
$test('ASSIGN_SUBSET_WITHOUT_DUPLICATION',($linked['count']??0)===2&&count(array_unique($linked['traveller_ids']??[]))===2);
$test('NO_NATIONALITY_IN_REGISTRY',!str_contains(json_encode($tm::registry($cart)),'nationality'));
remove_all_filters('etg_traveller_management/verified_service_links');
$test('ONLY_TRAVELLER_OPTION_CHANGED',array_keys($GLOBALS['unit_options'])===[$tm::OPTION]);
echo "UNIFIED_HERMETIC_FAILURES=$fail\n";exit($fail?1:0);
