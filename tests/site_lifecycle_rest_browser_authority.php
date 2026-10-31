<?php
define('ABSPATH','/tmp/');
define('WP_ENVIRONMENT_TYPE','staging');
define('LOGGED_IN_COOKIE','wordpress_logged_in');
class WP_Error {public function __construct(public $code='',public $message='',public $data=[]){}}
class WP_REST_Server {const READABLE='GET';const CREATABLE='POST';}
$GLOBALS['caps']=['manage_options'=>false,'activate_plugins'=>false];
$GLOBALS['cookie_valid']=false;
$GLOBALS['nonce_valid']=false;
function current_user_can($cap){return $GLOBALS['caps'][$cap]??false;}
function wp_get_environment_type(){return 'staging';}
function wp_validate_auth_cookie($v,$scheme){return $GLOBALS['cookie_valid']?7:false;}
function get_current_user_id(){return 7;}
function wp_get_session_token(){return 'signed-session';}
function wp_verify_nonce($v,$act){return $GLOBALS['nonce_valid'];}
function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$route]=$args;}
function check($v,$label){if(!$v)throw new Exception($label);}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-lifecycle-rest.php';
$c='MAD4B_SCP_Site_Lifecycle_REST';
$c::routes();
check(count($GLOBALS['routes'])===7,'unexpected route count');
foreach($GLOBALS['routes'] as $route=>$args){
    check($args['permission_callback']==[$c,$route==='/site-lifecycle/reenroll-staging'?'can_write':'can_read'],'incorrect route permission');
}
check($c::can_read()===false,'unauth read allowed');
check($c::can_write() instanceof WP_Error,'unprivileged reenroll accepted');
$GLOBALS['caps']=['manage_options'=>true,'activate_plugins'=>true];
check($c::can_write() instanceof WP_Error,'bearer-only reenroll accepted');
$_COOKIE[LOGGED_IN_COOKIE]='auth-cookie';
$GLOBALS['cookie_valid']=true;
check($c::can_write() instanceof WP_Error,'cookie without wp nonce accepted');
$_SERVER['HTTP_X_WP_NONCE']='nonce';
$GLOBALS['nonce_valid']=true;
check($c::can_write()===true,'valid exact administrator session rejected');
echo "SITE_LIFECYCLE_REST_BROWSER_AUTHORITY PASS seven routes, browser nonce, staff denial\n";
