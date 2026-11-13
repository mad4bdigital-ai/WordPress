<?php
error_reporting(E_ALL);
define('ABSPATH','/tmp/');
define('MAD4B_MU_MODULAR_LOADER_BOOTED',true);
define('WP_CLI',true);
$GLOBALS['options']=[];
function get_current_user_id(){return 0;}
function get_option($k,$v=false){return array_key_exists($k,$GLOBALS['options'])?$GLOBALS['options'][$k]:$v;}
function add_option($k,$v,$autoload='',$arg=false){if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}
function add_action($a,$b,$c=null){}
class WP_REST_Request{
    public $method,$route,$body,$headers=[];
    public function __construct($method,$route,$body=''){ $this->method=$method;$this->route=$route;$this->body=$body;}
    public function get_method(){return $this->method;}
    public function get_route(){return $this->route;}
    public function get_body(){return $this->body;}
    public function get_header($k){return $this->headers[strtolower($k)]??'';}
    public function set_header($k,$v){$this->headers[strtolower($k)]=$v;}
}
class MAD4B_Central_Dual_Lane_Registry_V1{public static function central(){return true;}public static function allowed(){return false;}}
class MAD4B_SCP_Site_Profile{public static function origin_enrolled(){return true;}public static function site_urls_match_enrollment(){return true;}}
class MAD4B_Company_Native_WPCLI_Enrollment_V1{
    const SLOT='mad4b_company_native_wpcli_slot_v1_';
    public static function readback($company,$env) {
        return $GLOBALS['enrolled']?['state'=>'ORIGINAL_305_PERSISTENT_PAIR_ENROLLED',
          'full_wpcli_active'=>true,'host_authority_verified'=>true]:
            ['state'=>'DENIED'];
    }
}
class MAD4B_Company_Environment_Registry_V2{
    public static function target($c,$e){
        return ['site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
         'canonical_origin'=>'https://wayfatours.com','environment'=>'production'];
    }
}
class MAD4B_Central_VPS_Broker_V1{const PUBKEY='pubkey';}
$fixture=json_decode(file_get_contents(getenv('MAD4B_305_TEST_FIXTURE')?:'/tmp/mad4b-305-no-user-tests/fixture.json'),true);
$GLOBALS['options']['pubkey']=$fixture['public_key_b64'];
$GLOBALS['options'][MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.'wayfatours_production']=
    ['grant_id'=>str_repeat('1',32)];
$GLOBALS['options']['mad4b_central_ssh_grant_v1_'.str_repeat('1',32)]=[
    'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED',
    'principal_type'=>'signed_vps_wpcli','operation'=>'company.remote.wpcli.full',
    'grant_mode'=>'persistent','target_host_id'=>'srv2030908',
    'grant_id'=>str_repeat('1',32),'company'=>'wayfatours',
    'environment'=>'production',
    'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
    'canonical_origin'=>'https://wayfatours.com',
    'central_principal_id'=>1
];
$GLOBALS['enrolled']=true;
require (getenv('MAD4B_305_TEST_SOURCE')?:'/tmp/mad4b-305-native-no-user-candidate.php');
$c='MAD4B_Central_SSH_Command_Intake_V1';
$grant=$GLOBALS['options']['mad4b_central_ssh_grant_v1_'.str_repeat('1',32)];
$reflect=new ReflectionMethod($c,'actor_id');
function req($proof,$body='{}') {
    $request=new WP_REST_Request('POST','/mad4b/v1/central-execution-lanes/wayfatours/commands',$body);
    $request->set_header('X-MAD4B-Native-Proof',base64_encode(json_encode($proof)));
    return $request;
}
$missing=req($fixture['proof']);$missing->set_header('X-MAD4B-Native-Proof','');
if($c::permitted($missing)){echo "MISSING_SIGNATURE_ACCEPTED";exit(2);}
$bad=$fixture['proof'];$bad['signature']=base64_encode(str_repeat('x',64));
if($c::permitted(req($bad))){echo "INVALID_SIGNATURE_ACCEPTED";exit(3);}
$changed=req($fixture['proof'],'{"changed":true}');
if($c::permitted($changed)){echo "DIFFERENT_PAYLOAD_ACCEPTED";exit(4);}
$grant['state']='REVOKED';
$GLOBALS['options']['mad4b_central_ssh_grant_v1_'.str_repeat('1',32)]=$grant;
if($c::permitted(req($fixture['proof']))){echo "REVOKED_GRANT_ACCEPTED";exit(5);}
$grant['state']='CENTRAL_OPERATION_SCOPE_GRANTED';
$GLOBALS['options']['mad4b_central_ssh_grant_v1_'.str_repeat('1',32)]=$grant;
$r=req($fixture['proof']);
if(!$c::permitted($r)){echo "SIGNED_NATIVE_CLI_REJECTED";exit(6);}
if($reflect->invoke(null)!==1){echo "ORIGINAL_GRANT_ACTOR_NOT_USED";exit(7);}
if(!$c::permitted($r)){echo "SAME_REST_REQUEST_REJECTED";exit(8);}
if($c::permitted(req($fixture['proof']))){echo "SIGNED_NONCE_REPLAY_ACCEPTED";exit(9);}
$g=$fixture['get_proof'];
$get=new WP_REST_Request('GET','/mad4b/v1/central-execution-lanes/wayfatours/commands/'.str_repeat('b',32),'');
$get->set_header('X-MAD4B-Native-Proof',base64_encode(json_encode($g)));
$GLOBALS['enrolled']=false;
$rejectedWhileRevoked=req($fixture['proof']);
if($c::permitted($rejectedWhileRevoked)){echo "REVOKED_ENROLLMENT_ACCEPTED";exit(12);}
$GLOBALS['enrolled']=true;
if(!$c::permitted($get)){echo "SIGNED_GET_READBACK_REJECTED";exit(10);}
if($reflect->invoke(null)!==1){echo "GET_PRINCIPAL_NOT_PINNED";exit(11);}
echo json_encode([
 'wp_user_override_required'=>false,
 'signed_native_grant_accepted'=>true,
 'missing_signature_denied'=>true,
 'tampered_signature_denied'=>true,
 'changed_command_denied'=>true,
 'revoked_grant_denied'=>true,
 'nonce_replay_denied'=>true,
 'signed_readback_accepted'=>true,
 'original_rest_flow_untouched'=>true
]);
