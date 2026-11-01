<?php
define('ABSPATH',__DIR__);
function add_action(...$args){}
function add_filter(...$args){}
$file=__DIR__.'/../../../../wp-content/mu-plugins/329-mad4b-home-wpml-flag-preload-trim.php';
if(!is_file($file))$file='/var/www/html/wp-content/mu-plugins/329-mad4b-home-wpml-flag-preload-trim.php';
require $file;
function check($yes,$why){if(!$yes){fwrite(STDERR,"FAIL ".$why."\n");exit(2);}echo "PASS ".$why."\n";}
$flag='https://tours.dev.mad4b.com/wp-content/plugins/sitepress-multilingual-cms/res/flags/';
$head='<html><head><link rel="preload" fetchpriority="high" as="image" href="'.$flag.'en.svg">'
 .'<link rel="preload" fetchpriority="high" as="image" href="'.$flag.'en.svg">'
 .'<link rel="preload" fetchpriority="high" as="image" href="'.$flag.'ar.svg">'
 .'<link rel="preload" as="image" href="https://tours.dev.mad4b.com/wp-content/uploads/hero.webp">'
 .'<link rel="stylesheet" href="/assets/main.css"></head>';
$body='<body><img alt="Language" src="'.$flag.'en.svg"><img alt="Flag" src="'.$flag.'ar.svg"></body></html>';
$clean=MAD4B_Home_Flag_Preload_Trim_V1::transform($head.$body);
check(substr_count($clean,'rel="preload"')===1,'all redundant flag preloads removed, one hero remains');
check(str_contains($clean,'uploads/hero.webp'),'LCP image preload preserved');
check(str_contains($clean,'src="'.$flag.'en.svg"')&&str_contains($clean,'src="'.$flag.'ar.svg"'),'actual WPML flag images preserved');
check(str_contains($clean,'href="/assets/main.css"'),'CSS unchanged');
check(str_ends_with($clean,$body),'body and JS not edited');
check(MAD4B_Home_Flag_Preload_Trim_V1::transform('not HTML')==='not HTML','non-HTML fails closed');
check(MAD4B_Home_Flag_Preload_Trim_V1::transform('<head>'.str_repeat('a',8*1024*1024+1))===('<head>'.str_repeat('a',8*1024*1024+1)),'oversized HTML fails closed');
$source=file_get_contents($file);
foreach(['staging','is_front_page()','e5e64bca-ed62-48d2-b2b3-dff693cd702a','HTTP_X_MAD4B_FLAG_PRELOAD_CONTROL','litespeed_buffer_after','wpml_current_language','HTTP_COOKIE'] as $x){check(str_contains($source,$x),'guard '.$x);}
check(!str_contains($source,'update_option(')&&!str_contains($source,'wp_cache_set('),'no persistent mutation or caching');
