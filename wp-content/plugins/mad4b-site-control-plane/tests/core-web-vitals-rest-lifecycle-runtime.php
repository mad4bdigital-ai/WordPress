<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) ||
    'staging' !== wp_get_environment_type() ) { exit; }
$source_file = dirname( __DIR__ ) . '/includes/class-mad4b-scp-core-web-vitals.php';
$source = file_get_contents( $source_file );
if ( ! is_string( $source ) || '' === $source ) throw new RuntimeException( 'CWV source unreadable' );
$names = array(
 'MAD4B_SCP_Core_Web_Vitals' => 'MAD4B_SCP_CWV_Candidate',
 'mad4b_scp_core_web_vitals_v1' => 'mad4b_scp_cwv_candidate_record_v1',
 'mad4b_scp_cwv_settings_v1' => 'mad4b_scp_cwv_candidate_settings_v1',
 'mad4b_scp_core_web_vitals_lock_v1' => 'mad4b_scp_cwv_candidate_lock_v1',
 'mad4b_scp_cwv_last_job_v1' => 'mad4b_scp_cwv_candidate_job_v1',
 'mad4b_core_web_vitals_daily' => 'mad4b_cwv_candidate_daily',
 'mad4b_core_web_vitals_refresh_once' => 'mad4b_cwv_candidate_once',
 'mad4b_core_web_vitals_device_worker' => 'mad4b_cwv_candidate_device',
 'mad4b_core_web_vitals_device_worker' => 'mad4b_cwv_candidate_device',
 'mad4b/v1' => 'mad4b-cwv-test/v1',
);
$isolated = str_replace( array_keys( $names ), array_values( $names ), $source );
eval( '?>' . $isolated );
$c = 'MAD4B_SCP_CWV_Candidate';
$prefix = '/mad4b-cwv-test/v1/admin/core-web-vitals';
$opts = array( $c::OPTION, $c::SETTINGS_OPTION, $c::JOB_OPTION, $c::LOCK );
function cwv_test_assert( $ok, $name ) {
    if ( ! $ok ) throw new RuntimeException( 'CWV TEST FAIL: ' . $name );
    echo "PASS " . $name . "\n";
}
function cwv_rest_request( $method, $path, $body = null ) {
    $request = new WP_REST_Request( $method, $path );
    if ( null !== $body ) {
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
}
foreach ($opts as $opt) if (false !== get_option($opt, false)) throw new RuntimeException('candidate option collision');
$previous = get_current_user_id();
try {
    $server = rest_get_server();
    $c::register_rest_routes();
    $routes = $server->get_routes();
    foreach( array( $prefix, $prefix.'/lifecycle', $prefix.'/settings', $prefix.'/refresh' ) as $route ) {
        cwv_test_assert(isset($routes[$route]), 'route '.$route);
    }
    wp_set_current_user(0);
    cwv_test_assert( 403 === cwv_rest_request('GET', $prefix)->get_status(), 'guest_read_denied');
    cwv_test_assert( 403 === cwv_rest_request('POST', $prefix.'/settings',array('page_url'=>home_url('/')))->get_status(), 'guest_write_denied');
    cwv_test_assert( 403 === cwv_rest_request('POST', $prefix.'/refresh',array())->get_status(), 'guest_queue_denied');
    $admins=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ids'));
    if ( !$admins ) throw new RuntimeException('No authorized admin tester');
    wp_set_current_user((int)$admins[0]);
    cwv_test_assert(200===cwv_rest_request('GET',$prefix)->get_status(),'admin_status_200');
    cwv_test_assert(200===cwv_rest_request('GET',$prefix.'/lifecycle')->get_status(),'admin_lifecycle_200');
    $bad=cwv_rest_request('POST',$prefix.'/settings',array('page_url'=>'https://example.org/'));
    cwv_test_assert(422===$bad->get_status(),'foreign_url_rejected');
    $badExtra=cwv_rest_request('POST',$prefix.'/settings',array('page_url'=>home_url('/'), 'unexpected'=>'yes'));
    cwv_test_assert(422===$badExtra->get_status(),'unknown_field_rejected');
    $fakeKey='CWVlocalTestKey0123456789012';
    $ok=cwv_rest_request('POST',$prefix.'/settings',array('page_url'=>home_url('/'),'api_key'=>$fakeKey));
    cwv_test_assert(200===$ok->get_status(),'admin_save_200');
    cwv_test_assert(false===strpos(wp_json_encode($ok->get_data()),$fakeKey),'no_secret_in_rest_response');
    $saved=get_option($c::SETTINGS_OPTION);
    cwv_test_assert(is_array($saved)&&!empty($saved['encrypted_api_key'])&&false===strpos(wp_json_encode($saved),$fakeKey),'key_encrypted_at_rest');
    $queued=cwv_rest_request('POST',$prefix.'/refresh',array());
    cwv_test_assert(202===$queued->get_status(),'refresh_queued_202');
    $job=get_option($c::JOB_OPTION);
    cwv_test_assert('queued'===($job['state']??''),'queued_receipt_persisted');
    $mockBody=array(
      'originLoadingExperience'=>array('metrics'=>array(
        'LARGEST_CONTENTFUL_PAINT_MS'=>array('percentile'=>2250),
        'INTERACTION_TO_NEXT_PAINT'=>array('percentile'=>180),
        'CUMULATIVE_LAYOUT_SHIFT_SCORE'=>array('percentile'=>0.02)
      )),
      'loadingExperience'=>array('metrics'=>array()),
      'lighthouseResult'=>array('categories'=>array('performance'=>array('score'=>0.91)))
    );
    $mock=function($pre,$args,$url) use($mockBody) {
        if(0!==strpos($url,$c='https://www.googleapis.com/pagespeedonline/v5/runPagespeed')) return $pre;
        if(false!==strpos($url,'strategy=mobile')) {
            return array('headers'=>array(),'body'=>'{}','response'=>array('code'=>429,'message'=>'Too Many Requests'),'cookies'=>array());
        }
        return array('headers'=>array(),'body'=>wp_json_encode($mockBody),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array());
    };
    add_filter('pre_http_request',$mock,10,3);
    // Simulate WordPress Cron consumption and process one provider request per device.
    $c::dispatch_queued();
    $id=$c::lifecycle_status()['job_id'];
    wp_clear_scheduled_hook($c::DEVICE_HOOK,array($id,'mobile'));
    $c::run_device($id,'mobile');
    wp_clear_scheduled_hook($c::DEVICE_HOOK,array($id,'desktop'));
    $c::run_device($id,'desktop');
    remove_filter('pre_http_request',$mock,10);
    $state=$c::lifecycle_status();
    cwv_test_assert('partial'===$state['state'],'partial_job_state');
    $status=$c::status();
    cwv_test_assert('rate_limited'===($status['attempts']['mobile']['state']??''),'mobile_429_stored');
    cwv_test_assert('collected'===($status['attempts']['desktop']['state']??''),'desktop_collected_stored');
    cwv_test_assert('passed'===($status['devices']['desktop']['origin']['verdict']??''),'desktop_field_data_passed');
    cwv_test_assert($state['cooldown_until_epoch']>time(),'backoff_recorded');
    $backoff=cwv_rest_request('POST',$prefix.'/refresh',array());
    // The original pending WP-Cron entry can still exist during a direct worker test.
    // Existing queued receipts are idempotent, never a second enqueue.
    cwv_test_assert(in_array($backoff->get_status(),array(200,429),true),'retry_is_bounded');
    $updated=$c::save_settings(home_url('/sample-path/'),'',false);
    cwv_test_assert(!is_wp_error($updated),'page_change_saved');
    $after=$c::status();
    cwv_test_assert(empty($after['devices']),'old_page_evidence_cleared');
    cwv_test_assert($after['api_key_configured'],'blank_key_keeps_secret');
    cwv_test_assert(0===$c::lifecycle_status()['cooldown_until_epoch'],'configuration_reset_cooldown');
    $clear=cwv_rest_request('POST',$prefix.'/settings',array('page_url'=>home_url('/'),'clear_api_key'=>true));
    cwv_test_assert(200===$clear->get_status()&&!$clear->get_data()['settings']['api_key_configured'],'admin_key_clear');
    echo "CWV_ISOLATED_REST_LIFECYCLE_ALL_PASS\n";
} finally {
    wp_set_current_user($previous);
    wp_clear_scheduled_hook($c::ONCE_HOOK);
    wp_clear_scheduled_hook($c::DAILY_HOOK);
    foreach($opts as $opt) delete_option($opt);
}
