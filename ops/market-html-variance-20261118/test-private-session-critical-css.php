<?php
declare(strict_types=1);
const ABSPATH='/';
define('WP_CONTENT_DIR',sys_get_temp_dir().'/mad4b-private-ccss-unit-'.getmypid());
@mkdir(WP_CONTENT_DIR.'/litespeed/css',0700,true);
@mkdir(WP_CONTENT_DIR.'/mad4b-private-session-critical-css-v1',0700,true);
$option='off';$logged=false;$wcHasSession=false;$host='wayfatours.com';$env='production';$locale='en';
function get_option($key,$default=null) {
 global $option,$host;
 if ($key==='mad4b_private_session_critical_css_v1')return $option;
 if ($key==='home')return 'https://'.$host;
 return $default;
}
function wp_get_environment_type(){global $env;return $env;}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function is_user_logged_in(){global $logged;return $logged;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_preview(){return false;}
function is_feed(){return false;}
function apply_filters($tag,$x){global $locale;return $tag==='wpml_current_language'?$locale:$x;}
function esc_url($x){return $x;}
function add_action($a,$b,$c=10){}
function add_filter($a,$b,$c=10,$d=1){}
class MAD4B_Shared_First_Paint_Performance {public static function home_view(){return true;}}
class MAD4B_Experience_Site_Scope_V1 {public static function bound(){return true;}}
class WC_QA {public $session; public function __construct(){$this->session=new class {public function has_session(){global $wcHasSession;return $wcHasSession;}};}}
function WC(){static $wc;return $wc??=new WC_QA();}
$plugin=getenv('MAD4B_PRIVATE_CCSS_PLUGIN');
if(!$plugin || !is_file($plugin))throw new RuntimeException('Missing exact candidate source');
require $plugin;
$cls=MAD4B_Private_Session_Critical_CSS_V1::class;
$n=0;
function ok($name,$v){global $n;$n++;if(!$v)throw new RuntimeException('FAIL: '.$name);echo 'PASS '.$name.PHP_EOL;}
$_SERVER=['MAD4B_TRUSTED_DEVICE_CLASS'=>'mobile','REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/','QUERY_STRING'=>'','HTTP_COOKIE'=>'wordpress_logged_in_qa=placeholder'];
ok('default option off',$cls::eligible()===false);
$option='on';
ok('non-authenticated private cookie is excluded',$cls::eligible()===false);
$logged=true;
ok('validated authenticated source can enter CSS-only admission',$cls::eligible()===true);
$_SERVER['REQUEST_URI']='/tours-and-activities/';
ok('not-home route excluded',$cls::eligible()===false);
$_SERVER['REQUEST_URI']='/';
$_SERVER['HTTP_X_MAD4B_CURRENCY']='EUR';
ok('injected currency header excluded',$cls::eligible()===false);
unset($_SERVER['HTTP_X_MAD4B_CURRENCY']);
$wcHasSession=true;
ok('woocommerce session excluded',$cls::eligible()===false);
$wcHasSession=false;
$env='staging';
ok('not-production environment excluded',$cls::eligible()===false);
$env='production';
$host='other.example';
ok('other site excluded',$cls::eligible()===false);
$host='wayfatours.com';
$bundle=str_repeat('a',32).'.css';
$bundleFile=WP_CONTENT_DIR.'/litespeed/css/'.$bundle;
file_put_contents($bundleFile,str_repeat('.site-root{display:block}',7000));
$sourceSha=hash_file('sha256',$bundleFile);
ok('without certified private manifest always fallback',$cls::certified_asset($bundle,$sourceSha,'en')===null);
$html='<html><head><link rel="stylesheet" href="https://wayfatours.com/wp-content/litespeed/css/'.$bundle.'"></head><body>'.str_repeat('normal content ',3500).'</body></html>';
ok('without manifest original CSS HTML untouched',$cls::transform($html)===$html);
$css=str_repeat('.hero{display:block}',700);
$cssSha=hash('sha256',$css);
$cssFile=$cssSha.'.css';
file_put_contents(WP_CONTENT_DIR.'/mad4b-private-session-critical-css-v1/'.$cssFile,$css);
$key=hash('sha256','wayfatours.com|/|en|mobile|authenticated|'.$sourceSha);
$entry=[
  'route'=>'/','language'=>'en','device'=>'mobile','privacy_scope'=>'authenticated',
  'source_file'=>$bundle,'source_sha256'=>$sourceSha,'state'=>'certified',
  'browser_visual_qa'=>'pass','interaction_qa'=>'pass',
  'viewports'=>[390,768],'css_file'=>$cssFile,'critical_sha256'=>$cssSha
];
$base=['contract'=>'mad4b.private-session-critical-css.v1','site_host'=>'wayfatours.com','environment'=>'production',
       'entries'=>[$key=>$entry]];
$manifest=WP_CONTENT_DIR.'/mad4b-private-session-critical-css-v1/manifest.json';
file_put_contents($manifest,json_encode($base));
ok('matching exact manifest and CSS bytes accepted',$cls::certified_asset($bundle,$sourceSha,'en')!==null);
$rendered=$cls::transform($html);
ok('strict private certificate changes one stylesheet',$rendered!==$html && substr_count($rendered,'id="mad4b-private-critical-css-v1"')===1);
ok('nonblocking rest link retains original full CSS SHA URL',
   str_contains($rendered,'id="mad4b-private-css-rest-v1"') &&
   str_contains($rendered,'/wp-content/litespeed/css/'.$bundle) && str_contains($rendered,'media="print"'));
ok('native stylesheet fallback present in noscript',str_contains($rendered,'<noscript><link rel="stylesheet"'));
ok('public cache-eligibility header not generated',
   !str_contains($rendered,'X-MAD4B-Cache-Eligibility') && !str_contains($rendered,'X-Wayfa-Public-Page'));
ok('exact asset hash changes fail-closed',
    $cls::certified_asset($bundle,str_repeat('e',64),'en')===null);
ok('not-recertified language fails closed',$cls::certified_asset($bundle,$sourceSha,'es')===null);
$base['entries'][$key]['browser_visual_qa']='pending';file_put_contents($manifest,json_encode($base));
ok('visual QA pending fails closed',$cls::certified_asset($bundle,$sourceSha,'en')===null);
$base['entries'][$key]['browser_visual_qa']='pass';
$base['entries'][$key]['interaction_qa']='pending';file_put_contents($manifest,json_encode($base));
ok('interaction QA pending fails closed',$cls::certified_asset($bundle,$sourceSha,'en')===null);
$base['entries'][$key]['interaction_qa']='pass';
$base['entries'][$key]['viewports']=[390];file_put_contents($manifest,json_encode($base));
ok('768px viewport proof mandatory',$cls::certified_asset($bundle,$sourceSha,'en')===null);
$base['entries'][$key]['viewports']=[390,768];file_put_contents($manifest,json_encode($base));
file_put_contents(WP_CONTENT_DIR.'/mad4b-private-session-critical-css-v1/'.$cssFile,$css.'EXTRA');
ok('tampered CSS bytes rejected',$cls::certified_asset($bundle,$sourceSha,'en')===null);
@unlink($manifest);@unlink($bundleFile);@unlink(WP_CONTENT_DIR.'/mad4b-private-session-critical-css-v1/'.$cssFile);
@rmdir(WP_CONTENT_DIR.'/mad4b-private-session-critical-css-v1');
@rmdir(WP_CONTENT_DIR.'/litespeed/css');@rmdir(WP_CONTENT_DIR.'/litespeed');@rmdir(WP_CONTENT_DIR);
echo "PRIVATE_SESSION_CRITICAL_CSS_VERIFIED_CASES $n".PHP_EOL;
