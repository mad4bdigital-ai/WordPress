import {planOriginalCoreTransportRecovery} from './original_dual_route_policy.mjs';
// MAD4B original-Core execution routes. Typed operations and original general
// shell are first-class peer routes. The general shell is preserved as a
// fallback CAPABILITY, never a fallback for a denied typed invocation.
// This module creates no identities, credentials, grants or signed evidence.
const HEX32=/^[a-f0-9]{32}$/;
const TYPED=Object.freeze([
 'verify_host_binding','register_native_executor',
 'reconcile_313_receipt','wpcli.execute'
]);
const TYPED_SET=new Set(TYPED);
const fail=(state,reason,operation=null)=>Object.freeze({
 contract:'mad4b.original-core-execution-result.v1',
 state,reason_code:reason,operation,
 verified:false,executed:false
});
const exact=(a,b,fields)=>fields.every(k=>
 typeof a?.[k]==='string'&&a[k].length>0&&a[k]===b?.[k]);
const BIND=['company','site_uuid','canonical_origin','environment',
 'transport_id','grant_id','target_host_id'];
const modeFor=op=>op==='maintenance.shell'?'general_shell_fallback':
 TYPED_SET.has(op)?'typed_original_core':null;

export const availableGovernedRoutes=()=>Object.freeze({
 typed:[...TYPED],general_shell_fallback:'maintenance.shell',
 fallback_condition:'A separately admitted original maintenance.shell job is mandatory',
 cross_route_replay:false,raw_shell_from_typed_request:false
});

