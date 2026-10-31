<?php
define('ABSPATH','/tmp/');define('MAD4B_LOCAL_CLIENT_SITE_UUID','11111111-2222-3333-4444-555555555555');
class WP_Error {public $errors=[];public function add($key,$msg){$this->errors[$key]=$msg;}}
class WP_User {public function __construct(public $ID,public $user_login){}}
function wp_get_environment_type(){return 'local';}
function get_user_meta($id,$key,$one){return '11111111-2222-3333-4444-555555555555';}
function wp_parse_url($value,$part){return parse_url($value,$part);}
function home_url($p){return 'http://localhost/';}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-ai-only-early-auth-guard.php';
function run($path,$method='POST',$route=''){$e=new WP_Error;$_SERVER['REQUEST_URI']=$path;$_SERVER['REQUEST_METHOD']=$method;$_GET=[];if($route!=='')$_GET['rest_route']=$route;MAD4B_SCP_AI_Only_Early_Auth_Guard::authenticate($e,new WP_User(7,'mad4b_ai_local_worker'),null);return array_keys($e->errors);}
function check($ok,$message){if(!$ok)throw new Exception($message);}
check(run('/wp-json/mad4b-local-ai/v1/context-review')===[],'context POST denied');
check(run('/wp-json/mad4b-local-ai/v1/context-review','GET')===[],'context GET denied');
check(run('/wp-json/mad4b-local-ai/v1/domain-review')===[],'domain POST denied');
check(in_array('mad4b_ai_only_service_route_denied',run('/wp-json/mad4b-local-ai/v1/domain-review','GET'),true),'domain GET accepted');
check(in_array('mad4b_ai_only_service_route_denied',run('/wp-json/wp/v2/users'),true),'foreign route accepted');
check(in_array('mad4b_ai_only_service_route_denied',run('/wp-json/wp/v2/users','POST','/mad4b-local-ai/v1/context-review'),true),'forged query accepted');
check(run('/','POST','/mad4b-local-ai/v1/context-review')===[],'index query route incorrectly denied');
echo "AI_SERVICE_EARLY_AUTH_RUNTIME PASS isolated credential route separation\n";
