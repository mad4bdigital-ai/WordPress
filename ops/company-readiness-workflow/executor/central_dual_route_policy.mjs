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
  const mutate=request.intent!=="read"&&request.intent!=="diagnostic";
  const grant=context.grant||{};
  if(grant.principal_id!==request.principal_id||grant.authorization_ref!==request.authorization_ref||grant.environment!==request.environment||grant.revoked===true)return deny("GRANT_NOT_BOUND");
  if(mutate&&!grant.allow_mutations)return deny("MUTATION_NOT_AUTHORIZED");
  if(request.environment==="production"&&mutate&&!grant.allow_production_mutations)return deny("PRODUCTION_NOT_AUTHORIZED");
  if(lane==="registered_executor"){
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
  if(grant.host_id!==request.host_id)return deny("SSH_GRANT_NOT_HOST_BOUND");
  if(mutate&&(!context.audit_journal_ready||!context.rollback_planned))return deny("SSH_MUTATION_PRECONDITIONS_MISSING");
  if(request.target_scope==="wordpress"&&(!request.site_uuid||!request.origin||grant.site_uuid!==request.site_uuid||grant.origin!==request.origin))return deny("SITE_SCOPE_NOT_VERIFIED");
  return {contract:"mad4b.central-execution-route-decision.v1",status:"AUTHORIZED_FOR_DISPATCH",lane,host_id:request.host_id,requires_readback:mutate,requires_journal:true,execution_performed:false,mcp_health_not_required:true};
}
export {routeDual};
