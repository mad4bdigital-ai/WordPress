<?php
/** No WordPress instance. Current-origin read must not reset Elementor query. */
define('ABSPATH','/isolated');
define('ICL_SITEPRESS_VERSION','4.7');
$GLOBALS['home']='https://site.example';
$GLOBALS['canonical']='https://site.example';
$GLOBALS['language']='es';
$GLOBALS['sitepressLanguage']='es';
$GLOBALS['activeLanguagesCalled']=0;
class SitePress {
 public function get_this_lang(){return $GLOBALS['sitepressLanguage'];}
}
$GLOBALS['sitepress']=new SitePress();
$_SERVER['HTTP_HOST']='site.example';
function home_url($path='/'){return $GLOBALS['home'];}
function site_url($path='/'){return $GLOBALS['canonical'];}
function get_option($key,$default=null) {
 return $key==='home'?'https://site.example':$default;
}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function absint($v){return abs((int)$v);}
function apply_filters($hook,$value,...$args) {
 if($hook==='wpml_current_language')return $GLOBALS['language'];
 if($hook==='wpml_active_languages'){
  $GLOBALS['activeLanguagesCalled']++;
  throw new RuntimeException('WPML active languages resets current listing query');
 }
 return $value;
}
function check($v,$message){if(!$v){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php';
$cls=MAD4B_SCP_Site_Profile::class;
check($cls::current_origin()==='https://site.example','original unlocalized origin accepted');
$GLOBALS['home']='https://site.example/es/';
check($cls::current_origin()==='https://site.example','verified WPML language collapses to same site identity');
check($GLOBALS['activeLanguagesCalled']===0,'dangerous active-languages filter never invoked');
$GLOBALS['sitepressLanguage']='fr';
check($cls::current_origin()==='https://site.example/es','mismatched registered WPML language cannot collapse');
$GLOBALS['sitepressLanguage']='es';
$GLOBALS['language']='invalid/locale';
check($cls::current_origin()==='https://site.example/es','malformed locale cannot collapse');
$GLOBALS['language']='es';
$GLOBALS['home']='https://site.example/es/other';
check($cls::current_origin()==='https://site.example/es/other','extra path never interpreted as valid WPML prefix');
$GLOBALS['home']='https://attacker.example/es';
check($cls::current_origin()==='https://attacker.example/es','foreign hostname never collapses');
$GLOBALS['home']='https://site.example/es/';
$_SERVER['HTTP_HOST']='attacker.example';
check($cls::current_origin()==='https://site.example/es','request-host mismatch fails closed');
$_SERVER['HTTP_HOST']='site.example';
$GLOBALS['sitepress']=null;
check($cls::current_origin()==='https://site.example/es','uninitialized SitePress does not grant origin rewrite');
$GLOBALS['sitepress']=new SitePress();
check($cls::current_origin()==='https://site.example','legitimate SitePress context restores exact origin');
check($GLOBALS['activeLanguagesCalled']===0,'all readback cases avoided query-resetting WPML filter');
echo json_encode(['state'=>'PASS','cases'=>11,'wpdb_modified'=>false,
 'wpml_query_reset_filter_called'=>false,'site_grant_created'=>false]).PHP_EOL;
