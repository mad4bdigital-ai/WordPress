<?php
define('ABSPATH','/');
$GLOBALS['env']='staging';$GLOBALS['scope']=true;$GLOBALS['option']='off';$GLOBALS['home']=true;
$GLOBALS['admin']=false;$GLOBALS['ajax']=false;$GLOBALS['logged']=false;$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['QUERY_STRING']='';$_GET=[];
class MAD4B_Experience_Site_Scope_V1{public static function bound(){return $GLOBALS['scope'];}}
class MAD4B_Shared_First_Paint_Performance{public static function home_view(){return $GLOBALS['home'];}}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function is_user_logged_in(){return $GLOBALS['logged'];}
function wp_get_environment_type(){return $GLOBALS['env'];}
function get_option($name,$default=null){if($name==='mad4b_perf_critical_css_v1')return $GLOBALS['option'];return $default;}
function add_action(...$args){}
function add_filter(...$args){}
function ok($name,$value){if(!$value)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";}
$f=getenv('MAD4B_CRITICAL_CSS_SOURCE');if(!$f||!is_file($f))throw new RuntimeException('Missing candidate');require $f;
$ref=new ReflectionMethod(MAD4B_Verified_Critical_CSS_V1::class,'eligible');$ref->setAccessible(true);
$enabled=static fn()=> $ref->invoke(null);
ok('Staging off remains off',$enabled()===false);
$_SERVER['QUERY_STRING']='mad4b_critical_css_probe=1';
ok('Staging QA probe company bound',$enabled()===true);
$GLOBALS['scope']=false;
ok('Staging QA probe foreign company rejected',$enabled()===false);
$GLOBALS['scope']=true;$GLOBALS['env']='production';
ok('Production anonymous QA probe rejected',$enabled()===false);
$_SERVER['QUERY_STRING']='';$GLOBALS['option']='off';
ok('Production default OFF',$enabled()===false);
$GLOBALS['option']='on';
ok('Production locally enabled only on permitted homepage',$enabled()===true);
$GLOBALS['home']=false;
ok('Nonhome denied',$enabled()===false);
$GLOBALS['home']=true;$GLOBALS['logged']=true;
ok('Authenticated user denied',$enabled()===false);
$GLOBALS['logged']=false;$GLOBALS['admin']=true;
ok('Admin denied',$enabled()===false);
$GLOBALS['admin']=false;$_SERVER['QUERY_STRING']='unexpected=1';
ok('Unknown query denied',$enabled()===false);
echo "PASS_CRITICAL_CSS_PRODUCTION_PROBE_GUARD\n";
