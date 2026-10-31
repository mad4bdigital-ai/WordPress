<?php
define('ABSPATH','/tmp/');define('WP_CLI',true);define('WP_ENVIRONMENT_TYPE','local');define('DB_NAME','mad4b_local_test');
define('MAD4B_CSO_LOCAL_HTTP_LOOPBACK',true);define('MAD4B_CSO_LOCAL_HTTP_TRANSPORT','ssh_tunnel');
class WP_CLI {public static function add_command($name,$callback){}}
function wp_parse_url($value,$part=-1){return $part===-1?parse_url($value):parse_url($value,$part);}
function home_url(){return 'http://127.0.0.1:8080';}
function wp_get_environment_type(){return $GLOBALS['env']??'local';}
function is_ssl(){return false;}
function get_current_user_id(){return $GLOBALS['uid']??0;}
class MAD4B_SCP_Site_Profile {public static $ready=true;public static function status(){return ['configured'=>true,'site_uuid'=>'abc-site-123','configured_environment'=>'local','origin_match'=>self::$ready,'environment_match'=>self::$ready,'wordpress_environment'=>'local','authority_ready'=>self::$ready,'same_origin_clone_protection'=>true,'deployment_binding_match'=>true,'mutation_pending_audit'=>false,'write_enabled'=>true];}}
class MAD4B_SCP_Audit {public static function storage_status(){return ['ready'=>true,'append_only'=>true];}}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-local-transport-policy.php';
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli-execution-context.php';
function check($v,$name){if(!$v)throw new Exception($name);}
$t='MAD4B_SCP_Local_Transport_Policy';$origin='http://127.0.0.1:8080';
check($t::loopback_enabled($origin),'exact opted-in localhost incorrectly denied');
check(!$t::loopback_enabled('http://example.com:8080'),'foreign public origin allowed');
check(!$t::loopback_enabled('http://127.0.0.1:80'),'privileged port allowed');
$headers=['HTTP_HOST'=>'127.0.0.1:8080','HTTP_ORIGIN'=>$origin,'HTTP_SEC_FETCH_SITE'=>'same-origin'];
check($t::request_allowed($origin,$headers,true),'same-origin POST incorrectly denied');
check(!$t::request_allowed($origin,$headers+['HTTP_X_FORWARDED_FOR'=>'203.0.113.2'],true),'proxy ingress allowed');
check(!$t::request_allowed($origin,array_merge($headers,['HTTP_ORIGIN'=>'https://foreign.test']),true),'cross-site POST allowed');
check(!$t::request_allowed($origin,array_merge($headers,['HTTP_HOST'=>'foreign.test']),false),'foreign Host allowed');
check($t::status($origin)['public_host_http_allowed']===false && $t::status($origin)['authorizing']===false,'transport provided authority');
putenv('MAD4B_CLI_TARGET_SITE_UUID=abc-site-123');
putenv('MAD4B_CLI_TARGET_ORIGIN='.$origin);
putenv('MAD4B_CLI_TARGET_DATABASE=mad4b_local_test');
putenv('MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=local');
$c='MAD4B_SCP_CLI_Execution_Context';
$r=$c::resolve('runtime.status.read',false);
check($r['read_context_verified']&&$r['host_site_binding_verified']&&!$r['local_operation_authorized'],'valid exact readonly context denied');
$w=$c::resolve('test.operation',true);
check($w['local_operation_authorized']&&$w['production_mutation_authorized']===false,'local bounded write incorrect');
putenv('MAD4B_CLI_TARGET_SITE_UUID=foreign');
$r=$c::resolve('test.operation',true);
check(!$r['local_operation_authorized']&&in_array('host_registry_exact_site',$r['blockers'],true),'foreign host site granted mutation');
putenv('MAD4B_CLI_TARGET_SITE_UUID=abc-site-123');
$GLOBALS['uid']=9;
check(!$c::resolve('test.operation',true)['local_operation_authorized'],'impersonating user granted host operation');
echo "LOCAL_TRANSPORT_CLI_CONTEXT_RUNTIME PASS loopback, origin, host identity, user impersonation, nonauthorizing status\n";
