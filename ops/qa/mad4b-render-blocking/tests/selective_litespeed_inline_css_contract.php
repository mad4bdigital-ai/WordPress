<?php
/** LiteSpeed source contract; standalone PHP without WordPress or price context. */
define('ABSPATH',__DIR__);
function add_action(...$args){}
$path=__DIR__.'/../../../../wp-content/mu-plugins/332-mad4b-litespeed-selective-inline-css.php';
if(!is_file($path))$path='/tmp/332-mad4b-litespeed-selective-inline-css.php';
require $path;
function test($ok,$name){if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";}
$c='MAD4B_LiteSpeed_Selective_Inline_CSS_V1';
test($c::safe_cookies([], 'en'),'anonymous allowed');
test($c::safe_cookies(['sbjs_current'=>'sample','_ga'=>'testing','_ga_9RMVFTLGMQ'=>'x','wp-wpml_current_language'=>'en'],'en'),'non-functional attribution and matching WPML locale allowed');
test(!$c::safe_cookies(['wp-wpml_current_language'=>'fr'],'en'),'mismatched locale fail closed');
foreach(['woocommerce_cart_hash','wp_woocommerce_session_123','wordpress_logged_in_foo','wpml_wc_current_currency','litespeed_cache','country_selected','PHPSESSID','unknown_cookie'] as $key) {
 test(!$c::safe_cookies([$key=>'value'],'en'),'deny context cookie '.$key);
}
test(!$c::safe_cookies(['_ga_injection!'=>'x'],'en'),'deny nonstandard cookie name');
test($c::safe_raw_cookie_header('sbjs_current=x; wp-wpml_current_language=en; _ga=y','en'),'allowed original browser cookie header');
test(!$c::safe_raw_cookie_header('wp-wpml_current_language=fr','en'),'reject mismatched original cookie before WPML mutation');
test(!$c::safe_raw_cookie_header('woocommerce_cart_hash=x','en'),'deny original cart cookie');
test(!$c::safe_raw_cookie_header('sbjs_current=x; sbjs_current=y','en'),'deny duplicate cookie names');
test(!$c::safe_raw_cookie_header('invalidcookie','en'),'deny malformed cookie header');
test(!$c::safe_raw_cookie_header(str_repeat('x',4097),'en'),'bound raw header size');

$code=file_get_contents($path);
foreach([
 "wp_get_environment_type()!=='staging'","is_user_logged_in()",
 'is_preview()','wp_doing_ajax()', "LSCWP_V!==self::EXPECT_LS",
 'ELEMENTOR_VERSION!==self::EXPECT_EL','HTTP_X_MAD4B_CSS_SELECTIVE_CONTROL',
 'HTTP_X_MAD4B_CSS_COMBINE_CANARY','HTTP_X_GEO_COUNTRY',
 'HTTP_CF_IPCOUNTRY','HTTP_X_MAD4B_CURRENCY',
 "get_woocommerce_currency()!=='USD'","get_option('litespeed.conf.optm-css_comb_ext_inl')",
 "litespeed_optimize_css_excludes","\$excludes[]='elementor-dcss-'",
 "add_action('wp'","'site_uuid'"] as $pin){
 test(str_contains($code,$pin),'source pinned '.substr($pin,0,48));
}
test(!str_contains($code,'update_option(')&&!str_contains($code,'set_transient('),'no persistent WP option/database mutation');
