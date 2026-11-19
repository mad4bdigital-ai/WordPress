<?php
/** Regression: only exact same-site enabled WPML locale may normalize an AJAX home URL. */
define('ABSPATH', __DIR__);
define('ICL_SITEPRESS_VERSION','4.7');
$GLOBALS['home_val']='https://tours.dev.mad4b.com/';
$GLOBALS['origin']='https://tours.dev.mad4b.com';
$GLOBALS['lang']='en';
$GLOBALS['ajax']=false;
$GLOBALS['active']=['en','es','fr','de','it','ar'];
$GLOBALS['hidden']=[];
$_SERVER['HTTP_HOST']='tours.dev.mad4b.com';
class SitePress {public function get_this_lang(){return $GLOBALS['lang'];}}
$GLOBALS['sitepress']=new SitePress();
function home_url($p){return $GLOBALS['home_val'];}
function site_url($p){return $GLOBALS['origin'].'/';}
function get_option($key,$default=null){
 if($key==='home')return $GLOBALS['origin'];
 if($key==='icl_sitepress_settings')return [
 'active_languages'=>$GLOBALS['active'],'hidden_languages'=>$GLOBALS['hidden']];
 return $default;
}
function apply_filters($key,$value){return $key==='wpml_current_language'?$GLOBALS['lang']:$value;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function absint($v){return abs((int)$v);}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
require dirname(__DIR__).'/includes/class-mad4b-scp-site-profile.php';
function assert_origin($name,$expected) {
 $v=MAD4B_SCP_Site_Profile::current_origin();
 if($v!==$expected){fwrite(STDERR,"FAIL $name expected $expected got $v\n");exit(1);}
}
assert_origin('canonical','https://tours.dev.mad4b.com');
$GLOBALS['home_val']='https://tours.dev.mad4b.com/es/';
$GLOBALS['lang']='es';
assert_origin('normal sitepress es','https://tours.dev.mad4b.com');
$GLOBALS['lang']='en';
assert_origin('nonajax mismatched locale stays distinct','https://tours.dev.mad4b.com/es');
$GLOBALS['ajax']=true;
assert_origin('AJAX independent locale validation','https://tours.dev.mad4b.com');
$GLOBALS['hidden']=['es'];
assert_origin('hidden locale denied','https://tours.dev.mad4b.com/es');
$GLOBALS['hidden']=[];
$GLOBALS['active']=['en','fr'];
assert_origin('non-active locale denied','https://tours.dev.mad4b.com/es');
$GLOBALS['active']=['en','es'];
$GLOBALS['home_val']='https://tours.dev.mad4b.com/es/malicious';
assert_origin('extra path rejected','https://tours.dev.mad4b.com/es/malicious');
$GLOBALS['home_val']='https://evil.example/es/';
assert_origin('foreign host rejected','https://evil.example/es');
$GLOBALS['home_val']='https://tours.dev.mad4b.com/es/';
$_SERVER['HTTP_HOST']='evil.example';
assert_origin('mismatched request host rejected','https://tours.dev.mad4b.com/es');
$_SERVER['HTTP_HOST']='tours.dev.mad4b.com';
$GLOBALS['origin']='https://another.example';
assert_origin('canonical siteurl conflict denied','https://tours.dev.mad4b.com/es');
echo "PASS bounded WPML AJAX canonical origin guards (10 cases)\n";
