<?php
/** First-party WordPress principal validation. No permissions are granted. */
define('ABSPATH',__DIR__.'/');
define('REST_REQUEST',true);
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action($hook,$cb){return true;}
function get_option($key,$fallback=null){return $GLOBALS['mode'];}
function get_current_user_id(){return 17;}
function get_userdata($id){return $id===17?(object)['ID'=>17]:false;}
function user_can($user,$cap){return in_array($cap,['read','edit_posts'],true)&&$user->ID===17;}
function absint($id){return abs((int)$id);}
function sanitize_key($id){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$id));}
function wp_validate_auth_cookie($cookie,$type){return $GLOBALS['cookie'];}
function wp_verify_nonce($nonce,$purpose){return $GLOBALS['nonce_result'];}
function is_ssl(){return true;}
function owner_check($ok,$why){
 if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Site_Profile {
 static $origin=true;
 static function origin_enrolled(){return self::$origin;}
}
class MAD4B_SCP_Policy {
 static $allow=true;
 static $gate=['effective'=>true];
 static function can_connect_user($id){return self::$allow;}
 static function mutation_gate_status(){return self::$gate;}
}
class MAD4B_SCP_Schema {
 static function is_ready(){return true;}
}
class MAD4B_SCP_Agent_Registry {
 static $lookups=0,$created=0;
 static $binding=false,$actor=null,$grant_rows=[];
 static function subject_binding($type,$fp){self::$lookups++;return self::$binding;}
 static function get_agent_by_slug($slug){return self::$actor;}
 static function create_agent($args){self::$created++;return new WP_Error('fixture_no_create');}
 static function grants_for_agent($id,$server){return self::$grant_rows;}
}
class MAD4B_SCP_Servers {
 static function provider_for_ability($server,$ability){return 'core';}
}
function wp_get_environment_type(){return $GLOBALS['mock_environment']??'production';}
class MAD4B_SCP_OAuth_Resource_Bridge {
 static $active=true;
 static $scope=true;
 static function verified_bearer_active(){return self::$active;}
 static function verified_bearer_has_scope($scope){return self::$scope;}
}
class MAD4B_SCP_OAuth_Subject_User_Bridge {
 static $subject;
 static $mapped_auth=true;
 static function mapped_context(){return [
  'authenticated'=>self::$mapped_auth,'wp_user_id'=>17,'subject_fingerprint'=>self::$subject
 ];}
}
$GLOBALS['mode']='delegated_user';
$GLOBALS['cookie']=17;
$GLOBALS['nonce_result']=1;
$_SERVER=['HTTP_X_WP_NONCE'=>'signed-rest-nonce'];
MAD4B_SCP_OAuth_Subject_User_Bridge::$subject=str_repeat('a',64);
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-delegation-mode.php';
$c='MAD4B_SCP_Delegation_Mode';
$oauth=[
 'authenticated'=>true,'subject_type'=>'oauth','auth_method'=>'oauth2_bearer',
 'origin'=>'mcp','wp_user_id'=>17,
 'subject_fingerprint'=>MAD4B_SCP_OAuth_Subject_User_Bridge::$subject,
 'token_scopes'=>['mad4b:read']
];
owner_check(is_object($c::authenticated_user($oauth)),
 'Exact independently mapped OAuth user can be observed as existing actor');
owner_check(is_object($c::verified_wordpress_user($oauth)),
 'The same verified OAuth actor resolves via first-party native authority');
foreach([new WP_Error('policy_error'),null,1,'true'] as $wrong){
 MAD4B_SCP_Policy::$allow=$wrong;
 owner_check($c::authenticated_user($oauth)===null,
  'Unverified connection Policy cannot prove user delegation');
}
MAD4B_SCP_Policy::$allow=true;
foreach([new WP_Error('oauth_error'),null,1,'true'] as $wrong){
 MAD4B_SCP_OAuth_Resource_Bridge::$active=$wrong;
 owner_check($c::authenticated_user($oauth)===null,
  'OAuth bearer active status must be exact verified true');
}
MAD4B_SCP_OAuth_Resource_Bridge::$active=true;
foreach([new WP_Error('scope_error'),null,1,'true'] as $wrong){
 MAD4B_SCP_OAuth_Resource_Bridge::$scope=$wrong;
 owner_check($c::authenticated_user($oauth)===null,
  'OAuth original scope must be positively verified and not error-shaped');
}
MAD4B_SCP_OAuth_Resource_Bridge::$scope=true;
foreach([new WP_Error('mapped_unverified'),null,1,'true'] as $unknown_mapping){
 MAD4B_SCP_OAuth_Subject_User_Bridge::$mapped_auth=$unknown_mapping;
 owner_check($c::authenticated_user($oauth)===null,
  'Mapped OAuth subject must carry exact first-party boolean authentication');
}
MAD4B_SCP_OAuth_Subject_User_Bridge::$mapped_auth=true;
foreach([new WP_Error('source_unverified'),null,1,'true'] as $unknown_auth){
 $changed=$oauth;$changed['authenticated']=$unknown_auth;
 owner_check($c::authenticated_user($changed)===null,
  'Unverified original identity authenticated flag cannot establish WordPress user');
}
owner_check(is_object($c::verified_wordpress_user([])),
 'Core authenticated WordPress browser session is independent of MCP bearer');
foreach([new WP_Error('cookie_error'),null,1,'17',false] as $wrong){
 $GLOBALS['cookie']=$wrong;
 owner_check($c::verified_wordpress_user([])===null,
  'Invalid signed WordPress user cookie cannot impersonate delegated user');
}
$GLOBALS['cookie']=17;
foreach([new WP_Error('nonce_error'),null,'1',true,false,3] as $wrong){
 $GLOBALS['nonce_result']=$wrong;
 owner_check($c::verified_wordpress_user([])===null,
  'Unknown or error-shaped original REST nonce cannot be a valid WP session');
}
$GLOBALS['nonce_result']=2;
owner_check(is_object($c::verified_wordpress_user([])),
 'Core second nonce time window preserves legitimate browser authentication');
MAD4B_SCP_Site_Profile::$origin=false;
owner_check($c::verified_wordpress_user([])===null,
 'Original WordPress browser session cannot be adopted into foreign site enrollment');
MAD4B_SCP_Site_Profile::$origin=true;
foreach([new WP_Error('site_write_unknown'),null,['effective'=>1],['effective'=>new WP_Error('unknown')],[]] as $unknown_gate){
 MAD4B_SCP_Policy::$gate=$unknown_gate;
 MAD4B_SCP_Agent_Registry::$lookups=0;
 $result=$c::ensure_content_actor($oauth);
 owner_check(is_wp_error($result) &&MAD4B_SCP_Agent_Registry::$lookups===0,
  'Unknown original site write gate cannot even look up delegation agent');
}
MAD4B_SCP_Policy::$gate=['effective'=>true];
foreach([new WP_Error('write_scope_unknown'),null,1,'1'] as $unknown_scope){
 MAD4B_SCP_OAuth_Resource_Bridge::$scope=$unknown_scope;
 MAD4B_SCP_Agent_Registry::$lookups=0;
 $result=$c::ensure_content_actor($oauth);
 owner_check(is_wp_error($result) &&MAD4B_SCP_Agent_Registry::$lookups===0,
  'Unknown OAuth write scope must not reach Agent Registry enrollment');
}
MAD4B_SCP_OAuth_Resource_Bridge::$scope=true;
MAD4B_SCP_Agent_Registry::$lookups=0;
$disabled=$c::ensure_content_actor($oauth);
owner_check(is_wp_error($disabled) &&MAD4B_SCP_Agent_Registry::$lookups===1,
 'Explicit write scope still observes production environment deny before any actor provisioning');
$GLOBALS['mock_environment']='staging';
foreach(['unreadable',1,new stdClass(),new WP_Error('db_error')] as $unknown_binding){
 MAD4B_SCP_Agent_Registry::$binding=$unknown_binding;
 MAD4B_SCP_Agent_Registry::$created=0;
 $result=$c::ensure_content_actor($oauth);
 owner_check(is_wp_error($result) && MAD4B_SCP_Agent_Registry::$created===0,
  'Uncertain original registered subject binding cannot launch a new delegated agent');
}
MAD4B_SCP_Agent_Registry::$binding=null;
MAD4B_SCP_Agent_Registry::$created=0;
$new_approval=$c::ensure_content_actor($oauth);
owner_check(is_wp_error($new_approval)
 &&MAD4B_SCP_Agent_Registry::$created===1,
 'Only exact null unbound subject can reach original Agent Registry create method');
$GLOBALS['mock_environment']='production';
$GLOBALS['mode']='independent_nhi';
owner_check($c::verified_wordpress_user([])===null
 &&$c::authenticated_user($oauth)===null,
 'Independent NHI does not impersonate a WordPress user');
echo 'PASS: DELEGATION_OWNER '.($GLOBALS['checks']??0).
 ' original OAuth and browser identity failures'.PHP_EOL;
