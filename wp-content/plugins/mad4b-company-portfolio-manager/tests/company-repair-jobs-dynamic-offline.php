<?php
/** Pure, offline dynamic-registry and no-replay contract acceptance. */
define('ABSPATH','/');
$includes=dirname(__DIR__).'/includes';
require $includes.'/class-mad4b-company-repair-capability-discovery.php';
require $includes.'/class-mad4b-company-repair-job-contract.php';
$registry='MAD4B_Company_Repair_Capability_Discovery_V1';
$c='MAD4B_Company_Repair_Job_Contract_V1';
$ok=[];$test=static function(string $k,bool $v)use(&$ok){$ok[$k]=$v;};
$check='new_capability_check';
$profile=[
 'operation'=>'custom.company.repair.future','provider'=>'trusted_custom_source_provider',
 'authority_family'=>'original_305','target_scope'=>'wordpress',
 'command_format'=>'wpcli.argv.json.v1',
 'evidence_contract'=>'mad4b.dynamic-future-provider.v3',
 'provider_readback_contract'=>'mad4b.dynamic-future-provider.v3',
 'registry_source_trusted'=>true,
 'repair_support'=>[
  'contract'=>$registry::CONTRACT,
  'operation'=>'custom.company.repair.future',
  'provider'=>'trusted_custom_source_provider',
  'evidence_contract'=>'mad4b.dynamic-future-provider.v3',
  'resource_scope'=>'exact_site_resource',
  'mutation_authorized'=>false,'independent_313_required'=>true,
  'rollback_via_new_original_job'=>true,
  'supported_checks'=>[$check],
 ],
];
$one=$registry::inspect_profiles($check,[$profile]);
$test('new_operation_not_hardcoded',($one['state']??'')==='EXACT_CAPABILITY_DISCOVERED'
 &&($one['selected']['operation']??'')==='custom.company.repair.future');
$test('discovery_never_executes',($one['ready_for_first_mutation']??true)===false
 &&($one['native_305_issued']??true)===false);
$test('unrelated_check_unmatched',$registry::inspect_profiles('another_check',[$profile])['candidate_count']===0);
$test('no_profile_unmatched',$registry::inspect_profiles($check,[])['selected']===null);
$second=$profile;$second['operation']='new.account.provider.v99';$second['provider']='source_company_second';
$second['repair_support']['operation']=$second['operation'];
$second['repair_support']['provider']=$second['provider'];
$ambiguous=$registry::inspect_profiles($check,[$profile,$second]);
$test('ambiguity_requires_governed_resolution',
 ($ambiguous['state']??'')==='MULTIPLE_PROVIDERS_NEED_EXPLICIT_GOVERNED_RESOLUTION'
 &&$ambiguous['selected']===null);
$reverse=$registry::inspect_profiles($check,[$second,$profile]);
$test('fingerprint_stable_order',
 $reverse['capability_fingerprint']===$ambiguous['capability_fingerprint']);
$test('new_capability_changes_fingerprint',
 $one['capability_fingerprint']!==$ambiguous['capability_fingerprint']);
$tests=[
 'untrusted_source'=>static function(&$p){$p['registry_source_trusted']=false;},
 'unsupported_authority'=>static function(&$p){$p['authority_family']='certified_operation';},
 'host_not_wordpress'=>static function(&$p){$p['target_scope']='host';},
 'generic_transport'=>static function(&$p){$p['evidence_contract']='original_305_claim';$p['provider_readback_contract']='original_305_claim';$p['repair_support']['evidence_contract']='original_305_claim';},
 'unsupported_command'=>static function(&$p){$p['command_format']='maintenance.shell.text.v1';},
 'provider_not_matching'=>static function(&$p){$p['repair_support']['provider']='stolen_provider';},
 'operation_not_matching'=>static function(&$p){$p['repair_support']['operation']='spoofed.other.provider';},
 'readback_wrong_contract'=>static function(&$p){$p['provider_readback_contract']='spoofed.contract.v1';},
 'missing_rollback'=>static function(&$p){$p['repair_support']['rollback_via_new_original_job']=false;},
 'missing_independent_readback'=>static function(&$p){$p['repair_support']['independent_313_required']=false;},
 'claims_execution'=>static function(&$p){$p['repair_support']['mutation_authorized']=true;},
 'wrong_resource_scope'=>static function(&$p){$p['repair_support']['resource_scope']='global_site';},
 'duplicated_checks'=>static function(&$p){$p['repair_support']['supported_checks']=['new_capability_check','new_capability_check'];},
 'bad_check_text'=>static function(&$p){$p['repair_support']['supported_checks']=['../new_capability_check'];},
 'wrong_capability_contract'=>static function(&$p){$p['repair_support']['contract']='made.up.contract';},
];
foreach($tests as $name=>$tamper) {
 $p=$profile;$tamper($p);
 $out=$registry::inspect_profiles($check,[$p]);
 $test('reject_'.$name,$out['candidate_count']===0 &&$out['selected']===null);
}
$duplicate=$registry::inspect_profiles($check,[$profile,$profile]);
$test('duplicate_operation_not_picked',$duplicate['selected']===null
 &&$duplicate['candidate_count']===0);

