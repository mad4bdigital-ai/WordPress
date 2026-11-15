<?php
/** Original dynamic routing + evidence-backed central enrichment, no live WordPress. */
define('ABSPATH',__DIR__.'/');
define('REST_REQUEST',true);
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=null){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function source_test($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}$GLOBALS["original_source_passed_assertions"]=($GLOBALS["original_source_passed_assertions"]??0)+1;}
final class WP_REST_Request {
 private $method,$route,$headers;
 function __construct($method='POST',$route='/mad4b/v1/enrich',$headers=array()){
  $this->method=$method;$this->route=$route;$this->headers=$headers;
 }
 function get_method(){return $this->method;}
 function get_route(){return $this->route;}
 function get_header($x){return $this->headers[strtolower($x)]??'';}
}
final class MAD4B_Test_REST_Server {
 function get_routes(){
   if($GLOBALS['route_server_throw'])throw new RuntimeException('unavailable REST route registry');
   return $GLOBALS['routes'];
 }
}
function rest_get_server(){return new MAD4B_Test_REST_Server();}
function get_current_user_id(){return $GLOBALS['current_user'];}
function current_user_can($permission){return $GLOBALS['admin'];}
function is_user_logged_in(){return $GLOBALS['logged_in'];}
function wp_verify_nonce($nonce,$action){
 return (($GLOBALS['nonce_allowed']??true)===true&&$nonce==='good_nonce'&&$action==='wp_rest')?1:false;
}
function get_option($option,$default=array()){return $GLOBALS['options'][$option]??$default;}
function update_option($option,$value,$autoload=false){
 $GLOBALS['options'][$option]=$value;return true;
}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_get_environment_type(){return $GLOBALS['environment'];}
final class MAD4B_SCP_Policy {
 public static function can_read(){
   if($GLOBALS['allow_read']==='throw')throw new RuntimeException('read-policy-unknown');
   return $GLOBALS['allow_read'];
 }
 public static function can_mutate(){
   if($GLOBALS['allow_write']==='throw')throw new RuntimeException('write-policy-unknown');
   return $GLOBALS['allow_write'];
 }
 public static function can_connect_user($id){return $id===17;}
}
final class MAD4B_SCP_Site_Profile {
 public static function status(){
   if($GLOBALS['profile_throw'])throw new RuntimeException('profile not available');
   return $GLOBALS['profile'];
 }
}
final class MAD4B_SCP_Identity_Context {
 public static function current(){
   if($GLOBALS['identity_throw'])throw new RuntimeException('identity not available');
   return $GLOBALS['identity'];
 }
}
final class MAD4B_SCP_OAuth_Resource_Bridge {
 public static function verified_bearer_active(){
   if($GLOBALS['bearer_verified']==='throw')
     throw new DomainException('OAuth verifier unknown');
   return $GLOBALS['bearer_verified'];
 }
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-capability-transport-routing.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-central-write-acceptance.php';
function source_reset(){
 $GLOBALS['current_user']=17;
 $GLOBALS['admin']=true;
 $GLOBALS['logged_in']=true;
 $GLOBALS['nonce_allowed']=true;
 $GLOBALS['allow_read']=true;
 $GLOBALS['allow_write']=true;
 $GLOBALS['bearer_verified']=false;
 $GLOBALS['profile_throw']=false;
 $GLOBALS['identity_throw']=false;
 $GLOBALS['route_server_throw']=false;
 $GLOBALS['environment']='staging';
 $GLOBALS['profile']=array('site_uuid'=>'11111111-1111-4111-8111-111111111111',
   'configured'=>true,'origin_match'=>true,'deployment_binding_match'=>true,
   'same_origin_clone_protection'=>true,'environment_match'=>true,
   'configured_environment'=>'staging','environment'=>'staging',
   'authority_ready'=>true,'mutation_pending_audit'=>false);
 $GLOBALS['identity']=array('authenticated'=>true,'wp_user_id'=>17,
    'auth_method'=>'wp_rest_nonce','subject_type'=>'wp_user');
 $GLOBALS['options']=array();
 $GLOBALS['routes']=array('/mad4b/v1/enrich'=>array(
    array('methods'=>array('POST'=>true),'permission_callback'=>'source_route_permission')));
 $_SERVER['REQUEST_METHOD']='POST';
}
function source_route_permission($request){
 if($GLOBALS['route_permission']==='throw')
   throw new DomainException('route callback unavailable');
 return $GLOBALS['route_permission'];
}
$rt='MAD4B_SCP_Capability_Transport_Routing';
$cwa='MAD4B_SCP_Central_Write_Acceptance';
source_reset();
$GLOBALS['route_permission']=true;
$request=new WP_REST_Request('POST','/mad4b/v1/enrich',array('x-wp-nonce'=>'good_nonce'));
$e=$rt::rest_write_evidence($request);
source_test($e['rest_exact_write_verified']===true &&
  $e['rest_operation_permission_verified']===true,
 'Only exact registered POST route permission and nonce grant REST evidence');
$ok=$cwa::route_admission($request);
source_test($ok['eligible']===true && $ok['mutation_performed']===false,
 'Central writes only project the exact native route permission, never execute');
source_reset();
source_test($rt::mode_for_transport($rt::VPS)==='registered_operator',
 'Default route preference is site-scoped original registered operator');
$state=['http_read_authenticated'=>true,'vps_read_verified'=>true,
 'http_write_authenticated'=>false,'vps_exact_write_verified'=>true];
$ch=$rt::choose('write',$state);
source_test($ch['status']==='READY' && $ch['selected_transport']===$rt::VPS
 && $ch['authority_created']===false,'VPS exact non-user write evidence eligible, not itself authority');
source_test($rt::choose('write',array_replace($state,['vps_exact_write_verified'=>false]))['status']
 ==='HOLD_NO_VERIFIED_TRANSPORT','Read verified cannot silently become a write');
foreach(['unknown','partial','committed'] as $prior){
 $ch=$rt::choose('write',array_replace($state,['previous_attempt_outcome'=>$prior]));
 source_test($ch['retry_allowed']===false && $ch['selected_transport']===null,
  'No write transport failover after uncertain or committed attempt');
}
source_test($rt::choose('delete',$state)['status']==='HOLD_INVALID_EFFECT',
 'Unknown effect cannot activate any route');
$saved_profile=$GLOBALS['profile'];
$GLOBALS['profile']['deployment_binding_match']=false;
source_test($rt::choose('write',$state)['status']==='HOLD_NO_VERIFIED_TRANSPORT',
 'Unverified site profile forces read-only regardless of client VPS hint');
$GLOBALS['profile']=$saved_profile;
source_test($rt::choose('write',['rest_read_authenticated'=>true,
 'rest_exact_write_verified'=>true])['status']==='HOLD_NO_VERIFIED_TRANSPORT',
 'REST write needs operation and site proof, not transport hints');
$scoped=['rest_read_authenticated'=>true,'rest_exact_write_verified'=>true,
 'rest_site_identity_verified'=>true,'rest_operation_permission_verified'=>true];
source_test($rt::choose('write',$scoped)['selected_transport']===$rt::REST,
 'REST exact operation proof is distinct from WordPress login');
source_reset();
$GLOBALS['options'][$rt::EXECUTION_PREFERENCE_OPTION]=[
 'contract'=>$rt::EXECUTION_PREFERENCE_CONTRACT,'revision'=>1,
 'default_mode'=>'signed_broker','site_overrides'=>[]];
source_test($rt::choose('write',$state)['status']==='HOLD_NO_VERIFIED_TRANSPORT',
 'Signed broker preference never invents a signed broker proof');
$ch=$rt::choose('write',array_merge($state,['vps_signed_broker_verified'=>true]));
source_test($ch['selected_transport']===$rt::VPS,
 'Independent server signed broker evidence is still needed');
source_reset();
$GLOBALS['options'][$rt::EXECUTION_PREFERENCE_OPTION]=[
 'contract'=>'invalid','revision'=>1,
 'default_mode'=>'registered_operator','site_overrides'=>[]];
source_test($rt::choose('write',$state)['status']==='HOLD_NO_VERIFIED_TRANSPORT',
 'Malformed stored preference is read-only fail closed');
source_reset();
$bad_rest=array(
 'user_missing'=>static function(){ $GLOBALS['current_user']=0;},
 'not_logged_in'=>static function(){ $GLOBALS['logged_in']=false;},
 'nonce_missing'=>static function(){ $GLOBALS['nonce_allowed']=false; },
 'site_drift'=>static function(){ $GLOBALS['profile']['origin_match']=false;},
 'policy_denied'=>static function(){ $GLOBALS['allow_write']=false;},
 'route_permission_denied'=>static function(){ $GLOBALS['route_permission']=false;},
 'route_permission_wp_error'=>static function(){ $GLOBALS['route_permission']=new WP_Error('blocked');},
 'route_registry_throw'=>static function(){ $GLOBALS['route_server_throw']=true;},
 'site_profile_exception'=>static function(){ $GLOBALS['profile_throw']=true;},
 'identity_exception'=>static function(){ $GLOBALS['identity_throw']=true;},
 'route_permission_exception'=>static function(){ $GLOBALS['route_permission']='throw';},
 'duplicate_route'=>static function(){ $GLOBALS['routes']['/mad4b/v1/enrich'][]=
    array('methods'=>['POST'=>true],'permission_callback'=>'source_route_permission');},

);
foreach($bad_rest as $label=>$change){
 source_reset();$GLOBALS['route_permission']=true;$change();
 $r=$rt::rest_write_evidence($request);
 source_test($r['rest_exact_write_verified']===false,'REST must deny '.$label);
}
source_reset();$GLOBALS['route_permission']=true;
$GLOBALS['identity']=['authenticated'=>false];
source_test($rt::rest_write_evidence($request)['rest_exact_write_verified']===true,
 'Browser REST authentication is proven by WP nonce and logged-in user, not by external OAuth identity');
source_reset();$GLOBALS['route_permission']=true;
source_test($rt::rest_write_evidence(new WP_REST_Request('POST',
 '/mad4b/v1/enrich',['authorization'=>'Bearer fake']))['rest_exact_write_verified']===false,
 'Cannot forge OAuth bearer from text Authorization header');
source_reset();$GLOBALS['route_permission']=true;
$GLOBALS['identity']=['authenticated'=>true,'wp_user_id'=>17,
 'auth_method'=>'oauth2_bearer','subject_type'=>'oauth'];
$bearer_request=new WP_REST_Request('POST','/mad4b/v1/enrich',
 ['authorization'=>'Bearer verified-context']);
$GLOBALS['bearer_verified']=true;
source_test($rt::rest_write_evidence($bearer_request)['rest_exact_write_verified']===true,
 'Verified original OAuth bearer is admissible only with exact user and route');
foreach(array(null,1,new WP_Error('oauth_unknown'),'throw') as $unverified){
  $GLOBALS['bearer_verified']=$unverified;
  source_test($rt::rest_write_evidence($bearer_request)['rest_exact_write_verified']===false,
    'Unknown or throwing OAuth resource verification cannot grant REST write');
}
source_reset();$GLOBALS['route_permission']=true;
$GLOBALS['identity']=['authenticated'=>true,'wp_user_id'=>17,
 'auth_method'=>'wp_application_password','subject_type'=>'wp_app_password'];
source_test($rt::rest_write_evidence(new WP_REST_Request('POST',
 '/mad4b/v1/enrich',['authorization'=>'Basic verified-by-wordpress']))['rest_exact_write_verified']===true,
 'Existing WordPress-verified Basic authentication remains valid');
source_test($rt::rest_write_evidence(new WP_REST_Request('POST',
 '/mad4b/v1/enrich'))['rest_exact_write_verified']===true,
 'Proxy-stripped verified WP application password remains valid');

source_test($rt::rest_write_evidence(new WP_REST_Request('PATCH','/mad4b/v1/enrich',
 ['x-wp-nonce'=>'good_nonce']))['rest_exact_write_verified']===false,
 'REST request method mismatch rejected independently');
source_reset();$now=1700000000;
$source=[
 'site_uuid'=>'11111111-1111-4111-8111-111111111111','environment'=>'staging',
 'subject_id'=>'native-post-1','ability'=>'mad4b/enrich-text',
 'job_id'=>'content-job-1','source_sha256'=>str_repeat('a',64),
 'revision'=>'8','reference_id'=>'approved-source-1',
 'evidence_verified_at'=>$now,'independent_verification'=>true,
 'quality_pass'=>true,'rights_pass'=>true,'evidence_sha256'=>str_repeat('b',64)
];
$scope=['site_uuid'=>$source['site_uuid'],'environment'=>'staging',
 'max_evidence_age_seconds'=>3600];
$claim=array_merge($source,['contract'=>$cwa::CONTRACT,
 'verified_at'=>$now]);
unset($claim['evidence_verified_at']);
$claim['idempotency_key']=$cwa::intent_key($source,$scope,$source['evidence_sha256']);
$accept=$cwa::verify($claim,$source,$scope,$now+25);
source_test($accept['eligible']===true && $accept['mutation_performed']===false,
 'Exact source-bound identity, verified QA and immutable job intent qualify without writing');
$cases=[
 'site_uuid'=>'11111111-1111-4111-8111-111111111119',
 'environment'=>'production',
 'subject_id'=>'other',
 'ability'=>'mad4b/enrich-image',
 'job_id'=>'different-job',
 'source_sha256'=>str_repeat('c',64),
 'revision'=>'9',
 'reference_id'=>'other',
 'verified_at'=>$now-3650,
 'independent_verification'=>false,
 'quality_pass'=>false,
 'rights_pass'=>false,
 'evidence_sha256'=>str_repeat('d',64),
 'idempotency_key'=>str_repeat('e',64)
];
foreach($cases as $key=>$value){
 $drift=$claim;$drift[$key]=$value;
 $out=$cwa::verify($drift,$source,$scope,$now+25);
 source_test($out['eligible']===false,'Claim drift must not qualify: '.$key);
}
$bad_source=$source;
$bad_source['rights_pass']=false;
source_test($cwa::verify($claim,$bad_source,$scope,$now+25)['eligible']===false,
 'Server-known media rights must be verified separately from client claim');
source_test($cwa::permitted()===true,'Scoped original WP owner may pass preflight only');
source_reset();
$GLOBALS['profile_throw']=true;
source_test($rt::mode_for_transport($rt::VPS)==='read_only',
 'Unavailable enrolled Site Profile denies selection rather than fatal PHP');
source_test($cwa::site_scope()===array()
   && $cwa::permitted()===false,
 'Unavailable Site Profile cannot be used for central writes');
source_reset();
$GLOBALS['allow_write']='throw';
$permitted=$cwa::permitted();
source_test($permitted===false,
 'Throwing original policy denies Central Write before mutation');
source_reset();
source_test($cwa::site_scope()['environment']==='staging',
 'Current enrolled Site Profile owns the effective write environment');
foreach(array('configured','environment_match','same_origin_clone_protection',
    'authority_ready','deployment_binding_match','origin_match') as $required){
  source_reset();
  $GLOBALS['profile'][$required]=false;
  source_test($cwa::site_scope()===array() && $cwa::permitted()===false,
    'Original central write scope refuses missing site fact: '.$required);
}
source_reset();
$GLOBALS['profile']['environment']='production';
source_test($cwa::site_scope()===array() && $cwa::permitted()===false,
 'Central write refuses explicit source Site Profile environment drift');
source_reset();
$GLOBALS['profile']['mutation_pending_audit']=true;
source_test($cwa::site_scope()===array() && $cwa::permitted()===false,
 'No central write while Site Profile binding has pending audit');
source_reset();
$GLOBALS['environment']='production';
source_test($cwa::site_scope()['environment']==='staging',
 'Implicit native WordPress Production default cannot override effective Site Profile');
source_reset();$GLOBALS['route_permission']=true;
foreach(array(null,1,new WP_Error('unknown_policy'),'throw') as $value){
 source_reset();$GLOBALS['route_permission']=true;
 $GLOBALS['allow_write']=$value;
 $e=$rt::rest_write_evidence($request);
 source_test($e['rest_exact_write_verified']===false &&
   $e['rest_operation_permission_verified']===false,
   'Unverified original mutation policy cannot authorize REST route');
}
foreach(array(null,1,new WP_Error('unknown_policy'),'throw') as $value){
 source_reset();$GLOBALS['route_permission']=true;
 $GLOBALS['allow_read']=$value;
 source_test($rt::rest_write_evidence($request)['rest_exact_write_verified']===false,
   'Unverified original read policy cannot authorize REST route');
}
source_reset();
$GLOBALS['profile']['authority_ready']=false;
source_test($rt::mode_for_transport($rt::VPS)==='read_only',
 'Unready Site Profile cannot retain a write execution mode');
source_reset();
$GLOBALS['profile']['same_origin_clone_protection']=false;
source_test($rt::mode_for_transport($rt::VPS)==='read_only',
 'Missing independent Host identity cannot retain registered operator mode');
source_reset();
$GLOBALS['profile']['environment_match']=false;
source_test($rt::mode_for_transport($rt::VPS)==='read_only',
 'Site environment mismatch cannot retain registered operator mode');
source_reset();$GLOBALS['route_permission']=true;
$GLOBALS['profile']['authority_ready']=false;
source_test($rt::rest_write_evidence($request)['rest_exact_write_verified']===false,
 'REST site authority explicitly denied by Profile readiness');
source_reset();
$GLOBALS['allow_write']=false;
source_test($cwa::permitted()===false,'Revoked original mutation policy denies preflight');
echo "PASS: CENTRAL_TRANSPORT_WRITE_ORIGINAL ".($GLOBALS["original_source_passed_assertions"]??0)." original-code positive/negative routing and proof checks\n";
