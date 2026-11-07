<?php
/** Strict local fixture for production/staging predictable CSS manifest isolation. */
define('ABSPATH','/');
define('WP_CONTENT_DIR','/tmp/mad4b-predictive-css-unit');
$GLOBALS['site']=1;
$GLOBALS['bound']=true;
$GLOBALS['homepage']=true;
$GLOBALS['home_urls']=[1=>'https://tours.dev.mad4b.com',2=>'https://wayfatours.com'];
$GLOBALS['language']='en';$GLOBALS['home_url_calls']=0;
function get_current_blog_id(){return $GLOBALS['site'];}
function get_option($key,$default=null){
 if($key==='home')return $GLOBALS['home_urls'][$GLOBALS['site']];
 return $default;
}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function home_url($path='/'){$GLOBALS['home_url_calls']++;throw new RuntimeException('localized home_url must never identify cache owner');}
function add_action(...$args){}
function add_filter(...$args){}
class MAD4B_Experience_Site_Scope_V1{public static function bound(){return $GLOBALS['bound'];}}
function pass($label,$v){if(!$v)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$dir=WP_CONTENT_DIR.'/mad4b-predictive-css-v2';
if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('cannot create fixture directory');
$f=$dir.'/manifest.json';
$manifest=['version'=>2,'contract'=>'mad4b.predictive-css-plan.v2',
 'site_host'=>'tours.dev.mad4b.com','release_state'=>'canary_only','routes'=>[
 '/|en'=>['path'=>'/','language'=>'en','devices'=>['mobile'=>['state'=>'canary','strategy'=>'critical_inline_then_native_async']]]]];
file_put_contents($f,json_encode($manifest));
$path=getenv('MAD4B_CRITICAL_PORTABLE_SOURCE');
if(!$path||!is_file($path))throw new RuntimeException('missing target');
require $path;
$cls=MAD4B_Predictive_CSS_Plan_V2::class;
$s=$cls::summary();
pass('Source company matches own manifest', $s['present']===true);
pass('Source release status only canary', $s['release']==='canary_only');
$GLOBALS['site']=2;$GLOBALS['language']='es';
$s=$cls::summary();
pass('Second company cannot reuse first company manifest', $s['present']===false);
pass('Second company stays off', $s['runtime']==='off');
$GLOBALS['site']=1;$GLOBALS['language']='fr';
$s=$cls::summary();
pass('Language switch does not break canonical company', $s['present']===true);
$GLOBALS['bound']=false;
$s=$cls::summary();
pass('Unauthorized site cannot read memoized manifest', $s['present']===false);
$GLOBALS['bound']=true;
$GLOBALS['site']=2;
$GLOBALS['home_urls'][2]='https://tours.dev.mad4b.com';
$s=$cls::summary();
pass('Blog identity partition does not reuse another blog', $s['present']===true);
$GLOBALS['home_urls'][2]='https://wayfatours.com';
$s=$cls::summary();
pass('Canonical home changed: previous manifest never reused', $s['present']===false);
pass('No WPML-localized home_url called', $GLOBALS['home_url_calls']===0);
unlink($f);
echo "PASS_PREDICTIVE_CSS_TENANT_ISOLATION\n";
