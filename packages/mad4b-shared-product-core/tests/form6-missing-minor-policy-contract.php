<?php
/**
 * Isolated Form 6 preview regression, without WordPress DB or live rates.
 * Never places an adult-only reference into an all-traveller total.
 */
define('ABSPATH',__DIR__);
define('SHARED_PRODUCT_LAB_ONLY',true);
function wp_get_environment_type(){return 'local';}
class WP_Error {
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(...$args){}
function add_filter(...$args){}
function absint($v){return max(0,(int)$v);}
function get_post_type($id){return (int)$id===24?'tour-rates':'tours-and-activities';}
function get_post_status($id){return 'publish';}
function get_post($id){return (object)['post_type'=>get_post_type($id),'post_status'=>'publish','post_password'=>''];}
function get_post_meta($id,$key,$single=true){
 if((int)$id===24 && $key==='related_tour_id')return 42;
 return '';
}
function shared_product_booking_v2_preview($payload){
 // Only emulate the provider's verified adult quote, never a child price.
 return ['total'=>125.0*(int)$payload['quantities']['persons'],
 'currency'=>'USD','lines'=>[['kind'=>'base','amount'=>125.0*(int)$payload['quantities']['persons']]]];
}
require dirname(__DIR__).'/mu-plugins/shared-product-core/adapters/presentation-form6-price-quote-v1.php';
$base=['tour_id'=>42,'source'=>'rate','rate_id'=>24,'travel_date'=>'2027-01-15','adults'=>2,'children'=>0,'infants'=>0,'occupancy'=>'double','extras'=>[]];
$checks=[];
$checks['adult_only_legacy_total']=false;
$a=shared_presentation_form6_price_quote($base);
$checks['adult_only_legacy_total']=!is_wp_error($a)&&$a['total']===250.0&&$a['status']==='price_preview_only'&&$a['checkout_ready']===false;
$b=shared_presentation_form6_price_quote(array_replace($base,['children'=>1]));
$checks['one_child_shows_adult_reference']=!is_wp_error($b)&&$b['base_preview_amount']===250.0;
$checks['one_child_never_pretends_total']=!is_wp_error($b)&&$b['total']===null&&$b['subtotal']===null&&$b['status']==='partial_price_preview_only';
$checks['one_child_not_free']=!is_wp_error($b)&&$b['child_price_state']==='requires_separate_pricing';
$checks['rate_identity_preserved']=!is_wp_error($b)&&$b['rate_id']===24&&$b['source']==='rate';
$c=shared_presentation_form6_price_quote(array_replace($base,['infants'=>1]));
$checks['infant_only_shows_adult_reference']=!is_wp_error($c)&&$c['base_preview_amount']===250.0&&$c['total']===null;
$d=shared_presentation_form6_price_quote(array_replace($base,['adults'=>0,'children'=>1]));
$checks['zero_adults_rejected']=is_wp_error($d)&&$d->get_error_code()==='form6_quote_traveler_invalid';
$e=shared_presentation_form6_price_quote(array_replace($base,['rate_id'=>25,'children'=>1]));
$checks['wrong_rate_rejected']=is_wp_error($e)&&$e->get_error_code()==='form6_quote_wpml_rate_not_sibling';
foreach($checks as $key=>$pass)echo $key.'='.($pass?'PASS':'FAIL').PHP_EOL;
echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
exit(in_array(false,$checks,true)?1:0);
