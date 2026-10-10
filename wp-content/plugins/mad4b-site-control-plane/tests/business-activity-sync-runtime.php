<?php
/** Native isolated fake-provider acceptance for MSR02 exact-sync coordinator. */
define('ABSPATH',__DIR__);
class WP_Error { private $code; function __construct($code,$message=''){ $this->code=$code; }
 function get_error_code(){return $this->code;} }
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($value,$options=0){return json_encode($value,$options);}
function current_user_can($cap,$id=0){return true;}
function get_post_type($id){return in_array($id,array(101,102,103,104,105,106,107),true)?'vendor_profiles':'other';}
function get_post_meta($id,$field,$single){return isset($GLOBALS['wp_values'][$id][$field])?$GLOBALS['wp_values'][$id][$field]:'';}
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['options'])?$GLOBALS['options'][$key]:$default;}
function add_option($key,$value,$unused='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['options']))return false;
 $GLOBALS['options'][$key]=$value;return true;}
function update_option($key,$value,$autoload=false){
 if(isset($GLOBALS['fail_option_state'])&&is_array($value)&&
    strpos($key,'mad4b_asyn_operation_')===0&&
    isset($value['state'])&&$value['state']===$GLOBALS['fail_option_state'])return false;
 $old=get_option($key,false);$GLOBALS['options'][$key]=$value;return $old!==$value;
}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function apply_filters($hook,$adapters,$profile){if($hook==='mad4b_activity_sync_adapters'){$adapters['google_drive']=array(
 'read'=>'fake_drive_read','write'=>'fake_drive_write',
 'conditional_write'=>true,'readback'=>true);}return $adapters;}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
 static function site_uuid(){return '11111111-2222-4333-8444-555555555555';}
}
class MAD4B_SCP_Content_Experience_Profiles {
 static $owner='wordpress';
 static function profile($slug){
  if($slug!=='vendor')return new WP_Error('unknown');
  return array('enabled'=>true,'slug'=>'vendor','post_type'=>'vendor_profiles',
   'meta_keys'=>array('biography'),'revision'=>3,'authority_sha256'=>str_repeat('a',64),
   'activity_contract'=>array('enabled'=>true,'sync_identity_key'=>'post_id',
    'attribute_meta_keys'=>array('biography'),'field_owners'=>array('biography'=>self::$owner),
    'sync_targets'=>array('drive'=>array('provider'=>'google_drive','purpose'=>'record_data',
     'source_ref'=>'drive_doc_01','resource_kind'=>'drive_document',
     'field_keys'=>array('biography'),'direction'=>'bidirectional',
     'field_bindings'=>array()))));
 }
}
$GLOBALS['options']=array();
$GLOBALS['wp_values']=array(101=>array('biography'=>'Original'),102=>array('biography'=>'Original'),
 103=>array('biography'=>'Original'));
$GLOBALS['drive_value']='Original'; $GLOBALS['drive_revision']=1; $GLOBALS['drive_uncertain']=false;
function fake_drive_read($source,$fields,$binding){
 return array('resource_id'=>'drive_doc_01','revision'=>'drive_r'.$GLOBALS['drive_revision'],
  'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'state'=>'present',
  'fields'=>array('biography'=>$GLOBALS['drive_value']));
}
function fake_drive_write($source,$field,$value,$expected,$binding){
 if($expected['revision']!=='drive_r'.$GLOBALS['drive_revision'])return new WP_Error('drive_cas_mismatch');
 $GLOBALS['drive_value']=$value; $GLOBALS['drive_revision']++;
 if($GLOBALS['drive_uncertain'])return new WP_Error('provider_timeout_after_write');
 return fake_drive_read($source,array($field),$binding);
}
require __DIR__.'/../includes/class-mad4b-scp-activity-source-reconciliation.php';
require __DIR__.'/../includes/class-mad4b-scp-activity-sync-runtime.php';
function ck($ok,$desc){if(!$ok)throw new RuntimeException($desc);}
function scope($id){return array('profile_slug'=>'vendor','entity_id'=>(string)$id);}
function confirmed($args){return array_merge($args,array('confirmed'=>true,'operation_key'=>'local_op_1234567'));}
$first=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(101));
ck(!is_wp_error($first)&&$first['mode']==='bootstrap'&&$first['ready_for_apply'],'Initial identical provider bootstrap not available');
$seed=MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(101),array('plan_sha256'=>$first['plan_sha256']))));
ck(!is_wp_error($seed)&&$seed['checkpoint_initialized'],'Server did not persist initial checkpoint');
$GLOBALS['wp_values'][101]['biography']='Approved change';
$plan=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(101));
ck(!is_wp_error($plan)&&$plan['mode']==='sync'&&$plan['ready_for_apply']&&
 count($plan['steps'])===1&&$plan['steps'][0]['destination']==='drive',
 'Canonical edit did not produce conditional Drive proposal');