$scope=[
 'company_code'=>'wayfatours','environment'=>'production',
 'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
 'canonical_origin'=>'https://wayfatours.com',
 'incident_code'=>'generic_source_conflict','resource_key'=>'incident:generic_source_conflict',
 'source_plan_sha256'=>str_repeat('a',64)
];
$job=$c::fresh($scope,10000);
$legacy=$job;
$job['target_check']=$check;
$job['ownership_scope']='mixed';
$job['evidence_lane']='target_local';
$job['native_operation']=$profile['operation'];
$job['provider_resolution_sha256']=$one['capability_fingerprint'];
$job['required_dependencies']=[];
$job['acceptance']='Exact independently signed original readback required';
$job['owner']='Trusted original provider';
$job['repair_strategy']='inspect before change';
$job['immutable_plan_sha256']=str_repeat('e',64);
$job['immutable_descriptor_sha256']=$c::descriptor($job);
$test('new_descriptor_nonempty',$c::sha($job['immutable_descriptor_sha256']));
$test('create_journal_valid',$job!==[]&&$c::valid_chain($job));
$test('stable_scope_id',$c::key($scope)===$job['id']);
$test('legacy_zero_revision_readable_for_reconcile',$c::valid_chain($legacy));
$test('legacy_unbound_does_not_advance',
   !$c::valid_chain($c::append($legacy,'BASELINE_COLLECTED',[],10001)));
$bad=$job;$bad['native_operation']='other.provider.operation';
$test('descriptor_tamper_operation_rejected',!$c::valid_chain($bad));
$bad=$job;$bad['provider_resolution_sha256']=str_repeat('f',64);
$test('descriptor_tamper_provider_fingerprint_rejected',!$c::valid_chain($bad));
$bad=$job;$bad['acceptance']='skip signed readback';
$test('descriptor_tamper_acceptance_rejected',!$c::valid_chain($bad));
$three=$registry::inspect_profiles($check,[$profile,$profile,$profile]);
$test('third_duplicate_provider_never_resurrects', $three['candidate_count']===0
  &&$three['selected']===null);
$other=$scope;$other['environment']='staging';
$test('environment_isolation',$c::key($other)!==$job['id']);
$other=$scope;$other['site_uuid']='b3a7cc36-db7e-4a14-8456-b41857def05c';
$test('target_identity_isolation',$c::key($other)!==$job['id']);
$other=$scope;$other['source_plan_sha256']=str_repeat('b',64);
$test('source_plan_isolation',$c::key($other)!==$job['id']);
$baseline=[
 'contract'=>$c::BASELINE,
 'company_code'=>$scope['company_code'],'environment'=>$scope['environment'],
 'site_uuid'=>$scope['site_uuid'],'canonical_origin'=>$scope['canonical_origin'],
 'resource_key'=>$scope['resource_key'],
 'source_plan_sha256'=>$scope['source_plan_sha256'],
 'before_sha256'=>str_repeat('a',64),'rollback_sha256'=>str_repeat('a',64),
 'expected_after_sha256'=>str_repeat('b',64),
 'resource_manifest_sha256'=>str_repeat('c',64),
 'evidence_level'=>'LOCAL_WORDPRESS_UNSIGNED','observed_epoch'=>9999
];
$test('baseline_exact_valid',$c::baseline_valid($baseline,$job,10000));
$bad=$baseline;$bad['site_uuid']='another';
$test('baseline_cross_site_rejected',!$c::baseline_valid($bad,$job,10000));
$bad=$baseline;$bad['rollback_sha256']=str_repeat('1',64);
$test('baseline_wrong_rollback_rejected',!$c::baseline_valid($bad,$job,10000));
$bad=$baseline;$bad['expected_after_sha256']=$bad['before_sha256'];
$test('baseline_noop_requires_separate_acceptance',!$c::baseline_valid($bad,$job,10000));
$bad=$baseline;$bad['observed_epoch']=1;
$test('baseline_stale_rejected',!$c::baseline_valid($bad,$job,10000));
$bad=$baseline;$bad['evidence_level']='FAKE_SIGNED_ORIGINAL_313';
$test('baseline_cannot_claim_signed',!$c::baseline_valid($bad,$job,10000));
$bad=$baseline;$bad['observed_epoch']=10061;
$test('baseline_future_timestamp_rejected',!$c::baseline_valid($bad,$job,10000));
$next=$c::append($job,'BASELINE_COLLECTED',[
 'before_sha256'=>$baseline['before_sha256'],
 'rollback_sha256'=>$baseline['rollback_sha256'],
 'expected_after_sha256'=>$baseline['expected_after_sha256'],
 'resource_manifest_sha256'=>$baseline['resource_manifest_sha256']
],10000);
$next['baseline']=$baseline;
$test('baseline_event_valid',$c::valid_chain($next));
$bad=$next;$bad['baseline']['before_sha256']=str_repeat('9',64);
$test('baseline_tampering_detected',!$c::valid_chain($bad));
$bad=$next;$bad['events'][0]['facts']['before_sha256']=str_repeat('9',64);
$test('event_tampering_detected',!$c::valid_chain($bad));
$bad=$next;$bad['revision']++;
$test('revision_tamper_detected',!$c::valid_chain($bad));
$bad=$next;$bad['last_event_sha256']=str_repeat('3',64);
$test('journal_hash_tamper_detected',!$c::valid_chain($bad));
$bad=$next;$bad['company_code']='otherco';
$test('company_scope_tamper_detected',!$c::valid_chain($bad));
$prepared=$c::append($next,'BASELINE_PREPARED',['provider'=>'dynamic'],10001);
$test('ready_for_original_owner_no_auto_dispatch',$c::valid_chain($prepared)
 &&$prepared['state']==='AWAITING_ORIGINAL_305_JOB'
 &&!$prepared['original_305_submitted_by_repair_job']);
