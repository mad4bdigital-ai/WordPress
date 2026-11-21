<?php
/** Real first-party queue readback and content review mode, hermetic runtime. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($c,$m='',$data=array()){$this->code=$c;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$x));}
function current_user_can($c){return !empty($GLOBALS['grants'][$c]);}
function add_action($hook,$callback,$priority=10){$GLOBALS['actions'][]=array($hook,$callback,$priority);}
function register_rest_route($ns,$route,$args){$GLOBALS['routes'][]=array($ns,$route,$args);}
class OriginalResponse {
 public $data;
 public $headers=array();
 function __construct($data){$this->data=$data;}
 function header($k,$v){$this->headers[$k]=$v;}
}
function rest_ensure_response($data){return new OriginalResponse($data);}
final class MAD4B_SCP_Site_Profile {
 public static function origin_enrolled(){return $GLOBALS['site_bound'];}
 public static function site_urls_match_enrollment(){return $GLOBALS['site_bound'];}
 public static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}
 public static function current_environment(){return 'staging';}
}
final class MAD4B_SCP_Assistant_Read_Work_Operations {
 public static function validate_for_operation($operation,$payload){
  return $GLOBALS['job_input_valid'] && $operation==='assistant_configuration_diff';
 }
 public static function runtime_binding_matches($payload,$identity){
  return $GLOBALS['job_binding_valid'];
 }
}
final class MAD4B_SCP_Remote_Work_Queue {
 public static function get_job($job){return $GLOBALS['queue_job'];}
}
final class MAD4B_SCP_Content_Jobs {
 public static function get_job($input){return $GLOBALS['content_job'];}
}
function native_check($ok,$name){
 if(!$ok){fwrite(STDERR,'FAIL: '.$name.PHP_EOL);exit(1);}
 $GLOBALS['native_cases']=($GLOBALS['native_cases']??0)+1;
}
$base=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $base.'class-mad4b-scp-fcp-queue-readback.php';
require $base.'class-mad4b-scp-content-workflow-modes.php';
$readback='MAD4B_SCP_FCP_Queue_Readback';
$id='11111111-1111-4111-8111-111111111111';
$GLOBALS['grants']=array();
$GLOBALS['site_bound']=true;
$GLOBALS['job_input_valid']=true;
$GLOBALS['job_binding_valid']=true;
$GLOBALS['queue_job']=array('job_id'=>$id,
 'operation_id'=>'assistant_configuration_diff',
 'status'=>'done','payload'=>array('site_id'=>$id),
 'expected_identity'=>array('site_id'=>$id));
native_check(!$readback::permitted(),'Anonymous queue read is forbidden');
$GLOBALS['grants']['mad4b_manage_company_operations']=true;
native_check($readback::permitted(),'Original company operation scope can read');
$readback::routes();
native_check(count($GLOBALS['routes'])===1
 && $GLOBALS['routes'][0][1]==='/fcp/local-queue/(?P<job_id>[a-f0-9-]{36})'
 && $GLOBALS['routes'][0][2]['methods']==='GET'
 && $GLOBALS['routes'][0][2]['permission_callback']===
   array('MAD4B_SCP_FCP_Queue_Readback','permitted'),
 'Original FCP REST route is a permission-gated GET');
$good=$readback::inspect($id);
native_check(is_array($good)&&$good['read_only']===true
 && $good['runtime_binding_matches']===true
 && $good['blind_retry_allowed']===false
 && $good['execution_verified']===false
 && $good['reconciliation_required']===false,
 'Exact queued assistant READ state never self-certifies execution');
$r=$readback::respond(array('job_id'=>$id));
native_check($r instanceof OriginalResponse
 && $r->headers['Cache-Control']==='private, no-store'
 && $r->data['original_queue_job_id']===$id,
 'Queue REST response cannot become shared public cache');
native_check(is_wp_error($readback::inspect('bad'))
 && $readback::inspect('bad')->get_error_code()==='mad4b_fcp_queue_job_id_invalid',
 'Malformed original queue UUID is denied');
$original=$GLOBALS['queue_job'];
$GLOBALS['queue_job']['operation_id']='assistant_write_apply';
native_check(is_wp_error($readback::inspect($id)),
 'Original remote WRITE operation not exposed in readback');
$GLOBALS['queue_job']=$original;
$GLOBALS['queue_job']['job_id']='22222222-2222-4222-8222-222222222222';
native_check(is_wp_error($readback::inspect($id)),
 'Foreign original queue job cannot masquerade as requested id');
$GLOBALS['queue_job']=$original;
$GLOBALS['queue_job']['status']='reconciling';
native_check($readback::inspect($id)['reconciliation_required']===true,
 'Uncertain original outcome demands reconciliation');
$GLOBALS['queue_job']=$original;
$GLOBALS['job_binding_valid']=false;
$drift=$readback::inspect($id);
native_check($drift['runtime_binding_matches']===false
 && $drift['reconciliation_required']===true
 && $drift['blind_retry_allowed']===false,
 'Site binding drift cannot claim existing execution success');
$GLOBALS['job_binding_valid']=true;
$GLOBALS['job_input_valid']=false;
native_check($readback::inspect($id)['reconciliation_required']===true,
 'Original operation input validation is independent of queue state');
$GLOBALS['job_input_valid']=true;
$GLOBALS['site_bound']=false;
native_check(is_wp_error($readback::inspect($id)),
 'Non-enrolled Site Profile cannot expose queue');
$GLOBALS['site_bound']=true;
$GLOBALS['queue_job']=new WP_Error('remote_queue_unavailable');
native_check(is_wp_error($readback::inspect($id)),
 'Original provider error is preserved without guessing state');
$GLOBALS['queue_job']=$original;

$mode='MAD4B_SCP_Content_Workflow_Modes';
native_check($mode::choices()===array('autonomous','assisted','strict')
 && $mode::DEFAULT_MODE==='autonomous',
 'Three review modes remain dynamic UI choices without publication');
native_check($mode::requested('review_gated')==='strict',
 'Legacy review_gated mode retains mandatory independent review');
native_check(is_wp_error($mode::requested('unreviewed')),
 'Unknown review mode never becomes autonomous');
native_check($mode::for_input(array())==='autonomous',
 'New no-job workflow can use configured default');
$GLOBALS['content_job']=array('job'=>array('automation_level'=>''));
native_check($mode::for_job('job-1')==='strict',
 'Legacy job with blank review level never inherits autonomous writes');
$GLOBALS['content_job']=array('job'=>array('automation_level'=>'assisted'));
native_check($mode::for_input(array('job_id'=>'job-1'))==='assisted',
 'Existing job review mode inherited dynamically from its saved record');
$GLOBALS['content_job']=new WP_Error('job_unavailable');
native_check(is_wp_error($mode::for_job('job-1')),
 'Failed original ContentJob lookup cannot emit a trusted mode');
$blocked=$mode::review_result('autonomous',array('missing_evidence'),true);
native_check(!$blocked['qa_passed'] && !$blocked['can_store_in_hub']
 && !$blocked['publication_authorized'],
 'Hard QA blocker prevents autonomous staging and publication');
$pending=$mode::review_result('autonomous',array(),false);
native_check(!$pending['qa_passed'] && !$pending['can_store_in_hub'],
 'Unfinished QA cannot become silent self-approval');
$self=$mode::review_result('autonomous',array(),true);
native_check($self['qa_passed']===true && $self['can_store_in_hub']===true
 && $self['can_publish']===false && $self['publication_authorized']===false,
 'Qualified autonomous QA allows Hub staging but never publication');
$strict=$mode::review_result('strict',array(),true);
native_check($strict['qa_passed']===true && $strict['independent_review_required']===true
 && !$strict['can_store_in_hub'] && !$strict['can_publish'],
 'Strict mode always requires an independent reviewer');
$assisted=$mode::review_result('assisted',array(),true);
native_check($assisted['human_review_required']===true
 && !$assisted['can_store_in_hub'],
 'Assisted mode cannot silently self-approve');
$catalog=$mode::catalog();
native_check($catalog['publication_authorized']===false
 && $catalog['mutation_performed']===false,
 'Review mode discovery is not a grant or side effect');
echo 'PASS: ORIGINAL_QUEUE_AND_REVIEW_MODES '.$GLOBALS['native_cases'].
  ' first-party permission/recovery cases'.PHP_EOL;
