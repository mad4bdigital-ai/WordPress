<?php
define('ABSPATH','/');
function add_action($hook,$callback,$priority=10){return true;}
function is_admin(){return false;}
function is_user_logged_in(){return false;}
function wp_doing_ajax(){return false;}
function is_preview(){return false;}
function is_404(){return false;}
function is_front_page(){return in_array($_SERVER['REQUEST_URI']??'', ['/','/es/'],true);}
function is_post_type_archive(){return true;}
function wp_parse_url($s,$k){return parse_url($s,$k);}
function get_option($s,$default=null){return 'https://wayfatours.com';}
function apply_filters($s,$val){return $GLOBALS['lang'];}
class MAD4B_Country_Switcher {
 public static function saved_country(){return $GLOBALS['signed_ok']?'US':'';}
}
require_once '/tmp/mad4b-signed-catalog-admission.php';
$cls='MAD4B_Wayfa_Signed_Home_FastCGI_V1';
$good='mad4b_country_selected_v1='.str_repeat('A',42).'.'.str_repeat('a',64);
function check($label,$ok){if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(2);}echo 'PASS '.$label.PHP_EOL;}
$GLOBALS['signed_ok']=true;
$GLOBALS['lang']='en';
$_COOKIE=['mad4b_country_selected_v1'=>explode('=',$good,2)[1]];
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/tours-and-activities/','QUERY_STRING'=>'','HTTP_COOKIE'=>$good];
check('exact canonical unfiltered archive admitted',$cls::eligible());
$_SERVER['REQUEST_URI']='/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:day-tours/';
check('JetSmartFilters archive excluded',!$cls::eligible());
$_SERVER['REQUEST_URI']='/tours-and-activities/example-cruise/';
check('tour commerce page excluded',!$cls::eligible());
$_SERVER['REQUEST_URI']='/tours-and-activities/';
$_SERVER['QUERY_STRING']='date=2026-12-01';
check('filter or date query excluded',!$cls::eligible());
$_SERVER['QUERY_STRING']='';
$_SERVER['HTTP_COOKIE']=$good.'; wp_woocommerce_session_x=1';
check('WooCommerce session never admitted',!$cls::eligible());
$_SERVER['HTTP_COOKIE']=$good;
$_SERVER['HTTP_X_MAD4B_CURRENCY']='EUR';
check('market override header excluded',!$cls::eligible());
unset($_SERVER['HTTP_X_MAD4B_CURRENCY']);
$GLOBALS['signed_ok']=false;
check('HMAC failure at WordPress origin excluded',!$cls::eligible());
$GLOBALS['signed_ok']=true;
$_SERVER['REQUEST_URI']='/';
check('original signed homepage unaffected',$cls::eligible());
$_SERVER['REQUEST_URI']='/es/';
$GLOBALS['lang']='es';
check('original Spanish homepage unaffected',$cls::eligible());
echo 'SIGNED_ARCHIVE_PHP_ROUTE_CONTRACT 9'.PHP_EOL;
