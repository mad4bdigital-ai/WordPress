<?php
define('ABSPATH','/');
define('ICL_SITEPRESS_VERSION','4.9.0');
$opts=[];
function add_action(...$x){}
function add_filter(...$x){}
function wp_get_environment_type(){return 'staging';}
function wp_parse_url($s,$key){return parse_url($s,$key);}
function home_url(){return 'https://tours.dev.mad4b.com';}
function get_option($k,$d=[]){
 global $opts;
 if($k==='mad4b_scp_brand_context_profile_v1')return ['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'];
 if($k==='mad4b_uael_css_eligibility_v1')return ['mode'=>'nonblocking_home_only','site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a','enabled'=>true,'origin'=>'https://tours.dev.mad4b.com'];
 return $opts[$k]??$d;
}
function is_admin(){return false;}
function is_customize_preview(){return false;}
function is_front_page(){return $GLOBALS['front']??true;}
function apply_filters($name,$value,...$other){return $name==='wpml_active_languages'?['en'=>[],'ar'=>[],'fr'=>[]]:$value;}
function esc_url($u){return $u;}
function get_current_blog_id(){return 1;}
$assert=static function($test,$why){if(!$test){fwrite(STDERR,'FAIL '.$why."\n");exit(1);}echo 'PASS '.$why."\n";};
require dirname(__DIR__,4).'/wp-content/mu-plugins/307-mad4b-uael-critical-css-policy.php';
require dirname(__DIR__,4).'/wp-content/mu-plugins/308-mad4b-live-render-blocking-monitor.php';
$src='<link rel="stylesheet" id="uael-frontend-css" href="https://tours.dev.mad4b.com/uael.css" media="all" />';
$_SERVER['REQUEST_URI']='/';$GLOBALS['front']=true;
$s=MAD4B_UAEL_Critical_CSS_Policy_V1::stylesheet($src,'uael-frontend','https://tours.dev.mad4b.com/uael.css','all');
$assert(str_contains($s,'media="print"') && str_contains($s,'onload=') && str_contains($s,'<noscript>'),'homepage CSS nonblocking with no-JS fallback');
$assert(str_contains($s,'href="https://tours.dev.mad4b.com/uael.css"'),'original UAEL CSS URL retained');
$assert(MAD4B_UAEL_Critical_CSS_Policy_V1::stylesheet($src,'another-style','/x.css','all')===$src,'unrelated stylesheet unchanged');
$_SERVER['REQUEST_URI']='/ar/';$GLOBALS['front']=false;
$assert(str_contains(MAD4B_UAEL_Critical_CSS_Policy_V1::stylesheet($src,'uael-frontend','https://tours.dev.mad4b.com/uael.css','all'),'media="print"'),'WPML Arabic frontpage handled without localized origin mismatch');
$_SERVER['REQUEST_URI']='/destination/egypt/';$GLOBALS['front']=false;
$assert(MAD4B_UAEL_Critical_CSS_Policy_V1::stylesheet($src,'uael-frontend','https://tours.dev.mad4b.com/uael.css','all')===$src,'destination UAEL remains render critical');
$opts['mad4b_live_render_blocking_v1']=['complete'=>true,'observed_at'=>gmdate('c'),'pages'=>array_fill(0,7,['http_status'=>200]),'issues'=>[['code'=>'perf_rbcss_daa3ffea4aa4','severity'=>'high','label'=>'Observed CSS rendering blocked']], 'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'];
$r=MAD4B_Live_Render_Blocking_Monitor_V1::external([],[]);
$assert(count($r)===1&&$r[0]['source']==='mad4b-live-performance'&&$r[0]['post_type']==='site','live browser issue enters MAD4B provider');
$assert($r[0]['id']===hash('sha256','1|site|0|'.$r[0]['code']),'performance issue has exact source stable ID');
$opts['mad4b_live_render_blocking_v1']['issues']=[];
$assert(MAD4B_Live_Render_Blocking_Monitor_V1::external([],[])===[],'resolved live issue automatically removed from discovery');
$opts['mad4b_live_render_blocking_v1']['observed_at']=gmdate('c',time()-7200);
$stale=MAD4B_Live_Render_Blocking_Monitor_V1::external([],[]);
$assert(count($stale)===1&&$stale[0]['code']==='perf_probe_stale','missing fresh live probe triggers regression warning');
