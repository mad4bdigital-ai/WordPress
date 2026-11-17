<?php
error_reporting(E_ALL);
define('ABSPATH','/test/');
define('WPMU_PLUGIN_DIR',dirname(__DIR__,2).'/wp-content/mu-plugins');
define('REST_REQUEST',false);
$GLOBALS['mode']='payment_only';
$GLOBALS['cart_flag']='auto';
$GLOBALS['url']='/';
$GLOBALS['scripts']=[];
function add_action(...$args){}
function add_shortcode(...$args){}
function add_filter(...$args){}
function get_option($name,$default=null){
 if($name==='mad4b_woocommerce_public_experience_v1')return $GLOBALS['mode'];
 if($name==='mad4b_cart_island_enabled_v1')return $GLOBALS['cart_flag'];
 return $default;
}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_wc_endpoint_url($part){return false;}
function is_checkout(){return false;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function home_url($path='/'){return 'https://site.example.test/';}
function rest_url($path){return 'https://site.example.test/wp-json/'.ltrim($path,'/');}
function content_url($p){return 'https://site.example.test/wp-content/'.ltrim($p,'/');}
function wp_enqueue_script(...$args){$GLOBALS['scripts'][]=$args;}
function wp_enqueue_style(...$args){}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function esc_html__($s,$domain){return $s;}
function esc_attr__($s,$domain){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
class WooCommerce{}
require WPMU_PLUGIN_DIR.'/433-mad4b-cart-island-v1.php';
function ok($condition,$label){if(!$condition)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$_SERVER['REQUEST_URI']='/';
ok(MAD4B_Cart_Island_V1::experience()==='payment_only','live payment-only recognized');
ok(MAD4B_Cart_Island_V1::shortcode()==='','cart hidden from payment-only');
ok(MAD4B_Cart_Island_V1::fragment_data(['foo'=>'bar'],'wc-cart-fragments')===null,'old fragments dormant on public payment-only');
ok(MAD4B_Cart_Island_V1::fragment_data(['foo'=>'bar'],'wc-checkout')!==null,'payment scripts unmodified');
$GLOBALS['url']='/checkout/order-pay/100/?key=fake';
$_SERVER['REQUEST_URI']=$GLOBALS['url'];
ok(MAD4B_Cart_Island_V1::fragment_data(['foo'=>'bar'],'wc-cart-fragments')!==null,'signed order-pay fragments not changed');
$GLOBALS['mode']='standard';
$GLOBALS['url']='/';$_SERVER['REQUEST_URI']='/';
ok(MAD4B_Cart_Island_V1::enabled(),'island pre-enrolled when storefront returns');
ok(MAD4B_Cart_Island_V1::shortcode()!=='','island renders in storefront');
$x=MAD4B_Cart_Island_V1::shortcode();
ok(strpos($x,'data-mad4b-cart-island="v1"')!==false,'island static shell contract');
ok(strpos($x,'customer')===false&&strpos($x,'order-pay')===false&&strpos($x,'cart-token')===false,'no private cart/payment payload in HTML');
ok(strpos($x,'/wp-json/wc/store/v1/cart')!==false,'native Woo Store API owner reused');
ok(count($GLOBALS['scripts'])===2,'JS script enqueued only for islands');
$GLOBALS['cart_flag']='off';
ok(MAD4B_Cart_Island_V1::shortcode()==='','explicit cart island off mode');
$GLOBALS['cart_flag']='auto';
$_SERVER['REQUEST_URI']='/checkout/order-pay/100/';
ok(MAD4B_Cart_Island_V1::shortcode()==='','signed payment route never gets cart island');
$_SERVER['REQUEST_URI']='/checkout/order-received/100/';
ok(MAD4B_Cart_Island_V1::shortcode()==='','order received route never gets cart island');
$_SERVER['REQUEST_URI']='/';
ok(MAD4B_Cart_Island_V1::fragment_data(['foo'=>'bar'],'wc-cart-fragments')!==null,'native fragments not force-disabled when storefront active');
echo "PASS_CART_ISLAND_HERMETIC\n";
