<?php
/** Local, no-credential/no-network source test for original WordPress app-password callback. */
define('ABSPATH','/');
define('REST_REQUEST',true);
$GLOBALS['environment']='local';
$GLOBALS['ssl']=false;
$GLOBALS['origin_enrolled']=true;
$GLOBALS['site_user_enrolled']=true;
$GLOBALS['credential_match']=false;
$GLOBALS['agent_grant']='allow';
$GLOBALS['subject']=0;
$GLOBALS['verifier_valid']=false;
$GLOBALS['hook_names']=[];
$GLOBALS['checks']=0;
class WP_Error {private $code;function __construct($x){$this->code=$x;}}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_User {public $ID;public function __construct($id){$this->ID=$id;}}
function add_action($n,$cb,$p=10,$args=1){$GLOBALS['hook_names'][]=$n;}
function add_filter($n,$cb,$p=10){$GLOBALS['hook_names'][]=$n;}
function is_ssl(){return $GLOBALS['ssl'];}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function get_current_user_id(){return $GLOBALS['subject'];}
function wp_set_current_user($id){$GLOBALS['subject']=$id;}
class MAD4B_SCP_Site_Profile {
 public static function origin_enrolled(){return $GLOBALS['origin_enrolled'];}
 public static function user_is_enrolled($id){return $GLOBALS['site_user_enrolled'];}
 public static function site_uuid(){return '123e4567-e89b-12d3-a456-426614174000';}
}
class MAD4B_SCP_AI_Only_Local_Activation {
 public static function matches_credential($u,$item){return $GLOBALS['credential_match'];}
}
class MAD4B_SCP_Agent_Registry {
 public static function resolve_agent($ctx){return ['id'=>19,'status'=>'enabled'];}
 public static function exact_grant($id,$server,$ability,$provider){
  if($id!==19||$server!=='mad4b-write'||$ability!=='mad4b/context-ai-review'||$provider!=='core')return ['effect'=>'deny'];
  return ['effect'=>$GLOBALS['agent_grant']];
 }
}
function wp_validate_application_password($unused) {
 if(!$GLOBALS['verifier_valid'])return null;
 MAD4B_SCP_Application_Password_Agent_Bridge::authenticated(new WP_User(17),['uuid'=>'wp-original-verified-callback']);
 return 17;
}
class RequestFixture {
 public $route,$authorization;
 public function __construct($route,$auth='Basic dGVzdDpmb28='){$this->route=$route;$this->authorization=$auth;}
 public function get_route(){return $this->route;}
 public function get_header($name){return $name==='authorization'?$this->authorization:'';}
}
function check($name,$value) {
 ++$GLOBALS['checks']; if(!$value){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);} echo 'PASS '.$name.PHP_EOL;
}
require dirname(__DIR__).'/includes/class-mad4b-scp-application-password-agent-bridge.php';
$bridge='MAD4B_SCP_Application_Password_Agent_Bridge';
check('source_registered_original_wordpress_hooks',in_array('application_password_did_authenticate',$GLOBALS['hook_names'],true)&&in_array('mad4b_scp_authenticated_subject_context',$GLOBALS['hook_names'],true));
$req=new RequestFixture('/mcp/mad4b-chatgpt');
check('basic_header_is_not_identity',!$bridge::verified_for_mcp_request($req));
check('anonymous_context_not_overwritten',$bridge::context(['authenticated'=>false])===['authenticated'=>false]);
$bridge::authenticated(new WP_User(17),[]);
check('missing_original_uuid_denied',!$bridge::verified_for_mcp_request($req));
$bridge::authenticated(new WP_User(17),['uuid'=>'wp-original-verified-callback']);
check('authenticated_context_bind',($bridge::context(['authenticated'=>false])['auth_method']??'')==='wp_application_password');
check('different_user_denied',!$bridge::verified_for_mcp_request($req));
$GLOBALS['subject']=17;
check('foreign_route_denied',!$bridge::verified_for_mcp_request(new RequestFixture('/wp/v2/users/me')));
check('non_basic_auth_header_denied',!$bridge::verified_for_mcp_request(new RequestFixture('/mcp/mad4b-chatgpt','Bearer fake')));
$GLOBALS['agent_grant']='deny';
check('revoked_exact_agent_grant_denied',!$bridge::verified_for_mcp_request($req));
$GLOBALS['agent_grant']='allow';
check('scoped_mcp_access_after_original_wp_auth',$bridge::verified_for_mcp_request($req)===true);
check('cannot_override_existing_auth_context',($bridge::context(['authenticated'=>true,'subject_fingerprint'=>'existing'])['subject_fingerprint']??'')==='existing');
$GLOBALS['environment']='production';
check('production_mcp_bridge_denied',!$bridge::verified_for_mcp_request($req));
$GLOBALS['environment']='local';$GLOBALS['subject']=0;
$GLOBALS['site_user_enrolled']=false;$GLOBALS['credential_match']=false;
$bridge::authenticated(new WP_User(17),['uuid'=>'wp-original-verified-callback']);
check('unenrolled_subject_denied',!$bridge::verified_for_mcp_request($req));
$GLOBALS['credential_match']=true;
$bridge::authenticated(new WP_User(17),['uuid'=>'wp-original-verified-callback']);
$GLOBALS['subject']=17;
check('local_ai_credential_must_have_exact_grant',$bridge::verified_for_mcp_request($req)===true);
$GLOBALS['origin_enrolled']=false;
$bridge::authenticated(new WP_User(17),['uuid'=>'wp-original-verified-callback']);
check('unenrolled_origin_denied',!$bridge::verified_for_mcp_request($req));
$GLOBALS['origin_enrolled']=true;$GLOBALS['site_user_enrolled']=true;
$GLOBALS['subject']=0;$bridge::authenticated(new WP_User(17),[]);
$GLOBALS['verifier_valid']=true;
check('rest_pre_dispatch_invokes_wp_verifier',$bridge::verified_for_mcp_request($req)===true&&$GLOBALS['subject']===17);
$GLOBALS['verifier_valid']=false;
echo 'SUMMARY pass='.$GLOBALS['checks'].' fail=0'.PHP_EOL;
