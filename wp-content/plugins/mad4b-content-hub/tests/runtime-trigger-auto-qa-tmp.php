<?php
if(parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')die('WRONG_SITE');
if(!MAD4B_Hub_Runtime_Triggers::settings()['auto_enabled'])die('AUTO_DISABLED');
$scope=MAD4B_SCP_Operational_Integrity::capture();
if(is_wp_error($scope))die($scope->get_error_code());
$brand=$scope['scope']['brand_ref'];
$result=MAD4B_SCP_Content_Jobs::create_job([
 'brand_id'=>$brand,
 'subject'=>'STAGING QA: Dynamic Runtime automatic event-to-ContentJob binding',
 'language'=>'en','country'=>'eg','content_type'=>'article',
 'target_post_type'=>'post','automation_level'=>'strict',
 'reason'=>'Validate ContentJob auto event triggers and queue binding on isolated Staging'
]);
if(is_wp_error($result)){echo 'CREATE_ERROR '.$result->get_error_code()."\n";return;}
$job=$result['job'];$id=$job['job_id'];
echo "CREATED_JOB $id state=".$job['state']." rev=".$job['job_revision']."\n";
$after=MAD4B_Hub_Runtime_Triggers::job_status(['job_id'=>$id]);
echo 'EVENT_CREATED_QUEUE='.wp_json_encode(['batch_count'=>$after['batch_count']??0,'batches'=>$after['batches']??[]])."\n";
$transition=MAD4B_SCP_Content_Jobs::transition_job([
 'job_id'=>$id,'expected_revision'=>(int)$job['job_revision'],
 'state'=>'QUEUED','stage'=>'SITE_DISCOVERY',
 'reason'=>'QA verify automatically queued transition-scoped runtime resolution'
]);
if(is_wp_error($transition)){echo "TRANSITION_ERROR ".$transition->get_error_code()."\n";return;}
$next=$transition['job'];
echo "TRANSITION_JOB $id state=".$next['state']." stage=".$next['stage']." rev=".$next['job_revision']."\n";
$after=MAD4B_Hub_Runtime_Triggers::job_status(['job_id'=>$id]);
echo 'EVENT_TRANSITION_QUEUE='.wp_json_encode(['batch_count'=>$after['batch_count']??0,'batches'=>$after['batches']??[]])."\n";
$replay=MAD4B_Hub_Runtime_Triggers::committed(['job_id'=>$id,'job_revision'=>2,'stage'=>'SITE_DISCOVERY','state'=>'QUEUED','event_type'=>'transitioned']);
$again=MAD4B_Hub_Runtime_Triggers::job_status(['job_id'=>$id]);
echo 'REPLAY_BATCH_COUNT='.($again['batch_count']??0)."\n";
$verify=[];
foreach(($after['batches']??[]) as $batch){
 $verify[]=['batch'=>$batch['batch_id'],'tasks'=>array_column($batch['tasks'],'state','task_key')];
 $result=MAD4B_Hub_Bulk_Jobs_Queue::batch_control(['batch_id'=>$batch['batch_id'],'action'=>'CANCEL']);
 echo "QA_BATCH_CLEANUP ".$batch['batch_id']." ".(is_wp_error($result)?$result->get_error_code():'CANCELLED')."\n";
}
$cancel=MAD4B_SCP_Content_Jobs::cancel_job(['job_id'=>$id,'expected_revision'=>(int)$next['job_revision'],'reason'=>'QA ContentJob integration complete, no content was published']);
echo "QA_JOB_CANCEL ".(is_wp_error($cancel)?$cancel->get_error_code():$cancel['job']['state'])."\n";
echo "RESULT_JOBS_BOUND=".wp_json_encode($verify)."\n";
