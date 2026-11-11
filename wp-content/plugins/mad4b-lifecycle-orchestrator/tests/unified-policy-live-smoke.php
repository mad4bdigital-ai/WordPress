<?php
$base=['event_type'=>'manual'];
$cases=[
 'seo'=>['goal'=>'seo','target_kind'=>'post','target_id'=>67881]+$base,
 'media'=>['goal'=>'media','target_kind'=>'attachment','target_id'=>67890]+$base,
 'content'=>['goal'=>'content','target_kind'=>'content_job','job_id'=>'f09a022c-dcfa-4e85-9302-9a2248bd25a4']+$base,
 'company'=>['goal'=>'company','target_kind'=>'company','company_code'=>'wayfatours','representation_mode'=>'central_admin_proxy']+$base,
];
$report=['contract'=>'mad4b.unified-governed-lifecycle.live-smoke.v1','version'=>'1.0.0-staging'];
foreach($cases as $name=>$input) {
 $out=MAD4B_Lifecycle_Orchestrator::policy($input);
 if(is_wp_error($out)){$report[$name]=['error'=>$out->get_error_code()];continue;}
 $steps=$out['steps'];$bad=[];
 foreach($steps as $step)
    if($step['execution_kind']==='write' && ($step['state']==='READY'||$step['verified']||$step['mutation_performed']))$bad[]=$step['id'];
 $report[$name]=[
    'state'=>$out['state'],'steps'=>count($steps),
    'ready'=>$out['ready_independent_steps'],
    'pending'=>$out['pending_steps'],
    'unsafe_write_ready_steps'=>$bad,
    'provider_write_executed'=>$out['provider_write_executed'],
    'native_verification_complete'=>$out['native_verification_complete'],
 ];
}
foreach([
 'mad4b_nhi_identity_required'=>'BLOCKED_AUTHORITY',
 'mad4b_content_job_commit_uncertain'=>'NEEDS_RECONCILIATION',
 'STAGING_WPCLI_BUSY'=>'RECOVERING',
 'provider_dependency_missing'=>'BLOCKED_DEPENDENCY'
] as $err=>$expected) {
 $a=MAD4B_Unified_Governed_Lifecycle_Policy::classify_error($err);
 $report['classifier'][$err]=['state'=>$a['state'],'expected'=>$expected,'blind_write_retry'=>$a['retry_write']];
}
$report['policy_ability_registered']=wp_has_ability('mad4b/lifecycle-policy-plan');
echo wp_json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
