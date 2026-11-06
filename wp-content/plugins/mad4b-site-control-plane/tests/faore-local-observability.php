<?php
define('ABSPATH','/test/');
class WP_Error {public function __construct(public $code,public $message,public $data) {}}
class WP_REST_Response {
    public array $headers = [];
    public function __construct(public $data) {}
    public function header($key,$value):void {$this->headers[$key]=$value;}
}
function is_wp_error($x):bool {return $x instanceof WP_Error;}
function rest_ensure_response($x) {return new WP_REST_Response($x);}
$GLOBALS['routes']=[];
$GLOBALS['hooks']=[];
$GLOBALS['caps']=[];
function add_action($name,$callable,$priority=10):void {$GLOBALS['hooks'][$name][]=$callable;}
function register_rest_route($namespace,$route,$options):void {$GLOBALS['routes'][$namespace.$route]=$options;}
function current_user_can($cap):bool {return in_array($cap,$GLOBALS['caps'],true);}
final class MAD4B_SCP_Site_Profile {
    public static bool $enrolled = false;
    public static bool $matches = true;
    public static function origin_enrolled():bool {return self::$enrolled;}
    public static function site_urls_match_enrollment():bool {return self::$matches;}
    public static function site_uuid():string {return '00000000-0000-4000-8000-000000000001';}
    public static function current_environment():string {return 'staging';}
    public static function site_origin():string {return 'https://company.example';}
    public static function profile_digest():string {return str_repeat('a',64);}
}
final class MAD4B_SCP_Site_Capability_Discovery {
    public static function observe($origin) {return [
        'read_inventory_state'=>'observed','snapshot_sha256'=>str_repeat('b',64),
        'discovery_complete'=>true];}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-fcp-local-observability.php';
function expect($condition,$reason) {if(!$condition) throw new RuntimeException($reason);}
$c='MAD4B_SCP_FCP_Local_Observability';
$c::boot();
expect(count($GLOBALS['hooks']['rest_api_init'])===1,'no route boot');
$GLOBALS['hooks']['rest_api_init'][0]();
$route=$GLOBALS['routes']['mad4b/v1/fcp/local-capabilities']??null;
expect(is_array($route),'route absent');
expect($route['methods']==='GET','wrong route method');
expect(!$c::permitted(),'permission should fail');
$GLOBALS['caps']=['manage_options'];
expect($c::permitted(),'admin permission not accepted');
expect(is_wp_error($c::observe()),'unenrolled identity should fail');
MAD4B_SCP_Site_Profile::$enrolled=true;
MAD4B_SCP_Site_Profile::$matches=false;
expect(is_wp_error($c::observe()),'mismatched origin should fail');
MAD4B_SCP_Site_Profile::$matches=true;
$ob=$c::observe();
expect($ob['site_uuid']==='00000000-0000-4000-8000-000000000001','uuid');
expect($ob['read_only']===true && $ob['authorizing']===false && $ob['native_write_authorized']===false,'scope');
expect($ob['capabilities'][0]['supported_operations']===['read'],'identity read');
expect($ob['observed']['discovery_complete']===true,'inventory');
define('ICL_SITEPRESS_VERSION','4.7');
$wpml=$c::observe();
expect($wpml['capabilities'][1]['supported_operations']===[],'WPML not admitted');
$res=$c::respond();
expect($res instanceof WP_REST_Response && $res->headers['Cache-Control']==='private, no-store','REST response');
echo "FAORE PHP OBSERVABILITY: 12 assertions PASS\n";