$stale=MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(101),array('plan_sha256'=>str_repeat('f',64)))));
ck(is_wp_error($stale)&&$stale->get_error_code()==='mad4b_sync_plan_stale_or_conflicted',
 'Stale approved plan started writes');
$begin=MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(101),array('plan_sha256'=>$plan['plan_sha256']))));
ck(!is_wp_error($begin)&&$begin['state']==='queued','Operation not persisted before write');
$firstStep=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(101)));
ck(!is_wp_error($firstStep)&&$GLOBALS['drive_value']==='Approved change'&&$firstStep['readback_verified'],
 'Drive adapter CAS/readback did not apply approved exact field');
$finish=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(101)));
ck(!is_wp_error($finish)&&$finish['state']==='complete'&&$finish['checkpoint_advanced'],
 'All-provider readback did not advance durable checkpoint');
$state=MAD4B_SCP_Activity_Sync_Runtime::status(scope(101));
$archive=MAD4B_SCP_Activity_Sync_Runtime::archive(array_merge(scope(101),
 array('confirmed'=>true,'expected_operation_sha256'=>$state['operation_sha256'])));
ck(!is_wp_error($archive)&&$archive['archived'],'Completed journal not archived with receipt');
$next=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(101));
ck(!is_wp_error($next)&&!$next['has_conflicts']&&count($next['steps'])===0,'Checkpoint remained stale');
$GLOBALS['wp_values'][102]['biography']='Different';
$disagree=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(102));
ck(!is_wp_error($disagree)&&$disagree['mode']==='bootstrap'&&
 !$disagree['ready_for_apply']&&$disagree['requires_manual_reconciliation'],
 'Divergent initial sources autoaccepted without arbitration');
$arbitration=MAD4B_SCP_Activity_Sync_Runtime::plan(array_merge(scope(102),
 array('field_sources'=>array('biography'=>'wordpress'))));
ck(!is_wp_error($arbitration)&&$arbitration['mode']==='bootstrap_arbitrate'&&
 $arbitration['ready_for_apply']&&count($arbitration['steps'])===1,
 'Divergent initial sources lack exact owner-reviewed bootstrap plan');
$startArbitration=MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(102),
 array('field_sources'=>array('biography'=>'wordpress'),'plan_sha256'=>$arbitration['plan_sha256']))));
ck(!is_wp_error($startArbitration)&&$startArbitration['state']==='queued',
 'Owner-reviewed initial source choice failed to reserve journal');
$applyArbitration=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(102)));
ck(!is_wp_error($applyArbitration)&&$GLOBALS['drive_value']==='Different',
 'Arbitration failed exact conditional destination update');
$finishArbitration=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(102)));
ck(!is_wp_error($finishArbitration)&&$finishArbitration['checkpoint_advanced'],
 'Initial divergent sources did not converge to a trusted checkpoint');
