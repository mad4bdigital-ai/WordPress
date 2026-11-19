<?php
/** Hermetic owner-only WooCommerce settings locator; no credential writes. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $c;public function __construct($c,$m='',$args=array()){$this->c=$c;}public function get_error_code(){return $this->c;}}
class MAD4B_SCP_Adaptive_Interaction_Broker {public static $allowed=false;public static function permitted(){return self::$allowed;}}
class WooCommerce {}
function current_user_can($cap){return $cap==='manage_woocommerce' && $GLOBALS['can_manage'];}
function admin_url($path){return 'https://test.example.invalid/wp-admin/'.$path;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function is_ssl(){return true;}
function home_url(){return 'https://test.example.invalid/';}
class MAD4B_SCP_Local_Transport_Policy {public static function loopback_enabled($origin){return false;}}
class FakeDatabase {public function get_database_path(){return $GLOBALS['db_file'];}}
class FakeIntegration {public function get_database_service(){return new FakeDatabase();}public function get_option($name){return $GLOBALS['has_key']?'SECRET_MUST_NEVER_LEAK':'';}}
class FakeIntegrations {public function get_integration($name){return $GLOBALS['found_integration']&&$name==='maxmind_geolocation'?new FakeIntegration():null;}}
function WC(){return $GLOBALS['wc'];}
function ok($value,$label){if(!$value){fwrite(STDERR,'FAIL: '.$label."\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-native-settings-locator.php';
$GLOBALS['can_manage']=false;$GLOBALS['wc']=(object)array('integrations'=>new FakeIntegrations());$GLOBALS['db_file']='';$GLOBALS['has_key']=false;$GLOBALS['found_integration']=true;
$cls='MAD4B_SCP_Native_Settings_Locator';
$x=$cls::status('unknown_target');ok($x instanceof WP_Error&&$x->get_error_code()==='mad4b_native_owner_unknown','Reject unknown target');
$x=$cls::status($cls::MAXMIND);ok($x instanceof WP_Error&&$x->get_error_code()==='mad4b_native_owner_permission_denied','Reject missing broker permission');
MAD4B_SCP_Adaptive_Interaction_Broker::$allowed=true;
$x=$cls::status($cls::MAXMIND);ok($x instanceof WP_Error&&$x->get_error_code()==='mad4b_native_owner_permission_denied','Reject missing Woo capability');
$GLOBALS['can_manage']=true;$GLOBALS['found_integration']=false;
$x=$cls::status($cls::MAXMIND);ok($x instanceof WP_Error&&$x->get_error_code()==='mad4b_native_maxmind_unavailable','Reject missing original integration');
$GLOBALS['found_integration']=true;$x=$cls::status($cls::MAXMIND);
ok($x['state']==='WAITING_NATIVE_KEY'&&$x['owner']==='woocommerce','Missing native license is visible only as boolean');
ok($x['transport_attested']===true&&$x['license_key_present']===false,'HTTPS observed, secret missing');
$GLOBALS['has_key']=true;$x=$cls::status($cls::MAXMIND);ok($x['state']==='WAITING_NATIVE_DATABASE','Configured key requires database');
$f=tempnam(sys_get_temp_dir(),'mad4b-geo-');register_shutdown_function(static function()use($f){@unlink($f);});$GLOBALS['db_file']=$f;
$x=$cls::status($cls::MAXMIND);ok($x['state']==='READY'&&$x['license_key_present']&&$x['geoip_database_ready'],'Only existing local DB readback marks ready');
$encoded=json_encode($x);ok(strpos($encoded,'SECRET_MUST_NEVER_LEAK')===false,'Do not expose native license value');
ok($x['native_write_performed']===false&&$x['mutation_authorized']===false&&$x['automatic_retry_authorized']===false,'No license save or GeoIP download is authorized');
ok(strpos($x['native_settings_url'],'tab=integration')!==false,'Point to WooCommerce original settings only');
echo "PASS: WooCommerce native settings locator owner-only, secret-free, no mutation (11 checks)\n";