$reject=$c::append($prepared,'BASELINE_COLLECTED',[],10002);
$test('cannot_repeat_baseline',($reject['error']??'')==='INVALID_TRANSITION');
$original='a1b2c3d4e5f678901234567890abcdef';
$bound=$c::append($prepared,'BIND_ORIGINAL_JOB',
 ['original_job_id'=>$original,'command_sha256'=>str_repeat('d',64)],10002);
$bound['original_job_id']=$original;$bound['expected_command_sha256']=str_repeat('d',64);
$test('original_job_bound_to_journal',$c::valid_chain($bound));
$bad=$bound;$bad['original_job_id']=str_repeat('a',32);
$test('original_job_rebinding_tamper_detected',!$c::valid_chain($bad));
$reject=$c::append($bound,'BIND_ORIGINAL_JOB',[],10003);
$test('cannot_bind_second_original_job',($reject['error']??'')==='INVALID_TRANSITION');
$uncertain=$c::append($bound,'OBSERVE_UNKNOWN_OUTCOME',
 ['execution_state'=>'unknown'],10003);
$test('uncertain_effect_is_not_resubmission',$c::valid_chain($uncertain)
 &&$uncertain['state']==='EXECUTION_OUTCOME_UNKNOWN'
 &&!$uncertain['original_305_submitted_by_repair_job']);
$verified=$c::append($uncertain,'OBSERVE_SIGNED_REPAIR_313',[
 'signed_receipt_sha256'=>str_repeat('e',64),
 'observed_after_sha256'=>str_repeat('b',64),
],10004);
$verified['signed_repair_313_sha256']=str_repeat('e',64);
$verified['observed_after_sha256']=str_repeat('b',64);
$test('signed_finished_state_requires_matching_journal',$c::valid_chain($verified)
 &&$verified['state']==='RESOURCE_REPAIR_313_VERIFIED');
$bad=$verified;$bad['signed_repair_313_sha256']=str_repeat('f',64);
$test('forged_receipt_digest_detected',!$c::valid_chain($bad));
$bad=$verified;$bad['observed_after_sha256']=str_repeat('f',64);
$test('forged_signed_postimage_detected',!$c::valid_chain($bad));
$reject=$c::append($verified,'BIND_ORIGINAL_JOB',[],10005);
$test('terminal_state_cannot_redispatch',($reject['error']??'')==='INVALID_TRANSITION');
$drift=$c::append($bound,'OBSERVE_SIGNED_DIGEST_MISMATCH',[
 'signed_receipt_sha256'=>str_repeat('e',64),
 'observed_after_sha256'=>str_repeat('f',64)
],10004);
$drift['signed_repair_313_sha256']=str_repeat('e',64);
$drift['observed_after_sha256']=str_repeat('f',64);
$test('signed_mismatch_is_hold',$c::valid_chain($drift)
 &&$drift['state']==='POSTCONDITION_DRIFT_HOLD');
$rollback=$c::append($drift,'REQUEST_ROLLBACK',['new_original_job_required'=>true],10005);
$test('rollback_requires_distinct_original_job',$rollback['state']==='ROLLBACK_REQUIRED'
 &&!$rollback['execution_authorized_by_repair_job']);
$bad=$job;$bad['execution_authorized_by_repair_job']=true;
$test('no_forged_execution_authority',!$c::valid_chain($bad));
$bad=$job;$bad['original_305_submitted_by_repair_job']=true;
$test('no_forged_original_submission',!$c::valid_chain($bad));
echo json_encode(['pass'=>!in_array(false,$ok,true),
 'assertions'=>count($ok),
 'failed'=>array_keys(array_filter($ok,fn($x)=>!$x)),
 'new_dynamic_operation'=>$one['selected']['operation'],
 'discovery_state'=>$one['state']],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$ok,true))exit(1);
