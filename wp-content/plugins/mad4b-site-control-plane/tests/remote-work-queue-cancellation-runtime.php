<?php
define('ABSPATH',__DIR__);
define('DAY_IN_SECONDS',86400);
class WP_Error{private $c,$m,$d;function __construct($c,$m='',$d=array()){$this->c=$c;$this->m=$m;$this->d=$d;}function get_error_code(){return $this->c;}function get_error_data(){return $this->d;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function maybe_serialize($v){return is_array($v)||is_object($v)?serialize($v):(string)$v;}
function wp_cache_delete($k,$g=''){return true;}
$GLOBALS['opts']=array();$GLOBALS['uuid_counter']=0;
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function add_option($k,$v,$deprecated='',$autoload=false){if(array_key_exists($k,$GLOBALS['opts']))return false;$GLOBALS['opts'][$k]=$v;return true;}
function wp_generate_uuid4(){$GLOBALS['uuid_counter']++;return sprintf('00000000-0000-4000-8000-%012d',$GLOBALS['uuid_counter']);}
function wp_rand(){return 12345;}
class FakeDB{
 public $options='wp_options';
 function delete($table,$where,$formats){
  $k=$where['option_name']; if(!array_key_exists($k,$GLOBALS['opts']))return 0;
  if(maybe_serialize($GLOBALS['opts'][$k])!==$where['option_value'])return 0;
  unset($GLOBALS['opts'][$k]); return 1;
 }
}
$GLOBALS['wpdb']=new FakeDB();
require dirname(__DIR__).'/includes/class-mad4b-scp-remote-work-queue.php';
$fail=static function($m){fwrite(STDERR,"FAIL remote-work-queue-cancellation: {$m}\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$id=array('source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64));

$e=MAD4B_SCP_Remote_Work_Queue::enqueue('frontend_performance_sampling',array('probe'=>'a'),$id,3600);
$job=$e['job']['job_id'];
$c=MAD4B_SCP_Remote_Work_Queue::cancel($job,'operator_cancel');
$check('cancelled_no_effect'===$c['state']&&!$c['job']['reconciliation_required'],'pending cancellation was not safe no-effect');
$check('mad4b_remote_work_job_not_claimable'===$code(MAD4B_SCP_Remote_Work_Queue::claim($job,'worker',60,$id)),'cancelled no-effect job was claimable');

$e=MAD4B_SCP_Remote_Work_Queue::enqueue('frontend_performance_sampling',array('probe'=>'b'),$id,3600);
$job=$e['job']['job_id'];$cl=MAD4B_SCP_Remote_Work_Queue::claim($job,'worker',60,$id);
$check(is_array($cl)&&'claimed'===$cl['state'],'claim fixture failed');
$entered=MAD4B_SCP_Remote_Work_Queue::provider_checkpoint($job,'worker',$cl['lease_token'],'provider_entered');
$check(is_array($entered)&&'provider_entered'===$entered['job']['provider_checkpoint'],'provider entry fixture was not durable');
$c=MAD4B_SCP_Remote_Work_Queue::cancel($job,'operator_cancel');
$check('reconciling'===$c['state']&&!empty($c['job']['reconciliation_required'])&&empty($c['job']['blind_retry_allowed']),'claimed cancellation became terminal');
$weak=MAD4B_SCP_Remote_Work_Queue::complete($job,'worker',$cl['lease_token'],array('verification'=>'weak'));
$check('mad4b_remote_work_reconciliation_required'===$code($weak),'reconciling completion accepted without postcondition proof');
$proof=array('postcondition_verified'=>true,'provider_effect_state'=>'applied','provider_execution_ref'=>'provider:fixture:1','evidence_sha256'=>str_repeat('d',64));
$done=MAD4B_SCP_Remote_Work_Queue::complete($job,'worker',$cl['lease_token'],$proof);
$check('completed'===$done['state']&&!$done['job']['reconciliation_required'],'verified applied reconciliation did not settle completed');

$e=MAD4B_SCP_Remote_Work_Queue::enqueue('frontend_performance_sampling',array('probe'=>'c'),$id,3600);
$job=$e['job']['job_id'];$cl=MAD4B_SCP_Remote_Work_Queue::claim($job,'worker2',60,$id);
$jobs=get_option(MAD4B_SCP_Remote_Work_Queue::OPTION,array());
$jobs[$job]['lease_expires_at_epoch']=time()-1;update_option(MAD4B_SCP_Remote_Work_Queue::OPTION,$jobs,false);
$again=MAD4B_SCP_Remote_Work_Queue::claim($job,'worker3',60,$id);
$check('mad4b_remote_work_reconciliation_required'===$code($again),'expired claimed lease was replay-claimed');
$state=MAD4B_SCP_Remote_Work_Queue::get_job($job);
$check('reconciling'===$state['status']&&!empty($state['reconciliation_required']),'expired claim was not durably quarantined');

$e=MAD4B_SCP_Remote_Work_Queue::enqueue('frontend_performance_sampling',array('probe'=>'d'),$id,3600);
$job=$e['job']['job_id'];$cl=MAD4B_SCP_Remote_Work_Queue::claim($job,'worker4',60,$id);MAD4B_SCP_Remote_Work_Queue::cancel($job,'operator_cancel');
$proof=array('postcondition_verified'=>true,'provider_effect_state'=>'no_effect','provider_execution_ref'=>'provider:fixture:2','evidence_sha256'=>str_repeat('e',64));
$settled=MAD4B_SCP_Remote_Work_Queue::complete($job,'worker4',$cl['lease_token'],$proof);
$check('cancelled_no_effect'===$settled['state'],'verified no-effect reconciliation did not settle cancelled-no-effect');


$e=MAD4B_SCP_Remote_Work_Queue::enqueue('frontend_performance_sampling',array('probe'=>'e'),$id,3600);
$job=$e['job']['job_id'];$cl=MAD4B_SCP_Remote_Work_Queue::claim($job,'worker5',60,$id);
$c=MAD4B_SCP_Remote_Work_Queue::cancel($job,'operator_cancel');
$signal=MAD4B_SCP_Remote_Work_Queue::cancellation_signal($job,'worker5',$cl['lease_token']);
$check(is_array($signal)&&!empty($signal['cancel_requested'])&&'not_entered'===$signal['provider_checkpoint']&&!empty($signal['provider_cancel_required']),'cancel signal was not propagated before provider entry');
$entry=MAD4B_SCP_Remote_Work_Queue::provider_checkpoint($job,'worker5',$cl['lease_token'],'provider_entered');
$check('mad4b_remote_work_cancel_before_provider_entry'===$code($entry),'provider entry was admitted after durable pre-entry cancellation');
$badAck=MAD4B_SCP_Remote_Work_Queue::acknowledge_cancellation($job,'worker5',$cl['lease_token'],$signal['cancel_generation']+1);
$check('mad4b_remote_work_cancel_ack_generation_mismatch'===$code($badAck),'stale/wrong cancellation generation was acknowledged');
$ack=MAD4B_SCP_Remote_Work_Queue::acknowledge_cancellation($job,'worker5',$cl['lease_token'],$signal['cancel_generation']);
$check('cancelled_no_effect'===$ack['state']&&!$ack['job']['reconciliation_required']&&!$ack['job']['provider_side_effect_possible'],'pre-entry cancellation acknowledgement did not prove no-effect');

$e=MAD4B_SCP_Remote_Work_Queue::enqueue('frontend_performance_sampling',array('probe'=>'f'),$id,3600);
$job=$e['job']['job_id'];$cl=MAD4B_SCP_Remote_Work_Queue::claim($job,'worker6',60,$id);
$entered=MAD4B_SCP_Remote_Work_Queue::provider_checkpoint($job,'worker6',$cl['lease_token'],'provider_entered');
$check(is_array($entered)&&'provider_entered'===$entered['job']['provider_checkpoint']&&!empty($entered['job']['provider_side_effect_possible']),'provider entry checkpoint was not durable');
$c=MAD4B_SCP_Remote_Work_Queue::cancel($job,'operator_cancel');
$signal=MAD4B_SCP_Remote_Work_Queue::cancellation_signal($job,'worker6',$cl['lease_token']);
$check(!empty($signal['cancel_requested'])&&!empty($signal['provider_side_effect_possible'])&&!empty($signal['reconciliation_required']),'post-entry cancellation lost possible-side-effect semantics');
$ack=MAD4B_SCP_Remote_Work_Queue::acknowledge_cancellation($job,'worker6',$cl['lease_token'],$signal['cancel_generation']);
$check('mad4b_remote_work_reconciliation_required'===$code($ack),'post-entry cancellation was falsely acknowledged as no-effect');
$returned=MAD4B_SCP_Remote_Work_Queue::provider_checkpoint($job,'worker6',$cl['lease_token'],'provider_returned');
$check(is_array($returned)&&'provider_returned'===$returned['job']['provider_checkpoint'],'provider return checkpoint was not persisted during reconciliation');
$proof=array('postcondition_verified'=>true,'provider_effect_state'=>'applied','provider_execution_ref'=>'provider:fixture:3','evidence_sha256'=>str_repeat('f',64));
$done=MAD4B_SCP_Remote_Work_Queue::complete($job,'worker6',$cl['lease_token'],$proof);
$check('completed'===$done['state']&&!$done['job']['reconciliation_required'],'post-entry cancellation did not require and consume postcondition proof');

echo "mad4b.remote-work-queue-cancellation.runtime.v1: PASS\n";
