<?php
/**
 * PERMANENT MAD4B DYNAMIC ROUTING/LIFECYCLE REGRESSION
 * Read-only. No jobs, claims, grants, signatures or receipts created.
 *
 * Invariants: bounded workload-sensitive 305 admission, original old Job
 * remains expired, signed Host Inventory selection is exact and unique, and
 * unavailable Runner IDs cannot silently enable an Operator route.
 */
defined('ABSPATH') || exit;
$assert=static function(bool $yes,string $reason):void{
 if(!$yes)WP_CLI::error('MAD4B_DYNAMIC_MULTIROUTE_FAIL_'.$reason);
};
$intake='MAD4B_Central_SSH_Command_Intake_V1';
// Generic target IDs are read-only fixtures, not a static Host allowlist.
foreach(['','unregistered-test-host','another-test-host'] as $host){
 $v=$intake::wpcli_lifecycle_window($host);
 $assert(($v['contract']??'')==='mad4b.original-305-native-313-adaptive-window.v1','WINDOW_CONTRACT_'.$host);
 $assert($v['seconds']>=$intake::PERSISTENT_WPCLI_313_JOB_WINDOW,'WINDOW_TOO_SHORT_'.$host);
 $assert($v['seconds']<=$intake::PERSISTENT_WPCLI_313_JOB_MAX_WINDOW,'WINDOW_UNBOUNDED_'.$host);
 $assert($v['authorization_changed']===false && $v['existing_jobs_changed']===false,'WINDOW_MINTED_AUTHORITY');
}
$fp=str_repeat('a',64);
$registry=[
 'contract'=>'mad4b.company-host-topology-registry.v1',
 'state'=>'VERIFIED','registration_authority_readback'=>'ORIGINAL_SIGNED_RECEIPT_VERIFIED',
 'required_nodes'=>[
  ['node_id'=>'web-01','runtime_role'=>'wordpress_mcp',
    'target_fingerprint'=>$fp,'runner_profile_id'=>'primary-web-01'],
  ['node_id'=>'web-02','runtime_role'=>'wordpress_mcp',
    'target_fingerprint'=>str_repeat('b',64),'runner_profile_id'=>'primary-web-02']
 ]
];
$owner='MAD4B_SCP_Operator_Runner_Profile';
$id=$owner::from_verified_inventory($registry,$fp);
$assert($id==='primary-web-01','RUNNER_NOT_DISCOVERED');
$unverified=$registry;$unverified['registration_authority_readback']='';
$assert(is_wp_error($owner::from_verified_inventory($unverified,$fp)),
 'UNSIGNED_RUNNER_ADMITTED');
$duplicate=$registry;$duplicate['required_nodes'][1]['target_fingerprint']=$fp;
$assert(is_wp_error($owner::from_verified_inventory($duplicate,$fp)),
 'AMBIGUOUS_WEBHEAD_ADMITTED');
$bad=$registry;$bad['required_nodes'][0]['runner_profile_id']='../wrong';
$assert(is_wp_error($owner::from_verified_inventory($bad,$fp)),
 'INVALID_RUNNER_PROFILE_ADMITTED');
$live=$owner::current_id();
$assert(is_wp_error($live) || (is_string($live)
    && preg_match('/^[a-z][a-z0-9-]{2,63}$/D',$live)),
 'LIVE_RUNNER_NOT_STRICTLY_ENROLLED_OR_REJECTED');
// Read an existing expired original job if one is available; never mint,
// renew, complete or replay one solely to test this diagnostic.
global $wpdb;
$names=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.
    ' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 80',
    $wpdb->esc_like(MAD4B_Central_VPS_Broker_V1::JOB).'%'));
$observed_expired=false;
foreach((array)$names as $name){
 $job=get_option($name,false);
 if(!is_array($job) || !preg_match('/^[a-f0-9]{32}$/D',(string)($job['job_id']??'')))
    continue;
 $row=MAD4B_Broker_Remote_SSH_Receipt_V1::lifecycle_readback($job['job_id']);
 $assert(!$row['execution_replay_permitted'] && !$row['grant_created']
    && !$row['mutation_performed'],'READBACK_ESCALATED_AUTHORITY');
 if(($row['original_job_expired']??false)
    &&($row['pending_original_313']??false)){
   $assert($row['state']==='EXPIRED_RECEIPT_ONLY_RECOVERY_REQUIRED'
       && !$row['signed_313_certified'],'EXPIRED_313_WRONGLY_CLOSED');
   $observed_expired=true;
   break;
 }
}
$unknown=MAD4B_Broker_Remote_SSH_Receipt_V1::lifecycle_readback(str_repeat('0',32));
$assert(!$unknown['signed_313_certified'],'UNKNOWN_JOB_CERTIFIED');
WP_CLI::line(wp_json_encode([
 'contract'=>'mad4b.native313-dynamic-multiroute-regression.v1',
 'result'=>'PASS','tested_host_inputs'=>3,
 'expired_original_sample_observed'=>$observed_expired,
 'dynamic_runner_from_signed_inventory_fixture'=>'PASS',
 'duplicate_unregistered_and_invalid_runner_rejected'=>true,
 'live_runner_evaluated_against_original_policy'=>true,
 'expired_original_313_recovery_required'=>true,
 'original_jobs_mutated'=>false,'production_mutated'=>false
],JSON_UNESCAPED_SLASHES));
