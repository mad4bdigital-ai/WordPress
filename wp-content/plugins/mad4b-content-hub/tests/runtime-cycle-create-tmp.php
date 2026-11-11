<?php
if(parse_url(home_url('/'),PHP_URL_HOST)!=='tours.dev.mad4b.com')die('WRONG_SITE');
$id='14d65a58-73f2-4f1c-8512-c9fb652317a0';
$job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
if(is_wp_error($job))die($job->get_error_code());
$key='qa.auto.runtime.readonly.20261030';
$r=MAD4B_Hub_Trigger_Bus::manual(['event_key'=>$key,'job_id'=>$id,'stage'=>$job['job']['stage']]);
if(is_wp_error($r))die($r->get_error_code());
$batch=$r['batch_id'];
echo "QUEUED batch=$batch source=manual job=$id\n";
global $wpdb;
$selection=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['batch_id'=>$batch]);
foreach($selection['batches'][0]['tasks'] as $task)
 echo "PRE ".$task['task_key']." ".$task['state']." linked_job=".$task['content_job_id']."\n";
