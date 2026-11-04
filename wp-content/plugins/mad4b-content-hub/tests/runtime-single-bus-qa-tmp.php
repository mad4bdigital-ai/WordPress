<?php
if(parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')die('ENV_GUARD');
$c=MAD4B_SCP_Operational_Integrity::capture();
if(is_wp_error($c))die($c->get_error_code());
$id='';$passes=[];
try{
 $created=MAD4B_SCP_Content_Jobs::create_job([
  'brand_id'=>$c['scope']['brand_ref'],'subject'=>'Staging QA runtime single trigger bus event test',
  'language'=>'en','country'=>'eg','content_type'=>'article',
  'automation_level'=>'strict','target_post_type'=>'post',
  'reason'=>'Native ContentJob post-commit event trigger QA with no provider actions']);
 if(is_wp_error($created))die('CREATE_ERROR '.$created->get_error_code());
 $j=$created['job'];$id=$j['job_id'];
 $q=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['job_id'=>$id]);
 $batches=$q['batches'];
 $eventBatches=array_values(array_filter($batches,fn($b)=>str_starts_with($b['batch_key'],'event.')));
 $passes['created_one_batch']=count($eventBatches)===1&&count($batches)===1;
 echo 'CREATED_JOB='.$id.' batches='.count($batches)."\n";
 $tr=MAD4B_SCP_Content_Jobs::transition_job([
  'job_id'=>$id,'expected_revision'=>1,'state'=>'QUEUED','stage'=>'SITE_DISCOVERY',
  'reason'=>'Queue next stage from committed ContentJob transition']);
 if(is_wp_error($tr))die('TRANSITION_ERROR '.$tr->get_error_code());
 $q=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['job_id'=>$id]);
 $batches=$q['batches'];
 $eventBatches=array_values(array_filter($batches,fn($b)=>str_starts_with($b['batch_key'],'event.')));
 $passes['transition_one_more_batch']=count($eventBatches)===2&&count($batches)===2;
 $old=array_values(array_filter($batches,fn($b)=>($b['tasks'][0]['stage']??'')==='INTAKE'));
 $passes['old_stage_tasks_fenced']=count($old)===1&&count(array_filter($old[0]['tasks'],fn($t)=>$t['state']==='CANCELLED'))===2;
 $parts=[];foreach($batches as $b)$parts[]=[$b['batch_key'],$b['tasks'][0]['stage']??'',array_column($b['tasks'],'state')];
 echo 'POST_TRANSITION='.wp_json_encode($parts)."\n";
 $status=MAD4B_Hub_Trigger_Bus::status();
 $passes['auto_and_manual_both_enabled']=!empty($status['policy']['auto_enabled'])&&!empty($status['policy']['manual_enabled']);
} finally {
 if($id!==''){
  $q=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['job_id'=>$id]);
  if(!is_wp_error($q))foreach($q['batches'] as $b)
    if($b['state']!=='CANCELLED')MAD4B_Hub_Bulk_Jobs_Queue::batch_control(['batch_id'=>$b['batch_id'],'action'=>'CANCEL']);
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
  if(!is_wp_error($job)&&!in_array($job['job']['state'],['COMPLETED','CANCELLED'],true)){
   $end=MAD4B_SCP_Content_Jobs::cancel_job([
    'job_id'=>$id,'expected_revision'=>(int)$job['job']['job_revision'],
    'reason'=>'Staging QA completed; no content publication or provider work']);
   echo 'QA_JOB_CLEANUP='.(is_wp_error($end)?$end->get_error_code():$end['job']['state'])."\n";
  }
 }
}
foreach($passes as $name=>$ok)echo ($ok?'PASS ':'FAIL ').$name."\n";
echo 'TESTS_PASSED='.count(array_filter($passes)).'/'.count($passes)."\n";
