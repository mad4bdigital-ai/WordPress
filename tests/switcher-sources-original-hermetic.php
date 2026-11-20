<?php
/** Original frontend Passport/WPML/WCML switcher values, no plugin writes. */
define('ABSPATH',__DIR__.'/');
define('WP_PLUGIN_DIR',__DIR__.'/not-installed');
class WP_Error {
 function __construct($code='unknown'){}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class WC_Countries {
 static $countries=['EG'=>'Egypt','FR'=>'France'];
 static function get_countries(){return self::$countries;}
}
class WC_Application {
 public $countries;
 function __construct(){$this->countries=new WC_Countries();}
 function plugin_path(){return '/no-woocommerce-files';}
}
$GLOBALS['wc']=new WC_Application();
function WC(){return $GLOBALS['wc'];}
function get_option($name,$default=[]){return $GLOBALS['option'];}
function get_woocommerce_currencies(){return ['USD'=>'US Dollar','EUR'=>'Euro','EGP'=>'Egyptian Pound'];}
function get_woocommerce_currency_symbol($code){return $code==='EUR'?'&euro;':$code;}
function apply_filters($name,$default,...$args){
 if($name==='wpml_active_languages')return $GLOBALS['languages'];
 if($name==='wpml_current_language')return $GLOBALS['current'];
 return $default;
}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$v));}
class MAD4B_SCP_Market_Context {
 static $lang='en';
 static function language(){return self::$lang;}
}
class MAD4B_SCP_Market_Profiles {
 static $countries=[['country'=>'EG'],['country'=>'FR']];
 static $one=['country'=>'EG','language'=>'en','approved'=>true];
 static function approved_list(){return self::$countries;}
 static function by_country($country,$language){return self::$one;}
}
function switcher_check($value,$reason){
 if(!$value){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
$GLOBALS['option']=['currency_options'=>[
 'USD'=>['enabled'=>true],'EUR'=>['enabled'=>true],'bad'=>['enabled'=>true]
]];
$GLOBALS['languages']=[
 'en'=>['native_name'=>'English','country_flag_url'=>'','url'=>'/en/'],
 'es'=>['native_name'=>'Español','country_flag_url'=>'','url'=>'/es/']
];
$GLOBALS['current']='en';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-switcher-sources.php';
$c='MAD4B_SCP_Switcher_Sources';
switcher_check($c::valid_country('eg')==='EG' &&
 $c::country_name('FR')==='France' &&$c::country_name('ZZ')==='',
 'Passport options derive only from original WooCommerce country source');
switcher_check($c::current_language()==='en'&&count($c::languages())===2,
 'Native WPML language source retains enrolled languages, no static duplicate');
$money=$c::currencies();
switcher_check(count($money)===2
 &&$money['EUR']['symbol']==='€' &&isset($money['USD']),
 'WCML currency options are authoritative; unknown three-letter codes are not invented');
switcher_check(array_keys($c::market_countries())===['EG','FR']
 &&$c::approved_market('EG')['approved']===true,
 'Only Woo country + original company market profile intersection becomes selectable');
$GLOBALS['option']=new WP_Error('wcml_option_unavailable');
switcher_check($c::currencies()===[],
 'Unknown WCML option cannot crash frontend switcher or reveal unapproved currency');
$GLOBALS['option']=[];
$GLOBALS['languages']=new WP_Error('wpml_missing');
switcher_check($c::languages()===[],
 'Unknown WPML registry does not invent market/language options');
MAD4B_SCP_Market_Context::$lang=new WP_Error('market_context_missing');
switcher_check($c::current_language()==='',
 'Unknown current market language must not become an HTML-facing PHP fatal');
MAD4B_SCP_Market_Context::$lang='en';
MAD4B_SCP_Market_Profiles::$one=new WP_Error('profile_unverified');
switcher_check($c::approved_market('EG')===[],
 'Error-shaped Market Profile cannot masquerade as approved passport option');
MAD4B_SCP_Market_Profiles::$one=['country'=>'EG','language'=>'en','approved'=>true];
WC_Countries::$countries=new WP_Error('wc_country_unknown');
switcher_check($c::countries()===[],
 'Unknown original WooCommerce country inventory cannot be cast or serialized into a public country menu');
echo 'PASS: SWITCHER_SOURCES_ORIGINAL '.($GLOBALS['checks']??0).
 ' original WooCommerce, WPML, WCML and no-false-market cases'.PHP_EOL;
