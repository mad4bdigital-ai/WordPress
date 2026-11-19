<?php
/** Standalone in-memory authoring contract for the existing WordPress entities. */
error_reporting(E_ALL);
define('ABSPATH','/');
class WP_Post {
    public $ID;
    public $post_type;
    public function __construct($id,$type){$this->ID=$id;$this->post_type=$type;}
}
function add_action(){return true;}
function current_user_can($cap,$id=null){return $GLOBALS['authorized'];}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
function wp_verify_nonce($nonce,$action){return $nonce==='test_nonce'?1:false;}
function sanitize_text_field($value){return strip_tags((string)$value);}
function wp_unslash($value){return stripslashes($value);}
function get_post_type($id){return (int)$id===100?'properties':((int)$id===200?'units':false);}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][(int)$id][$key]??'';}
function update_post_meta($id,$key,$value){
    $GLOBALS['write_count']++;
    $GLOBALS['meta'][(int)$id][$key]=$value;
}
function delete_post_meta($id,$key){
    $GLOBALS['write_count']++;
    unset($GLOBALS['meta'][(int)$id][$key]);
}
function get_woocommerce_currencies(){return ['USD'=>'Dollar','EUR'=>'Euro','GBP'=>'Pound','EGP'=>'Egyptian Pound'];}
function abk_price_unit_choices(){
    return ['values'=>['per_room_night'=>'Per Room / Night','per_cabin_night'=>'Per Cabin / Night','per_stay'=>'Per Stay']];
}
function assert_eq($name,$actual,$expected){
    if($actual!==$expected)throw new RuntimeException($name.' expected '.
        var_export($expected,true).' actual '.var_export($actual,true));
    $GLOBALS['checks'][]=$name;
}
$GLOBALS['authorized']=true;
$GLOBALS['write_count']=0;
$GLOBALS['meta']=[
    100=>['prop_start_price'=>'50'],
    200=>['unit_start_price'=>'66'],
    300=>['prop_related_property_id'=>'100','prop_related_unit_id'=>''],
    301=>['prop_related_property_id'=>'100','prop_related_unit_id'=>'200'],
];
require __DIR__.'/../wp-content/mu-plugins/007-mad4b-stay-price-layers-admin.php';
$property=new WP_Post(100,'properties');
$unit=new WP_Post(200,'units');
$rateProperty=new WP_Post(300,'property-rates');
$rateUnit=new WP_Post(301,'property-rates');
$_POST=[
    'mad4b_stay_layers_nonce'=>'test_nonce','mad4b_layer_amount'=>'125',
    'mad4b_layer_currency'=>'usd','mad4b_layer_basis'=>'per_room_night',
    'mad4b_layer_kind'=>'sell'
];
MAD4B_Stay_Layers_Admin_V1::save_direct(100,$property);
assert_eq('property direct price uses native meta',get_post_meta(100,'prop_start_price'),'125');
assert_eq('property currency normalized',get_post_meta(100,'prop_base_currency'),'USD');
assert_eq('property unit recorded',get_post_meta(100,'prop_direct_price_unit'),'per_room_night');
assert_eq('property sell source',get_post_meta(100,'prop_direct_price_kind'),'sell');
$writes=$GLOBALS['write_count'];
$_POST['mad4b_layer_currency']='USD?';
MAD4B_Stay_Layers_Admin_V1::save_direct(100,$property);
assert_eq('bad currency rejected atomically',$GLOBALS['write_count'],$writes);
$_POST['mad4b_layer_currency']='ZZZ';
MAD4B_Stay_Layers_Admin_V1::save_direct(100,$property);
assert_eq('unsupported currency rejected',$GLOBALS['write_count'],$writes);
$_POST['mad4b_layer_currency']='USD';
$_POST['mad4b_layer_amount']='-10';
MAD4B_Stay_Layers_Admin_V1::save_direct(100,$property);
assert_eq('bad amount rejected atomically',$GLOBALS['write_count'],$writes);
$_POST['mad4b_layer_amount']='';
MAD4B_Stay_Layers_Admin_V1::save_direct(100,$property);
assert_eq('cleared direct price removed',get_post_meta(100,'prop_start_price'),'');
assert_eq('unit price unchanged',get_post_meta(200,'unit_start_price'),'66');
$_POST['mad4b_layer_kind']='cost';
MAD4B_Stay_Layers_Admin_V1::save_direct(200,$unit);
assert_eq('unit internal cost persisted',get_post_meta(200,'unit_direct_price_kind'),'cost');
assert_eq('native unit price not overwritten',get_post_meta(200,'unit_start_price'),'66');
$writes=$GLOBALS['write_count'];
$GLOBALS['authorized']=false;
$_POST['mad4b_layer_amount']='199';
MAD4B_Stay_Layers_Admin_V1::save_direct(100,$property);
assert_eq('unauthorized write blocked',$GLOBALS['write_count'],$writes);
$GLOBALS['authorized']=true;
$_POST['mad4b_stay_layers_nonce']='bad';
MAD4B_Stay_Layers_Admin_V1::save_direct(100,$property);
assert_eq('invalid nonce blocks write',$GLOBALS['write_count'],$writes);
$_POST=[
  'mad4b_stay_layers_nonce'=>'test_nonce',
  'mad4b_layer_scope'=>'property','mad4b_layer_kind'=>'sell'
];
MAD4B_Stay_Layers_Admin_V1::save_rate(300,$rateProperty);
assert_eq('property rate scope saved',get_post_meta(300,'mad4b_rate_scope_v1'),'property');
$_POST['mad4b_layer_scope']='unit';
$writes=$GLOBALS['write_count'];
MAD4B_Stay_Layers_Admin_V1::save_rate(300,$rateProperty);
assert_eq('cannot mark unit-less rate as unit scope',$GLOBALS['write_count'],$writes);
$_POST['mad4b_layer_scope']='property';
MAD4B_Stay_Layers_Admin_V1::save_rate(301,$rateUnit);
assert_eq('cannot mark linked unit rate property-wide',$GLOBALS['write_count'],$writes);
$_POST['mad4b_layer_scope']='unit';
MAD4B_Stay_Layers_Admin_V1::save_rate(301,$rateUnit);
assert_eq('existing unit-rate scope saved',get_post_meta(301,'mad4b_rate_scope_v1'),'unit');
assert_eq('unit rate never loses property link',get_post_meta(301,'prop_related_property_id'),'100');
assert_eq('unit rate never loses unit link',get_post_meta(301,'prop_related_unit_id'),'200');
echo 'PASS '.count($GLOBALS['checks'])." admin assertions. In-memory only.\n";
