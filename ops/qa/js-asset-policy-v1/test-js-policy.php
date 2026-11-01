<?php
error_reporting(E_ALL);
$root=sys_get_temp_dir().'/mad4b-js-test-'.getmypid();mkdir($root.'/mu-plugins',0700,true);
define('ABSPATH',$root.'/');define('WP_CONTENT_DIR',$root);define('LSCWP_V','7.9.2');define('ELEMENTOR_VERSION','4.3.5');
$GLOBALS['env']='staging';$GLOBALS['host']='https://tours.dev.mad4b.com';$GLOBALS['logged']=false;$GLOBALS['admin']=false;
$GLOBALS['opts']=['mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'],
'litespeed.conf.optm-js_defer'=>'0','litespeed.conf.guest_optm'=>'','litespeed.conf.optm-js_min'=>'1',
'litespeed.conf.guest'=>'1','mad4b_css_ai_epoch_v1'=>8];
function wp_get_environment_type(){return $GLOBALS['env'];}function home_url(){return $GLOBALS['host'];}
function wp_parse_url($s,$x){return parse_url($s,$x);}function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function is_admin(){return $GLOBALS['admin'];}function is_user_logged_in(){return $GLOBALS['logged'];}
function wp_doing_ajax(){return false;}function is_preview(){return false;}
function apply_filters($name,$arg){return $name==='wpml_current_language'?'en':$arg;}
function add_filter(){return true;}function add_action(){return true;}
function check($ok,$message){if(!$ok){fwrite(STDERR,'FAIL '.$message."\n");exit(9);}echo 'PASS '.$message."\n";}
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/','HTTP_USER_AGENT'=>'Mozilla/5.0 Windows NT 10.0 Chrome/155'];
require (getenv('MAD4B_JS_POLICY_SOURCE') ?: '/var/www/html/wp-content/mu-plugins/337-mad4b-device-js-combine-policy.php');
$class='MAD4B_Device_JS_Combine_V1';$refl=new ReflectionProperty($class,'choice');if(PHP_VERSION_ID<80100)$refl->setAccessible(true);
$decision=static function()use($refl,$class){$refl->setValue(null,null);return $class::decision();};
check($decision()==='native','Default is native without certificate');
$_SERVER['HTTP_X_MAD4B_JS_POLICY']='full-v1';check($decision()==='native','Canary requires isolated no-store proof');
$_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']='1';$_SERVER['HTTP_X_MAD4B_DYNAMIC_CSS']='native-v1';
check($decision()==='full','Explicit full combination is manual canary only');
check($class::combine('')==='1'&&$class::external_inline('')==='1','Delegates full combination to native LiteSpeed filters');
$_SERVER['HTTP_X_MAD4B_JS_POLICY']='files-v1';check($decision()==='files','Files-only is independently selectable');
check($class::external_inline('1')==='0','Inline combination disabled for files-only');
$_SERVER['HTTP_COOKIE']='cart=personal';check($decision()==='native','Personal cookie fails closed');unset($_SERVER['HTTP_COOKIE']);
$GLOBALS['opts']['litespeed.conf.optm-js_defer']='2';check($decision()==='native','Global Delay and local combine are mutually exclusive');$GLOBALS['opts']['litespeed.conf.optm-js_defer']='0';
unset($_SERVER['HTTP_X_MAD4B_JS_POLICY']);
$k=hash('sha256','en|/');$cert=['contract'=>'mad4b.js.route-policy.v1','site_host'=>'tours.dev.mad4b.com','site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a','routes'=>[$k=>['path'=>'/','locale'=>'en','devices'=>['desktop'=>['status'=>'certified','mode'=>'files','expires'=>time()+3600,'epoch'=>8,'guest_mode'=>'1','litespeed'=>'7.9.2','elementor'=>'4.3.5']]]]];
file_put_contents($root.'/mu-plugins/mad4b-js-autonomous-policy.json',json_encode($cert));
check($decision()==='files','Exactly bound certified Desktop policy executes');
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (Android 15) Mobile';check($decision()==='native','Desktop certified policy never applies to Mobile');
$GLOBALS['opts']['mad4b_css_ai_epoch_v1']=9;$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 Windows NT 10.0 Chrome/155';check($decision()==='native','Content epoch drift revokes certificate');
$GLOBALS['opts']['mad4b_css_ai_epoch_v1']=8;$GLOBALS['opts']['litespeed.conf.guest']='0';check($decision()==='native','Guest Mode change revokes certificate');
$GLOBALS['opts']['litespeed.conf.guest']='1';$GLOBALS['env']='production';check($decision()==='native','Production fail-closed');
unlink($root.'/mu-plugins/mad4b-js-autonomous-policy.json');rmdir($root.'/mu-plugins');rmdir($root);
echo 'mad4b.js.combine.policy.v1 PASS'."\n";
