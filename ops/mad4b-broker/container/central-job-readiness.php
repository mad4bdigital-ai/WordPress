<?php
defined('ABSPATH') || exit;
global $wpdb;
$prefix = MAD4B_Central_VPS_Broker_V1::JOB;
$keys = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 80",
    $wpdb->esc_like($prefix).'%'
));
$summary = ['site'=>home_url(),'execution_user'=>get_current_user_id(),
    'environment'=>'staging','write_performed'=>false,
    'container_role'=>'nonroot_read_only_coordinator',
    'jobs'=>[]];
foreach ((array)$keys as $key) {
    $job=get_option($key,false);
    if (!is_array($job) || ($job['principal_type']??null)!=='vps_native'
        || ($job['company']??null)!=='tours-central' 
        || ($job['environment']??null)!=='staging') continue;
    $id=(string)($job['job_id']??'');
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) continue;
    $receipt=get_option(MAD4B_Broker_General_Shell_Receipt_V1::PREFIX.$id,false);
    $summary['jobs'][]=[
        'job_id'=>$id,'state'=>$job['state']??'UNKNOWN',
        'receipt_exists'=>is_array($receipt),
        'executed_recorded'=>($job['executed']??false)===true
    ];
    if(count($summary['jobs'])>=12)break;
}
echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES);
