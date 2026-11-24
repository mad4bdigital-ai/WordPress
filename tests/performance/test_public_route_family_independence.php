<?php
/** Hermetic independent Tour route admission (no WordPress/site mutation). */
declare(strict_types=1);
define('ABSPATH','/');
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/tours/example/',
 'HTTP_COOKIE'=>'wp-wpml_current_language=en'];
$_COOKIE=['wp-wpml_current_language'=>'en'];
$GLOBALS['option']=['mad4b_compiled_tour_family_mode_v1'=>'on',
 'mad4b_compiled_tour_family_v1'=>['not'=>'approved']];
function add_action(...$args){}
function get_option($k,$default=null){return $GLOBALS['option'][$k]??$default;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function is_preview(){return false;}
function apply_filters($name,$v){return $name==='wpml_current_language'?'en':$v;}
function is_checkout(){return false;}
function is_cart(){return false;}
function is_account_page(){return false;}
class MAD4B_Experience_Site_Scope_V1{static function bound(){return true;}}
function check(bool $v,string $label){if(!$v)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
require $argv[1];
$r=new ReflectionProperty('MAD4B_Compiled_Tour_Family_V1','tour');$r->setValue(60623);
$c='MAD4B_Compiled_Tour_Family_V1';
check($c::public_route_eligible(),'Public canonical Tour route admitted independent of Elementor family manifest');
check(!$c::eligible(),'Original 453 widget remains blocked without certified child template');
$_SERVER['HTTP_X_WP_NONCE']='TEST';
check(!$c::public_route_eligible(),'Nonce-bearing route is never shareable');
unset($_SERVER['HTTP_X_WP_NONCE']);
$_COOKIE['woocommerce_cart_hash']='x';
check(!$c::public_route_eligible(),'Commerce/cart cookies deny public route');
unset($_COOKIE['woocommerce_cart_hash']);
check($c::public_route_eligible(),'Public route resumes after removing unsafe cookie');
$_SERVER['QUERY_STRING']='?hello=1';
check(!$c::public_route_eligible(),'Query string denial retained');
unset($_SERVER['QUERY_STRING']);
$_SERVER['REQUEST_METHOD']='POST';
check(!$c::public_route_eligible(),'Non-GET request denial retained');
$_SERVER['REQUEST_METHOD']='GET';
check($c::public_route_eligible(),'GET canonical route remains eligible');
echo 'ALL_INDEPENDENT_TOUR_ADMISSION_TESTS_PASS'.PHP_EOL;
