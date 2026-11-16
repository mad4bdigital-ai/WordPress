<?php
/** Original Staging-only TEST Brand: never a real WordPress Site enrollment. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$m='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['brand_env'];}
function current_user_can($cap){return $GLOBALS['permissions'][$cap]??false;}
function add_action($name,$handler,$priority=10){$GLOBALS['hooks'][$name][]=$handler;}
function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$ns.$route]=$args;}
function rest_ensure_response($value){return new TestResponse($value);}
function wp_json_encode($v,$opt=0){return json_encode($v,$opt);}
function get_option($name,$fallback=false){return $GLOBALS['options'][$name]??$fallback;}
class TestResponse {
 public $data,$headers=[];
 function __construct($value){$this->data=$value;}
 function header($name,$value){$this->headers[$name]=$value;}
}
class WP_REST_Request {
 private $params;
 function __construct($params){$this->params=$params;}
 function get_json_params(){return $this->params;}
}
function brand_check($ok,$reason){
 if(!$ok){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class MAD4B_SCP_Context_Authority {
 const STAGING_TRIAL_ARCHIVE='test_archive';
 static $status=[];
 static $last_payload=null;
 static $return_error=false;
 static function profile(){return self::$status;}
 static function staging_trial_plan(){return ['can_create'=>false,'mutation_performed'=>false];}
 static function staging_trial_create($params){
  self::$last_payload=$params;
  return self::$return_error?new WP_Error('trial_not_ready'):['created'=>true];
 }
 static function staging_trial_rename($params){
  self::$last_payload=$params;
  return self::$return_error?new WP_Error('trial_not_ready'):['renamed'=>true];
 }
 static function staging_trial_restore($params){
  self::$last_payload=$params;
  return self::$return_error?new WP_Error('trial_not_ready'):['restored'=>true];
 }
}
class MAD4B_SCP_Site_Profile {
 static $status=[];
 static function status(){return self::$status;}
}
class MAD4B_SCP_Company_Communication {
 static $allowed=false;
 static function canSend(){return self::$allowed;}
}
$GLOBALS['brand_env']='staging';
$GLOBALS['permissions']=['manage_options'=>true,'activate_plugins'=>true];
$GLOBALS['hooks']=[];$GLOBALS['routes']=[];
$GLOBALS['options']=['test_archive'=>['trial_id'=>'brand-test-42']];
MAD4B_SCP_Context_Authority::$status=[
 'status'=>'test_only','site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'brand_id'=>'brand-test-42','brand_name'=>'Wayfa Staging TEST','revision'=>3
];
MAD4B_SCP_Site_Profile::$status=[
 'site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'environment'=>'staging','configured'=>true,'origin_match'=>true,
 'environment_match'=>true
];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-brand-trial.php';
$c='MAD4B_SCP_Staging_Brand_Trial';
brand_check($c::canReview()===true,'Original admin plugin capabilities gate the test-only Brand API');
foreach($GLOBALS['hooks']['rest_api_init'] as $callback)$callback();
brand_check(count($GLOBALS['routes'])===5,'Five original staging brand routes registered');
foreach($GLOBALS['routes'] as $path=>$descriptor){
 brand_check($descriptor['permission_callback']===[$c,'canReview']
  &&in_array($descriptor['methods'],['GET','POST'],true),
  'REST method and callback must remain owned by original first-party test Brand runtime');
}
$plan=$c::plan(null);
brand_check($plan instanceof TestResponse &&$plan->data['can_create']===false
 &&$plan->headers['Cache-Control']==='private, no-store',
 'Read-only original test Brand plan cannot create or authorize a source');
$trial=$c::status(null);
brand_check($trial->data['test_active']===true
 &&$trial->data['trial_id']==='brand-test-42'
 &&$trial->headers['Cache-Control']==='private, no-store',
 'Exact original active test Brand is privately observed by an enrolled administrator');
MAD4B_SCP_Company_Communication::$allowed=true;
$with_mail=$c::status(null);
brand_check($with_mail->data['email_send_allowed']===false,
 'Test-only Brand status cannot claim live mail permission even if a generic communication provider does');
MAD4B_SCP_Company_Communication::$allowed=new WP_Error('smtp_undetermined');
$unknown=$c::status(null);
brand_check($unknown->data['email_send_allowed']===false,
 'Unknown native communication authority cannot be exposed as sendable');
MAD4B_SCP_Company_Communication::$allowed=false;
MAD4B_SCP_Site_Profile::$status['site_uuid']='22222222-2222-4222-8222-222222222222';
$foreign=$c::status(null);
brand_check($foreign->data['test_active']===false
 &&$foreign->data['trial_id']===null,
 'Cloned Site Profile cannot claim historical foreign site TEST Brand as active');
MAD4B_SCP_Site_Profile::$status['site_uuid']=MAD4B_SCP_Context_Authority::$status['site_uuid'];
$GLOBALS['brand_env']='production';
brand_check(!$c::canReview(),
 'Production cannot use Staging-only original test Brand administration');
$GLOBALS['brand_env']='staging';
$GLOBALS['permissions']['activate_plugins']=false;
brand_check(!$c::canReview(),'Manage Options alone is insufficient for TEST Brand mutation');
$GLOBALS['permissions']['activate_plugins']=true;
$request=new WP_REST_Request(['expected_plan_sha256'=>str_repeat('a',64),'confirmation'=>'not-approved']);
$created=$c::create($request);
brand_check($created instanceof TestResponse
 &&MAD4B_SCP_Context_Authority::$last_payload['confirmation']==='not-approved'
 &&$created->headers['Cache-Control']==='private, no-store',
 'TEST Brand create passes original input untouched to native Context Authority, not its own writer');
MAD4B_SCP_Context_Authority::$return_error=true;
brand_check(is_wp_error($c::rename($request))&&is_wp_error($c::restore($request)),
 'Original native Context Authority refusal is propagated, not suppressed as success');
echo 'PASS: STAGING_BRAND_TRIAL '.($GLOBALS['cases']??0).
 ' first-party site-bound REST and private TEST-ONLY status cases'.PHP_EOL;
