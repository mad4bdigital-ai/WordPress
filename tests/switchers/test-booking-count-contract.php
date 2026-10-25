<?php
/** No WordPress/DB: verifies Booking v1 authority, not Woo cart quantity. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function apply_filters($name, $count, ...$args) {
 if($name==='etg_traveller_nationality/required_count'&&($GLOBALS['test_external_count_override']??false))return 2;
 return $count;
}
function get_post_type($id) {return $id===101?'tour-rates':'tours-and-activities';}
function is_wp_error($v) {return $v instanceof WP_Error;}
function get_option($key,$default=[]) {return $default;}
function get_post_meta($id,$key,$single=false) {return '';}
function sanitize_text_field($v) {return trim((string)$v);}
function sanitize_key($v) {return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function wp_salt($key='auth') {return 'test-salt';}
class WP_Error {function __construct(public $code='invalid'){} }
class WC_Product {public function __construct(private $id){} public function get_id(){return $this->id;}}
function shared_product_booking_configuration_matches($saved){
 if(($saved['status']??'')!=='server_quote' || ($saved['valid']??true)!==true)return new WP_Error('stale');
 return $saved;
}
class ETG_Traveller_Nationality_Switcher {
 public static function validate_list($input) {
  if(!is_array($input)||!$input||count($input)>999)return new WP_Error();
  foreach($input as $s)if(!in_array($s,['EG','US','SA'],true))return new WP_Error();
  return array_values($input);
 }
}
require getenv('SWITCHER_MANAGEMENT_FILE') ?: __DIR__ . '/../../wp-content/plugins/etg-traveller-nationality-switcher/includes-traveller-management.php';
require getenv('SWITCHER_BLOCKS_FILE') ?: __DIR__ . '/../../wp-content/plugins/etg-traveller-nationality-switcher/includes-checkout-blocks.php';
$c='ETG_Traveller_Nationality_Checkout_Blocks';
$check=static function($name,$pass){if(!$pass){fwrite(STDERR,$name."=FAIL\n");exit(1);}echo $name."=PASS\n";};
$rate=['product_id'=>101,'quantity'=>1,'data'=>new WC_Product(101),'shared_booking_configuration'=>[
 'status'=>'server_quote','rate_id'=>101,'quantities'=>['persons'=>3],'options'=>[]]];
$check('QUOTE_PERSONS_3_WC_QUANTITY_1',$c::traveller_count($rate)===3);
$check('EXPECTED_ITEMS_3',($c::expected_items(['cart-key'=>$rate])['cart-key']['travellers']??null)===3);
$check('PAYLOAD_3_ACCEPTED',$c::validate_request(['travellers'=>['cart-key'=>['EG','US','SA']]],$c::expected_items(['cart-key'=>$rate]))['cart-key']===['EG','US','SA']);
$check('PAYLOAD_ONLY_WOO_QUANTITY_REJECTED',is_wp_error($c::validate_request(['travellers'=>['cart-key'=>['EG']]],$c::expected_items(['cart-key'=>$rate]))));
$no=$rate;unset($no['shared_booking_configuration']);
$check('NO_QUOTE_FAIL_CLOSED',$c::traveller_count($no)===0);
$draft=$no;$draft['configuration']=['schema_version'=>2,'quantities'=>['persons'=>3]];
$check('V2_PREVIEW_DRAFT_NOT_CHECKOUT_CERTIFIED',$c::traveller_count($draft)===0);
$bad=$rate;$bad['shared_booking_configuration']['valid']=false;
$check('STALE_QUOTE_FAIL_CLOSED',$c::traveller_count($bad)===0);
$bad=$rate;$bad['shared_booking_configuration']['rate_id']=222;
$check('WRONG_PRODUCT_QUOTE_FAIL_CLOSED',$c::traveller_count($bad)===0);
$bad=$rate;$bad['quantity']=3;
$check('BOOKING_UNIT_QUANTITY_CHANGED_FAIL_CLOSED',$c::traveller_count($bad)===0);
$bad=$rate;$bad['shared_booking_configuration']['options']['travelers']=2;
$check('CONFLICTING_COUNT_REJECTED',$c::traveller_count($bad)===0);
$bad=$rate;$bad['shared_booking_configuration']['quantities']['persons']=21;
$check('OVER_20_SERVER_VERIFIED_ALLOWED',$c::traveller_count($bad)===21);
$tour=['product_id'=>102,'quantity'=>4,'data'=>new WC_Product(102)];
$check('TOUR_QUANTITY_NOT_PERSON_COUNT',$c::traveller_count($tour)===0);
$GLOBALS['test_external_count_override']=true;
$check('FILTER_CANNOT_INVENT_COUNT_FROM_MISSING_QUOTE',$c::traveller_count($no)===0);
$check('FILTER_CANNOT_CHANGE_VERIFIED_COUNT',$c::traveller_count($rate)===0);
$GLOBALS['test_external_count_override']=false;
echo "BOOKING_COUNT_FAILURES=0\n";