$archiveStatus=MAD4B_SCP_Activity_Sync_Runtime::status(scope(102));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::archive(array_merge(scope(102),
 array('confirmed'=>true,'expected_operation_sha256'=>$archiveStatus['operation_sha256'])))),
 'Arbitrated operation was not archived');
$GLOBALS['wp_values'][104]=array('biography'=>'Original');
$GLOBALS['drive_value']='Original';$GLOBALS['drive_revision']++;
$seedPlan=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(104));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(104),
 array('plan_sha256'=>$seedPlan['plan_sha256']))))), 'Second entity bootstrap failed');
$GLOBALS['wp_values'][104]['biography']='Next revision';
$p=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(104));
$start=MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(104),array('plan_sha256'=>$p['plan_sha256']))));
ck(!is_wp_error($start),'Exact concurrent test start failed');
$GLOBALS['drive_value']='Other editor';$GLOBALS['drive_revision']++;
$mut=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(104)));
ck(is_wp_error($mut)&&$mut->get_error_code()==='mad4b_sync_destination_changed_since_approval',
 'Unapproved external Drive edit overwritten');
$afterRefusal=MAD4B_SCP_Activity_Sync_Runtime::status(scope(104));
$driftLease='mad4b_asyn_lease_'.hash('sha256',
 MAD4B_SCP_Site_Profile::site_uuid().'|vendor|104');
ck($afterRefusal['operation_state']==='needs_reconcile' &&
 !array_key_exists($driftLease,$GLOBALS['options']),
 'Prewrite refusal must durably record conflict and release inactive worker lease');
$GLOBALS['drive_value']='Next revision';$GLOBALS['drive_revision']++;
$partialStatus=MAD4B_SCP_Activity_Sync_Runtime::status(scope(104));
$closed=MAD4B_SCP_Activity_Sync_Runtime::finalize_reconciled(array_merge(
 scope(104),array('confirmed'=>true,'expected_operation_sha256'=>$partialStatus['operation_sha256'])));
ck(!is_wp_error($closed)&&$closed['state']==='complete'&&
 $closed['provider_readbacks_verified'],'Partial saga could not close after independent convergence');
$GLOBALS['drive_value']='Original';$GLOBALS['drive_revision']++;
$seed3=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(103));
ck(!is_wp_error($seed3)&&$seed3['ready_for_apply'],'Third clean bootstrap failed');
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(103),
 array('plan_sha256'=>$seed3['plan_sha256']))))), 'Third checkpoint not durable');
$GLOBALS['wp_values'][103]['biography']='Third approved';$GLOBALS['drive_uncertain']=true;
$p3=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(103));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(103),
 array('plan_sha256'=>$p3['plan_sha256']))))), 'Third operation failed');
$timeout=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(103)));
ck(is_wp_error($timeout),'Unknown provider write outcome silently passed');
$status=MAD4B_SCP_Activity_Sync_Runtime::status(scope(103));
ck($status['operation_state']==='needs_reconcile','Uncertain write not journaled');
$recovered=MAD4B_SCP_Activity_Sync_Runtime::recover(array_merge(confirmed(scope(103)),
 array('expected_operation_sha256'=>$status['operation_sha256'])));
ck(!is_wp_error($recovered)&&$recovered['provider_replayed']===false,
 'Recovery replayed provider write instead of exact postwrite readback');
$GLOBALS['drive_uncertain']=false;
$completed=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(103)));
ck(!is_wp_error($completed)&&$completed['checkpoint_advanced'],'Recovered write did not converge checkpoint');

// Fault injection: journal storage fails before a provider write.
// The lease may remain held conservatively, but there must be ZERO remote writes.
$GLOBALS['wp_values'][105]=array('biography'=>'Original');
$GLOBALS['drive_value']='Original'; $GLOBALS['drive_revision']++;
$seed5=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(105));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(105),
 array('plan_sha256'=>$seed5['plan_sha256']))))), 'Fault test bootstrap failed');
