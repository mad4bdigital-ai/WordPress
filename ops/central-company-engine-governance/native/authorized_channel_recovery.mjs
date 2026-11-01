/** MAD4B channel diagnosis and authorization-preserving recovery planning.
 * Pure read-only decisions. No connector calls, SSH, grants, writes or replay.
 * Transport failures can suggest another enrolled provider; tool policy
 * denials always remain denials across ALL channels.
 */
const POLICY_DENIED=new Set([
 'TOOL_POLICY_REJECTED','PROVIDER_POLICY_REJECTED','SECURITY_DENIED',
 'AUTHORIZATION_DENIED','PERMISSION_DENIED','OPERATOR_REVOKED',
 'HOST_POLICY_DENIED','GRANT_DENIED'
]);
const CONTRACT_ERRORS=new Set([
 'INVOCATION_SCHEMA_INVALID','REQUIRED_ARGUMENT_MISSING',
 'ARGUMENT_CONTRACT_MISMATCH','UNSUPPORTED_ARGUMENT',
 'COMMAND_FORMAT_INVALID'
]);
const CREDENTIAL_ERRORS=new Set([
 'SSH_KEY_NOT_ENROLLED','SSH_KEY_REVOKED','AUTHENTICATION_FAILED',
 'CREDENTIAL_EXPIRED','OAUTH_SCOPE_MISSING'
]);
const CONNECTIVITY_ERRORS=new Set([
 'TRANSPORT_UNREACHABLE','PROVIDER_UNAVAILABLE',
 'CONNECTION_NOT_ESTABLISHED','NETWORK_TIMEOUT_BEFORE_SEND'
]);
const ID=/^[a-z][a-z0-9_.-]{1,79}$/;
const HEX64=/^[a-f0-9]{64}$/;
const SCOPE=['company','site_uuid','canonical_origin','environment',
 'host_id','operation','command_sha256','authorization_ref'];
const exact=(a,b)=>SCOPE.every(k=>typeof a?.[k]==='string' &&
 a[k].length>0 && a[k]===b?.[k]);
const stop=(state,reason,category)=>Object.freeze({
 contract:'mad4b.authorized-channel-recovery.v1',
 state,reason_code:reason,category,execution_performed:false,
 previous_job_replayed:false,new_core_job_required:false
});

export function diagnoseInvocationFailure(failure={}){
 const code=typeof failure?.code==='string'?failure.code:'';
 // Explicit security and authorization denial always win, even when another
 // field reports an intermittent network problem.
 if(failure?.security_denial===true||failure?.permission_denial===true||
    POLICY_DENIED.has(code))
  return stop('BLOCKED','POLICY_DENIAL_CANNOT_ROUTE_AROUND','policy');
 if(CREDENTIAL_ERRORS.has(code))
  return stop('BLOCKED','CHANNEL_IDENTITY_ENROLLMENT_REQUIRED','credentials');
 if(CONTRACT_ERRORS.has(code))
  return stop('REPAIR_INVOCATION_CONTRACT',
    'USE_DECLARED_TOOL_SCHEMA_AND_EXACT_AUTHORIZED_KEY','schema');
 if(CONNECTIVITY_ERRORS.has(code)){
  if(failure?.verified_source!=='original_core_execution_journal' ||
     failure?.before_dispatch_verified!==true ||
     failure?.dispatch_started!==false ||
     failure?.claim_committed!==false ||
     failure?.effects_possible!==false)
   return stop('PARKED_NO_REPLAY',
     'TRANSPORT_OUTCOME_NOT_PROVEN_SAFE','uncertain');
  return stop('DISCOVER_AUTHORIZED_CHANNELS',
    'PRE_DISPATCH_TRANSPORT_FAILURE_VERIFIED','transport');
 }
 return stop('PARKED_NO_REPLAY','UNCLASSIFIED_OR_UNCERTAIN_OUTCOME','uncertain');
}

/**
 * Registered candidates are discovered by the caller's current provider
 * registry, not by static host IDs or an invented provider allowlist.
 * The output is only a plan: original Core must re-admit a fresh, exact job.
 */
