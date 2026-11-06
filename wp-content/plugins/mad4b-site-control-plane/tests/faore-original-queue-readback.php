<?php
declare(strict_types=1);
define('ABSPATH','/test/');
final class WP_Error {public function __construct(public string $code, public string $message, public array $data=[]) {}}
final class WP_REST_Response {public array $headers=[];public function __construct(public array $data) {}public function header($k,$v):void{$this->headers[$k]=$v;}}
final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $values) {}
    public function offsetExists($key):bool{return isset($this->values[$key]);}
    public function offsetGet($key):mixed{return $this->values[$key]??null;}
    public function offsetSet($key,$value):void{$this->values[$key]=$value;}
    public function offsetUnset($key):void{unset($this->values[$key]);}
}
function is_wp_error($value):bool{return $value instanceof WP_Error;}
function rest_ensure_response($value){return new WP_REST_Response($value);}
$GLOBALS['caps']=[];
$GLOBALS['hooks']=[];
$GLOBALS['routes']=[];
function add_action($k,$fn,$p=10):void{$GLOBALS['hooks'][$k][]=$fn;}
function register_rest_route($n,$route,$args):void{$GLOBALS['routes'][$n.$route]=$args;}
function current_user_can($cap):bool{return in_array($cap,$GLOBALS['caps'],true);}
final class MAD4B_SCP_Site_Profile {
    public static bool $enrolled=false;
    public static function origin_enrolled():bool{return self::$enrolled;}
    public static function site_urls_match_enrollment():bool{return self::$enrolled;}
    public static function site_uuid():string{return '00000000-0000-4000-8000-000000000001';}
    public static function current_environment():string{return 'staging';}
}
final class MAD4B_SCP_Remote_Work_Queue {
    public static array $job=[];
    public static function get_job($id){return self::$job;}
}
final class MAD4B_SCP_Assistant_Read_Work_Operations {
    public static bool $binding=true;
    public static function validate_for_operation($operation,$payload):bool{return $operation==='assistant_dependency_readback' && $payload['contract']==='existing-queue-read';}
    public static function runtime_binding_matches($payload,$identity):bool{return self::$binding;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-fcp-queue-readback.php';
function ok($value,$reason):void{if(!$value)throw new RuntimeException($reason);}
$c='MAD4B_SCP_FCP_Queue_Readback';
$id='a62dc131-dad7-45ed-88e7-9fd93d34ad2e';
$c::boot();$GLOBALS['hooks']['rest_api_init'][0]();
ok(count($GLOBALS['routes'])===1,'route boot');
ok(! $c::permitted(),'privilege');
ok(is_wp_error($c::inspect($id)),'enrolled fence');
MAD4B_SCP_Site_Profile::$enrolled=true;
ok(is_wp_error($c::inspect('bad')),'bad job identifier');
MAD4B_SCP_Remote_Work_Queue::$job=[
 'job_id'=>$id,'operation_id'=>'assistant_dependency_readback',
 'status'=>'claimed','effective_status'=>'reconciling',
 'reconciliation_required'=>true,
 'payload'=>['contract'=>'existing-queue-read','secret'=>'DO_NOT_EXPORT'],
 'expected_identity'=>['private'=>'DO_NOT_EXPORT'],
];
$state=$c::inspect($id);
ok($state['runtime_binding_matches']===true,'binding check');
ok($state['queue_status']==='reconciling','real queue status');
ok($state['reconciliation_required']===true,'reconciliation signal');
ok($state['blind_retry_allowed']===false,'never retry blindly');
ok($state['execution_verified']===false,'queue is not a 313 receipt');
ok(strpos(json_encode($state),'DO_NOT_EXPORT')===false,'no payload leak');
MAD4B_SCP_Assistant_Read_Work_Operations::$binding=false;
ok($c::inspect($id)['runtime_binding_matches']===false,'runtime drift');
MAD4B_SCP_Remote_Work_Queue::$job['operation_id']='arbitrary_remote_write';
ok(is_wp_error($c::inspect($id)),'unregistered operation');
MAD4B_SCP_Remote_Work_Queue::$job['operation_id']='assistant_dependency_readback';
$GLOBALS['caps']=['manage_options'];
ok($c::permitted(),'owner read permission');
$response=$c::respond(new WP_REST_Request(['job_id'=>$id]));
ok($response instanceof WP_REST_Response && $response->headers['Cache-Control']==='private, no-store','cache fence');
echo "FAORE Original Queue Readback test PASS\n";
