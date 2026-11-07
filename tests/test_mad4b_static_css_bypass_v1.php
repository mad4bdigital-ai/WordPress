<?php
defined('ABSPATH') || define('ABSPATH','/');
final class Jet_Engine_Dynamic_Tags_Manager {
    public function add_listing_item_dynamic_css($content,$listing_id,$inner) {
        $GLOBALS['vendor_calls']++;
        return '<style>/*native dynamic css*/</style>'.$content;
    }
}
$GLOBALS['vendor_calls']=0;
$GLOBALS['option']='on';
$GLOBALS['css_meta']=[];
$GLOBALS['posts']=[];
$GLOBALS['wp_filter']=[];
function add_action($name,$fn,$priority=10){return true;}
function is_admin(){return false;}
function is_user_logged_in(){return false;}
function wp_doing_ajax(){return false;}
function wp_get_environment_type(){return 'staging';}
function get_option($key,$default=null){return $GLOBALS['option'];}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$key,$single){return $GLOBALS['css_meta'][$id]??false;}
function add_filter($hook,$cb,$pri=10,$args=1){
    if(!isset($GLOBALS['wp_filter'][$hook]))$GLOBALS['wp_filter'][$hook]=(object)['callbacks'=>[]];
    $GLOBALS['wp_filter'][$hook]->callbacks[$pri][]=['function'=>$cb,'accepted_args'=>$args];
}
function remove_filter($hook,$cb,$pri=10){
    $items=&$GLOBALS['wp_filter'][$hook]->callbacks[$pri];
    foreach($items as $key=>$row)if($row['function']===$cb)unset($items[$key]);
}
$_SERVER['REQUEST_METHOD']='GET';
$path=getenv('MAD4B_CSS_BYPASS_SOURCE');
if(!$path || !is_file($path))throw new Exception('Missing source');
require $path;
function ok($name,$cond){if(!$cond)throw new RuntimeException("FAIL: ".$name);echo "PASS ".$name."\n";}
$time=time();
$GLOBALS['posts'][71]=(object)['post_type'=>'elementor_library','post_status'=>'publish','post_modified_gmt'=>gmdate('Y-m-d H:i:s',$time-600)];
$GLOBALS['posts'][72]=(object)['post_type'=>'elementor_library','post_status'=>'publish','post_modified_gmt'=>gmdate('Y-m-d H:i:s',$time-600)];
$GLOBALS['css_meta'][71]=['status'=>'file','time'=>$time,'dynamic_elements_ids'=>[]];
$GLOBALS['css_meta'][72]=['status'=>'file','time'=>$time,'dynamic_elements_ids'=>['abc123']];
ok('static listing verified',MAD4B_Jet_Static_Dynamic_CSS_Bypass::static_group(71,[]));
ok('dynamic listing blocked',!MAD4B_Jet_Static_Dynamic_CSS_Bypass::static_group(72,[]));
ok('nested dynamic blocks unsafe skip',!MAD4B_Jet_Static_Dynamic_CSS_Bypass::static_group(71,[72]));
ok('unknown listing falls back',!MAD4B_Jet_Static_Dynamic_CSS_Bypass::static_group(444,[]));
$manager=new Jet_Engine_Dynamic_Tags_Manager;
add_filter('jet-engine/elementor-views/frontend/listing-content',[$manager,'add_listing_item_dynamic_css'],10,3);
MAD4B_Jet_Static_Dynamic_CSS_Bypass::install();
$callback=$GLOBALS['wp_filter']['jet-engine/elementor-views/frontend/listing-content']->callbacks[10];
ok('vendor replaced with one guarded wrapper',count($callback)===1);
ok('static skips redundant CSS build',MAD4B_Jet_Static_Dynamic_CSS_Bypass::intercept('<div>static</div>',71,[])==='<div>static</div>' && $GLOBALS['vendor_calls']===0);
ok('dynamic delegates unmodified',MAD4B_Jet_Static_Dynamic_CSS_Bypass::intercept('<div>dynamic</div>',72,[])==='<style>/*native dynamic css*/</style><div>dynamic</div>' && $GLOBALS['vendor_calls']===1);
ok('mixed nested delegates unmodified',str_contains(MAD4B_Jet_Static_Dynamic_CSS_Bypass::intercept('nested',71,[72]),'native dynamic css'));
$GLOBALS['option']='off';
ok('emergency off always delegates',str_contains(MAD4B_Jet_Static_Dynamic_CSS_Bypass::intercept('disabled',71,[]),'native dynamic css'));
echo "PASS STATIC CSS GUARD CONTRACT\n";
