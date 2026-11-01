<?php
define('ABSPATH','/');
define('ICL_SITEPRESS_VERSION','4.9.0');
define('WP_CONTENT_DIR',sys_get_temp_dir().'/mad4b-local-cairo-contract');
$opts=[];
function add_action(...$x){}
function add_filter(...$x){}
function wp_get_environment_type(){return 'staging';}
function wp_parse_url($s,$key){return parse_url($s,$key);}
function home_url(){return 'https://tours.dev.mad4b.com';}
function get_option($k,$d=[]){
 global $opts;
 if($k==='mad4b_scp_brand_context_profile_v1')return ['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'];
 if($k==='mad4b_uael_css_eligibility_v1')return ['mode'=>'nonblocking_home_only','site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a','enabled'=>true,'origin'=>'https://tours.dev.mad4b.com','mobile_tuned_routes'=>['/tours-and-activities/','/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/']];
 return $opts[$k]??$d;
}
function is_admin(){return false;}
function is_customize_preview(){return false;}
function is_front_page(){return $GLOBALS['front']??true;}
function is_singular($type){return $GLOBALS['singular']??false;}
function get_queried_object_id(){return $GLOBALS['qid']??0;}
function apply_filters($name,$value,...$other){if($name==='wpml_active_languages')return ['en'=>[],'ar'=>[],'fr'=>[]];if($name==='wpml_object_id'&&$value===60631)return 60629;return $value;}
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
$_SERVER['REQUEST_URI']='/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/';
$product=MAD4B_UAEL_Critical_CSS_Policy_V1::stylesheet($src,'uael-frontend','https://tours.dev.mad4b.com/uael.css','all');
$assert(str_contains($product,'screen and (min-width: 769px)')&&str_contains($product,'onload=')&&str_contains($product,'<noscript>'),'product mobile UAEL CSS non-blocking while desktop remains blocking');
$_SERVER['REQUEST_URI']='/tours-and-activities/';
$archive=MAD4B_UAEL_Critical_CSS_Policy_V1::stylesheet($src,'uael-frontend','https://tours.dev.mad4b.com/uael.css','all');
$assert(str_contains($archive,'min-width: 769px'),'catalog archive uses mobile-only non-blocking UAEL');
$GLOBALS['singular']=true;$GLOBALS['qid']=60631;
$_SERVER['REQUEST_URI']='/fr/tours-and-activities/le-meilleur-de-legypte-le-caire-louxor-croisiere-sur-le-nil-et-mer-rouge-12-jours/';
$translated=MAD4B_UAEL_Critical_CSS_Policy_V1::stylesheet($src,'uael-frontend','https://tours.dev.mad4b.com/uael.css','all');
$assert(str_contains($translated,'min-width: 769px'),'translated product slug resolves original WPML product styling');
$GLOBALS['singular']=false;
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

$fontBase=WP_CONTENT_DIR.'/uploads/elementor/google-fonts';
@mkdir($fontBase.'/css',0777,true);
@mkdir($fontBase.'/fonts',0777,true);
file_put_contents($fontBase.'/css/cairo.css',"font-family: 'Cairo';font-display: swap;src: url('cairo.woff2') format('woff2');".str_repeat(' ',1150));
file_put_contents($fontBase.'/fonts/cairo-0d2badd4.woff2','mock-font');
file_put_contents($fontBase.'/fonts/cairo-eb4f7cda.woff2','mock-font');
require dirname(__DIR__,4).'/wp-content/mu-plugins/309-mad4b-astra-local-cairo.php';
$opts['mad4b_astra_local_cairo_v1']=['enabled'=>true];
$rem='https://fonts.googleapis.com/css?family=Cairo:400%2C%2C600&display=swap';
$local=MAD4B_Astra_Local_Cairo_V1::url($rem,'astra-google-fonts');
$assert($local==='https://tours.dev.mad4b.com/wp-content/uploads/elementor/google-fonts/css/cairo.css','Astra Cairo uses same-origin WOFF2 CSS');
$assert(MAD4B_Astra_Local_Cairo_V1::url($rem,'unrelated')===$rem,'unrelated styles are not intercepted');
$opts['mad4b_astra_local_cairo_v1']=['enabled'=>false];
$assert(MAD4B_Astra_Local_Cairo_V1::url($rem,'astra-google-fonts')===$rem,'Cairo localization feature gate supports rollback');

$opts['mad4b_live_render_blocking_v1']['observed_at']=gmdate('c');
$opts['mad4b_live_render_blocking_v1']['issues']=[['code'=>'perf_ttfb_site','severity'=>'high','label'=>'Slow median server']];
$ttfb=MAD4B_Live_Render_Blocking_Monitor_V1::external([],[]);
$assert(count($ttfb)===1&&$ttfb[0]['code']==='perf_ttfb_site','TTFB high alert uses live-performance native provider');

$monitorSource=file_get_contents(dirname(__DIR__,4).'/wp-content/mu-plugins/308-mad4b-live-render-blocking-monitor.php');
$assert(str_contains($monitorSource,'>24') && str_contains($monitorSource,'>80'),
    'expanded browser probe keeps bounded 24-page and 80-issue limits');
$assert(str_contains($monitorSource,'isset($seen[$code])') && str_contains($monitorSource,'e5e64bca-ed62-48d2-b2b3-dff693cd702a'),
    'expanded evidence import retains unique codes and site UUID binding');
