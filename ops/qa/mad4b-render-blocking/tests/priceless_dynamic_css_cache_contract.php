<?php
/** Isolated Staging CSS-only regression contract, no WordPress bootstrap. */
define('ABSPATH',__DIR__);
function add_action(...$args){}
$source=dirname(__DIR__,4).'/wp-content/mu-plugins/324-mad4b-priceless-dynamic-css-cache.php';
if(!is_file($source))$source='/tmp/mad4b-css-only-source.php';
require $source;
function must($test,$name){if(!$test){fwrite(STDERR,"FAIL: ".$name."\n");exit(1);}echo "PASS: ".$name."\n";}
$klass='MAD4B_Priceless_CSS_Cache_V1';
$old='1234567890123456';$new='9876543210123456';
$css='<style type="text/css">.elementor-dcss-'.$old.' .price-card{background-image:url("/assets/tour.jpg")}</style>';
$canonical=$klass::prepare_css_cache($css,$old);
must(is_string($canonical)&&strpos($canonical,$old)===false,'cache canonical CSS has no old request id');
must(substr_count($canonical,'__MAD4B_CURRENT_DCSS_UNIQUE_ID__')===1,'placeholder for current request selector');
$pricing1='<div data-price-source="rate"><span>$100</span></div>';
$pricing2='<div data-price-source="rate"><span>$115</span></div>';
must($klass::serve_cached_css($canonical,$old,$pricing1)===$css.$pricing1,'original exact CSS and fresh markup');
must($klass::serve_cached_css($canonical,$new,$pricing2)===str_replace($old,$new,$css).$pricing2,'new request selector and newly computed price preserved');
must(!str_contains($canonical,'$100')&&!str_contains($canonical,'$115'),'price HTML is never stored in CSS cache');
must($klass::prepare_css_cache('<div>not CSS</div>',$old)===false,'non-CSS prefix refused');
must($klass::prepare_css_cache('<style type="text/css">.static{}</style>',$old)===false,'missing selector refused');
must($klass::prepare_css_cache('<style type="text/css">.x{color:red}',$old)===false,'unclosed stylesheet refused');
$s=file_get_contents($source);
foreach(['wp_get_environment_type()', 'is_post_type_archive(', 'HTTP_COOKIE','HTTP_AUTHORIZATION',
 'HTTP_X_MAD4B_CURRENCY','HTTP_X_GEO_COUNTRY','HTTP_CF_IPCOUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY',
 'wp_doing_ajax()','is_user_logged_in()','is_preview()','HTTP_X_MAD4B_PRICELESS_CSS_CONTROL',
 'wp_is_mobile()','post_modified_gmt','get_post_meta($post->ID)', "get_woocommerce_currency()!=='USD'",
 '322-mad4b-empty-jet-css-fastpath.php'] as $guard){
 must(str_contains($s,$guard),'source guard '.$guard);
}
must(substr_count($s,"=>[")===6,'six exact locale source pin groups');
must(str_contains($s,"'mad4b_priceless_css_v1',35"),'cache has 35-second TTL');
