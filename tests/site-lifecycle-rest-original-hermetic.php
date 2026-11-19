<?php
/** Original Site Lifecycle WordPress REST; no network or real WP options. */
define('ABSPATH',__DIR__.'/');
define('WP_ENVIRONMENT_TYPE','staging');
define('LOGGED_IN_COOKIE','test-signed-browser-cookie');
define('DB_NAME','mad4b_staging_test_db');
class WP_Error {
 private $code;
 function __construct($code,$msg='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
 function get_error_message(){return 'original error';}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_REST_Server { const READABLE='GET'; const CREATABLE='POST'; }
class WP_REST_Request {
 private $params;
 function __construct($params){$this->params=$params;}
 function get_json_params(){return $this->params;}
}
class WP_REST_Response {
 public $data;public $code;public $headers=[];
 function __construct($data,$code=200){$this->data=$data;$this->code=$code;}
 function header($name,$value){$this->headers[$name]=$value;}
}
function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$ns.$route]=$args;}
function current_user_can($cap){return $GLOBALS['permissions'][$cap]??false;}
function get_current_user_id(){return 17;}
function wp_get_environment_type(){return $GLOBALS['runtime_environment'];}
function wp_validate_auth_cookie($cookie,$scheme){
 return $GLOBALS['cookie_auth'];
}
function wp_get_session_token(){return $GLOBALS['session_token'];}
function wp_verify_nonce($nonce,$name){
 return $GLOBALS['nonce_result'];
}
function wp_json_encode($input,$opts=0){return json_encode($input,$opts);}
function lifecycle_assert($value,$reason){
 if(!$value){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class MAD4B_SCP_Audit {
 static function storage_status(){return ['ready'=>true,'append_only'=>true,'event_count'=>7];}
}
class MAD4B_SCP_Site_Profile {
 const ENV_SYNC_PROFILE_ONLY='profile_only';
 static $save_count=0;
 static $input=null;
 static $origin='https://staging.wayfa.example';
 static $display='WAYFA Tours Staging';
 static function current_origin(){return self::$origin;}
 static function profile(){return [
  'display_name'=>self::$display,'site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'brand_id'=>'wayfa','environment'=>'production','revision'=>9
 ];}
 static function status(){return [
  'configured'=>true,'site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'revision'=>9,'profile_digest'=>str_repeat('a',64),
  'environment'=>'production','configured_environment'=>'production',
  'origin_match'=>true,'authority_ready'=>false,
  'deployment_binding_configured'=>true,'same_origin_clone_protection'=>false,
  'write_enabled'=>false,'mutation_pending_audit'=>false,
  'blockers'=>[]
 ];}
 static function save_current_site($input){self::$save_count++;self::$input=$input;return [
  'saved'=>true];}
 static function reset_cache(){}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-lifecycle-rest.php';
$c='MAD4B_SCP_Site_Lifecycle_REST';
$GLOBALS['permissions']=['manage_options'=>false,'activate_plugins'=>false];
$GLOBALS['runtime_environment']='staging';
$GLOBALS['cookie_auth']=false;
$GLOBALS['nonce_result']=false;
$GLOBALS['session_token']='';
$GLOBALS['routes']=[];
$_COOKIE=[];$_SERVER=[];
$c::routes();
lifecycle_assert(count($GLOBALS['routes'])===7,'Original Site Lifecycle REST has six read routes and one guarded POST');
foreach($GLOBALS['routes'] as $route=>$data){
 lifecycle_assert($data['permission_callback']===[$c,substr($route,-17)==='/reenroll-staging'?'can_write':'can_read']
  &&$data['methods']===(substr($route,-17)==='/reenroll-staging'?'POST':'GET'),
  'Original REST route is never public and has method-specific authentication');
}
lifecycle_assert(is_wp_error($c::can_write()),
 'Unprivileged WordPress user cannot reenroll a company Site Profile');
$GLOBALS['permissions']=['manage_options'=>true,'activate_plugins'=>false];
lifecycle_assert(is_wp_error($c::can_write()),
 'Manage Options without Activate Plugins cannot update native Site Profile');
$GLOBALS['permissions']['activate_plugins']=true;
lifecycle_assert(is_wp_error($c::can_write()),
 'REST Bearer or Application Password without native browser session cannot reenroll');
$_COOKIE[LOGGED_IN_COOKIE]='fake-session-cookie';
$_SERVER['HTTP_X_WP_NONCE']='browser-original-rest-nonce';
$GLOBALS['cookie_auth']=17;
$GLOBALS['session_token']='session-original';
$GLOBALS['nonce_result']=new WP_Error('nonce_source_unavailable');
lifecycle_assert(is_wp_error($c::can_write()),
 'Truthy WP_Error nonce verification is never permission to mutate Site Profile');
$GLOBALS['nonce_result']=1;
lifecycle_assert($c::can_write()===true,
 'Exact original WordPress cookie, session, capability and nonce permit plan-bound execution');
foreach([0,new WP_Error('cookie_invalid'),'17'] as $cookie_identity){
 $GLOBALS['cookie_auth']=$cookie_identity;
 lifecycle_assert(is_wp_error($c::can_write()),
  'Malformed original WordPress cookie identity cannot bypass native owner consent');
}
$GLOBALS['cookie_auth']=17;
$GLOBALS['runtime_environment']='production';
lifecycle_assert(is_wp_error($c::can_write()),
 'Original Staging-only lifecycle never reenrolls Production');
$GLOBALS['runtime_environment']='staging';
$plan=$c::plan();
lifecycle_assert($plan instanceof WP_REST_Response && $plan->data['eligible']===true
 &&$plan->headers['Cache-Control']==='private, no-store, max-age=0',
 'Re-enrollment plan is exact, read only and privately uncached');
$body=[
 'expected_revision'=>$plan->data['expected_revision'],
 'expected_site_uuid'=>$plan->data['expected_site_uuid'],
 'expected_profile_digest'=>$plan->data['expected_profile_digest'],
 'expected_plan_sha256'=>$plan->data['plan_sha256'],
 'expected_origin'=>$plan->data['expected_origin'],
 'confirmation'=>$plan->data['confirmation'],
];
$bad=$body;$bad['unknown_key']='forged';
lifecycle_assert(is_wp_error($c::reenroll_staging(new WP_REST_Request($bad)))
 &&MAD4B_SCP_Site_Profile::$save_count===0,
 'Unknown JSON input cannot change native Site Profile');
$bad=$body;$bad['expected_plan_sha256']=str_repeat('c',64);
lifecycle_assert(is_wp_error($c::reenroll_staging(new WP_REST_Request($bad)))
 &&MAD4B_SCP_Site_Profile::$save_count===0,
 'Stale signed source plan cannot change site identity');
$done=$c::reenroll_staging(new WP_REST_Request($body));
lifecycle_assert(MAD4B_SCP_Site_Profile::$save_count===1
 &&MAD4B_SCP_Site_Profile::$input['display_name']==='WAYFA Tours Staging',
 'Company display name must derive from original Site Profile, never hardcode ETG');
lifecycle_assert(MAD4B_SCP_Site_Profile::$input['oauth_enabled']===false
 &&MAD4B_SCP_Site_Profile::$input['skills_enabled']===false
 &&MAD4B_SCP_Site_Profile::$input['write_enabled']===false
 &&MAD4B_SCP_Site_Profile::$input['managed_runtime_enabled']===false,
 'Reenrollment never copies cloned source write/OAuth/Skills or provider authority');
lifecycle_assert($done instanceof WP_REST_Response && $done->code===409
 &&$done->data['state']==='PERSISTED_BUT_RECONCILIATION_REQUIRED',
 'Unverified native persisted source must return reconciliation instead of claiming completion');
echo 'PASS: SITE_LIFECYCLE_REST '.($GLOBALS['cases']??0).
 ' first-party REST, browser authorization and no-grant cases'.PHP_EOL;
