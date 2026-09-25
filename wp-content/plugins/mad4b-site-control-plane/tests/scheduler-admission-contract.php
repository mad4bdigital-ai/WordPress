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

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-scheduler-admission.php');
foreach(array('wp_schedule_event(','wp_unschedule_event(','wp_insert_post(','$wpdb->','shell_exec(','exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'mutation primitive present: '.$forbidden);
}
echo "mad4b.scheduler-admission.v1: PASS\n";
