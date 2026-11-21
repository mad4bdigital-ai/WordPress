<?php
/** Negative-only: original first-party Operator gate never reaches actual grants. */
require __DIR__ . '/operator-grants-convergence-smoke.php';
function proof($condition, $label) {
 if (!$condition) {fwrite(STDERR,"FAIL: $label\n");exit(2);}
 echo "PASS $label\n";
}
$gateway='MAD4B_SCP_Operator_Grant_Convergence';
$initial=$gateway::execute(array('action'=>'plan'));
proof(is_array($initial),'policy_bound_plan_exists');
proof(($initial['policy_sha256']??'')===str_repeat('d',64),'policy_digest_exposed_without_approval');
proof($initial['canonical_plan_sha256']===$initial['developer_policy_v2']['legacy_plan_sha256'],'canonical_v1_plan_unchanged');
proof(MAD4B_SCP_Developer_Authority::$apply_calls===0,'no_canonical_apply_during_plan');
$input=array(
 'action'=>'apply',
 'confirmation'=>$gateway::CONFIRM,
 'expected_plan_sha256'=>$initial['canonical_plan_sha256'],
 'expected_source_commit_sha'=>$initial['source_commit_sha'],
 'expected_site_uuid'=>$initial['site_uuid'],
 'expected_environment'=>$initial['target_environment'],
 'expected_profile_revision'=>$initial['profile_revision'],
 'expected_profile_digest'=>$initial['profile_digest'],
);
$missing=$gateway::execute($input);
proof(is_wp_error($missing)&&$missing->get_error_code()==='mad4b_operator_grants_policy_changed',
      'missing_governance_digest_fails_closed');
$input['expected_policy_sha256']='not-a-sha';
$bad=$gateway::execute($input);
proof(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_operator_grants_policy_changed',
      'invalid_governance_digest_fails_closed');
$input['expected_policy_sha256']=str_repeat('e',64);
$mismatch=$gateway::execute($input);
proof(is_wp_error($mismatch)&&$mismatch->get_error_code()==='mad4b_operator_grants_policy_changed',
      'unreviewed_policy_digest_fails_closed');
$input['expected_policy_sha256']=$initial['policy_sha256'];
$GLOBALS['registered_policy_digest']=str_repeat('f',64);
$changed=$gateway::execute($input);
proof(is_wp_error($changed)&&$changed->get_error_code()==='mad4b_operator_grants_policy_changed',
      'policy_drift_after_plan_denied');
unset($GLOBALS['registered_policy_digest']);
$GLOBALS['test_track_policy_calls']=0;
$GLOBALS['test_policy_flip_on_call']=3;
$near_apply=$gateway::execute($input);
proof(is_wp_error($near_apply)
 && $near_apply->get_error_code()==='mad4b_operator_grants_policy_recheck_changed',
 'policy_drift_on_final_pre_apply_readback_denied');
proof($GLOBALS['test_track_policy_calls']>=3,'final_pre_apply_policy_read_reached');
unset($GLOBALS['test_policy_flip_on_call'],$GLOBALS['test_track_policy_calls']);
proof(MAD4B_SCP_Developer_Authority::$apply_calls===0,'all_negative_cases_before_canonical_apply');
echo 'OPERATOR_EXACT_POLICY_BINDING_HERMETIC: PASS (no grant writes, no signed release)'.PHP_EOL;
