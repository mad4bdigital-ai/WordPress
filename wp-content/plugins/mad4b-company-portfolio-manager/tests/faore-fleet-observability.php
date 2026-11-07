<?php
define('ABSPATH','/test/');
class WP_Error {public function __construct(public $code,public $message,public $data) {}}
class WP_REST_Response {public array $headers=[];public function __construct(public $data) {}public function header($k,$v){$this->headers[$k]=$v;}}
$GLOBALS['state']=['alpha'=>['credential'=>'PRIVATE','customer_db'=>'DO_NOT_EXPORT'],'beta'=>[]];
$GLOBALS['permission']=false;$GLOBALS['routes']=[];$GLOBALS['hooks']=[];
function is_wp_error($x):bool {return $x instanceof WP_Error;}
function get_option($name,$fallback=[]) {return $GLOBALS['state'];}
function add_action($hook,$fn,$priority=10) {$GLOBALS['hooks'][$hook][]=$fn;}
function register_rest_route($ns,$route,$args) {$GLOBALS['routes'][$ns.$route]=$args;}
function rest_ensure_response($x) {return new WP_REST_Response($x);}
class MAD4B_Company_Portfolio_Manager_V1 {
    const REGISTRY='mocked-registry';
    public static function admin_permission(){return $GLOBALS['permission'] ? true : new WP_Error('denied','No access',[]);}
    public static function company_binding($code) {return $code==='alpha' ? ['company_site_uuid'=>'00000000-0000-4000-8000-000000000001','environment'=>'staging','sensitive'=>'HIDDEN'] : new WP_Error('unverified','No proof',[]);}
}
require dirname(__DIR__).'/includes/class-mad4b-company-faore-fleet-observability.php';
function expect($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$c='MAD4B_Company_FAORE_Fleet_Observability';
$c::boot();$GLOBALS['hooks']['rest_api_init'][0]();
$route=$GLOBALS['routes']['mad4b/v1/company-manager/faore/fleet']??null;
expect(is_array($route) && $route['methods']==='GET','route');
expect(is_wp_error($c::permission()),'permission bypass');
$GLOBALS['permission']=true;
expect($c::permission()===true,'admin permission');
$report=$c::observe();
expect($report['company_count']===2,'count');
expect($report['companies'][0]['binding_state']==='VERIFIED_REGISTRY_BINDING','alpha');
expect($report['companies'][1]['binding_state']==='UNVERIFIED','beta');
expect($report['companies'][0]['native_execution']==='NOT_EXECUTED','false receipt');
expect($report['execution_authorized']===false,'no grant');
expect(strpos(json_encode($report),'PRIVATE')===false,'credential leak');
expect(strpos(json_encode($report),'DO_NOT_EXPORT')===false,'customer data leak');
$response=$c::respond();
expect($response->headers['Cache-Control']==='private, no-store','cache');
$GLOBALS['state']='broken';
expect(is_wp_error($c::observe()),'malformed registry');
$GLOBALS['state']=array_fill_keys(array_map(fn($n)=>'co'.$n,range(100,400)),[]);
expect(is_wp_error($c::observe()),'unbounded registry');
echo "FAORE PHP FLEET OBSERVATION: 12 assertions PASS\n";
