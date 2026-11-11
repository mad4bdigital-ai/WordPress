<?php
$job_id='f09a022c-dcfa-4e85-9302-9a2248bd25a4';
$j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
if(is_wp_error($j))throw new RuntimeException($j->get_error_code());
$job=$j['job'];
if(($job['state']??'')==='COMPLETED')throw new RuntimeException('negative_fixture_is_completed');
$reflect=new ReflectionMethod('MAD4B_Lifecycle_Orchestrator','source_verified');
$fake=['event'=>['job_id'=>$job_id],'source_receipt'=>[
 'source'=>'content_job_transition',
 'revision'=>(int)$job['job_revision'],'state'=>'COMPLETED',
 'stage'=>(string)$job['stage']]];
$untrusted=$reflect->invoke(null,$fake);
$fakeTask='eeeeeeee-ffff-4111-8aaa-eeeeeeeeeeee';
$payload=['content_job_id'=>$job_id,'task_id'=>$fakeTask,
 'state'=>'COMPLETED','stage'=>(string)$job['stage'],'operation'=>'stage_work','verified_success'=>true];
$rev=substr(hash('sha256',$fakeTask.'|COMPLETED'),0,48);
$identity=['site'=>home_url('/'),'goal'=>'content','kind'=>'content_job','target'=>0,
 'job'=>$job_id,'company'=>'','manager'=>'','type'=>'hub_task_finished','revision'=>$rev];
$id=hash('sha256',wp_json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$before=get_option('mad4b_lifecycle_event_v1_'.$id,false);
do_action('mad4b_hub_bulk_task_finished',$payload);
$after=get_option('mad4b_lifecycle_event_v1_'.$id,false);
echo wp_json_encode(['contract'=>'mad4b.lifecycle-verification-negative.v1',
 'source_job_state'=>$job['state'],
 'fake_completed_source_refused'=>$untrusted===null,
 'fake_completed_task_refused'=>$before===false&&$after===false,
 'provider_mutation_performed'=>false]);
