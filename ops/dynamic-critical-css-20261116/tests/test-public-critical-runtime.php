<?php
declare(strict_types=1);
define('ABSPATH','/test/');
define('WP_CONTENT_DIR','/tmp/mad4b-public-critical-runtime-unit-20261117-'.getmypid());
@mkdir(WP_CONTENT_DIR.'/litespeed/css',0700,true);
@mkdir(WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/assets',0700,true);
@mkdir(WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/certs',0700,true);
$GLOBALS['options']=['home'=>'https://tours.dev.mad4b.com','mad4b_perf_critical_css_all_public_v1'=>'off',
 'mad4b_css_ai_epoch_v1'=>129,'litespeed.conf.optm-css_async'=>'0'];
$GLOBALS['calls']=[];
function get_option($k,$default=null){return $GLOBALS['options'][$k]??$default;}
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
function apply_filters($key,$value=null){return $key==='wpml_current_language'?'en':$value;}
function get_woocommerce_currency(){return 'USD';}
function mad4b_render_query_market_identity(){
 return ['country'=>$GLOBALS['marketCountry']??'DE','currency'=>'USD'];
}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??0;}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function add_action($hook,$cb,$priority=10,$args=1){$GLOBALS['calls'][$hook]=$cb;}
function add_filter($hook,$cb,$priority=10,$args=1){$GLOBALS['calls'][$hook][]=$cb;}
class MAD4B_Public_Critical_CSS_Coverage_V1 {
 public static function authorized(){return true;}
 public static function allowed_path($path){return $path==='/contact-us/';}
}
require '/tmp/mad4b-public-css-runtime-lint-20261117.php';
$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']='mobile';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/contact-us/';
$_SERVER['QUERY_STRING']='';
$fname=str_repeat('a',32).'.css';
$source=str_repeat('h1{font-size:32px}.contact{display:flex}',5000);
$critical=str_repeat('.contact{display:block}h1{font-size:18px}',600);
file_put_contents(WP_CONTENT_DIR.'/litespeed/css/'.$fname,$source);
$cs=hash('sha256',$critical);
file_put_contents(WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/assets/'.$cs.'.css',$critical);
$doc=hash('sha256','69520');
$cert=[
 'contract'=>'mad4b.public-critical-css.route-certificate.v1','status'=>'certified',
 'host'=>'tours.dev.mad4b.com','path'=>'/contact-us/','language'=>'en',
 'type'=>'page','object_id'=>69520,'market_country'=>'static','currency'=>'USD',
 'css_epoch'=>129,'runtime_sha'=>hash_file('sha256','/tmp/mad4b-public-css-runtime-lint-20261117.php'),
 'critical_cap_px'=>767,'native_mobile_breakpoint'=>767,
 'css_file'=>$fname,'css_sha'=>hash('sha256',$source),
 'critical_file'=>$cs.'.css','critical_sha'=>$cs,'critical_bytes'=>strlen($critical),
 'elementor_doc_fingerprint'=>$doc,
 'dependencies'=>['global_generation'=>0,'route_generation'=>0,
    'listing_generation'=>0,'contains_dynamic_listing'=>false],
 'evidence'=>['decision'=>'passed_evidence','viewports'=>[320,390,414,767,768],'tested'=>5]
];
$key=hash('sha256','tours.dev.mad4b.com|en|/contact-us/|static|USD');
$path=WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/certs/'.$key.'.json';
file_put_contents($path,json_encode($cert,JSON_UNESCAPED_SLASHES));
$html='<html><head><title>Contact</title><link data-optimized="2" rel="stylesheet" href="https://tours.dev.mad4b.com/wp-content/litespeed/css/'.$fname.'"></head><body class="page-id-69520"><main class="elementor elementor-69520">Contact</main>'.str_repeat('test ',300).'</body></html>';
function check($label,$condition){
 if(!$condition)throw new RuntimeException('FAIL '.$label);
 echo "PASS $label\n";
}
$GLOBALS['options']['mad4b_perf_critical_css_all_public_v1']='on';
$GLOBALS['mock_password']='private';
check('published password-protected page never receives public CSS',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['mock_password']='';
$GLOBALS['mock_status']='draft';
check('unpublished page never receives public CSS',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['mock_status']='publish';
$GLOBALS['mock_preview']=true;
check('WordPress preview never receives public CSS',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['mock_preview']=false;
$GLOBALS['options']['mad4b_perf_critical_css_all_public_v1']='off';
check('opt-in off preserves complete native CSS',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['options']['mad4b_perf_critical_css_all_public_v1']='on';
check('full verified route rewrites exactly once',
 str_contains(MAD4B_Public_Critical_CSS_Runtime_V1::transform($html),'id="mad4b-public-critical-css-v1"'));
$done=MAD4B_Public_Critical_CSS_Runtime_V1::transform($html);
check('original CSS render blocking at 768px',
 str_contains($done,'media="(min-width:768px)"'));
check('critical stylesheet only below 768px',
 str_contains($done,'media="(max-width:767px)"'));
check('original complete CSS fallback present',
 str_contains($done,'<noscript><link'));
check('native stylesheet appears once in active markup',
 substr_count($done,'id="mad4b-public-css-rest-v1"')===1);
check('no duplicate transform',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($done)===$done);
$_SERVER['QUERY_STRING']='currency=EUR';
check('query-string and price variants cannot reuse certificate',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$_SERVER['QUERY_STRING']='';
$_SERVER['HTTP_COOKIE']='woocommerce_items_in_cart=1';
check('cart cookies cannot receive a static first-paint certificate',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
unset($_SERVER['HTTP_COOKIE']);
$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']='desktop';
check('unmeasured desktop remains native CSS',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']='mobile';
$cert['css_epoch']=128;file_put_contents($path,json_encode($cert));
check('stale CSS epoch invalidates route certificate',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['css_epoch']=129;$cert['css_sha']=hash('sha256','bad');file_put_contents($path,json_encode($cert));
check('source CSS byte drift preserves original stylesheet',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['css_sha']=hash('sha256',$source);$cert['object_id']=1;file_put_contents($path,json_encode($cert));
check('wrong WordPress queried object cannot reuse route',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['object_id']=69520;$cert['currency']='EUR';file_put_contents($path,json_encode($cert));
check('foreign currency cannot reuse market-specific proof',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['currency']='USD';$cert['evidence']['viewports']=[320,390,414,768];file_put_contents($path,json_encode($cert));
check('missing Elementor 767px performance evidence rejected',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['evidence']['viewports']=[320,390,414,767,768];
file_put_contents($path,json_encode($cert));
check('live certificate restored without regeneration',MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)!==$html);
$cert['runtime_sha']=hash('sha256','outdated-runtime');
file_put_contents($path,json_encode($cert));
check('stale PHP transformer version cannot inherit older browser evidence',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$cert['runtime_sha']=hash_file('sha256','/tmp/mad4b-public-css-runtime-lint-20261117.php');
file_put_contents($path,json_encode($cert));
$GLOBALS['meta'][69520]['_mad4b_ccss_route_generation_v1']=3;
check('same CSS with modified content invalidates old LCP proof',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);
$GLOBALS['meta'][69520]['_mad4b_ccss_route_generation_v1']=0;
$GLOBALS['options']['mad4b_ccss_global_generation_v1']=1;
check('global Elementor or CSS event invalidates old certificate',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($html)===$html);

$GLOBALS['options']['mad4b_ccss_global_generation_v1']=0;
$dynamic=str_replace('class="elementor elementor-69520"',
    'class="elementor elementor-69520 jet-listing-grid"',$html);
check('dynamic JetEngine listing cannot inherit static country certificate',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($dynamic)===$dynamic);
$marketCert=$cert;
$marketCert['market_country']='DE';
$marketCert['dependencies']['contains_dynamic_listing']=true;
$marketKey=hash('sha256','tours.dev.mad4b.com|en|/contact-us/|DE|USD');
$marketPath=WP_CONTENT_DIR.'/mad4b-public-critical-css-v1/certs/'.$marketKey.'.json';
file_put_contents($marketPath,json_encode($marketCert,JSON_UNESCAPED_SLASHES));
check('verified trusted country and currency admit market-dependent listing',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($dynamic)!==$dynamic);
$GLOBALS['marketCountry']='FR';
check('same currency but different country rejects German listing proof',
 MAD4B_Public_Critical_CSS_Runtime_V1::transform($dynamic)===$dynamic);
echo "PASS 24/24 public Critical CSS content-addressed runtime safety tests\n";
