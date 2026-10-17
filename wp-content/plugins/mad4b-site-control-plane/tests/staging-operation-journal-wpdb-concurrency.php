<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/* Runs ONLY inside disposable CI WordPress + MySQL/MariaDB, never against enrolled sites. */
$mode = isset($args[0]) ? (string) $args[0] : '';
$run = isset($args[1]) ? sanitize_key((string)$args[1]) : '';
$group = isset($args[2]) ? (string)$args[2] : '';
$variant = isset($args[3]) ? (int)$args[3] : 0;
if ( ! preg_match('/^[a-z0-9_-]{6,80}$/D',$run)
    || ! in_array($mode,array('setup','worker','verify'),true)
    || ! in_array($group,array('same','different'),true) ) {
    fwrite(STDERR,"Bad isolated CI arguments\n");exit(2);
}
$plugin = WP_PLUGIN_DIR . '/mad4b-site-control-plane/includes/class-mad4b-scp-staging-operation-journal.php';
require_once $plugin;
$journal = 'MAD4B_SCP_Staging_Operation_Journal';
$slug=hash('sha256',$run.'|'.$group);
$site=substr($slug,0,8).'-'.substr($slug,8,4).'-'.substr($slug,12,4).'-'.substr($slug,16,4).'-'.substr($slug,20,12);
$make_plan = static function($index) use($site,$run,$group) {
    return array(
        'site_uuid'=>$site,
        'plan_sha256'=>hash('sha256',$run.'|'.$group.'|'.$index),
        'source_commit_sha'=>str_repeat('b',40),
        'build_fingerprint'=>str_repeat('c',64),
        'package_manifest_digest'=>str_repeat('d',64),
    );
};
$active_key = $journal::ACTIVE_PREFIX . substr(hash('sha256',$site),0,32);
$latest_key = $journal::LATEST . '_' . substr(hash('sha256',$site),0,32);
if ('setup'===$mode) {
    delete_option($active_key);
    delete_option($latest_key);
    for ($i=0;$i<8;$i++) delete_option($journal::PREFIX . $journal::id($make_plan($i)));
    echo "ISOLATED_JOURNAL_SETUP_OK\n";return;
}
if ('worker'===$mode) {
    $plan=$make_plan('same'===$group ? 0 : $variant);
    $result=$journal::reserve($plan);
    echo wp_json_encode(array('worker'=>$variant,'success'=>!is_wp_error($result),
        'code'=>is_wp_error($result)?$result->get_error_code():'RESERVED')) . PHP_EOL;
    return;
}
$status=$journal::latest($site);
$active=get_option($active_key,'');
global $wpdb;
$names=array();
for ($i=0;$i<8;$i++) $names[]=$journal::PREFIX.$journal::id($make_plan($i));
$placeholders=implode(',',array_fill(0,count($names),'%s'));
$sql=$wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ($placeholders)",$names);
$count=(int)$wpdb->get_var($sql);
$ok=1===$count && is_string($active) && ''!==$active
    && 'EXECUTION_UNCERTAIN'===$status['state']
    && isset($status['operation_id']) && hash_equals($status['operation_id'],$active);
if (!$ok) {
    fwrite(STDERR,"ISOLATED_JOURNAL_CONCURRENCY_FAILED ".wp_json_encode(array(
        'count'=>$count,'status'=>$status,'active'=>$active)).PHP_EOL);exit(3);
}
echo "ISOLATED_JOURNAL_CONCURRENCY_PASS group=$group count=$count\n";
