<?php
define('ABSPATH','/');
$GLOBALS['calls']=0;$GLOBALS['options']=[];
class WP_Error {
 private string $code;
 public function __construct($code,$message=''){$this->code=(string)$code;}
 public function get_error_code(){return $this->code;}
}
class MAD4B_Distribution_Channel_Registry_V1 {
 const STORE='mad4b_distribution_channel_registry_v1';
 public static function rows():array {
  return ['meta_catalog'=>['configuration_revision'=>1,
   'external_delivery_enabled'=>false,'activation_requested'=>false,
   'provider_approval'=>'pending','account_alias'=>'']];
 }
 public static function connection_health():array{return ['meta_catalog'=>['missing'=>['verified_provider_missing']]];}
}
function sanitize_key($s){return (string)$s;}
function has_filter($key){return false;}
function current_user_can($cap){return false;}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($hook,$callable,$priority=10,$accepted=1){}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['calls']++;$GLOBALS['options'][$key]=$value;return true;}
function ok($label,$v){if(!$v)throw new RuntimeException('FAIL '.$label);echo "PASS ".$label."\n";}
$source=getenv('MAD4B_DISTRIBUTION_SOURCE');
if(!is_file($source))throw new RuntimeException('Missing source');
require $source;
$x=MAD4B_Distribution_Activation_V1::readiness('meta_catalog');
ok('delivery blocked without provider evidence',!$x['eligible'] && !$x['delivery_enabled']);
ok('real verification receipt required',in_array('current_provider_verification_receipt_required',$x['missing'],true));
ok('actual delivery adapter required',in_array('provider_delivery_adapter_missing',$x['missing'],true));
ok('cannot enable via unauthorized POST',MAD4B_Distribution_Activation_V1::activate('meta_catalog') instanceof WP_Error);
ok('cannot request without management rights',MAD4B_Distribution_Activation_V1::request('meta_catalog') instanceof WP_Error);
ok('no side effect during inspection',$GLOBALS['calls']===0);
echo "PASS_DISTRIBUTION_FAIL_CLOSED_CONTRACT\n";