$GLOBALS['wp_values'][105]['biography']='Edited without journal';
$p5=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(105));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(105),
 array('plan_sha256'=>$p5['plan_sha256']))))), 'Fault test begin failed');
$GLOBALS['fail_option_state']='step_inflight';
$before=$GLOBALS['drive_revision'];
$failed=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(105)));
unset($GLOBALS['fail_option_state']);
ck(is_wp_error($failed) && $failed->get_error_code()==='mad4b_sync_inflight_journal_unverified',
 'Write proceeded despite failed durable inflight journal');
ck($GLOBALS['drive_revision']===$before && $GLOBALS['drive_value']==='Original',
 'Provider write must not occur without journal readback');
$noWriteState=MAD4B_SCP_Activity_Sync_Runtime::status(scope(105));
$lease105='mad4b_asyn_lease_'.hash('sha256',
 MAD4B_SCP_Site_Profile::site_uuid().'|vendor|105');
ck($noWriteState['operation_state']==='queued' &&
 !array_key_exists($lease105,$GLOBALS['options']),
 'Unwritten operation held a lease after failed inflight journal');
$cancel105=MAD4B_SCP_Activity_Sync_Runtime::cancel(array_merge(scope(105),
 array('confirmed'=>true,'expected_operation_sha256'=>$noWriteState['operation_sha256'])));
ck(!is_wp_error($cancel105)&&$cancel105['provider_writes_executed']===0,
 'Exact zero-write failed journal operation could not be cancelled');

// Fault injection: provider write succeeds but checkpoint progress storage fails.
// Never report success or unlock a step whose durable receipt is uncertain.
$GLOBALS['wp_values'][106]=array('biography'=>'Original');
$GLOBALS['drive_value']='Original'; $GLOBALS['drive_revision']++;
$seed6=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(106));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(106),
 array('plan_sha256'=>$seed6['plan_sha256']))))), 'Postwrite test bootstrap failed');
$GLOBALS['wp_values'][106]['biography']='Written but progress failed';
$p6=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(106));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(106),
 array('plan_sha256'=>$p6['plan_sha256']))))), 'Postwrite test begin failed');
$GLOBALS['fail_option_state']='running';
$uncertain=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(106)));
unset($GLOBALS['fail_option_state']);
ck(is_wp_error($uncertain) && $uncertain->get_error_code()==='mad4b_sync_postwrite_journal_unverified',
 'Unrecorded postwrite step was incorrectly accepted');
ck($GLOBALS['drive_value']==='Written but progress failed' &&
 $GLOBALS['drive_revision']>=$before+2,'Provider write was not observed in fault test');
$s6=MAD4B_SCP_Activity_Sync_Runtime::status(scope(106));
ck($s6['operation_state']==='step_inflight','Uncertain write lost inflight state');
$blocked=MAD4B_SCP_Activity_Sync_Runtime::recover(array_merge(confirmed(scope(106)),
 array('expected_operation_sha256'=>$s6['operation_sha256'])));
ck(is_wp_error($blocked) &&
 $blocked->get_error_code()==='mad4b_sync_recover_inflight_worker_not_quiesced',
 'Recovery overtook a potentially active provider worker');
$recovery_key='mad4b_asyn_recovery_lease_'.hash('sha256',
 MAD4B_SCP_Site_Profile::site_uuid().'|vendor|106');
$GLOBALS['options'][$recovery_key]='other_recovery_worker';
$advance_lease='mad4b_asyn_lease_'.hash('sha256',
 MAD4B_SCP_Site_Profile::site_uuid().'|vendor|106');
// Simulate external process-exit confirmation. This is not a production lease
// reclamation algorithm and MUST NOT be implemented as automatic age-only release.
unset($GLOBALS['options'][$advance_lease]);
$busy=MAD4B_SCP_Activity_Sync_Runtime::recover(array_merge(confirmed(scope(106)),
 array('expected_operation_sha256'=>$s6['operation_sha256'])));
