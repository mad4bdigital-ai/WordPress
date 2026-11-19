<?php
/** Restrict original read work queue observations to identity-bound existing jobs. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $c;public function __construct($c,$message='',$args=[]){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class TestResponse {public $headers=[];public function header($k,$v){$this->headers[$k]=$v;}}
function rest_ensure_response($v){return new TestResponse();}
function current_user_can($cap){return in_array($cap,$GLOBALS['caps'],true);}
function add_action($event,$callback,$priority=10){$GLOBALS['registered_hooks'][]=[$event,$callback,$priority];}
function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$ns.$route]=$args;}
class MAD4B_SCP_Site_Profile {static $verified=false;static function origin_enrolled(){return self::$verified;}static function site_urls_match_enrollment(){return self::$verified;}static function site_uuid(){return '12345678-1234-4123-8123-123456789abc';}static function current_environment(){return 'staging';}}
class MAD4B_SCP_Assistant_Read_Work_Operations {static $binding=true;static function validate_for_operation($id,$payload){return self::$binding;}static function runtime_binding_matches($payload,$identity){return self::$binding;}}
class MAD4B_SCP_Remote_Work_Queue {
 static $job=[];static $reads=0;static function get_job($id){self::$reads++;return self::$job;}
}
function ensure($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-fcp-queue-readback.php';
$c='MAD4B_SCP_FCP_Queue_Readback';$GLOBALS['caps']=[];$c::boot();$c::routes();
$route=$GLOBALS['routes']['mad4b/v1/fcp/local-queue/(?P<job_id>[a-f0-9-]{36})'];
ensure($route['methods']==='GET'&&!$route['permission_callback'](),'GET only, not public');
$id='12345678-1234-4123-8123-123456789abc';
$x=$c::inspect('not-an-id');ensure($x instanceof WP_Error&&$x->get_error_code()==='mad4b_fcp_queue_job_id_invalid','UUID strictly required');
ensure(MAD4B_SCP_Remote_Work_Queue::$reads===0,'Invalid ID cannot read provider');
$x=$c::inspect($id);ensure($x instanceof WP_Error&&$x->get_error_code()==='mad4b_fcp_queue_site_identity_unverified','Unenrolled Site Profile denied');
MAD4B_SCP_Site_Profile::$verified=true;
MAD4B_SCP_Remote_Work_Queue::$job=['job_id'=>$id,'operation_id'=>'company.update','payload'=>[],'expected_identity'=>[],'status'=>'queued'];
$x=$c::inspect($id);ensure($x instanceof WP_Error&&$x->get_error_code()==='mad4b_fcp_queue_operation_not_allowed','Write jobs can never be observed');
MAD4B_SCP_Remote_Work_Queue::$job['operation_id']='assistant_provider_catalog_snapshot';
MAD4B_SCP_Remote_Work_Queue::$job['status']='running';
$x=$c::inspect($id);ensure($x['read_only']&&$x['blind_retry_allowed']===false&&$x['execution_verified']===false,'Existing read job does not become permission or receipt');
ensure($x['runtime_binding_matches']===true&&!$x['reconciliation_required'],'Matching original binding only');
MAD4B_SCP_Assistant_Read_Work_Operations::$binding=false;
$x=$c::inspect($id);ensure($x['runtime_binding_matches']===false&&$x['reconciliation_required'],'Stale identity must reconcile');
MAD4B_SCP_Assistant_Read_Work_Operations::$binding=true;
$GLOBALS['caps']=['manage_options'];ensure($route['permission_callback'](),'WordPress administrator read access');
$GLOBALS['caps']=[];ensure(!$route['permission_callback'](),'Revoked permission denies route');
$GLOBALS['caps']=['mad4b_manage_company_operations'];ensure($route['permission_callback'](),'Company operations role read allowed');
$response=$c::respond(['job_id'=>$id]);ensure($response instanceof TestResponse&&$response->headers['Cache-Control']==='private, no-store','Never cache queue response publicly');
echo "PASS: original read queue GET, strict UUID/site binding, deny write jobs, stale identity reconciliation, no retry or mutation\n";
