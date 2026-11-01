<?php
/** Hermetic 305 enrollment: the authenticated company channel is the sole owner decision. */
declare(strict_types=1);
define('ABSPATH', '/');
$GLOBALS['fixture_options']=[];
$GLOBALS['fixture_auth_ok']=true;
$GLOBALS['fixture_assignment_ok']=true;
function add_action(...$args) {}
function wp_verify_nonce($nonce,$action){return $GLOBALS['fixture_auth_ok'] && $nonce==='fixture-nonce' && $action==='wp_rest';}
function get_current_user_id(){return 7;}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_text_field($v){return $v;}
function get_option($key,$default=false){return $GLOBALS['fixture_options'][$key]??$default;}
function add_option($key,$val,$dep='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['fixture_options']))return false;
 $GLOBALS['fixture_options'][$key]=$val;return true;
}
function update_option($key,$val,$autoload=false){$GLOBALS['fixture_options'][$key]=$val;return true;}
class WP_Error {public function __construct(public $code,public $message,public $data=[]){} public function get_error_code(){return $this->code;}}
class WP_REST_Response {public function __construct(public $data,public $status=200){}}
class WP_REST_Request implements ArrayAccess {
 public function __construct(private $attrs,private $input){}
 public function get_header($k){return $k==='X-WP-Nonce'?'fixture-nonce':'';}
 public function get_json_params(){return $this->input;}
 public function offsetGet(mixed $k):mixed{return $this->attrs[$k]??null;}
 public function offsetExists(mixed $k):bool{return isset($this->attrs[$k]);}
 public function offsetSet(mixed $k,mixed $v):void{$this->attrs[$k]=$v;}
 public function offsetUnset(mixed $k):void{unset($this->attrs[$k]);}
}
class MAD4B_Central_Dual_Lane_Registry_V1 {
 const HOST_MAINTENANCE=['maintenance.shell'];
 public static function allowed(){return true;}
 public static function central(){return true;}
}
class MAD4B_Company_Operation_Admission_V1 {
 public static function assess($company,$op){return ['policy_delegation_admitted'=>$GLOBALS['fixture_assignment_ok'] && $company==='all-royal-egypt','company_site_uuid'=>'staging-site'];}
 public static function manager_id(){return 'manager-1';}
}
class MAD4B_Manager_Auto_Write_V1 {
 public static function company_target($company){return ['state'=>$company==='all-royal-egypt'?'REGISTERED':'NOT_REGISTERED',
 'site_uuid'=>'staging-site','canonical_origin'=>'https://staging.allroyalegypt.com','environment'=>'staging'];}
 public static function entitlement(...$args){return ['assignment_authorized'=>$GLOBALS['fixture_assignment_ok'],'assignment_revision'=>5];}
}
require __DIR__.'/original-broker-source/305-mad4b-central-ssh-command-intake.php';
function assert_ok($v,$msg){if(!$v){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$input=[
 'host_id'=>'hosting-2','host_principal'=>'siteuser',
 'host_key_fingerprint'=>'SHA256:'.str_repeat('A',43),
 'credential_ref'=>'vault:registry/hosting-2',
 'reason'=>'Enable SSH management for site-specific WP-CLI',
 'capabilities'=>['wpcli.full']
];
$call=fn($company)=>MAD4B_Central_SSH_Command_Intake_V1::register_transport(new WP_REST_Request(['company'=>$company],$input));
$enroll=$call('all-royal-egypt');
assert_ok($enroll instanceof WP_REST_Response && $enroll->status===201,'single channel decision refused');
$transport=$enroll->data;
assert_ok($transport['capabilities']===['wpcli.full'] && $transport['state']==='PENDING_INDEPENDENT_SSH_VERIFICATION','staging pending state');
assert_ok($transport['full_wpcli_active']===false,'cannot activate before host proof');
$gid=$transport['full_wpcli_grant_id'];
$grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$gid);
assert_ok(is_array($grant)&&$grant['grant_mode']==='persistent'&&$grant['transport_id']===$transport['transport_id'],'grant scoped to same channel');
assert_ok($grant['company']==='all-royal-egypt'&&$grant['environment']==='staging','company/site not exact');
assert_ok(!class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal',false),'test intentionally has no secondary admin');
$GLOBALS['fixture_assignment_ok']=false;
assert_ok($call('all-royal-egypt') instanceof WP_Error,'revoked delegated rights must block enrollment');
$GLOBALS['fixture_assignment_ok']=true;$GLOBALS['fixture_auth_ok']=false;
assert_ok($call('all-royal-egypt') instanceof WP_Error,'no authenticated channel must block enrollment');
$GLOBALS['fixture_auth_ok']=true;
assert_ok($call('different-company') instanceof WP_Error,'foreign company must block enrollment');
echo json_encode(['test'=>'305_channel_once','passed'=>true,'checks'=>8,'live_wp_mutation'=>false]).PHP_EOL;
