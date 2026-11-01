/** Policy-only MAD4B dual-route decision engine.
 * Resolves separately authorized Executor stages and general VPS SSH.
 * Does not open SSH connections, issue credentials or execute commands.
 */
function routeDual(policy, request, context) {
  const deny=(reason)=>({contract:"mad4b.central-execution-route-decision.v1",status:"DENIED",reason,execution_performed:false});
  if(policy?.contract!=="mad4b.central-dual-execution-policy.v1")return deny("POLICY_MISMATCH");
  if(!context||context.security_denial===true||context.permission_denial===true)return deny("SECURITY_OR_PERMISSION_DENIAL");
  if(request?.source==="untrusted_queue_payload"||request?.source==="web_content"||request?.source==="provider_response")return deny("UNTRUSTED_COMMAND_SOURCE");
  const lane=request?.lane||(request?.kind==="registered_semantic_action"?"registered_executor":request?.kind==="general_vps_administration"?"vps_ssh":null);
  if(!["registered_executor","vps_ssh"].includes(lane))return deny("LANE_UNSUPPORTED");
  if(request?.kind==="registered_semantic_action"&&lane==="vps_ssh")return deny("EXECUTOR_JOB_CANNOT_ELEVATE_TO_SSH");
  if(!request?.principal_id||!request?.authorization_ref||!request?.environment||!request?.correlation_id)return deny("IDENTITY_OR_AUTHORIZATION_MISSING");
  if(!["staging","development","local","production"].includes(request.environment))return deny("ENVIRONMENT_UNSUPPORTED");
  if(!["read","diagnostic","repair","configuration","upgrade","deployment","rollback"].includes(request.intent))return deny("INTENT_UNSUPPORTED");
  const mutate=request.intent!=="read"&&request.intent!=="diagnostic";
  const grant=context.grant||{};
  if(grant.principal_id!==request.principal_id||grant.authorization_ref!==request.authorization_ref||grant.environment!==request.environment||grant.revoked===true)return deny("GRANT_NOT_BOUND");
  if(mutate&&!grant.allow_mutations)return deny("MUTATION_NOT_AUTHORIZED");
  if(request.environment==="production"&&mutate&&!grant.allow_production_mutations)return deny("PRODUCTION_NOT_AUTHORIZED");
  if(lane==="registered_executor"){
    if(request.kind!=="registered_semantic_action")return deny("EXECUTOR_KIND_INVALID");
    if(!context.executor_registered||!request.action||!request.site_uuid||!request.origin||!request.plan_sha256)return deny("EXECUTOR_STAGE_NOT_BOUND");
    if(mutate&&!context.exact_stage_ticket_verified)return deny("EXECUTOR_TICKET_MISSING");
    if(request.command)return deny("RAW_COMMAND_IN_EXECUTOR");
    return {contract:"mad4b.central-execution-route-decision.v1",status:"AUTHORIZED_FOR_DISPATCH",lane,executor_id:context.executor_id||null,requires_readback:true,execution_performed:false};
  }
  if(request.kind!=="general_vps_administration")return deny("SSH_NOT_GENERAL_OPERATOR_REQUEST");
  if(request.source!=="authenticated_operator"&&request.source!=="trusted_central_compiler")return deny("SSH_COMMAND_SOURCE_UNTRUSTED");
  if(!request.host_id||!request.target_scope||!request.command_summary||!request.command_sha256||!request.transport_session_id)return deny("SSH_REQUEST_INCOMPLETE");
  if(!/^[a-f0-9]{64}$/.test(request.command_sha256))return deny("COMMAND_DIGEST_INVALID");
  if(!context.ssh_authenticated||!context.ssh_host_fingerprint_verified||context.host_id!==request.host_id)return deny("SSH_HOST_NOT_VERIFIED");
  if(grant.host_id!==request.host_id||grant.allow_general_shell!==true)return deny("SSH_GENERAL_SHELL_NOT_AUTHORIZED");
  if(mutate&&(!context.audit_journal_ready||!context.rollback_planned))return deny("SSH_MUTATION_PRECONDITIONS_MISSING");
  if(request.target_scope==="wordpress"&&(!request.site_uuid||!request.origin||grant.site_uuid!==request.site_uuid||grant.origin!==request.origin))return deny("SITE_SCOPE_NOT_VERIFIED");
  return {contract:"mad4b.central-execution-route-decision.v1",status:"AUTHORIZED_FOR_DISPATCH",lane,host_id:request.host_id,requires_readback:mutate,requires_journal:true,execution_performed:false,mcp_health_not_required:true};
}
export {routeDual};