ck(is_wp_error($busy)&&$busy->get_error_code()==='mad4b_sync_recovery_busy',
 'Two recovery workers entered the journal');
unset($GLOBALS['options'][$recovery_key]);
$recovered6=MAD4B_SCP_Activity_Sync_Runtime::recover(array_merge(confirmed(scope(106)),
 array('expected_operation_sha256'=>$s6['operation_sha256'])));
ck(!is_wp_error($recovered6)&&$recovered6['provider_replayed']===false,
 'Readback-based recovery failed after worker quiescence');
$done6=MAD4B_SCP_Activity_Sync_Runtime::advance(confirmed(scope(106)));
ck(!is_wp_error($done6)&&$done6['checkpoint_advanced'],
 'Recovered uncertain write was not checkpointed');

$GLOBALS['wp_values'][107]=array('biography'=>'Original');
$GLOBALS['drive_value']='Original'; $GLOBALS['drive_revision']++;
$seed7=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(107));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(107),
 array('plan_sha256'=>$seed7['plan_sha256']))))), 'Cancel race bootstrap failed');
$GLOBALS['wp_values'][107]['biography']='Pending cancel';
$p7=MAD4B_SCP_Activity_Sync_Runtime::plan(scope(107));
ck(!is_wp_error(MAD4B_SCP_Activity_Sync_Runtime::begin(confirmed(array_merge(scope(107),
 array('plan_sha256'=>$p7['plan_sha256']))))), 'Cancel race begin failed');
$c7=MAD4B_SCP_Activity_Sync_Runtime::status(scope(107));
$workerLease='mad4b_asyn_lease_'.hash('sha256',
 MAD4B_SCP_Site_Profile::site_uuid().'|vendor|107');
$GLOBALS['options'][$workerLease]=array('operation_key'=>'other_worker','at'=>time());
$recoveryLease107='mad4b_asyn_recovery_lease_'.hash('sha256',
 MAD4B_SCP_Site_Profile::site_uuid().'|vendor|107');
$GLOBALS['options'][$recoveryLease107]='another_finalizer';
$blockedRecoveryCancel=MAD4B_SCP_Activity_Sync_Runtime::cancel(array_merge(scope(107),
 array('confirmed'=>true,'expected_operation_sha256'=>$c7['operation_sha256'])));
ck(is_wp_error($blockedRecoveryCancel)&&$blockedRecoveryCancel->get_error_code()==='mad4b_sync_recovery_busy',
 'Cancel raced an existing reconcile/finalize worker');
unset($GLOBALS['options'][$recoveryLease107]);
$blockedCancel=MAD4B_SCP_Activity_Sync_Runtime::cancel(array_merge(scope(107),
 array('confirmed'=>true,'expected_operation_sha256'=>$c7['operation_sha256'])));
ck(is_wp_error($blockedCancel)&&$blockedCancel->get_error_code()==='mad4b_sync_cancel_worker_active',
 'Cancellation raced a reserved execution worker');
unset($GLOBALS['options'][$workerLease]);
$cancellation=MAD4B_SCP_Activity_Sync_Runtime::cancel(array_merge(scope(107),
 array('confirmed'=>true,'expected_operation_sha256'=>$c7['operation_sha256'])));
ck(!is_wp_error($cancellation)&&$cancellation['archived']&&
 !array_key_exists($workerLease,$GLOBALS['options']),
 'Cancellation after zero-write worker release failed');
echo "PASS MSR02 deterministic conflict unlock and zero-write cancellation lease checks\n";

echo "PASS MSR02 failure-injected prewrite/postwrite journal checks and serialized recovery\n";

echo "PASS MSR02 durable checkpoint, WordPress/Drive CAS, stale plan, conflict, readback, uncertain-write recovery and archival\n";
