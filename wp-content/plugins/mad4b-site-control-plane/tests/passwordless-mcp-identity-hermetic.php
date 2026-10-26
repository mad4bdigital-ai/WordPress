<?php
/** Original passwordless MCP verifier: PHP7.4/8.3, no real WordPress credentials or sessions. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['environment']='local';$GLOBALS['enrolled']=true;$GLOBALS['ssl']=true;
$GLOBALS['token']=str_repeat('a',64);$GLOBALS['agent_enabled']=true;
$GLOBALS['grant']=['effect'=>'allow','environment'=>'local'];$GLOBALS['site']='123e4567-e89b-12d3-a456-426614174000';
$GLOBALS['wp_user']=(object)['ID'=>23,'user_login'=>'mad4b_connection_abcdef123456','roles'=>['administrator']];
$GLOBALS['current_user']=0;$GLOBALS['transients']=[];$GLOBALS['hooks']=[];
function add_action($name,$callback,$priority=10){$GLOBALS['hooks'][]=$name;return true;}
function add_filter($name,$callback,$priority=10){$GLOBALS['hooks'][]=$name;return true;}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function is_ssl(){return $GLOBALS['ssl'];}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function get_userdata($id){return $GLOBALS['wp_user']&&$GLOBALS['wp_user']->ID===$id?$GLOBALS['wp_user']:false;}
function wp_set_current_user($id){$GLOBALS['current_user']=$id;}
class WP_Error{private $code;function __construct($code){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Site_Profile{
 static function origin_enrolled(){return $GLOBALS['enrolled'];}
 static function site_uuid(){return $GLOBALS['site'];}
}
class MAD4B_SCP_Policy{
 static function can_connect_user($id){return true;}
}
class MAD4B_SCP_Agent_Registry{
 static function resolve_agent($identity){
  if(!$GLOBALS['agent_enabled'])return new WP_Error('disabled');
  if(($identity['subject_type']??'')!=='mad4b_pwless_mcp')return new WP_Error('bad_type');
  return ['id'=>11,'public_id'=>'agent-certified','status'=>'enabled'];}
 static function exact_grant($id,$server,$ability,$provider){
  if($id!==11 || $server!=='mad4b-write'||$ability!=='mad4b/context-ai-review'||$provider!=='core')return new WP_Error('invalid_scope');
  return $GLOBALS['grant'];}
}
class Request{
 private $route,$authorization;
 function __construct($route,$authorization){$this->route=$route;$this->authorization=$authorization;}
 function get_route(){return $this->route;}
 function get_header($key){return $key==='authorization'?$this->authorization:'';}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-passwordless-connection.php';
$subject=hash('sha256','mad4b_pwless_mcp'.chr(0).$GLOBALS['site'].chr(0).'agent-certified');
$key='mad4b_pwless_mcp_'.hash('sha256',$GLOBALS['token']);
$original=['site'=>$GLOBALS['site'],'agent'=>'agent-certified','fingerprint'=>$subject,'wp_user_id'=>23];
$GLOBALS['transients'][$key]=$original;
$n=0;
function check($what,$ok){global $n;if(!$ok){fwrite(STDERR,'FAIL '.$what.PHP_EOL);exit(1);}$n++;}
function original(){global $key,$original;$GLOBALS['transients'][$key]=$original;}
function request($token=null,$route='/mcp/mad4b-chatgpt'){return new Request($route,'Bearer '.($token??$GLOBALS['token']));}
$class='MAD4B_SCP_Passwordless_Connection';
check('WordPress hooks registered',count($GLOBALS['hooks'])===5);
check('original exact user+site+Agent+grant accepted',$class::verify_mcp(request())===true);
check('current WP user adopted only on success',$GLOBALS['current_user']===23);
$identity=$class::identity_context(['authenticated'=>false]);
check('identity stamped from exact original subject',($identity['authenticated']??false)===true && ($identity['subject_fingerprint']??'')===$subject&&($identity['wp_user_id']??0)===23);
check('empty authenticated flag gets narrow bearer source',($identity['auth_method']??'')==='mad4b_passwordless_bearer'&&count($identity['token_scopes']??[])===0);
check('already authenticated context cannot be overwritten',$class::identity_context(['authenticated'=>true,'wp_user_id'=>44])['wp_user_id']===44);
check('missing token rejected',$class::verify_mcp(new Request('/mcp/mad4b-chatgpt',''))===false);
check('wrong bearer token rejected',$class::verify_mcp(request(str_repeat('b',64)))===false);
check('invalid path denied',$class::verify_mcp(request(null,'/mcp/mad4b-developer'))===false);
check('no original token leaves null auth identity',$class::identity_context(['authenticated'=>false])['authenticated']===false);
check('Bearer casing pattern enforced',$class::verify_mcp(new Request('/mcp/mad4b-chatgpt','bearer '.$GLOBALS['token']))===false);
$GLOBALS['transients'][$key]['site']='wrong';check('foreign site denied',$class::verify_mcp(request())===false);
original();$GLOBALS['transients'][$key]['agent']='evil';check('wrong agent binding denied',$class::verify_mcp(request())===false);
original();$GLOBALS['agent_enabled']=false;check('disabled Agent denied',$class::verify_mcp(request())===false);
$GLOBALS['agent_enabled']=true;
$GLOBALS['grant']=['effect'=>'deny','environment'=>'local'];
check('exact grant denied',$class::verify_mcp(request())===false);
$GLOBALS['grant']=new WP_Error('grant_missing');
check('missing original grant denied',$class::verify_mcp(request())===false);
$GLOBALS['grant']=['effect'=>'allow','environment'=>'local'];
$GLOBALS['wp_user']->roles=['subscriber'];
check('subscriber cannot bind MCP bearer',$class::verify_mcp(request())===false);
$GLOBALS['wp_user']->roles=['administrator','subscriber'];
check('two WordPress roles denied',$class::verify_mcp(request())===false);
$GLOBALS['wp_user']->roles=['administrator'];
$GLOBALS['wp_user']->user_login='other_admin';
check('non-integration admin denied',$class::verify_mcp(request())===false);
$GLOBALS['wp_user']->user_login='prefix_mad4b_connection_abcdef';
check('integration prefix must be at start',$class::verify_mcp(request())===false);
$GLOBALS['wp_user']->user_login='mad4b_connection_abcdef123456';
$GLOBALS['enrolled']=false;
check('unenrolled site denied',$class::verify_mcp(request())===false);
$GLOBALS['enrolled']=true;
$GLOBALS['environment']='production';
check('production denied',$class::verify_mcp(request())===false);
$GLOBALS['environment']='staging';$GLOBALS['ssl']=false;
check('insecure staging denied',$class::verify_mcp(request())===false);
$GLOBALS['ssl']=true;
check('SSL staging allowed with same Site/Agent',$class::verify_mcp(request())===true);
$GLOBALS['environment']='local';
unset($GLOBALS['transients'][$key]);
check('missing/expired transient denied',$class::verify_mcp(request())===false);
check('no authenticated identity after failed verification',$class::identity_context(['authenticated'=>false])['authenticated']===false);
echo 'PASS: '.$n.' original passwordless MCP identity and PHP 7.4 compatibility cases'.PHP_EOL;
