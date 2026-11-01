<?php
define('ABSPATH','/');
define('WP_CONTENT_DIR',sys_get_temp_dir().'/mad4b-lcp-preload-fixture');
function add_action(...$args){}
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function get_option($name,$default=[]){
 if($name==='mad4b_scp_brand_context_profile_v1')return ['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'];
 if($name==='mad4b_city_lcp_preload_v1')return ['enabled'=>true];
 return $default;
}
function esc_url($url){return htmlspecialchars($url,ENT_QUOTES,'UTF-8');}
function is_admin(){return false;}
function is_feed(){return false;}
function is_preview(){return false;}
function is_front_page(){return true;}
function wp_doing_ajax(){return false;}
$assert=static function($ok,$title){if(!$ok){fwrite(STDERR,'FAIL '.$title."\n");exit(1);}echo 'PASS '.$title."\n";};
require dirname(__DIR__,4).'/wp-content/mu-plugins/310-mad4b-city-slider-lcp-preload.php';
$path='/wp-content/uploads/2026/03/sampled-card-768x512.jpg';
$fixture=WP_CONTENT_DIR.'/uploads/2026/03/sampled-card-768x512.jpg.webp';
@mkdir(dirname($fixture),0777,true);file_put_contents($fixture,str_repeat('z',2048));
$url='https://tours.dev.mad4b.com'.$path;
$first='<div class="jet-listing-grid__items jet-listing-grid--45927 swiper-wrapper"><div class="jet-listing-grid__item swiper-slide"><style>.sample{background-image:url('.$url.')}</style><div style="background-image:url(&quot;'.$url.'&quot;);" class="elementor-element elementor-element-95d1c9c" data-id="95d1c9c"></div></div></div>';
$html='<!DOCTYPE html><html><head><meta charset="UTF-8"><style>.x{color:red}</style></head><body>'.$first.'</body></html>';
$_SERVER['REQUEST_URI']='/';
$assert(MAD4B_City_First_Card_LCP_V1::should_buffer(),'bounded English staging homepage route');
$from=MAD4B_City_First_Card_LCP_V1::first_visible_background($html);
$assert($from===$url.'.webp','exact CDN-optimized WebP LCP asset chosen, not duplicate original JPEG');
$out=MAD4B_City_First_Card_LCP_V1::transform($html);
$assert(substr_count($out,'id="mad4b-city-first-slide-lcp"')===1,'only one highest-priority image preload');
$assert(strpos($out,'id="mad4b-city-first-slide-lcp"')<1024,'preload appears in first 1KiB of HTML head');
$assert(str_contains($out,'fetchpriority="high"')&&str_contains($out,'as="image"')&&str_contains($out,'type="image/webp"'),'Chrome LCP discovery contract');
$assert(str_contains($out,'media="(max-width: 767px)"'),'desktop does not request mobile preload');
$assert(str_contains($out,$first),'JetEngine card and its CSS background remain fully intact');
@unlink($fixture);
$assert(MAD4B_City_First_Card_LCP_V1::first_visible_background($html)===null,'prevents duplicate load if WebP sibling is missing');
$bad=str_replace('tours.dev.mad4b.com/wp-content/uploads/','foreign.invalid/wp-content/uploads/',$html);
$assert(MAD4B_City_First_Card_LCP_V1::first_visible_background($bad)===null,'rejects cross-site LCP image preloads');
$_SERVER['REQUEST_URI']='/ar/';
$assert(!MAD4B_City_First_Card_LCP_V1::should_buffer(),'no preloading non-target locale or unrelated pages');
