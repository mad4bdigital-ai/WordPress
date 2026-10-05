<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']=array();
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_generate_uuid4(){static $i=0;++$i;return sprintf('00000000-0000-4000-8000-%012d',$i);}
function wp_rand(){return 7;}
function add_option($n,$v,$d='',$a=false){if(array_key_exists($n,$GLOBALS['opts']))return false;$GLOBALS['opts'][$n]=$v;return true;}
function get_option($n,$d=false){return array_key_exists($n,$GLOBALS['opts'])?$GLOBALS['opts'][$n]:$d;}
function update_option($n,$v,$a=false){$GLOBALS['opts'][$n]=$v;return true;}
function delete_option($n){if(!array_key_exists($n,$GLOBALS['opts']))return false;unset($GLOBALS['opts'][$n]);return true;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;private $d;public function __construct($c,$m='',$d=null){$this->c=$c;$this->d=$d;}public function get_error_code(){return $this->c;}}

require dirname(__DIR__).'/includes/class-mad4b-scp-scheduler-admission.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-scheduler-backlog.php';

$fail=static function($m){fwrite(STDERR,"FAIL scheduler-backlog-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$sha=static function($c){return str_repeat($c,64);};
$binding=array(
 'adapter_kind'=>'external_scheduler','adapter_id'=>'scheduler-a',
 'desired_state_sha256'=>$sha('a'),'expected_state_sha256'=>$sha('b'),
 'budget_fingerprint'=>$sha('c'),'authority_fingerprint'=>$sha('d'),
);
$observation=array(
 'adapter_kind'=>'external_scheduler','adapter_id'=>'scheduler-a',
 'desired_state_sha256'=>$sha('a'),'observed_state_sha256'=>$sha('b'),
 'budget_fingerprint'=>$sha('c'),'authority_fingerprint'=>$sha('d'),
);
$enqueue=MAD4B_SCP_Scheduler_Backlog::enqueue(array(
 'work'=>array('tenant_id'=>'tenant-1','site_id'=>'site-1','job_type'=>'research','provider_id'=>'provider-1','priority_class'=>'normal','fairness_weight'=>1),
 'work_payload'=>array('semantic_operation'=>'research_refresh','target'=>'cluster-1'),
 'adapter_binding'=>$binding,
 'quotas'=>array('max_queued_jobs'=>10,'max_concurrent_jobs'=>2),
 'central_state'=>array('available'=>true,'signed_evidence_current'=>true),
));
$check(is_array($enqueue) && 'queued'===$enqueue['state'],'durable enqueue failed');
$check(false===$enqueue['dispatch_performed'] && false===$enqueue['authorizing'],'enqueue dispatched/authorized work');
$id=$enqueue['item']['queue_id'];

$claim=MAD4B_SCP_Scheduler_Backlog::claim_next(array('worker_id'=>'worker-a','lease_seconds'=>60,'adapter_observation'=>$observation));
$check(is_array($claim) && 'leased'===$claim['state'],'claim-next failed');
$check(1===$claim['lease_generation'],'initial fencing generation invalid');
$token=$claim['lease_token'];

$bad=MAD4B_SCP_Scheduler_Backlog::heartbeat(array('queue_id'=>$id,'worker_id'=>'worker-a','lease_generation'=>1,'lease_token'=>str_repeat('0',64),'lease_seconds'=>60));
$check(is_wp_error($bad) && 'mad4b_scheduler_fencing_mismatch'===$bad->get_error_code(),'wrong lease token was accepted');

$drift=$observation;$drift['budget_fingerprint']=$sha('e');
$completion=MAD4B_SCP_Scheduler_Backlog::complete(array(
 'queue_id'=>$id,'worker_id'=>'worker-a','lease_generation'=>1,'lease_token'=>$token,
 'adapter_observation'=>$drift,'completion_evidence'=>array('provider_receipt_sha256'=>$sha('f')),
));
$check(is_wp_error($completion) && 'mad4b_scheduler_completion_state_drift'===$completion->get_error_code(),'completion ignored budget drift');

$status=MAD4B_SCP_Scheduler_Backlog::status(array());
$check('reconciliation_required'===$status['items'][0]['status'],'drifted completion was not quarantined');

$reconciled=MAD4B_SCP_Scheduler_Backlog::reconcile(array(
 'queue_id'=>$id,'disposition'=>'requeue_no_effect',
 'reconciliation_evidence'=>array('provider_effect'=>'none','receipt_sha256'=>$sha('1')),
));
$check(is_array($reconciled) && 'queued'===$reconciled['state'],'reconciliation did not requeue proven no-effect work');

$claim2=MAD4B_SCP_Scheduler_Backlog::claim_next(array('worker_id'=>'worker-b','lease_seconds'=>60,'adapter_observation'=>$observation));
$check(is_array($claim2) && 2===$claim2['lease_generation'],'fencing generation did not advance');
$stale=MAD4B_SCP_Scheduler_Backlog::heartbeat(array('queue_id'=>$id,'worker_id'=>'worker-a','lease_generation'=>1,'lease_token'=>$token,'lease_seconds'=>60));
$check(is_wp_error($stale) && 'mad4b_scheduler_fencing_mismatch'===$stale->get_error_code(),'stale worker retained lease authority');

$done=MAD4B_SCP_Scheduler_Backlog::complete(array(
 'queue_id'=>$id,'worker_id'=>'worker-b','lease_generation'=>2,'lease_token'=>$claim2['lease_token'],
 'adapter_observation'=>$observation,'completion_evidence'=>array('provider_receipt_sha256'=>$sha('2')),
));
$check(is_array($done) && 'completed'===$done['state'],'valid completion failed');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-scheduler-backlog.php');
foreach(array('wp_schedule_event(','wp_remote_get(','wp_remote_post(','shell_exec(','exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'scheduler backlog contains dispatcher primitive: '.$forbidden);
}
echo "mad4b.scheduler-backlog.v1: PASS\n";