export function createGovernedExecutionRoutes({core,typed={},generalShell}){
 if(!core||typeof core.readOriginalJob!=='function'||
    typeof core.verifyOriginalAdmission!=='function'||
    typeof core.claimOriginalJobOnce!=='function'||
    typeof core.verifyOriginalOperationEvidence!=='function'||
    typeof core.audit!=='function')
  throw Error('ORIGINAL_CORE_AUTHORITY_ADAPTERS_REQUIRED');
 return Object.freeze({
  routes:availableGovernedRoutes(),
  // No alternate dispatcher. Only the original Core can create the next job.
  // This bridge does not execute either the previous job or the replacement.
  async recoverPreDispatch({job_id}={}){
   if(!HEX32.test(job_id||''))return fail('DENIED','ORIGINAL_JOB_ID_INVALID','maintenance.shell');
   if(typeof core.readOriginalRecoveryEvidence!=='function'||
      typeof core.createOriginalRecoveryJobOnce!=='function')
     return fail('PARKED_PROVIDER_REVIEW_REQUIRED',
       'ORIGINAL_CORE_RECOVERY_ADAPTER_NOT_REGISTERED','maintenance.shell');
   let oldJob,record;
   try {
    oldJob=await core.readOriginalJob(job_id);
    record=await core.readOriginalRecoveryEvidence(job_id);
   }catch {
    return fail('PARKED_UNCERTAIN_NO_REPLAY',
      'ORIGINAL_RECOVERY_JOURNAL_UNAVAILABLE','maintenance.shell');
   }
   // This is recovery of an independently admitted original Shell operation,
   // not a way to turn a rejected typed/WP-CLI job into general Shell.
   const req=record?.request;
   if(!oldJob||oldJob.job_id!==job_id||
      oldJob.operation!=='maintenance.shell'||
      oldJob.environment!=='staging'||
      !exact(oldJob,oldJob,BIND)||
      !req||req.operation_contract!=='maintenance.shell'||
      req.company!==oldJob.company||req.site_uuid!==oldJob.site_uuid||
      req.origin!==oldJob.canonical_origin||
      req.environment!==oldJob.environment||
      req.host_id!==oldJob.target_host_id||
      req.command_sha256!==oldJob.command_sha256||
      record?.previous?.job_id!==job_id)
     return fail('DENIED','ORIGINAL_JOB_RECOVERY_SCOPE_MISMATCH','maintenance.shell');
   const plan=planOriginalCoreTransportRecovery(record);
   if(plan.state!=='CANDIDATE_REQUIRES_NEW_CORE_ADMISSION')
     return fail(plan.state,plan.reason,'maintenance.shell');
   // The provider choice is not authority. A Core transaction MUST validate
   // current 305/312 grant, exact source digest, provider, unclaimed state,
   // journal and unique replacement id before committing.
   let result;
   try {
    result=await core.createOriginalRecoveryJobOnce({
      original_job_id:job_id,
      operation:'maintenance.shell',
      provider:plan.provider,
      command_sha256:oldJob.command_sha256,
      site_uuid:oldJob.site_uuid,
      company:oldJob.company,
      canonical_origin:oldJob.canonical_origin,
      environment:oldJob.environment,
      target_host_id:oldJob.target_host_id
    });
   }catch {
    return fail('PARKED_UNCERTAIN_NO_REPLAY',
      'ORIGINAL_RECOVERY_JOB_CREATION_UNCERTAIN','maintenance.shell');
   }
   if(!result||result.state!=='ORIGINAL_CORE_RECOVERY_JOB_COMMITTED'||
      !HEX32.test(result.new_job_id||'')||result.new_job_id===job_id||
      result.original_job_id!==job_id||result.operation!=='maintenance.shell'||
      result.provider!==plan.provider||result.command_sha256!==oldJob.command_sha256||
      result.company!==oldJob.company||result.site_uuid!==oldJob.site_uuid||
      result.canonical_origin!==oldJob.canonical_origin||
      result.environment!==oldJob.environment||
      result.target_host_id!==oldJob.target_host_id||
      result.executed!==false)
     return fail('PARKED_UNCERTAIN_NO_REPLAY',
       'ORIGINAL_CORE_RECOVERY_COMMIT_UNVERIFIED','maintenance.shell');
   try {
    await core.audit({job_id,operation:'maintenance.shell',
      state:'ORIGINAL_RECOVERY_NEW_JOB_COMMITTED_NOT_DISPATCHED',
      recovery_job_id:result.new_job_id,provider:plan.provider});
   }catch {
    return fail('PARKED_UNCERTAIN_NO_REPLAY',
      'ORIGINAL_RECOVERY_AUDIT_UNCERTAIN','maintenance.shell');
   }
   return Object.freeze({
     contract:'mad4b.original-core-execution-result.v1',
     state:'NEW_ORIGINAL_CORE_JOB_READY',operation:'maintenance.shell',
     original_job_id:job_id,new_job_id:result.new_job_id,
     provider:plan.provider,verified:false,executed:false,
     requires_original_dispatcher:true,replay_permitted:false
   });
  },
  async execute({job_id,operation}){
   if(!HEX32.test(job_id)||!modeFor(operation))
    return fail('DENIED','OPERATION_OR_JOB_ID_INVALID',operation);
   // The request contains no argv, credentials, host, site or shell command.
   // All target/command fields are fetched from the original Core.
   let job,admission;
   try{
    job=await core.readOriginalJob(job_id);
    const originalCoreOperation=operation==='maintenance.shell'?
      'maintenance.shell':'company.remote.wpcli.full';
    if(!job||job.job_id!==job_id||
       job.operation!==originalCoreOperation||
       job.environment!=='staging'||!HEX32.test(job.transport_id||'')||
       !HEX32.test(job.grant_id||'')||
       !exact(job,job,BIND))
     return fail('DENIED','ORIGINAL_JOB_OR_STAGING_SCOPE_INVALID',operation);
    admission=await core.verifyOriginalAdmission({job_id,operation});
   }catch{
    return fail('PARKED_PROVIDER_REVIEW_REQUIRED','ORIGINAL_CORE_READ_UNAVAILABLE',operation);
   }
   if(!admission||admission.verified!==true||
      admission.eligible!==true||
      admission.original_core_source_verified!==true||
      admission.verified_action!==operation||
      admission.grant_state!=='CENTRAL_OPERATION_SCOPE_GRANTED'||
      admission.transport_revoked!==false||
      admission.delegate_revoked!==false||
      !exact(job,admission,BIND))
    return fail('DENIED','ORIGINAL_AUTHORITY_OR_EXACT_SITE_MISMATCH',operation);
   if(operation==='maintenance.shell'){
    if(admission.granted_operation!=='maintenance.shell'||
       admission.shell_fallback_allowed!==true)
      return fail('DENIED','INDEPENDENT_GENERAL_SHELL_GRANT_REQUIRED',operation);
    // Reuse the ALREADY INSTALLED central_dual_route_policy.mjs (vps_ssh).
    // No parallel shell entitlement or duplicate authorization model.
    if(typeof core.authorizeOriginalDualRouteShell!=='function')
      return fail('PARKED_PROVIDER_REVIEW_REQUIRED',
        'ORIGINAL_DUAL_ROUTE_SHELL_POLICY_UNAVAILABLE',operation);
    let shellDecision;
    try{
      shellDecision=await core.authorizeOriginalDualRouteShell({job_id,operation});
    }catch{
      return fail('PARKED_PROVIDER_REVIEW_REQUIRED',
        'ORIGINAL_DUAL_ROUTE_SHELL_POLICY_UNAVAILABLE',operation);
    }
    if(shellDecision?.contract!=='mad4b.central-execution-route-decision.v1'||
       shellDecision.status!=='AUTHORIZED_FOR_DISPATCH'||
       shellDecision.lane!=='vps_ssh'||
       shellDecision.execution_performed!==false||
       shellDecision.host_id!==job.target_host_id)
      return fail('DENIED','ORIGINAL_DUAL_ROUTE_SHELL_NOT_AUTHORIZED',operation);
   }else{
    // Bootstrap operations use the original 305 site-bound enrollment; only
    // actual WP-CLI execution requires an already certified Native.
    if(admission.granted_operation!=='company.remote.wpcli.full'||
       admission.original_enrollment_scope_valid!==true)
      return fail('DENIED','ORIGINAL_FULL_WPCLI_GRANT_REQUIRED',operation);
    if(operation==='register_native_executor'&&
       admission.independent_host_proof_verified!==true)
      return fail('DENIED','INDEPENDENT_HOST_PROOF_REQUIRED',operation);
    if(operation==='reconcile_313_receipt'&&
       (admission.original_job_claim_verified!==true||
        admission.original_312_verified!==true))
      return fail('DENIED','ORIGINAL_312_JOB_CLAIM_REQUIRED',operation);
    if(operation==='wpcli.execute'&&
       (admission.native_scope_valid!==true||
        admission.original_312_verified!==true||
        admission.native_enrollment_receipt_verified!==true))
      return fail('DENIED','CERTIFIED_NATIVE_AND_312_REQUIRED',operation);
   }
   const adapter=operation==='maintenance.shell'?
      generalShell?.executeOriginalShellJob:typed?.[operation];
   if(typeof adapter!=='function')
    return fail('PARKED_PROVIDER_REVIEW_REQUIRED',
      operation==='maintenance.shell'?'ORIGINAL_SHELL_PROVIDER_NOT_REGISTERED':
      'TYPED_PROVIDER_NOT_REGISTERED',operation);
   // A tool/security refusal never permits routing the same intent via Shell.
   // Dispatch uses the original atomic claim. 313 reconciliation does NOT
   // claim the already-executed job again: it reads the original claim and
   // its independent receipt, without resending argv or a Shell command.
   if(operation==='reconcile_313_receipt'){
    try{await core.audit({job_id,operation,state:'RECONCILE_EXISTING_CLAIM_READ_ONLY'})}
    catch{return fail('PARKED_UNCERTAIN_NO_REPLAY','RECONCILIATION_AUDIT_UNAVAILABLE',operation)}
   }else{
    let claimed;
    try {claimed=await core.claimOriginalJobOnce(job_id,job.command_sha256)}
    catch {return fail('PARKED_UNCERTAIN_NO_REPLAY','ORIGINAL_CLAIM_UNCERTAIN',operation)}
    if(claimed!==true)
     return fail('DENIED','ORIGINAL_JOB_ALREADY_CLAIMED',operation);
    try{await core.audit({job_id,operation,state:'CLAIMED'})}
    catch{return fail('PARKED_UNCERTAIN_NO_REPLAY','AUDIT_AFTER_CLAIM_UNCERTAIN',operation)}
   }
   let result;
   try{
    result=await adapter({job_id,operation,original_job:job,
     original_admission:admission});
   }catch{
    try{await core.audit({job_id,operation,state:'EXECUTION_UNCERTAIN_NO_REPLAY'})}catch{}
    return fail('PARKED_UNCERTAIN_NO_REPLAY','PROVIDER_OUTCOME_UNKNOWN',operation);
   }
   if(result?.state==='TOOL_POLICY_REJECTED'||
      result?.state==='PROVIDER_POLICY_REJECTED'){
    try{await core.audit({job_id,operation,state:'EXTERNAL_POLICY_REJECTED'})}catch{}
    return fail('PARKED_PROVIDER_REVIEW_REQUIRED','TOOL_OR_PROVIDER_POLICY_REJECTED',operation);
   }
   if(result?.state!=='EXECUTED' && result?.state!=='READ_VERIFIED'){
    try{await core.audit({job_id,operation,state:'OUTCOME_UNCERTAIN_NO_REPLAY'})}catch{}
    return fail('PARKED_UNCERTAIN_NO_REPLAY','PROVIDER_OUTCOME_UNCERTAIN',operation);
   }
   // Evidence is stage-specific. Requiring 313 for initial Host Proof
   // would deadlock enrollment; accepting Host Proof as a 313 receipt
   // would falsely certify WP-CLI. Keep the original Shell journal too.
   const evidenceKind=operation==='verify_host_binding'?'HOST_PROOF':
     operation==='register_native_executor'?'NATIVE_ENROLLMENT':
     operation==='maintenance.shell'?'ORIGINAL_SHELL_JOURNAL':'WPCLI_313';
   let proof;
   try{
    proof=await core.verifyOriginalOperationEvidence({job_id,operation,
      evidence_kind:evidenceKind,original_job:job,execution:result});
   }catch{
    return fail('PARKED_UNCERTAIN_NO_REPLAY',
      'INDEPENDENT_OPERATION_EVIDENCE_UNAVAILABLE',operation);
   }
   const evidenceValid=proof?.original_core_signature_verified===true&&
     proof?.evidence_kind===evidenceKind&&
     proof?.job_id===job_id&&
     proof?.command_sha256===job.command_sha256&&
     exact(job,proof,BIND)&&(
       (evidenceKind==='HOST_PROOF'&&proof.independent_host_proof_verified===true)||
       (evidenceKind==='NATIVE_ENROLLMENT'&&proof.durable_native_enrollment_verified===true)||
       (evidenceKind==='WPCLI_313'&&proof.original_313_verified===true&&
          proof.independent_host_readback_verified===true)||
       (evidenceKind==='ORIGINAL_SHELL_JOURNAL'&&
          proof.original_shell_journal_verified===true&&
          (admission.shell_mutates!==true||
             proof.independent_host_readback_verified===true))
     );
   if(!evidenceValid)
    return fail('PARKED_UNVERIFIED_READBACK',
      'ORIGINAL_OPERATION_EVIDENCE_OR_SCOPE_NOT_VERIFIED',operation);
   try{await core.audit({job_id,operation,state:'EXECUTION_AND_ORIGINAL_EVIDENCE_VERIFIED'})}
   catch{return fail('PARKED_UNCERTAIN_NO_REPLAY','FINAL_AUDIT_UNCERTAIN',operation)}
   return Object.freeze({
    contract:'mad4b.original-core-execution-result.v1',
    state:'VERIFIED',reason_code:'ORIGINAL_OPERATION_EVIDENCE_VERIFIED',
    operation,job_id,route:modeFor(operation),
    verified:true,executed:result.state==='EXECUTED'
   });
  }
 });
}