export function planAuthorizedChannelAlternatives({
 request, failure, core, candidates=[]
}={}){
 const diagnosis=diagnoseInvocationFailure(failure);
 if(diagnosis.state==='REPAIR_INVOCATION_CONTRACT'){
  if(failure?.provider_received===false &&
     failure?.before_dispatch_verified===true &&
     failure?.effects_possible===false &&
     failure?.documented_input_schema_verified===true)
   return Object.freeze({...diagnosis,
    state:'REBUILD_SAME_CHANNEL_INVOCATION',
    requires_declared_tool_contract:true,requires_authority_recheck:true});
  return stop('PARKED_NO_REPLAY','INVOCATION_ACCEPTANCE_NOT_PROVEN','uncertain');
 }
 if(diagnosis.state!=='DISCOVER_AUTHORIZED_CHANNELS')return diagnosis;
 if(!request || !ID.test(request.operation||'') ||
    !HEX64.test(request.command_sha256||'') ||
    !['development','local','staging','production'].includes(request.environment) ||
    !['authenticated_operator','trusted_central_compiler'].includes(request.source) ||
    !['general_vps_administration','registered_semantic_action'].includes(request.kind) ||
    !exact(request,failure) ||
    failure.original_job_claim_state!=='NOT_CLAIMED' ||
    !Array.isArray(candidates)||candidates.length>30)
  return stop('BLOCKED','ORIGINAL_REQUEST_OR_FAILURE_SCOPE_INVALID','authorization');
 if(!core || core.authority_source!=='original_core_305_312' ||
    core.verified!==true||core.revoked!==false ||
    core.grant_state!=='CENTRAL_OPERATION_SCOPE_GRANTED' ||
    core.original_claim_state!=='NOT_CLAIMED' ||
    core.audit_journal_ready!==true||!exact(request,core) ||
    core.security_denial===true || core.permission_denial===true)
  return stop('BLOCKED','CURRENT_ORIGINAL_CORE_AUTHORITY_REQUIRED','authorization');
 const mutates=!['read','diagnostic'].includes(request.intent);
 if(mutates && (core.allow_mutations!==true ||
    core.rollback_planned!==true))
  return stop('BLOCKED','EXACT_MUTATION_AND_ROLLBACK_REQUIRED','authorization');
 if(request.environment==='production'&&mutates &&
    core.allow_production_mutations!==true)
  return stop('BLOCKED','PRODUCTION_NOT_AUTHORIZED','authorization');
 const ranked=candidates.filter(c=>
  c && ID.test(c.id||'') && ID.test(c.provider||'') &&
  c.provider!==failure.provider && c.available===true &&
  c.registered===true && c.independent_authorization_verified===true &&
  c.current_provider_policy_verified===true &&
  c.original_core_binding_verified===true &&
  c.host_identity_pinned===true && c.operation_digest_verified===true &&
  c.original_claim_state==='NOT_CLAIMED' && exact(request,c) &&
  // Never transform a failed typed job into a general Shell request.
  c.kind===request.kind && c.source===request.source &&
  c.intent===request.intent &&
  !(request.kind==='registered_semantic_action' && c.is_general_shell===true)
 ).sort((a,b)=>(a.preference??100)-(b.preference??100) ||
                  a.id.localeCompare(b.id));
 if(!ranked.length)
  return stop('BLOCKED','NO_INDEPENDENTLY_AUTHORIZED_ALTERNATE_CHANNEL','availability');
 return Object.freeze({
  contract:'mad4b.authorized-channel-recovery.v1',
  state:'ALTERNATIVE_REQUIRES_FRESH_CORE_ADMISSION',
  category:'transport',provider:ranked[0].provider,
  channel_id:ranked[0].id,operation:request.operation,
  new_core_job_required:true,requires_current_authority_recheck:true,
  requires_original_305_312_and_readback:true,
  execution_performed:false,previous_job_replayed:false,
  original_policy_denial_overridden:false
 });
}
