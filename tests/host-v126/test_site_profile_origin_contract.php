<?php
/**
 * v126 isolated Site Profile identity evidence, HTTP and native CLI modes.
 * No WordPress installation, database, Host authority or live site writes.
 */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
define('ICL_SITEPRESS_VERSION','4.7.0');
if(($argv[1]??'http')==='cli')define('WP_CLI',true);
$GLOBALS['home']='https://tours.dev.mad4b.com/';
$GLOBALS['site']='https://tours.dev.mad4b.com/';
$GLOBALS['opts']=[
 'home'=>'https://tours.dev.mad4b.com',
 'siteurl'=>'https://tours.dev.mad4b.com',
 'icl_sitepress_settings'=>['active_languages'=>['en','ar','es'],'hidden_languages'=>[]]
];
$GLOBALS['code']='ar';
$GLOBALS['sitepress_code']='ar';
$GLOBALS['ajax']=false;
$_SERVER['HTTP_HOST']='tours.dev.mad4b.com';
class SitePress {
 public function get_this_lang(){return $GLOBALS['sitepress_code'];}
}
$GLOBALS['sitepress']=new SitePress;
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function absint($x){return abs((int)$x);}
function home_url($path=''){return rtrim($GLOBALS['home'],'/').'/'.ltrim($path,'/');}
function site_url($path=''){return rtrim($GLOBALS['site'],'/').'/'.ltrim($path,'/');}
function get_option($key,$default=false){return $GLOBALS['opts'][$key]??$default;}
function apply_filters($name,$value,...$args){return $name==='wpml_current_language'?$GLOBALS['code']:$value;}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function wp_get_environment_type(){return 'staging';}
require_once dirname(__DIR__,2).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php';
function check(bool $condition,string $name):void {
 global $count;
 if(!$condition)throw new RuntimeException("V126_FAIL_".$name);
 ++$count;
}
$count=0;
$cls='MAD4B_SCP_Site_Profile';
$canonical='https://tours.dev.mad4b.com';
if(($argv[1]??'http')==='http') {
 $GLOBALS['home']=$canonical.'/ar/';$GLOBALS['site']=$canonical.'/';
 check($cls::current_origin()===$canonical,'EXACT_REGISTERED_WPML_LANGUAGE');
 check($cls::current_host()==='tours.dev.mad4b.com','CANONICAL_HOST');
 $_SERVER['HTTP_HOST']='attacker.test';
 check($cls::current_origin()===$canonical.'/ar','CROSS_HOST_DENIED');
 $_SERVER['HTTP_HOST']='tours.dev.mad4b.com';
 $GLOBALS['home']='https://attacker.test/ar/';
 check($cls::current_origin()==='https://attacker.test/ar','CROSS_ORIGIN_DENIED');
 $GLOBALS['home']=$canonical.'/ar/';$GLOBALS['site']='https://attacker.test/';
 check($cls::current_origin()===$canonical.'/ar','SITEURL_MISMATCH_DENIED');
 $GLOBALS['site']=$canonical.'/';$GLOBALS['sitepress_code']='en';
 $GLOBALS['ajax']=false;
 check($cls::current_origin()===$canonical.'/ar','FRONTEND_DIFFERENT_WPML_LANG_DENIED');
 $GLOBALS['ajax']=true;
 check($cls::current_origin()===$canonical,'EXACT_AJAX_ACTIVE_LANG_SUFFIX_ACCEPTED');
 $GLOBALS['opts']['icl_sitepress_settings']['hidden_languages']=['ar'];
 check($cls::current_origin()===$canonical.'/ar','HIDDEN_LANGUAGE_DENIED');
 $GLOBALS['opts']['icl_sitepress_settings']['hidden_languages']=[];
 $GLOBALS['opts']['icl_sitepress_settings']['active_languages']=['en','es'];
 check($cls::current_origin()===$canonical.'/ar','UNREGISTERED_LANGUAGE_DENIED');
 $GLOBALS['opts']['icl_sitepress_settings']['active_languages']=['en','ar','es'];
 $GLOBALS['home']=$canonical.'/ar/extra/';
 check($cls::current_origin()===$canonical.'/ar/extra','UNREGISTERED_PATH_DENIED');
 $GLOBALS['home']=$canonical.'/ar/';
 $GLOBALS['sitepress']=null;
 check($cls::current_origin()===$canonical.'/ar','MISSING_SITEPRESS_DENIED');
 $GLOBALS['sitepress']=new SitePress;
 $GLOBALS['code']='ar';$GLOBALS['sitepress_code']='ar';
 $GLOBALS['ajax']=false;
 $p=new ReflectionProperty($cls,'profile');$p->setValue(null,['canonical_origin'=>$canonical]);
 $s=new ReflectionProperty($cls,'status');$s->setValue(null,['authority_ready'=>true]);
 $GLOBALS['home']=$canonical.'/';$GLOBALS['site']='http://tours.dev.mad4b.com/';
 check($cls::site_urls_match_enrollment()===false,'HTTP_SITE_URL_MISMATCH_NOT_SILENTLY_ACCEPTED');
 $GLOBALS['site']=$canonical.'/';
 check($cls::site_urls_match_enrollment()===true,'EXACT_ENROLLED_SITE_MATCH');
} else {
 $p=new ReflectionProperty($cls,'profile');$p->setValue(null,['canonical_origin'=>$canonical]);
 $s=new ReflectionProperty($cls,'status');$s->setValue(null,['authority_ready'=>true]);
 $GLOBALS['home']=$canonical.'/';$GLOBALS['site']='http://tours.dev.mad4b.com/';
 check($cls::site_urls_match_enrollment()===true,'WPCLI_SITE_SCHEME_WITH_EXACT_STORED_CANONICAL_ALLOWED');
 $GLOBALS['opts']['siteurl']='https://attacker.test';
 check($cls::site_urls_match_enrollment()===false,'WPCLI_CONFIGURED_SITE_URL_CHANGE_DENIED');
 $GLOBALS['opts']['siteurl']=$canonical;
 $GLOBALS['opts']['home']='http://tours.dev.mad4b.com';
 check($cls::site_urls_match_enrollment()===false,'WPCLI_CONFIGURED_HOME_SCHEME_CHANGE_DENIED');
 $GLOBALS['opts']['home']=$canonical;
 $GLOBALS['home']='https://attacker.test/';
 check($cls::site_urls_match_enrollment()===false,'WPCLI_REQUEST_ORIGIN_CHANGE_DENIED');
 $GLOBALS['home']=$canonical.'/';
 $GLOBALS['opts']['siteurl']=$canonical;
 $GLOBALS['site']=$canonical.'/';
 check($cls::site_urls_match_enrollment()===true,'WPCLI_EXACT_MATCH_ALLOWED');
}
echo json_encode(['state'=>'PASS','mode'=>$argv[1]??'http','checks'=>$count,
 'live_root_mutation'=>false,'wordpress_database'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
