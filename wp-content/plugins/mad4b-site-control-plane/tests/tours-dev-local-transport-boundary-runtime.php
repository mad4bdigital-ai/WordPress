<?php
/** Exact local-only transport source; any forwarded/public request fails closed. */
error_reporting(E_ALL); define('ABSPATH',__DIR__.'/');
define('MAD4B_CSO_LOCAL_HTTP_LOOPBACK', true);
define('MAD4B_CSO_LOCAL_HTTP_TRANSPORT','ssh_tunnel');
$GLOBALS['env']='local';$GLOBALS['home']='http://127.0.0.1:8088';$GLOBALS['ssl']=false;
function wp_get_environment_type(){return $GLOBALS['env'];}
function home_url(){return $GLOBALS['home'];}
function is_ssl(){return $GLOBALS['ssl'];}
function wp_parse_url($url){return parse_url($url);}
class MAD4B_SCP_Site_Profile {static $bound=true;static function status(){return ['origin_match'=>self::$bound,'environment_match'=>self::$bound,'configured_environment'=>'local','wordpress_environment'=>'local'];}}
function expect($condition,$label){if(!$condition){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-local-transport-policy.php';
$c='MAD4B_SCP_Local_Transport_Policy';$url='http://127.0.0.1:8088';
expect($c::loopback_enabled($url),'Exact declared OS loopback eligible');
expect(!$c::loopback_enabled('http://example.com:8088'),'Reject external host');
expect(!$c::loopback_enabled('https://127.0.0.1:8088'),'Reject fake HTTPS loopback');
expect(!$c::loopback_enabled('http://localhost:8088'),'Reject different actual WordPress binding');
expect(!$c::loopback_enabled('http://127.0.0.1:80'),'Reject privileged port');
expect(!$c::loopback_enabled('http://127.0.0.1:8088/path'),'Reject path decorations');
expect(!$c::loopback_enabled('http://someone:secret@127.0.0.1:8088'),'Reject credentials in origin');
expect(!$c::loopback_enabled('http://127.0.0.1:8088?x=y'),'Reject query injection');
$h=['HTTP_HOST'=>'127.0.0.1:8088'];
expect($c::request_allowed($url,$h),'Explicit local GET can be eligible');
expect(!$c::request_allowed($url,['HTTP_HOST'=>'evil.example']),'Reject forged HTTP Host');
expect(!$c::request_allowed($url,$h,true),'POST cannot omit Origin and fetch-site proof');
expect($c::request_allowed($url,$h+['HTTP_ORIGIN'=>$url,'HTTP_SEC_FETCH_SITE'=>'same-origin'],true),'POST requires same-origin proof');
foreach(['HTTP_X_FORWARDED_FOR','HTTP_FORWARDED','HTTP_X_REAL_IP','HTTP_X_FORWARDED_HOST'] as $k)
 expect(!$c::request_allowed($url,$h+[$k=>'127.0.0.1']),'Reject reverse proxy override '.$k);
MAD4B_SCP_Site_Profile::$bound=false;expect(!$c::loopback_enabled($url),'Site binding is independently required');
MAD4B_SCP_Site_Profile::$bound=true;$GLOBALS['env']='production';expect(!$c::loopback_enabled($url),'Reject Production even when constants opted in');
$GLOBALS['env']='local';$GLOBALS['ssl']=true;expect(!$c::request_allowed($url,$h),'Reject HTTPS misbinding on local HTTP route');
$status=$c::status($url);expect(!$status['authorizing']&&!$status['public_host_http_allowed']&&!$status['enables_secret_provider'],'Never grant transport authority or secrets');
echo "PASS: local-only transport rejects forged host, proxy, HTTP identity drift, and Production; never authorizes mutation\n";
