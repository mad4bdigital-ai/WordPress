<?php
/**
 * Source-owned visitor preference test. No WordPress database, live cookies,
 * user account, Woo order, network access or production mutation.
 */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('COOKIEPATH', '/');
define('DAY_IN_SECONDS', 86400);
define('ICL_SITEPRESS_VERSION', '4.7');
$GLOBALS['site_url_for_page'] = 'https://tenant.example.test/';
$GLOBALS['hidden_languages'] = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($name, $default, ...$args) {
 return $name === 'travel_booking/legacy_nationality_preference_keys'
   ? ['etg_nationality_preference_v1'] : $default;
}
function get_option($name, $default = null) {
 if ($name === 'home') return 'https://tenant.example.test';
 if ($name === 'icl_sitepress_settings') return [
   'active_languages'=>['en','es'],
   'hidden_languages'=>$GLOBALS['hidden_languages']
 ];
 return $default;
}
function home_url($path = '/') { return $GLOBALS['site_url_for_page']; }
function wp_salt($name = '') { return 'hermetic-site-secret'; }
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_SLASHES); }
function is_ssl() { return true; }
function do_action(...$args) {}
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {
 private $code;
 public function __construct($code, $message = '') {$this->code = $code;}
 public function get_error_code() {return $this->code;}
}
class MAD4B_SCP_Switcher_Sources {
 const CONTRACT='mad4b.switcher-sources.v1';
 public static function valid_country($code) {
  $code=strtoupper((string)$code);
  return in_array($code,['AE','GB','US'],true)?$code:'';
 }
 public static function market_countries(){return ['AE'=>'Emirates','GB'=>'United Kingdom','US'=>'United States'];}
 public static function countries(){return self::market_countries();}
 public static function approved_market($country,$lang=''){return ['profile_id'=>'market-'.strtolower($country)];}
 public static function current_language(){return 'en';}
}
class MAD4B_SCP_Market_Profiles {public static function site_uuid(){return 'site-uuid-test';}}
class FakeWooSession {
 public $active=false;
 public $data=[];
 public $writes=0;
 public $forced_cookies=0;
 public function has_session(){return $this->active;}
 public function get($key,$default=[]){return $this->data[$key]??$default;}
 public function set($key,$value){$this->writes++;$this->data[$key]=$value;}
 public function __unset($key){unset($this->data[$key]);}
 public function set_customer_session_cookie($value){$this->forced_cookies++;}
}
$GLOBALS['woocommerce']=(object)['session'=>new FakeWooSession()];
function WC(){return $GLOBALS['woocommerce'];}
$root=dirname(__DIR__).'/wp-content/plugins/';
require $root.'mad4b-country-switcher/mad4b-country-switcher.php';
require $root.'etg-traveller-nationality-switcher/etg-traveller-nationality-switcher.php';
function verify($ok,$name){if(!$ok)throw new RuntimeException('FAIL: '.$name);}
function signed_preference($record){
 $payload=rtrim(strtr(base64_encode(json_encode($record)),'+/','-_'),'=');
 return $payload.'.'.hash_hmac('sha256',$payload,wp_salt('auth'));
}
$country=MAD4B_Country_Switcher::set_country('AE');
verify(!is_wp_error($country),'country selection');
verify(WC()->session->writes===0 && WC()->session->forced_cookies===0,'guest country must not start Woo session');
$passport=Travel_Booking_Traveller_Nationality_Switcher::choose('GB');
verify($passport==='GB' && WC()->session->writes===0 && WC()->session->forced_cookies===0,'guest manual passport must not start Woo session');
$_COOKIE[MAD4B_Country_Switcher::COOKIE]=signed_preference([
 'contract'=>MAD4B_Country_Switcher::CONTRACT,'site_uuid'=>'site-uuid-test','country'=>'AE'
]);
$_COOKIE[Travel_Booking_Traveller_Nationality_Switcher::COOKIE]=signed_preference([
 'contract'=>Travel_Booking_Traveller_Nationality_Switcher::CONTRACT,
 'site_uuid'=>hash('sha256','https://tenant.example.test/'),'country'=>'GB'
]);
$GLOBALS['site_url_for_page']='https://tenant.example.test/es/';
verify(MAD4B_Country_Switcher::saved_country()==='AE','country persists EN to ES');
verify(Travel_Booking_Traveller_Nationality_Switcher::selected_manual()==='GB','canonical passport persists EN to ES');
$GLOBALS['site_url_for_page']='https://tenant.example.test/';
$_COOKIE[Travel_Booking_Traveller_Nationality_Switcher::COOKIE]=signed_preference([
 'contract'=>Travel_Booking_Traveller_Nationality_Switcher::CONTRACT,
 'site_uuid'=>hash('sha256','https://tenant.example.test/es/'),'country'=>'GB'
]);
verify(Travel_Booking_Traveller_Nationality_Switcher::selected_manual()==='GB','legacy ES passport survives ES to EN');
$GLOBALS['hidden_languages']=['es'];
verify(Travel_Booking_Traveller_Nationality_Switcher::selected_manual()==='','hidden locale hash is rejected');
$GLOBALS['hidden_languages']=[];
$_COOKIE[Travel_Booking_Traveller_Nationality_Switcher::COOKIE]=signed_preference([
 'contract'=>Travel_Booking_Traveller_Nationality_Switcher::CONTRACT,
 'site_uuid'=>hash('sha256','https://tenant.example.test/fr/'),'country'=>'GB'
]);
verify(Travel_Booking_Traveller_Nationality_Switcher::selected_manual()==='','unregistered locale hash rejected');
$_COOKIE[Travel_Booking_Traveller_Nationality_Switcher::COOKIE]='invalid.tampered';
verify(Travel_Booking_Traveller_Nationality_Switcher::selected_manual()==='','tampered signed preference rejected');
WC()->session->active=true;
verify(Travel_Booking_Traveller_Nationality_Switcher::choose('GB')==='GB','existing session passport save');
verify(!is_wp_error(MAD4B_Country_Switcher::set_country('US')),'existing session country save');
verify(WC()->session->writes===2 && WC()->session->forced_cookies===0,'existing Woo session updated without forcing cookie');
verify(Travel_Booking_Traveller_Nationality_Switcher::selected_manual()==='GB','manual choice survives country change');
Travel_Booking_Traveller_Nationality_Switcher::reset_manual();
verify(Travel_Booking_Traveller_Nationality_Switcher::selected_manual()==='','reset removes manual preference');
verify(!isset(WC()->session->data[Travel_Booking_Traveller_Nationality_Switcher::SESSION]),'reset removes existing Woo session preference');
verify(!isset($_COOKIE[Travel_Booking_Traveller_Nationality_Switcher::COOKIE]),'reset clears existing browser cookie state');
verify(MAD4B_Country_Switcher::saved_country()==='US','reset keeps market country intact');
echo "PASS: guest/session separation; WPML manual passport; hidden/foreign/tampered denial; reset; existing cart preservation\n";
