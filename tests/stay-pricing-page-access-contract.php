<?php
/** Standalone pricing panel permission and routing contract. No WordPress database. */
define('ABSPATH','/');
class WP_Error {
    private $code;
    public function __construct($code){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($name,$callback,$priority=10){return true;}
function current_user_can($cap,$id=null) {
    return !empty($GLOBALS['allowed']) && in_array($cap,
        ['commerce_manage_pricing','manage_woocommerce','edit_post'],true);
}
function get_post_type($id){return (int)$id===100?'properties':false;}
function get_post_status($id){return (int)$id===100?'publish':false;}
function taxonomy_exists($slug){return in_array($slug,['destinations','property-types'],true);}
function is_object_in_taxonomy($type,$taxonomy){return $type==='properties'&&taxonomy_exists($taxonomy);}
function sanitize_text_field($s){return strip_tags($s);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function wp_unslash($s){return stripslashes($s);}
function absint($n){return abs((int)$n);}
function get_term($id,$taxonomy){return new WP_Error('unknown_term');}
function admin_url($path){return 'https://example.test/wp-admin/'.$path;}
function plugin_dir_url($path){return 'https://example.test/wp-content/mu-plugins/';}
function wp_create_nonce($action){return 'sample_nonce';}
function wp_json_encode($object){return json_encode($object);}
function wp_enqueue_style($handle,$uri,$deps,$version){$GLOBALS['styles'][]=$handle;}
function wp_enqueue_script($handle,$uri,$deps,$version,$footer){$GLOBALS['scripts'][]=$handle;}
function wp_add_inline_script($handle,$source,$position){
    $GLOBALS['inline'][$handle]=$source;
}
function assert_true($label,$actual){
    if(!$actual)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['passed'][]=$label;
}
require_once __DIR__.'/../wp-content/mu-plugins/008-mad4b-stay-pricing-controls-panel.php';
$c='MAD4B_Stay_Pricing_Controls_Panel_V1';
$GLOBALS['allowed']=false;
assert_true('unprivileged cannot access panel',!$c::permitted());
$r=$c::properties(['filters'=>[]]);
assert_true('unprivileged cannot enumerate hotels',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_forbidden');
$r=$c::units(['property_id'=>100]);
assert_true('unprivileged cannot enumerate units',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_property_forbidden');
$r=$c::rates(['property_id'=>100]);
assert_true('unprivileged cannot read pricing records',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_property_forbidden');
$r=$c::preview(['property_id'=>100]);
assert_true('unprivileged cannot request preview',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_property_forbidden');
$_GET['page']='dynamic-pricing-controls';$c::assets();
assert_true('no in-page assets for unprivileged role',empty($GLOBALS['styles']));
$GLOBALS['allowed']=true;
assert_true('native pricing permission accepted',$c::permitted());
$r=$c::properties(['filters'=>['unsupported_taxonomy'=>123]]);
assert_true('unknown taxonomy rejected',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_filter_invalid');
$r=$c::properties(['filters'=>['destinations'=>'abc']]);
assert_true('invalid taxonomy ID rejected',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_term_invalid');
$r=$c::properties(['filters'=>['destinations'=>222]]);
assert_true('nonexistent taxonomy ID rejected',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_term_unknown');
$r=$c::term_search(['taxonomy'=>'post_tags','search'=>'travel']);
assert_true('remote search restricted to Property taxonomy',
    is_wp_error($r)&&$r->get_error_code()==='stay_panel_taxonomy_not_allowed');
$r=$c::units(['property_id'=>999]);
assert_true('unlinked entity rejected',is_wp_error($r)
    &&$r->get_error_code()==='stay_panel_property_forbidden');
$_GET['page']='plugins.php';$c::assets();
assert_true('assets scoped away from other WP admin pages',empty($GLOBALS['scripts']));
$_GET['page']='dynamic-pricing-controls';$c::assets();
assert_true('panel style loaded on original pricing page',
    in_array('mad4b-stay-pricing-inline',$GLOBALS['styles']??[],true));
assert_true('panel script loaded on original pricing page',
    in_array('mad4b-stay-pricing-inline',$GLOBALS['scripts']??[],true));
$inline=$GLOBALS['inline']['mad4b-stay-pricing-inline']??'';
assert_true('inline includes config and native markup',
    strpos($inline,'MAD4BStayPricingUI')!==false
    &&strpos($inline,'Accommodation &')!==false);
echo 'PASS '.count($GLOBALS['passed'])." access and routing assertions.\n";
