<?php
declare(strict_types=1);
define('ABSPATH','/tmp/mad4b-multicss-v2-'.getmypid().'/site/');
define('WP_CONTENT_DIR',ABSPATH.'wp-content');
@mkdir(WP_CONTENT_DIR.'/plugins/example',0700,true);
@mkdir(WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/assets',0700,true);
@mkdir(WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/certs-v2',0700,true);
$GLOBALS['options']=['home'=>'https://tours.dev.mad4b.com',
 'mad4b_perf_critical_css_all_public_v1'=>'on',
 'mad4b_perf_multi_css_v2'=>'on','mad4b_css_ai_epoch_v1'=>129,
 'litespeed.conf.optm-css_async'=>'0'];
$GLOBALS['meta']=[];
function get_option($k,$d=null){return $GLOBALS['options'][$k]??$d;}
function wp_parse_url($v,$p=null){return parse_url($v,$p);}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function is_front_page(){return false;}
function is_home(){return false;}
function is_singular($types=[]){return true;}
function is_tax($types=[]){return false;}
function is_category(){return false;}
function is_post_type_archive($types=[]){return false;}
function get_queried_object_id(){return 69520;}
function get_post_type($id){return 'page';}
function get_post($id){return (object)['ID'=>$id,'post_status'=>$GLOBALS['mock_status']??'publish','post_password'=>$GLOBALS['mock_password']??''];}
function is_preview(){return !empty($GLOBALS['mock_preview']);}
function apply_filters($k,$v=null){return $k==='wpml_current_language'?'en':$v;}
function get_woocommerce_currency(){return $GLOBALS['currency']??'USD';}
function mad4b_render_query_market_identity(){return ['country'=>$GLOBALS['marketCountry']??'DE','currency'=>$GLOBALS['currency']??'USD'];}
function get_post_meta($id,$k,$single=true){return $GLOBALS['meta'][$id][$k]??0;}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function add_action($hook,$call,$priority=10,$args=1){}
function add_filter($hook,$call,$priority=10,$args=1){}
class MAD4B_Public_Critical_CSS_Coverage_V1 {
 public static function authorized(){return true;}
 public static function allowed_path($path){return $path==='/contact-us/';}
}
$plugins=dirname(__DIR__,3).'/wp-content/mu-plugins/';
require $plugins.'422-mad4b-public-critical-css-runtime.php';
require $plugins.'422-mad4b-public-critical-css-v2-consumer.php';
$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']='mobile';
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/contact-us/';
$_SERVER['QUERY_STRING']='';
$count=0;
function check($name,$ok){global $count;if(!$ok)throw new RuntimeException('FAIL '.$name);$count++;echo 'PASS '.$name."\n";}
function canon($x){
 if(!is_array($x))return $x;
 if(array_is_list($x))return array_map('canon',$x);
 ksort($x,SORT_STRING);
 foreach($x as $k=>$v)$x[$k]=canon($v);
 return $x;
}
$links=[];$media=[];
$values=['.one{color:red}','.two{color:navy}'];
foreach($values as $i=>$text){
 $css=str_repeat($text,1500);
 $file=WP_CONTENT_DIR.'/plugins/example/'.($i?'b':'a').'.css';
 file_put_contents($file,$css);
 $href='https://tours.dev.mad4b.com/wp-content/plugins/example/'.($i?'b':'a').'.css';
 $links[]='<link rel="stylesheet" href="'.$href.'">';
 $media[]=['order'=>$i,'slot'=>$i,'href'=>$href,
    'path'=>'/wp-content/plugins/example/'.($i?'b':'a').'.css',
    'media'=>'all','bytes'=>strlen($css),'sha256'=>hash('sha256',$css)];
}
$m=['contract'=>'mad4b.public-css.ordered-dependencies.v1',
    'host'=>'tours.dev.mad4b.com','files'=>$media,
    'total_bytes'=>array_sum(array_column($media,'bytes'))];
$m['manifest_sha256']=hash('sha256',json_encode(canon($m),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$chunks=[];$bytes=0;
foreach([0,1] as $i){
 $content=str_repeat('h1{font-size:15px}',650);
 $sum=hash('sha256',$content);
 file_put_contents(WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/assets/'.$sum.'.css',$content);
 $chunks[]=['slot'=>$i,'source_sha256'=>str_repeat((string)$i,64),
     'source_bytes'=>strlen($content),
     'critical_sha256'=>$sum,'critical_bytes'=>strlen($content),
     'critical_file'=>$sum.'.css'];
 $bytes+=strlen($content);
}
$cert=[
  'contract'=>'mad4b.public-critical-css.route-certificate.v2','status'=>'certified',
  'host'=>'tours.dev.mad4b.com','path'=>'/contact-us/','language'=>'en',
  'type'=>'page','object_id'=>69520,'market_country'=>'static','currency'=>'USD',
  'css_epoch'=>129,'runtime_sha'=>hash_file('sha256',$plugins.'422-mad4b-public-critical-css-runtime.php'),
  'consumer_sha'=>hash_file('sha256',$plugins.'422-mad4b-public-critical-css-v2-consumer.php'),
  'critical_cap_px'=>767,'native_mobile_breakpoint'=>767,
  'source_manifest'=>$m,'source_manifest_sha256'=>$m['manifest_sha256'],
  'critical_total_bytes'=>$bytes,'critical_slots'=>$chunks,
  'elementor_doc_fingerprint'=>hash('sha256','69520'),
  'dependencies'=>['global_generation'=>0,'route_generation'=>0,'listing_generation'=>0,
    'contains_dynamic_listing'=>false],
  'evidence'=>['decision'=>'passed_evidence','viewports'=>[320,390,414,767,768],
      'tested'=>5,'performance_source'=>'real-original-css-urls',
      'qa_sha256'=>str_repeat('a',64),'minimum_mobile_fcp_gain_ms'=>200]
];
$key=hash('sha256','tours.dev.mad4b.com|en|/contact-us/|static|USD');
$certfile=WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/certs-v2/'.$key.'.json';
function store($cert){global $certfile;file_put_contents($certfile,json_encode($cert,JSON_UNESCAPED_SLASHES));}
store($cert);
$html='<html><head><title>Contact</title>'.$links[0]
    .'<style type="text/css">h1{color:green}</style>'.$links[1]
    .'</head><body class="page-id-69520"><main class="elementor elementor-69520">Test</main>'
    .str_repeat('sample ',200).'</body></html>';
$GLOBALS['options']['mad4b_perf_multi_css_v2']='off';
check('opt-out retains all original CSS links',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['options']['mad4b_perf_multi_css_v2']='on';
$result=MAD4B_Public_Critical_CSS_Runtime_V1::transform($html);
check('exact certificate transforms both source CSS links',$result!==$html);
check('per-slot critical styles appear at inline boundaries',substr_count($result,'data-mad4b-ccss-v2-critical="1"')===2);
check('both original source styles retained with deferred media',substr_count($result,'data-mad4b-ccss-v2-rest="1"')===2);
check('full CSS restored on load or by a timeout without losing original media',
    substr_count($result,'id="mad4b-public-ccss-v2-recovery"')===1
    && str_contains($result,'setTimeout(f,6500)'));
check('original inline style retained in exact order',strpos($result,'rest-v2-0')<strpos($result,'h1{color:green}')&&strpos($result,'h1{color:green}')<strpos($result,'rest-v2-1'));
check('non-script original CSS fallbacks preserved',substr_count($result,'<noscript><link')===2);
check('readback verifies all replaced links',MAD4B_Public_Critical_CSS_Consumer_V2::readback($result)===true);
check('idempotent HTML transformation',MAD4B_Public_Critical_CSS_Runtime_V1::transform($result)===$result);
$again=MAD4B_Public_Critical_CSS_Runtime_V1::transform($html);
$corrupted=str_replace('data-mad4b-ccss-v2-rest="1"','data-mad4b-ccss-v2-rest="0"',$again);
check('post-transform late filter mutation restores full original HTML',
    MAD4B_Public_Critical_CSS_Runtime_V1::readback($corrupted)===$html);
$mismatch=str_replace('href="'.$media[1]['href'].'"','href="https://example.org/other.css"',$html);
check('foreign stylesheet fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($mismatch)===$mismatch);
$mismatch=str_replace('<style type="text/css">h1{color:green}</style>','',$html);
check('missing inline style changes cascade slot and fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($mismatch)===$mismatch);
$mismatch=str_replace($links[0],'<link rel="stylesheet" href="'.$media[1]['href'].'">',$html);
check('reordered source CSS fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($mismatch)===$mismatch);
$mismatch=str_replace('<link rel="stylesheet"','<link rel="stylesheet" onload="alert(1)"',$html);
check('unmeasured link load handler fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($mismatch)===$mismatch);
$cert['runtime_sha']=str_repeat('1',64);store($cert);
check('unapproved 422 runtime SHA fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['runtime_sha']=hash_file('sha256',$plugins.'422-mad4b-public-critical-css-runtime.php');
$cert['consumer_sha']=str_repeat('2',64);store($cert);
check('unapproved v2 consumer SHA fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['consumer_sha']=hash_file('sha256',$plugins.'422-mad4b-public-critical-css-v2-consumer.php');
$cert['critical_slots'][1]['critical_sha256']=str_repeat('f',64);store($cert);
check('critical CSS SHA tamper fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['critical_slots'][1]['critical_sha256']=$chunks[1]['critical_sha256'];
$cert['source_manifest']['files'][0]['sha256']=str_repeat('f',64);store($cert);
check('source manifest tamper fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['source_manifest']=$m;
$cert['evidence']['viewports']=[320,390,414,768];store($cert);
check('missing 767px QA denies public transform',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['evidence']['viewports']=[320,390,414,767,768];store($cert);
$_SERVER['QUERY_STRING']='currency=EUR';
check('query-string currency bypass prohibited',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$_SERVER['QUERY_STRING']='';
$GLOBALS['options']['mad4b_ccss_global_generation_v1']=1;
check('stale dependency generation fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['options']['mad4b_ccss_global_generation_v1']=0;
$GLOBALS['mock_password']='secret';
check('password-protected posts retain native CSS',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['mock_password']='';
$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']='desktop';
check('desktop retains native CSS',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']='mobile';
$original=file_get_contents(WP_CONTENT_DIR.'/plugins/example/b.css');
file_put_contents(WP_CONTENT_DIR.'/plugins/example/b.css',$original.'/*changed*/');
check('source CSS changed on disk fails closed',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
check('critical CSS transformed readback is not forged after native fallback',
    MAD4B_Public_Critical_CSS_Consumer_V2::readback($html)===false);
echo "PASS ".$count."/".$count." v2 multi-CSS runtime tests\n";
