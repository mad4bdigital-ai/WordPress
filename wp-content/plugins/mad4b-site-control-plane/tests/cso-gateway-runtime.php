<?php
/** Gateway ingress/registration simulation. WordPress and authority services are explicit doubles. */
define('ABSPATH','/fixture/');
class WP_Error { private $code,$data; public function __construct($c,$m='',$d=null){$this->code=$c;$this->data=$d;} public function get_error_code(){return $this->code;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['mode']=$argv[1]??'normal'; $GLOBALS['flags']=array(); $GLOBALS['calls']=0; $GLOBALS['scope_changed']=false; $GLOBALS['registered']=array(); $GLOBALS['cookie']=true;
class MAD4B_SCP_CSO_Scope {
    public static function boot(){}
    public static function enabled($flag){return true===($GLOBALS['flags'][$flag]??false);}
    public static function current(){return $GLOBALS['scope_denied']??false?self::error('DENIED'):array('origin'=>'https://site.example','generation'=>1);}
    public static function assert_current($scope){return $GLOBALS['scope_changed']?self::error('CHANGED'):true;}
    public static function bounded($v){return !empty($GLOBALS['bad_bounds'])?self::error('BOUNDS'):strlen(json_encode($v))<=131072;}
    public static function safe_data($v){return !empty($GLOBALS['bad_safe'])?self::error('SECRET'):false===strpos(json_encode($v),'api_key');}
    public static function error($reason){return new WP_Error('cso_'.$reason,'Value-free error',array('reason'=>$reason,'authorizing'=>false));}
}
class MAD4B_SCP_CSO_Registry { public static function catalog($q,$l,$o){$GLOBALS['calls']++;return $GLOBALS['service_result']??array('status'=>'CATALOG','query'=>$q);} }
function add_action($hook,$callback,$priority=10){$GLOBALS['hooks'][]=$hook;}
function wp_has_ability($n){return isset($GLOBALS['registered'][$n]);}
function wp_register_ability($n,$a){if('partial'===$GLOBALS['mode']&&count($GLOBALS['registered'])>=2)return null;$GLOBALS['registered'][$n]=$a;return (object)$a;}
function is_user_logged_in(){return $GLOBALS['cookie'];}
function wp_verify_nonce($v,$action){return 'valid'===$v&&'wp_rest'===$action;}
function wp_parse_url($u){return parse_url($u);}
function untrailingslashit($v){return rtrim($v,'/');}
class Request {private $headers;public function __construct($h){$this->headers=$h;}public function get_header($n){return $this->headers[$n]??'';} }
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-gateway.php';
$checks=0;
function ok($v,$name){global $checks;if(!$v){fwrite(STDERR,'FAIL '.$name."\n");exit(1);} $checks++;}
function reason($r){$d=$r->get_error_data();return $d['reason'];}
$request=array('action'=>'capability_catalog','arguments'=>array());
MAD4B_SCP_CSO_Gateway::boot();ok(empty($GLOBALS['hooks']),'default-off hooks absent');
ok(is_wp_error(MAD4B_SCP_CSO_Gateway::dispatch($request)),'default-off dispatch');ok(0===$GLOBALS['calls'],'disabled never enters provider');
$GLOBALS['flags']=array_fill_keys(array('discovery','forms','single_write','bulk','workflow','secrets','multisite','operations','production_proposal'),true);
if('collision'===$GLOBALS['mode'])$GLOBALS['registered']['cso/form-prepare']=array('owner'=>'foreign');
MAD4B_SCP_CSO_Gateway::register_abilities();
if('normal'!==$GLOBALS['mode']){
    ok(array()===MAD4B_SCP_CSO_Gateway::read_tools(),'partial/collision exposes no tools');ok(false===MAD4B_SCP_CSO_Gateway::can_read(),'partial/collision permissions closed');
    if('collision'===$GLOBALS['mode'])ok(1===count($GLOBALS['registered']),'foreign namespace untouched');
    echo 'CSO GATEWAY '.$GLOBALS['mode'].': '.$checks." PASS\n";exit;
}
ok(count(MAD4B_SCP_CSO_Gateway::read_tools())>15,'read and plan catalog registered');
foreach($GLOBALS['registered']as$name=>$args){
    ok(!preg_match('/commit|workflow-run|secret-session|approval-plan|change-reconcile|change-compensate|draft$/',$name),'stateful actions excluded from readonly tools');
    ok(false===$args['input_schema']['additionalProperties'],'closed argument contract');
    ok(true===$args['meta']['annotations']['readonly'],'read annotation');
}
ok(true===MAD4B_SCP_CSO_Gateway::can_read(),'complete set permission');
ok(array('plan')===$GLOBALS['registered']['cso/change-history']['input_schema']['required'],'history binds its sealed plan');
ok('array'===$GLOBALS['registered']['cso/bulk-plan']['input_schema']['properties']['selection']['type'],'bulk selection is a typed list');
ok(193===$GLOBALS['registered']['cso/form-prepare']['input_schema']['properties']['ability_name']['maxLength'],'canonical ability length');
$v=MAD4B_SCP_CSO_Gateway::dispatch($request);ok('CATALOG'===$v['status'],'valid dispatch');ok(1===$GLOBALS['calls'],'exactly one service entry');
foreach(array(null,array('action'=>'missing','arguments'=>array()),array_merge($request,array('trusted'=>true)),array('action'=>'capability_catalog','arguments'=>array('grant'=>true)),array('action'=>'capability_catalog','arguments'=>array('query'=>'api_key')))as$bad){ok(is_wp_error(MAD4B_SCP_CSO_Gateway::dispatch($bad)),'malformed/secret denied');}
ok(1===$GLOBALS['calls'],'invalid input cannot enter service');
$GLOBALS['bad_safe']=true;ok(is_wp_error(MAD4B_SCP_CSO_Gateway::dispatch(array('action'=>'capability_catalog','arguments'=>array('query'=>'ordinary')))),'WP_Error safe guard cannot become success');unset($GLOBALS['bad_safe']);
$GLOBALS['bad_bounds']=true;ok(is_wp_error(MAD4B_SCP_CSO_Gateway::dispatch($request)),'WP_Error bounds guard cannot become success');unset($GLOBALS['bad_bounds']);
$GLOBALS['scope_denied']=true;ok(is_wp_error(MAD4B_SCP_CSO_Gateway::dispatch($request)),'scope denial');ok(false===MAD4B_SCP_CSO_Gateway::can_read(),'direct Ability permission denied');unset($GLOBALS['scope_denied']);
$GLOBALS['service_result']=new WP_Error('vendor','sk-do-not-disclose',array('reason'=>'EXPECTED_DENIAL','api_key'=>'do-not-disclose'));
$v=MAD4B_SCP_CSO_Gateway::dispatch($request);ok('EXPECTED_DENIAL'===reason($v),'stable reason retained');ok(false===strpos(json_encode($v->get_error_data()),'api_key'),'vendor error data removed');unset($GLOBALS['service_result']);
$GLOBALS['scope_changed']=true;ok('CHANGED'===reason(MAD4B_SCP_CSO_Gateway::dispatch($request)),'post-provider scope drift denial');$GLOBALS['scope_changed']=false;
$GLOBALS['service_result']=array('too_large'=>str_repeat('x',131073));ok('OUTPUT_CONTRACT_INVALID'===reason(MAD4B_SCP_CSO_Gateway::dispatch($request)),'bounded output');unset($GLOBALS['service_result']);
$headers=array('origin'=>'https://site.example','x-wp-nonce'=>'valid');
ok(true===MAD4B_SCP_CSO_Gateway::rest_permission(new Request($headers)),'same-origin cookie nonce');
foreach(array(array_merge($headers,array('origin'=>'https://foreign.example')),array_merge($headers,array('x-wp-nonce'=>'invalid')),array_merge($headers,array('authorization'=>'Bearer invalid')),array('x-wp-nonce'=>'valid'))as$h)ok(is_wp_error(MAD4B_SCP_CSO_Gateway::rest_permission(new Request($h))),'REST CSRF/authorization denial');
$GLOBALS['cookie']=false;ok(is_wp_error(MAD4B_SCP_CSO_Gateway::rest_permission(new Request($headers))),'REST login required');
echo 'CSO GATEWAY normal: '.$checks." PASS\n";
