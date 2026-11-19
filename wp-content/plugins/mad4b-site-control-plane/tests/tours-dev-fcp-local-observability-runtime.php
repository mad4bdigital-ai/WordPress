<?php
/** Read-only FCP status: source identity, GET owner permission, no grants or writes. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $c;public function __construct($c,$message='',$args=[]){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class TestResponse {public $headers=[];public function header($k,$v){$this->headers[$k]=$v;}}
function rest_ensure_response($v){return new TestResponse();}
function current_user_can($cap){return in_array($cap,$GLOBALS['caps'],true);}
function add_action($event,$callback,$priority=10){$GLOBALS['registered_hooks'][]=[$event,$callback,$priority];}
function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$ns.$route]=$args;}
class MAD4B_SCP_Site_Profile {
 static $enrolled=false;static $urls_match=true;static $uuid='12345678-1234-4123-8123-123456789abc';static $env='staging';static $origin='https://company.example';
 public static function origin_enrolled(){return self::$enrolled;}
 public static function site_urls_match_enrollment(){return self::$urls_match;}
 public static function site_uuid(){return self::$uuid;}
 public static function current_environment(){return self::$env;}
 public static function site_origin(){return self::$origin;}
 public static function profile_digest(){return str_repeat('c',64);}
}
class MAD4B_SCP_Site_Capability_Discovery {static function observe($origin){return ['read_inventory_state'=>'limited','snapshot_sha256'=>str_repeat('a',64),'discovery_complete'=>false];}}
function ensure($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-fcp-local-observability.php';
$c='MAD4B_SCP_FCP_Local_Observability';$GLOBALS['caps']=[];$c::boot();ensure(count($GLOBALS['registered_hooks'])===1,'Hook registration only');$c::routes();
$route=$GLOBALS['routes']['mad4b/v1/fcp/local-capabilities'];
ensure($route['methods']==='GET','Exact read-only GET route');ensure(!$route['permission_callback'](),'No public observation');
ensure(is_wp_error($c::observe()) && $c::observe()->get_error_code()==='mad4b_fcp_site_identity_unverified','Missing enrollment denied');
MAD4B_SCP_Site_Profile::$enrolled=true;MAD4B_SCP_Site_Profile::$urls_match=false;
ensure($c::observe() instanceof WP_Error,'Nonmatching Site Profile denied');
MAD4B_SCP_Site_Profile::$urls_match=true;MAD4B_SCP_Site_Profile::$uuid='untrusted';
ensure($c::observe()->get_error_code()==='mad4b_fcp_site_binding_invalid','Malformed site uuid denied');
MAD4B_SCP_Site_Profile::$uuid='12345678-1234-4123-8123-123456789abc';MAD4B_SCP_Site_Profile::$env='local';
ensure($c::observe() instanceof WP_Error,'Unsupported environment denied');
MAD4B_SCP_Site_Profile::$env='staging';MAD4B_SCP_Site_Profile::$origin='http://company.example';
ensure($c::observe() instanceof WP_Error,'Non-HTTPS origin denied');
MAD4B_SCP_Site_Profile::$origin='https://company.example';$x=$c::observe();
ensure(is_array($x)&&$x['read_only']&&$x['authorizing']===false&&$x['native_write_authorized']===false,'Read status without authority');
ensure($x['capabilities'][0]['supported_operations']===['read'],'No write capability');
$GLOBALS['caps']=['mad4b_manage_company_operations'];ensure($route['permission_callback'](),'Company operator read permission');
$GLOBALS['caps']=[];ensure(!$route['permission_callback'](),'Revocation is effective');
$GLOBALS['caps']=['manage_options'];$response=$c::respond();ensure($response instanceof TestResponse&&$response->headers['Cache-Control']==='private, no-store','Sensitive status cannot be cached publicly');
echo "PASS: FCP local capability GET, exact enrolled identity, private no-store, denied public access and no writes\n";