// Full WP-CLI is certified ONLY from independent durable enrollment AND
// an original, exact-scope read canary/313 receipt. Never by code deploy or CI.
export function evaluateFullWpcliLiveCertification({site,channel,native,canary,receipt}={}){
 const no=reason=>Object.freeze({approved:false,reason_code:reason});
 if(!site||site.environment!=='staging'||!channel||!native||
   !exact(site,channel,['company','site_uuid','canonical_origin','environment'])||
   channel.state!=='ENROLLED'||channel.full_wpcli_active!==true||
   channel.host_authority_verified!==true)
  return no('SITE_OR_CHANNEL_NOT_ENROLLED');
 if(native.registration_state!=='ENROLLED'||native.execute_registered!==true||
   native.original_core_312_bound!==true||native.grant_verifier_bound!==true||
   native.durable_enrollment_receipt_verified!==true||
   native.host_id!==channel.host_id||native.transport_id!==channel.transport_id||
   native.site_uuid!==site.site_uuid)
  return no('ORIGINAL_NATIVE_REGISTRATION_NOT_CERTIFIED');
 if(!canary||canary.original_core_claim_verified!==true||
   canary.argv_digest_verified!==true||canary.command_kind!=='wp.option.get.home'||
   canary.state!=='EXECUTED'||canary.company!==site.company||
   canary.site_uuid!==site.site_uuid||canary.transport_id!==channel.transport_id||
   canary.grant_id!==channel.full_wpcli_grant_id)
  return no('ORIGINAL_READ_CANARY_NOT_VERIFIED');
 if(!receipt||receipt.original_313_verified!==true||
   receipt.independent_host_readback_verified!==true||
   receipt.native_executor_certified!==true||
   receipt.job_id!==canary.job_id||
   receipt.command_sha256!==canary.command_sha256||
   receipt.company!==site.company||receipt.site_uuid!==site.site_uuid||
   receipt.transport_id!==channel.transport_id||
   receipt.grant_id!==canary.grant_id||
   receipt.readback_origin!==site.canonical_origin)
  return no('INDEPENDENT_313_READBACK_REQUIRED');
 return Object.freeze({approved:true,reason_code:'ORIGINAL_FULL_WPCLI_LIVE_CERTIFIED',
   site_uuid:site.site_uuid,company:site.company,environment:'staging'});
}
