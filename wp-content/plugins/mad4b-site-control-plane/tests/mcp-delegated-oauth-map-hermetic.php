<?php
// Hermetic mapper: bearer signature has ALREADY been checked by the mocked
// OAuth resource verifier. This test proves site/route/user gating only.
define('ABSPATH','/tmp');
$GLOBALS['verified']=true;
$GLOBALS['enrolled']=true;
$GLOBALS['write_gate']=true;
$GLOBALS['user_id']=0;
$GLOBALS['enroll_calls']=0;
$GLOBALS['last_error']='';
function add_filter(){}
function do_action($hook,$code,$user){$GLOBALS['last_error']=$code;}
function absint($id){return abs((int)$id);}
function wp_set_current_user($id){$GLOBALS['user_id']=$id;}
function get_userdata($id){return $id===7?(object)array('ID'=>7):false;}
function user_can($user,$cap){return $user->ID===7 && $cap==='edit_posts';}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error {
 private $code;
 function __construct($c,$message=''){ $this->code=$c; }
 function get_error_code(){return $this->code;}
 function get_error_message(){return $this->code;}
}
function verify($ok,$message){if(!$ok){fwrite(STDERR,'FAIL '.$message.PHP_EOL);exit(1);}}
class MAD4B_SCP_OAuth_Resource_Bridge {
 static function verified_bearer_active(){return $GLOBALS['verified'];}
 static function is_trusted_issuer($x){return $x==='https://issuer.example';}
 static function subject_allowed($issuer,$subject){return $subject==='user:7';}
}
class MAD4B_SCP_Site_Profile {
 static function origin_enrolled(){return $GLOBALS['enrolled'];}
 static function oauth_enabled(){return true;}
 static function user_is_enrolled($id){return $id===7&&$GLOBALS['enrolled'];}
 static function site_uuid(){return 'site-staging-uuid';}
 static function revision(){return 2;}
 static function profile_digest(){return str_repeat('a',64);}
}
class MAD4B_SCP_Policy {
 static function can_connect_user($id){return $id===7&&$GLOBALS['enrolled'];}
}
class MAD4B_SCP_Identity_Context {
 static function current(){return array(
 'authenticated'=>true,'subject_type'=>'oauth','auth_method'=>'oauth2_bearer',
 'origin'=>'mcp','wp_user_id'=>7,'token_scopes'=>array('mad4b:read'),
 'subject_fingerprint'=>hash('sha256',"oauth\0https://issuer.example\0user:7"));}
}
class MAD4B_SCP_Delegation_Mode {
 const DELEGATED='delegated_user';
 static function selected_mode(){return 'delegated_user';}
 static function ensure_content_actor($identity,$prepared=false){
  $GLOBALS['enroll_calls']++;
  if(!$prepared || $identity['wp_user_id']!==7 || !$GLOBALS['write_gate'])
   return new WP_Error('user_or_gate_invalid');
  return array('wp_user_id'=>7,'status'=>'enabled');
 }
}
class MockRequest {
 private $route;
 function __construct($route){$this->route=$route;}
 function get_route(){return $this->route;}
 function get_header($name){
  $header=array('alg'=>'RS256','typ'=>'JWT');
  $payload=array('iss'=>'https://issuer.example','sub'=>'user:7');
  $enc=function($x){return rtrim(strtr(base64_encode(json_encode($x)),'+/','-_'),'=');};
  return 'Bearer '.$enc($header).'.'.$enc($payload).'.signature';
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-oauth-subject-user-bridge.php';
$c='MAD4B_SCP_OAuth_Subject_User_Bridge';
verify($c::map_verified_subject(null,null,new MockRequest('/mcp/mad4b-chatgpt'))===null,'verified mapping');
verify($GLOBALS['user_id']===7,'mapped exact WP user');
verify($GLOBALS['enroll_calls']===1,'one bounded ChatGPT onboarding');
verify($c::mapped_context()['wp_user_id']===7,'mapped context retained');
$c::map_verified_subject(null,null,new MockRequest('/mcp/mad4b-developer'));
verify($GLOBALS['enroll_calls']===1,'developer route never provisions content grants');
$c::map_verified_subject(null,null,new MockRequest('/mcp/mad4b-enrollment'));
verify($GLOBALS['enroll_calls']===1,'enrollment route never provisions content grants');
$GLOBALS['verified']=false;
$c::map_verified_subject(null,null,new MockRequest('/mcp/mad4b-chatgpt'));
verify($GLOBALS['enroll_calls']===1,'unverified bearer not provisioned');
$GLOBALS['verified']=true;
$GLOBALS['enrolled']=false;
$no=$c::map_verified_subject(null,null,new MockRequest('/mcp/mad4b-chatgpt'));
verify(is_wp_error($no),'unenrolled site denied');
verify($GLOBALS['enroll_calls']===1,'unenrolled user no actor');
$GLOBALS['enrolled']=true;
$GLOBALS['write_gate']=false;
verify($c::map_verified_subject(null,null,new MockRequest('/mcp/mad4b-chatgpt'))===null,'read remains safe during write gate denial');
verify($GLOBALS['last_error']==='user_or_gate_invalid','operator receives bounded error code');
echo "PASS: DELEGATED_OAUTH_SUBJECT_MAP_STAGING_GUARDS\n";