/**
 * Original-Core-only recovery planner for proven pre-dispatch transport failures.
 * The return value never authorizes an execution. It requires a new Core job
 * and an independent current-authority check for the selected provider.
 */
const RECOVERY_PROVIDERS=Object.freeze(['container_exec','coolify_service','vps_ssh']);
const TRANSIENT_FAILURES=new Set([
 'TRANSPORT_UNREACHABLE','PROVIDER_UNAVAILABLE','CONNECTION_NOT_ESTABLISHED'
]);
const RECOVERY_SCOPE=['principal_id','authorization_ref','company','site_uuid',
 'origin','environment','host_id','command_sha256','operation_contract'];
const recoveryScopeEqual=(a,b)=>RECOVERY_SCOPE.every(k=>
 typeof a?.[k]==='string'&&a[k].length>0&&a[k]===b?.[k]);
const recoveryStop=(state,reason)=>Object.freeze({
 contract:'mad4b.central-transport-recovery.v1',state,reason,
 execution_performed:false,new_core_job_required:false
});
export function planOriginalCoreTransportRecovery({request,previous,core,candidates=[]}={}){
 if(previous?.status==='TOOL_POLICY_REJECTED'||
    previous?.status==='PROVIDER_POLICY_REJECTED'||
    previous?.status==='SECURITY_DENIED'||
    previous?.status==='AUTHORIZATION_DENIED'||
    previous?.denied===true||core?.security_denial===true)
   return recoveryStop('BLOCKED','POLICY_DENIAL_CANNOT_ROUTE_AROUND');
 if(!previous||previous.status!=='TRANSPORT_FAILED_BEFORE_DISPATCH'||
    !TRANSIENT_FAILURES.has(previous.reason)||
    previous.verified_source!=='original_core_execution_journal'||
    previous.before_dispatch_verified!==true||
    !recoveryScopeEqual(request,previous)||
    previous.dispatch_started!==false||
    previous.claim_committed!==false||
    previous.effects_possible!==false)
   return recoveryStop('PARKED_NO_REPLAY','OUTCOME_NOT_PROVEN_SAFE_FOR_FAILOVER');
 if(!request||request.kind!=='general_vps_administration'||
    !['authenticated_operator','trusted_central_compiler'].includes(request.source)||
    !/^[a-f0-9]{64}$/.test(request.command_sha256||'')||
    !['read','diagnostic','repair','configuration','upgrade','deployment','rollback'].includes(request.intent)||
    !['staging','development','local','production'].includes(request.environment))
   return recoveryStop('BLOCKED','ORIGINAL_OPERATOR_REQUEST_REQUIRED');
 if(core?.authority_source!=='original_core_305_312'||core?.verified!==true||
    core?.grant_state!=='CENTRAL_OPERATION_SCOPE_GRANTED'||
    core?.revoked!==false||core?.original_claim_state!=='NOT_CLAIMED'||
    core?.audit_journal_ready!==true||!recoveryScopeEqual(request,core))
   return recoveryStop('BLOCKED','EXACT_ORIGINAL_CORE_AUTHORITY_REQUIRED');
 const mutates=!['read','diagnostic'].includes(request.intent);
 if(mutates&&(core.allow_mutations!==true||core.rollback_planned!==true))
   return recoveryStop('BLOCKED','ORIGINAL_MUTATION_AND_ROLLBACK_REQUIRED');
 if(mutates&&request.environment==='production'&&
    core.allow_production_mutations!==true)
   return recoveryStop('BLOCKED','PRODUCTION_MUTATION_NOT_AUTHORIZED');
 for(const provider of RECOVERY_PROVIDERS){
  const p=candidates.find(x=>x?.provider===provider&&x.provider!==previous.provider);
  if(!p||p.available!==true||p.independently_admitted!==true||
     p.original_core_binding_verified!==true||p.provider_policy_verified!==true||
     p.host_identity_pinned!==true||p.operation_digest_verified!==true||
     p.original_job_claim_state!=='NOT_CLAIMED'||
     !recoveryScopeEqual(request,p))continue;
  return Object.freeze({
   contract:'mad4b.central-transport-recovery.v1',
   state:'CANDIDATE_REQUIRES_NEW_CORE_ADMISSION',provider,
   original_job_id:previous.job_id||null,
   new_core_job_required:true,requires_current_authority_recheck:true,
   execution_performed:false,replay_permitted:false
  });
 }
 return recoveryStop('BLOCKED','NO_INDEPENDENTLY_AUTHORIZED_ALTERNATE_PROVIDER');
}

