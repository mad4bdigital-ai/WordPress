<?php
/** Actual first-party Capability Gateway read/REST permission and request scope. No execution. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_current_blog_id(){return 17;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function ug_check($valid,$reason){
 if(!$valid){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['count']=($GLOBALS['count']??0)+1;
}
class MAD4B_SCP_Policy {
 static $read=true;
 static function can_read(){return self::$read;}
}
class MAD4B_SCP_Ability_Catalog_Transport {
 static $permission=true;
 static function rest_permission($request){return self::$permission;}
}
class TestGatewayRequest {
 function get_header($name){return '';}
}
class MAD4B_SCP_Request_Generation {
 const CONTRACT='mad4b.request-generation.v1';
 static $receipt=[
  'contract'=>self::CONTRACT,'generation'=>1,'boundary_sha256'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  'context_sha256'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','presentation_sha256'=>'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc','authorizing'=>false,
  'mutation_performed'=>false
 ];
 static function admit($surface){return self::$receipt;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-unified-capability-gateway.php';
$c='MAD4B_SCP_Unified_Capability_Gateway';
ug_check($c::can_read_rest()===true && $c::can_read_rest(new TestGatewayRequest())===true,
 'Verified exact native WordPress and REST transport reader remains eligible');
foreach([new WP_Error('policy_unavailable'),1,'true',false,null] as $bad){
 MAD4B_SCP_Policy::$read=$bad;
 ug_check($c::can_read_rest()!==true,
  'Unverified native WP Policy result cannot authorize the Capability Gateway');
}
MAD4B_SCP_Policy::$read=true;
foreach([new WP_Error('catalog_unavailable'),1,'true',false,null] as $bad){
 MAD4B_SCP_Ability_Catalog_Transport::$permission=$bad;
 ug_check($c::can_read_rest(new TestGatewayRequest())!==true,
  'Unverified native REST catalog transport decision cannot authorize discovery');
}
MAD4B_SCP_Ability_Catalog_Transport::$permission=true;
$baseline=MAD4B_SCP_Request_Generation::$receipt;
foreach([new WP_Error('generation_missing'),false,1,'true',null,[],
 ['contract'=>'foreign','generation'=>1,'boundary_sha256'=>'a'],
 ['contract'=>MAD4B_SCP_Request_Generation::CONTRACT,'generation'=>1,'boundary_sha256'=>new WP_Error('sha_invalid')],
 ['contract'=>MAD4B_SCP_Request_Generation::CONTRACT,'generation'=>new WP_Error('number_unknown'),'boundary_sha256'=>'a']
] as $bad){
 MAD4B_SCP_Request_Generation::$receipt=$bad;
 $res=$c::dispatch(['action'=>'unknown-capability-operation']);
 ug_check(is_wp_error($res) &&
  $res->get_error_code()!=='mad4b_capability_gateway_action_invalid',
  'Unverified first-party request generation cannot proceed to Capability dispatch');
}
MAD4B_SCP_Request_Generation::$receipt=$baseline;
$valid=$c::dispatch(['action'=>'unknown-capability-operation']);
ug_check(is_wp_error($valid) &&
 $valid->get_error_code()==='mad4b_capability_gateway_action_invalid',
 'Original exact request scope retains normal fixed action validation');
echo 'PASS: UNIFIED_GATEWAY_PERMISSION_ORIGINAL '.($GLOBALS['count']??0).
 ' WordPress REST permission and nonauthorizing request scope cases'.PHP_EOL;
