<?php
/** Local PHP behavioral assertions: no real WordPress, no cookies/posts. */
define('ABSPATH','/offline');
define('DAY_IN_SECONDS',86400);
define('COOKIEPATH','/');
$hooks=[];
$rendered=[];
function add_action($name,$callback,$priority=10){global $hooks;$hooks[]=$name;return true;}
function add_shortcode($name,$callback){return true;}
function add_filter($name,$callback,$priority=10,$count=1){return true;}
function get_option($name,$default=[]){return $default;}
function shortcode_atts($defaults,$atts,$tag=''){return array_merge($defaults,(array)$atts);}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function esc_url($v){return (string)$v;}
function esc_html__($v,$domain=''){return (string)$v;}
function __($v,$domain=''){return (string)$v;}
function sanitize_html_class($v){return preg_replace('/[^a-zA-Z0-9_\-]/','',strval($v));}
function admin_url($v=''){return '/wp-admin/'.$v;}
function wp_nonce_field($action,$field='_wpnonce',$referer=true,$display=true){return '<input type="hidden" name="'.$field.'" value="safe"/>';}
function selected($a,$b,$show=true){return $a===$b?'selected="selected"':'';}
function apply_filters($tag,$value,...$args){return 'en';}
function plugins_url($path,$file){return 'https://example.org/plugins/'.$path;}
function wp_enqueue_style($name,$url,$deps=[],$version=''){return null;}
function wp_enqueue_script($name,$url,$deps=[],$version='',$footer=true){return null;}
function wp_add_inline_script($name,$content,$position='after'){return null;}
function wp_json_encode($v){return json_encode($v);}
function home_url($p=''){return 'https://example.org'.$p;}
class MAD4B_SCP_Switcher_Sources {
 const CONTRACT='mad4b.switcher-sources.v1';
 public static $resolved=0;
 public static function countries(){return ['EG'=>'Egypt','FR'=>'France','US'=>'United States'];}
 public static function valid_country($iso){$v=strtoupper((string)$iso);return array_key_exists($v,self::countries())?$v:'';}
 public static function approved_market($country,$lang=''){
    self::$resolved++;return $country==='EG'?['profile_id'=>'market-eg']:[];
 }
 public static function current_language(){return 'en';}
 public static function flag_html($iso){return '<img src="/flag-'.$iso.'"/>';}
 public static function country_flag($iso){return '/flag-'.$iso;}
}
class MAD4B_SCP_Market_Profiles {
 public static function site_uuid(){return 'site-a';}
}
class MAD4B_SCP_Market_Context {
 public static function woo_geo_hint(){return 'EG';}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-country-switcher/mad4b-country-switcher.php';
function check($v,$label){if(!$v){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(2);}}
$s=MAD4B_Country_Switcher::shortcode();
check(strpos($s,'value="FR"')!==false,'all native Woo countries listed even without market approval');
check(strpos($s,'value="US"')!==false,'all native Woo countries represented');
check(strpos($s,'<option value="EG" selected=')===false,'geolocation not injected into cacheable markup');
check(strpos($s,'Suggested by location')===false,'geo text excluded from shared HTML');
check(strpos($s,'mad4b-country-flag')!==false,'safe unselected flag placeholder');
check(MAD4B_SCP_Switcher_Sources::$resolved===0,'no expensive market resolution per listed country');
check(MAD4B_Country_Switcher::saved_country()==='','no explicit visitor country');
check((MAD4B_Country_Switcher::context()['suggested_country']??'')==='EG',
 'advisory geo retained for per-visitor private AJAX only');
$ref=new ReflectionMethod('MAD4B_Country_Switcher','available');
check($ref->invoke(null)===true,'exact provider contract accepted');
echo json_encode(['state'=>'PASS','cases'=>9,'geo_in_cached_html'=>false,'market_entitlement_auto_granted'=>false,
 'site_mutated'=>false,'wp_runtime_test'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
