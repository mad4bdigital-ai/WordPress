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
