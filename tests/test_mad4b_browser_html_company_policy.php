<?php
define('ABSPATH','/');
$GLOBALS['runtime_env']='staging';$GLOBALS['bound']=true;$GLOBALS['site']='https://tours.dev.mad4b.com';
$GLOBALS['locale']='fr';$GLOBALS['default_locale']='en';$GLOBALS['langs']=['en','ar','fr','de','es','it'];$GLOBALS['enabled_option']=null;
$GLOBALS['url']='https://tours.dev.mad4b.com/fr/';$_SERVER['REQUEST_URI']='/fr/';$_SERVER['REQUEST_METHOD']='GET';
class MAD4B_Experience_Site_Scope_V1 {public static function bound(){return $GLOBALS['bound'];}}
function wp_get_environment_type(){return $GLOBALS['runtime_env'];}
function get_option($name,$default=null){
 if($name==='home')return $GLOBALS['site'];
 if($name==='mad4b_browser_html_public_v1')return $GLOBALS['enabled_option']??$default;
 if($name==='woocommerce_currency')return 'USD';
 if($name==='woocommerce_default_customer_address')return 'base';
 return $default;
}
function wp_parse_url($s,$component=-1){return parse_url($s,$component);}
function apply_filters($hook,$value,...$args){
 if($hook==='wpml_active_languages')return array_fill_keys($GLOBALS['langs'],['active'=>true]);
 if($hook==='wpml_current_language')return $GLOBALS['locale'];
 if($hook==='wpml_default_language')return $GLOBALS['default_locale'];
 return $value;
}
function add_filter($tag,$cb,$p=10){$GLOBALS['hooks'][$tag]=$cb;}
function add_action($tag,$cb,$p=10){$GLOBALS['hooks'][$tag]=$cb;}
function is_front_page(){return true;}
function is_post_type_archive($kind){return false;}
function is_tax($slug){return false;}
function is_singular($types){return false;}
function get_queried_object_id(){return 1001;}
function get_permalink($post){return $GLOBALS['url'];}
function ok($label,$condition){if(!$condition)throw new RuntimeException('FAIL '.$label);echo "PASS ".$label."\n";}
$src=getenv('MAD4B_BROWSER_POLICY_SOURCE');
if(!is_file($src))throw new RuntimeException('missing source');
require $src;
$cls='MAD4B_Company_Browser_HTML_Policy';
$method=new ReflectionMethod($cls,'canonical_public_path');
$method->setAccessible(true);
ok('staging site accepted',$cls::company_bound());
ok('staging default ON',$cls::enabled());
ok('staging method backward compatible',$cls::staging());
ok('French WPML canonical path accepted',$method->invoke(null)===true);
$GLOBALS['url']='https://tours.dev.mad4b.com/en/';
ok('mismatched canonical route rejected',$method->invoke(null)===false);
$GLOBALS['url']='https://tours.dev.mad4b.com/fr/';
$GLOBALS['site']='https://wayfatours.com';
$GLOBALS['runtime_env']='production';
$GLOBALS['locale']='es';$GLOBALS['langs']=['en','es'];
$GLOBALS['url']='https://wayfatours.com/es/';$_SERVER['REQUEST_URI']='/es/';
ok('Production site identity supported',$cls::company_bound());
ok('Production defaults OFF',$cls::enabled()===false);
ok('legacy staging flag still false in Production',$cls::staging()===false);
ok('Spanish WPML canonical route accepted',$method->invoke(null)===true);
$GLOBALS['enabled_option']='on';
ok('Production supports local opt-in',$cls::enabled()===true);
$GLOBALS['enabled_option']='off';
ok('emergency off',$cls::enabled()===false);
$GLOBALS['enabled_option']=null;$GLOBALS['bound']=false;
ok('unbound company denied',$cls::enabled()===false);
$GLOBALS['bound']=true;
$GLOBALS['langs']=['en'];
ok('locale missing from company registry denied',$method->invoke(null)===false);
echo "PASS_BROWSER_POLICY_COMPANY_LOCALE\n";
