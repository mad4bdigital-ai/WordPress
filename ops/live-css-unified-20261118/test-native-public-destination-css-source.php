<?php
/** Hermetic original WordPress archive contract; NO public content mutation. */
define('ABSPATH','/tmp/');
class WP_Term {
    public $term_id; public $taxonomy;
    public function __construct($id,$type){$this->term_id=$id;$this->taxonomy=$type;}
}
$mock=['type'=>'destinations','path'=>'/destination/africa-containent/egypt/aswan-gov/aswan-city/',
       'cookie'=>'','query'=>'','auth'=>'','logged'=>false,'opts'=>[]];
function add_action(...$args){}
function add_filter(...$args){}
function wp_parse_url($u,$component){return parse_url($u,$component);}
function home_url($x='/'){return 'https://wayfatours.com'.$x;}
function wp_get_environment_type(){return 'production';}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return (bool)$GLOBALS['mock']['logged'];}
function is_category(){return $GLOBALS['mock']['type']==='category';}
function is_tax($t){return $GLOBALS['mock']['type']===$t;}
function get_queried_object(){return new WP_Term(2311,$GLOBALS['mock']['type']);}
function get_term_link($term){return 'https://wayfatours.com'.$GLOBALS['mock']['path'];}
function is_wp_error($value){return false;}
function get_option($name,$default=null){return $GLOBALS['mock']['opts'][$name]??$default;}
require '/tmp/source-428-under-test.php';
$cls='MAD4B_Public_Destination_CSS_Source_V1';$checks=0;
$assert=function($value,$message)use(&$checks){if(!$value)throw new RuntimeException($message);++$checks;echo "PASS $message
";};
$set=function()use($cls){$GLOBALS['mock']['cookie']=$_SERVER['HTTP_COOKIE']??'';$cls::admit();return $cls::exclude_volatile(['existing'])===['existing','elementor-dcss-'];};
$reset=function(){$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']=$GLOBALS['mock']['path'];$_SERVER['HTTP_COOKIE']='';$_SERVER['HTTP_AUTHORIZATION']='';$_SERVER['QUERY_STRING']='';$GLOBALS['mock']['logged']=false;$GLOBALS['mock']['opts']=['litespeed.conf.optm-css_comb'=>'1','litespeed.conf.optm-css_min'=>'1'];};
$reset();$assert($set(),'published canonical anonymous destination gets stable external CSS');
$reset();$GLOBALS['mock']['type']='category';$GLOBALS['mock']['path']='/category/interests/';$_SERVER['REQUEST_URI']=$GLOBALS['mock']['path'];
$assert(!$set(),'separate original core-category 427 remains sole controller');
$reset();$GLOBALS['mock']['type']='destinations';$GLOBALS['mock']['path']='/destination/africa-containent/egypt/aswan-gov/aswan-city/';$_SERVER['REQUEST_URI']=$GLOBALS['mock']['path'];
$GLOBALS['mock']['opts']['mad4b_public_destination_stable_css_source_v1']='off';
$assert(!$set(),'destination-specific off switch is fail closed');
$reset();$GLOBALS['mock']['opts']['mad4b_public_category_stable_css_source_v1']='off';
$assert($set(),'independent category off switch never disables destination CSS');
$reset();$_SERVER['HTTP_COOKIE']='signed-test-cookie=opaque';$assert(!$set(),'signed or private visitor cookies never enter public CSS optimization');
$reset();$_SERVER['REQUEST_URI']='/destination/other/';$assert(!$set(),'noncanonical destination cannot inherit certificate source');
$reset();$_SERVER['QUERY_STRING']='date=2026-11-19';$assert(!$set(),'query variants do not enter anonymous public CSS optimizer');
$reset();$GLOBALS['mock']['logged']=true;$assert(!$set(),'logged-in viewer cannot inherit public CSS source');
$reset();$GLOBALS['mock']['type']='properties';$assert(!$set(),'hotel/property records are not inadvertently enabled');
$reset();$GLOBALS['mock']['type']='destinations';$_SERVER['REQUEST_METHOD']='POST';$assert(!$set(),'non-GET requests excluded');
echo "NATIVE_PUBLIC_DESTINATION_CSS_SOURCE_TESTS $checks/10
";
