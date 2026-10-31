<?php
define('ABSPATH','/tmp/');
class WP_Error {public function __construct(public $code='',public $message='',public $data=[]){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['caps'][$cap]??false;}
function wp_validate_auth_cookie($cookie,$scheme){return $GLOBALS['valid_cookie']??false?7:false;}
function wp_get_session_token(){return 'session';}
function wp_verify_nonce($nonce,$scope){return $GLOBALS['valid_nonce']??false;}
function get_current_user_id(){return 7;}
function check($v,$label){if(!$v)throw new Exception($label);}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-domain-cloudflare.php';
$c='MAD4B_SCP_Company_Domain_Cloudflare';
check(!$c::valid_host('192.0.2.6'),'IP accepted as company hostname');
check(!$c::valid_host('EXAMPLE.COM'),'uppercase host accepted');
check(!$c::valid_host('test..example.com'),'invalid hostname accepted');
check($c::valid_host('example.com'),'valid host rejected');
$settings=['registry'=>['exampleco'=>['production_admitted'=>true,'origin_host'=>'origin.example.com','approved_custom_domains'=>['travel.example.net']]],'origin_ipv4'=>'8.8.8.8'];
$target=$c::validate_target(['company_code'=>'exampleco','mode'=>'subdomain','hostname'=>'exampleco.mad4b.com'],$settings);
check(!is_wp_error($target),'approved company subdomain denied');
check(is_wp_error($c::validate_target(['company_code'=>'exampleco','mode'=>'subdomain','hostname'=>'other.mad4b.com'],$settings)),'cross-company hostname allowed');
check(is_wp_error($c::validate_target(['company_code'=>'otherco','mode'=>'subdomain','hostname'=>'otherco.mad4b.com'],$settings)),'unknown company admitted');
check(is_wp_error($c::validate_target(['company_code'=>'exampleco','mode'=>'custom_domain','hostname'=>'bad.example.org'],$settings)),'unapproved custom domain accepted');
check(is_wp_error($c::can_write()),'unprivileged domain modification allowed');
$GLOBALS['caps']=['manage_options'=>true,'activate_plugins'=>true];
check(is_wp_error($c::can_write()),'administrator without browser proof allowed');
define('LOGGED_IN_COOKIE','wp_logged_in');
$_COOKIE[LOGGED_IN_COOKIE]='signed-cookie';
$GLOBALS['valid_cookie']=true;
$_SERVER['HTTP_X_WP_NONCE']='nonce';
$GLOBALS['valid_nonce']=true;
check($c::can_write()===true,'valid owner browser session denied');
echo "COMPANY_DOMAIN_RUNTIME PASS bound company host, no foreign DNS, browser owner gate\n";
