<?php
define('ABSPATH','/');
$GLOBALS['env']='staging';$GLOBALS['scope']=true;
$GLOBALS['opt']='on';$GLOBALS['home']=true;$GLOBALS['logged']=false;$GLOBALS['admin']=false;$GLOBALS['ajax']=false;$_GET=[];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['QUERY_STRING']='';
class MAD4B_Experience_Site_Scope_V1 {public static function bound(){return $GLOBALS['scope'];}}
class MAD4B_Shared_First_Paint_Performance{public static function home_view(){return $GLOBALS['home'];}}
function wp_get_environment_type(){return $GLOBALS['env'];}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function is_user_logged_in(){return $GLOBALS['logged'];}
function get_option($name,$default=null){return $name==='mad4b_perf_stable_dcss_v1'?$GLOBALS['opt']:$default;}
function add_action($hook,$fn,$priority=10){}
function add_filter($hook,$fn,$priority=10,$args=1){}
function ok($label,$pass){if(!$pass)throw new RuntimeException('FAIL: '.$label);echo "PASS $label\n";}
$src=getenv('MAD4B_STABLE_CSS_SOURCE');if(!$src||!is_file($src))throw new RuntimeException('Missing source');require $src;
$clazz='MAD4B_Stable_Listing_CSS_Identity';
$method=new ReflectionMethod($clazz,'enabled');$method->setAccessible(true);
$run=static fn()=> $method->invoke(null);
ok('Staging normal homepage remains enabled',$run());
$GLOBALS['env']='production';
ok('Production normal homepage maintains existing ON option',$run());
$_GET=['mad4b_stable_dcss'=>'1'];$_SERVER['QUERY_STRING']='mad4b_stable_dcss=1';
ok('Production public QA flag denied',$run()===false);
$_GET=['mad4b_public_fragment_qa'=>'1'];$_SERVER['QUERY_STRING']='mad4b_public_fragment_qa=1';
ok('Production fragment QA flag denied',$run()===false);
$GLOBALS['env']='staging';
ok('Staging approved fragment QA works',$run());
$_GET=['mad4b_stable_dcss'=>'1'];$_SERVER['QUERY_STRING']='mad4b_stable_dcss=1';
ok('Staging normal QA works',$run());
$GLOBALS['scope']=false;ok('foreign company denied',$run()===false);$GLOBALS['scope']=true;
$GLOBALS['admin']=true;ok('admin denied',$run()===false);$GLOBALS['admin']=false;
$GLOBALS['logged']=true;ok('logged user denied',$run()===false);$GLOBALS['logged']=false;
$GLOBALS['home']=false;ok('non-homepage denied',$run()===false);$GLOBALS['home']=true;
$_GET=[];$_SERVER['QUERY_STRING']='';$GLOBALS['opt']='off';
ok('explicit OFF respected',$run()===false);
ok('cache_ready supported',$clazz::cache_ready()===false);
echo "PASS_STABLE_CSS_COMPANY_SCOPE\n";