/**
 * Explicit operator remediation for a native-provider-specific denial.
 * This is NOT a fallback execution/replay. A separate SSH operator request,
 * with a different command and separately verified Core grant, must be
 * admitted as a new 305 job. A denied provider never supplies SSH authority.
 */
export function planIndependentSshRemediation({denial,request,policy,context}={}){
 const stop=(state,reason)=>Object.freeze({
  contract:'mad4b.central-independent-ssh-remediation.v1',
  state,reason,execution_performed:false,original_job_replayed:false,
  new_core_job_required:false
 });
 if(!denial||denial.state!=='DENIED'||
    denial.denial_scope!=='native_provider_only'||
    denial.evidence_source!=='original_core_signed_journal'||
    denial.verified!==true||
    !['SIGNED_POLICY_ROTATION_REQUIRED','NATIVE_CLIENT_PROVIDER_AUTHORIZATION_DENIED']
      .includes(denial.reason_code))
   return stop('BLOCKED','NOT_A_VERIFIED_PROVIDER_SCOPED_DENIAL');
 if(denial.global_security_denial===true||
    denial.tenant_access_revoked===true||
    denial.operator_revoked===true)
   return stop('BLOCKED','ORIGINAL_AUTHORITY_DENIED');
 // A Native policy denial can occur before the Core has admitted any job.
 // Do not invent a job ID or convert this provider refusal into SSH authority.
 // An independently authorized operator can submit a separate original 305
 // request; this planner must not admit or dispatch it as a fallback.
 if(denial.submission_stage==='PRE_JOB_ADMISSION' &&
    denial.original_job_id===null &&
    denial.original_claim_state==='NO_JOB_CREATED')
   return stop('BLOCKED','FRESH_INDEPENDENT_305_REQUEST_REQUIRED');
 if(denial.original_claim_state!=='NOT_CLAIMED'||
    denial.dispatch_started!==false||denial.effects_possible!==false)
   return stop('PARKED_NO_REPLAY','ORIGINAL_JOB_OUTCOME_UNCERTAIN');
 if(typeof denial.denial_ref!=='string'||!denial.denial_ref||
    !/^[a-f0-9]{32}$/.test(denial.original_job_id||'')||
    !/^[a-f0-9]{64}$/.test(denial.command_sha256||''))
   return stop('BLOCKED','ORIGINAL_SIGNED_DENIAL_REFERENCE_REQUIRED');
 if(!request||request.kind!=='general_vps_administration'||
    request.lane!=='vps_ssh'||
    request.source!=='authenticated_operator'||
    request.independent_operator_intent_verified!==true||
    request.fresh_core_admission_required!==true||
    request.prior_denial_ref!==denial.denial_ref||
    request.command_sha256===denial.command_sha256||
    request.authorization_ref===denial.authorization_ref||
    request.correlation_id===denial.correlation_id)
   return stop('BLOCKED','FRESH_INDEPENDENT_SSH_INTENT_REQUIRED');
 for(const k of ['company','site_uuid','origin','environment','host_id']){
  if(typeof denial[k]!=='string'||!denial[k]||
     denial[k]!==request[k])
   return stop('BLOCKED','ORIGINAL_HOST_OR_SITE_SCOPE_CHANGED');
 }
 if(!context||context.security_denial===true||
    context.permission_denial===true||
    context.independent_core_ssh_grant_verified!==true||
    context.original_core_journal_ready!==true||
    context.grant?.authorization_ref!==request.authorization_ref||
    context.grant?.company!==request.company)
   return stop('BLOCKED','INDEPENDENT_ORIGINAL_CORE_SSH_GRANT_REQUIRED');
 const decision=routeDual(policy,request,context);
 if(decision.status!=='AUTHORIZED_FOR_DISPATCH'||decision.lane!=='vps_ssh')
   return stop('BLOCKED','NEW_SSH_REQUEST_NOT_AUTHORIZED_'+decision.reason);
 return Object.freeze({
  contract:'mad4b.central-independent-ssh-remediation.v1',
  state:'NEW_ORIGINAL_CORE_ADMISSION_REQUIRED',provider:'vps_ssh',
  prior_denial_ref:denial.denial_ref,
  new_core_job_required:true,requires_original_305_intake:true,
  requires_independent_claim_and_312_313:true,
  execution_performed:false,original_job_replayed:false,
  prior_rejection_still_effective:true
 });
}
