<?php
/** Real Site Profile source, hermetic WPML/WordPress stubs. No network or writes. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!defined('ICL_SITEPRESS_VERSION')) define('ICL_SITEPRESS_VERSION', '4.6');
class SitePress {
  public function get_this_lang(){ return $GLOBALS['wpml_lang']; }
}
$GLOBALS['sitepress']=new SitePress();
$GLOBALS['wpml_lang']='en';
$GLOBALS['ajax']=true;
$GLOBALS['home']='https://company.example.test/es';
$GLOBALS['base']='https://company.example.test';
$GLOBALS['site']='https://company.example.test';
$GLOBALS['active']=array('en','es');
$GLOBALS['hidden']=array();
$_SERVER['HTTP_HOST']='company.example.test';
function home_url($p=''){return rtrim($GLOBALS['home'],'/').'/';}
function site_url($p=''){return rtrim($GLOBALS['site'],'/').'/';}
function get_option($key,$default=false){
 if($key==='home')return $GLOBALS['base'];
 if($key==='icl_sitepress_settings')return array('active_languages'=>$GLOBALS['active'],'hidden_languages'=>$GLOBALS['hidden']);
 return $default;
}
function apply_filters($name,$value){return $name==='wpml_current_language'?'en':$value;}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function absint($v){return abs((int)$v);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php';
function origin_eq($want,$label){
 $got=MAD4B_SCP_Site_Profile::current_origin();
 if($got!==$want){fwrite(STDERR,"FAIL $label got=$got want=$want".PHP_EOL);exit(1);}
}
$base='https://company.example.test';
$es=$base.'/es';
origin_eq($base,'active language AJAX canonical');
$GLOBALS['ajax']=false;origin_eq($es,'non-AJAX not promoted');
$GLOBALS['ajax']=true;$GLOBALS['hidden']=array('es');origin_eq($es,'hidden locale denied');
$GLOBALS['hidden']=array();$GLOBALS['active']=array('en','fr');origin_eq($es,'inactive locale denied');
$GLOBALS['active']=array('en','es');
$_SERVER['HTTP_HOST']='attacker.example.test';origin_eq($es,'foreign Host denied');
$_SERVER['HTTP_HOST']='company.example.test:443';origin_eq($base,'canonical port accepted');
$_SERVER['HTTP_HOST']='company.example.test';
$GLOBALS['home']=$base.'/es/admin';origin_eq($base.'/es/admin','extra URL path denied');
$GLOBALS['home']=$base.'/es%2fadmin';origin_eq($base.'/es%2fadmin','encoded URL path denied');
$GLOBALS['home']=$base.'/en';origin_eq($base,'exact current locale accepted');
$GLOBALS['home']=$es;$GLOBALS['site']='https://different.example.test';origin_eq($es,'different site_url denied');
$GLOBALS['site']=$base;$GLOBALS['sitepress']=null;origin_eq($es,'missing WPML object denied');
$GLOBALS['sitepress']=new SitePress();$GLOBALS['home']=$base.'/';origin_eq($base,'canonical homepage unchanged');
echo 'PASS: VISITOR_WPML_ORIGIN_HERMETIC 12 assertions on real source'.PHP_EOL;
