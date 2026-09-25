<?php

define('ABSPATH',__DIR__.'/');
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require dirname(__DIR__).'/includes/class-mad4b-scp-scheduler-admission.php';
$fail=static function($m){fwrite(STDERR,"FAIL scheduler-admission-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};
$base=array(
 'work'=>array('tenant_id'=>'t1','site_id'=>'s1','job_type'=>'research','provider_id'=>'search','priority_class'=>'normal','fairness_weight'=>2),
 'queue_state'=>array('tenant_queued'=>1,'tenant_running'=>1,'provider_rate_limited'=>false,'reserved_recovery_slots_remaining'=>1),
 'quotas'=>array('max_queued_jobs'=>10,'max_concurrent_jobs'=>3),
 'central_state'=>array('available'=>true,'signed_evidence_current'=>true),
);
$r=MAD4B_SCP_Scheduler_Admission::evaluate($base);
$check('ADMIT'===$r['decision'],'normal work not admitted');
$check(false===$r['dispatch_performed'] && false===$r['authorizing'],'evaluator dispatched/authorized');

$limited=$base;$limited['queue_state']['provider_rate_limited']=true;
$r=MAD4B_SCP_Scheduler_Admission::evaluate($limited);
$check('QUEUE'===$r['decision'] && in_array('provider_rate_limited',$r['reasons'],true),'rate limited provider not queued');

$full=$base;$full['queue_state']['tenant_queued']=10;
$r=MAD4B_SCP_Scheduler_Admission::evaluate($full);
$check('DENY'===$r['decision'],'queue quota exhaustion not denied');

$recovery=$base;$recovery['work']['job_type']='rollback';$recovery['queue_state']['tenant_running']=3;
$r=MAD4B_SCP_Scheduler_Admission::evaluate($recovery);
$check('RESERVED_LANE'===$r['decision'],'reserved recovery lane unavailable');

$outage=$base;$outage['work']['high_risk']=true;$outage['central_state']=array('available'=>false,'signed_evidence_current'=>false);
$r=MAD4B_SCP_Scheduler_Admission::evaluate($outage);
$check('FAIL_CLOSED'===$r['decision'],'high-risk central outage did not fail closed');

$rank=MAD4B_SCP_Scheduler_Admission::fair_rank(array(
 'now_epoch'=>10000,
 'items'=>array(
  array('job_id'=>'job-heavy','tenant_id'=>'t1','site_id'=>'s1','admitted'=>true,'authority_current'=>true,'quota_current'=>true,'enqueued_at_epoch'=>9900,'fairness_weight'=>10,'priority_class'=>'normal'),
  array('job_id'=>'job-aged','tenant_id'=>'t2','site_id'=>'s2','admitted'=>true,'authority_current'=>true,'quota_current'=>true,'enqueued_at_epoch'=>5000,'fairness_weight'=>1,'priority_class'=>'normal'),
  array('job_id'=>'job-stale-authority','tenant_id'=>'t3','site_id'=>'s3','admitted'=>true,'authority_current'=>false,'quota_current'=>true,'enqueued_at_epoch'=>1000,'fairness_weight'=>100,'priority_class'=>'high'),
 ),
));
$check('mad4b.scheduler-fair-rank.v1'===$rank['contract'],'fair ranking contract mismatch');
$check('job-aged'===$rank['next_job_id'],'aging did not prevent weighted starvation');
$check(true===$rank['anti_starvation'],'anti-starvation evidence missing');
$check(false===$rank['authority_bypass_allowed'] && false===$rank['quota_bypass_allowed'],'fairness widened authority/quota');
$rejected_ids=array_map(static function($row){return $row['job_id']??'';},$rank['rejected']);
$check(in_array('job-stale-authority',$rejected_ids,true),'stale authority entered fair queue');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-scheduler-admission.php');
foreach(array('wp_schedule_event(','wp_unschedule_event(','wp_insert_post(','$wpdb->','shell_exec(','exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'mutation primitive present: '.$forbidden);
}
echo "mad4b.scheduler-admission.v1: PASS\n";
