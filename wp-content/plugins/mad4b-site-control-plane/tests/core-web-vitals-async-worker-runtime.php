<?php
$src= file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-core-web-vitals.php' );
$rename=array(
 'MAD4B_SCP_Core_Web_Vitals'=>'MAD4B_SCP_CWV_Worker_Test',
 'mad4b_scp_core_web_vitals_v1'=>'mad4b_scp_cwv_worker_test_data',
 'mad4b_scp_cwv_settings_v1'=>'mad4b_scp_cwv_worker_test_settings',
 'mad4b_scp_core_web_vitals_lock_v1'=>'mad4b_scp_cwv_worker_test_lock',
 'mad4b_scp_cwv_last_job_v1'=>'mad4b_scp_cwv_worker_test_job',
 'mad4b_core_web_vitals_daily'=>'mad4b_cwv_worker_test_daily',
 'mad4b_core_web_vitals_refresh_once'=>'mad4b_cwv_worker_test_once',
 'mad4b_core_web_vitals_device_worker'=>'mad4b_cwv_worker_test_device',
 'mad4b/v1'=>'mad4b-cwv-worker-test/v1',
);
if(!is_string($src)||''===$src)throw new RuntimeException('CWV source missing');
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='staging')return;
eval('?>'.str_replace(array_keys($rename),array_values($rename),$src));
$c = 'MAD4B_SCP_CWV_Worker_Test';
$options=array($c::OPTION,$c::SETTINGS_OPTION,$c::JOB_OPTION,$c::LOCK);
foreach($options as $o) if(false!==get_option($o,false)) throw new Exception('candidate option collision');
function cwv_worker_assert($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
$mockBody=array(
 'originLoadingExperience'=>array('metrics'=>array(
 'LARGEST_CONTENTFUL_PAINT_MS'=>array('percentile'=>2375),
 'INTERACTION_TO_NEXT_PAINT'=>array('percentile'=>180),
 'CUMULATIVE_LAYOUT_SHIFT_SCORE'=>array('percentile'=>0.02))),
 'loadingExperience'=>array('metrics'=>array()),
 'lighthouseResult'=>array('categories'=>array('performance'=>array('score'=>0.74)))
);
$failMobile=true;
$mock=function($pre,$args,$url)use(&$failMobile,$mockBody){
 if(strpos($url,'pagespeedonline/v5/runPagespeed')===false)return $pre;
 if($failMobile && strpos($url,'strategy=mobile')!==false)
    return new WP_Error('http_request_failed','cURL error 28: Operation timed out after 70000 milliseconds');
 return array('headers'=>array(),'body'=>wp_json_encode($mockBody),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array());
};
add_filter('pre_http_request',$mock,10,3);
try {
 $settings=$c::save_settings(home_url('/'),'',false);
 cwv_worker_assert(!is_wp_error($settings),'config_saved_without_key');
 $queued=$c::queue_refresh('rest_admin');
 cwv_worker_assert(($queued['state']??'')==='queued','queue_created');
 $job=$c::lifecycle_status();
 cwv_worker_assert($job['state']==='queued','queued_status');
 $c::dispatch_queued();
 $job=$c::lifecycle_status();
 cwv_worker_assert($job['state']==='running' && $job['active_device']==='mobile','mobile_device_dispatched');
 $id=$job['job_id'];
 $dup=$c::queue_refresh('rest_admin');
 cwv_worker_assert(($dup['state']??'')==='already_running','duplicate_queue_rejected');
 wp_clear_scheduled_hook($c::DEVICE_HOOK,array($id,'mobile'));
 $c::run_device($id,'mobile');
 $job=$c::lifecycle_status();
 cwv_worker_assert($job['state']==='running' && $job['active_device']==='desktop','desktop_after_mobile_timeout');
 cwv_worker_assert(($job['device_attempt_counts']['mobile']??0)===1,'mobile_first_attempt_recorded');
 wp_clear_scheduled_hook($c::DEVICE_HOOK,array($id,'desktop'));
 $c::run_device($id,'desktop');
 $job=$c::lifecycle_status();
 cwv_worker_assert($job['state']==='running' && $job['active_device']==='mobile','retry_mobile_after_desktop');
 $s=$c::status();
 cwv_worker_assert(($s['devices']['desktop']['origin']['verdict']??'')==='passed','desktop_saved');
 $failMobile=false;
 wp_clear_scheduled_hook($c::DEVICE_HOOK,array($id,'mobile'));
 $c::run_device($id,'mobile');
 $job=$c::lifecycle_status();
 cwv_worker_assert($job['state']==='completed','all_devices_completed');
 cwv_worker_assert(($job['device_attempt_counts']['mobile']??0)===2,'mobile_retry_count_2');
 $s=$c::status();
 cwv_worker_assert(($s['devices']['mobile']['origin']['verdict']??'')==='passed','mobile_crux_saved');
 cwv_worker_assert(count($s['history'])===2,'history_preserved');
 cwv_worker_assert(!$s['last_attempt_has_errors'],'last_attempts_clean');
 echo "CWV_WORKER_ASYNC_ALL_PASS\n";
} finally {
 remove_filter('pre_http_request',$mock,10);
 wp_clear_scheduled_hook($c::ONCE_HOOK);
 wp_clear_scheduled_hook($c::DAILY_HOOK);
 $j=get_option($c::JOB_OPTION,array());
 $id=$j['job_id']??'';
 if($id)foreach(array('mobile','desktop')as $strategy)wp_clear_scheduled_hook($c::DEVICE_HOOK,array($id,$strategy));
 foreach($options as $o)delete_option($o);
}
