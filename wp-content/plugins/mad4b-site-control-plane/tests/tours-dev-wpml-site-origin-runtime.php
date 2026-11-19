<?php
/** No-network WPML identity tests: localized origin is not tenant authorization. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ICL_SITEPRESS_VERSION', '4.9.0-fixture' );
$mode = $argv[1] ?? 'web';
if ( ! in_array( $mode, array( 'web', 'rest', 'cli' ), true ) ) exit( 11 );
define( 'WP_CLI', 'cli' === $mode );
define( 'REST_REQUEST', 'rest' === $mode );
$GLOBALS['home'] = 'https://demo.example.test';
$GLOBALS['siteurl'] = 'https://demo.example.test';
$GLOBALS['observed_home'] = $GLOBALS['home'];
$GLOBALS['observed_site'] = $GLOBALS['siteurl'];
$GLOBALS['reported_lang'] = 'en';
$GLOBALS['doing_ajax'] = false;
$GLOBALS['wpml_settings'] = array('active_languages'=>array('en','es','ar','de'),'hidden_languages'=>array('de'));
$_SERVER['HTTP_HOST'] = 'demo.example.test';
class SitePress { public $code = 'en'; public function get_this_lang() { return $this->code; } }
$GLOBALS['sitepress'] = new SitePress();
function home_url($path='/') { return rtrim($GLOBALS['observed_home'],'/') . $path; }
function site_url($path='/') { return rtrim($GLOBALS['observed_site'],'/') . $path; }
function wp_parse_url($url,$part=-1) { return parse_url($url,$part); }
function absint($value) { return abs((int)$value); }
function wp_doing_ajax() { return $GLOBALS['doing_ajax']; }
function apply_filters($tag,$default) { return $tag==='wpml_current_language'?$GLOBALS['reported_lang']:$default; }
function get_option($key,$default=null) {
 if($key==='home')return $GLOBALS['home'];
 if($key==='siteurl')return $GLOBALS['siteurl'];
 if($key==='icl_sitepress_settings')return $GLOBALS['wpml_settings'];
 return $default;
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-site-profile.php';
$profile = new ReflectionProperty('MAD4B_SCP_Site_Profile','profile');
$status = new ReflectionProperty('MAD4B_SCP_Site_Profile','status');
$profile->setValue(null,array('canonical_origin'=>'https://demo.example.test'));
$status->setValue(null,array('authority_ready'=>true));
$check = static function($yes,$why) use($mode) { if(!$yes){fwrite(STDERR,'FAIL '.$mode.': '.$why."\n");exit(1);} };
$reset=static function() {
 $GLOBALS['home']='https://demo.example.test';
 $GLOBALS['siteurl']=$GLOBALS['home'];
 $GLOBALS['observed_home']=$GLOBALS['home'];
 $GLOBALS['observed_site']=$GLOBALS['siteurl'];
 $GLOBALS['reported_lang']='en';
 $GLOBALS['sitepress']->code='en';
 $GLOBALS['doing_ajax']=false;
 $GLOBALS['wpml_settings']=array('active_languages'=>array('en','es','ar','de'),'hidden_languages'=>array('de'));
 $_SERVER['HTTP_HOST']='demo.example.test';
};
$check(MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Exact canonical origin');
$reset();$GLOBALS['observed_home'].='/es';$GLOBALS['sitepress']->code='es';$GLOBALS['reported_lang']='es';
$check(MAD4B_SCP_Site_Profile::current_origin()===$GLOBALS['home'],'Active locale must resolve to canonical origin');
$check(MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Authorized WPML locale cannot break same-site identity');
$reset();$GLOBALS['observed_home'].='/es';
$check(MAD4B_SCP_Site_Profile::site_urls_match_enrollment()===($mode==='rest'),
    'Only asynchronous REST may resolve registered locale mismatch without AJAX flag');
$reset();$GLOBALS['observed_home'].='/es';$GLOBALS['doing_ajax']=true;
$check(MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'AJAX locale from active WPML language');
$reset();$GLOBALS['observed_home'].='/fr';$GLOBALS['doing_ajax']=true;
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Unregistered language denied');
$reset();$GLOBALS['observed_home'].='/de';$GLOBALS['doing_ajax']=true;
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Hidden language denied');
$reset();$GLOBALS['observed_home'].='/es/extra';$GLOBALS['doing_ajax']=true;
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Arbitrary path suffix denied');
$reset();$GLOBALS['observed_home']='https://other.example.test/es';$GLOBALS['doing_ajax']=true;
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Foreign origin denied');
$reset();$GLOBALS['observed_home']='http://demo.example.test/es';$GLOBALS['doing_ajax']=true;
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Downgraded scheme denied');
$reset();$GLOBALS['observed_home'].='/es';$GLOBALS['doing_ajax']=true;$_SERVER['HTTP_HOST']='other.example.test';
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'HTTP Host mismatch denied');
$reset();$GLOBALS['observed_home'].='/es';$GLOBALS['doing_ajax']=true;$GLOBALS['observed_site']='https://different.example.test';
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'WordPress siteurl moved to another host denied');
$reset();$GLOBALS['sitepress']=null;$GLOBALS['observed_home'].='/es';$GLOBALS['doing_ajax']=true;
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'WPML identity unavailable denies alias');
$GLOBALS['sitepress']=new SitePress();
$reset();$status->setValue(null,array('authority_ready'=>false));
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'No site enrollment = no identity ready');
$status->setValue(null,array('authority_ready'=>true));
$reset();$GLOBALS['observed_site']='http://demo.example.test';
$check(MAD4B_SCP_Site_Profile::site_urls_match_enrollment()===($mode==='cli'),'Stored HTTPS WordPress siteurl may substitute only for native WP_CLI');
$reset();$GLOBALS['observed_site']='http://demo.example.test';$GLOBALS['siteurl']='http://demo.example.test';
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Persistently modified siteurl denies enrollment identity');
$reset();$GLOBALS['observed_site']='http://demo.example.test';$GLOBALS['home']='https://changed.example.test';
$check(!MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),'Persistently modified home option denies enrollment identity');
echo "PASS: WPML strict site origin for $mode (15+ cases)\n";
