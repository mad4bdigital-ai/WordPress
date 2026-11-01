import {diagnoseInvocationFailure} from './authorized_channel_recovery.mjs';
// Original-Core enrollment lifecycle. A coordinator, not an authority.
// All changing actions are typed and delegated to existing enrolled providers.
// This module cannot issue a grant, sign Host Proof, or run raw shell by itself.
const blocked=(state,reason,retryable=false)=>Object.freeze({state,reason,retryable,action:null});
const action=(state,operation)=>Object.freeze({state,action:operation,retryable:true});
const eq=(a,b,fields)=>fields.every(k=>a?.[k]!=null&&a[k]===b?.[k]);
const scopeFields=['company','site_uuid','canonical_origin','environment'];
const proofFields=['transport_id','grant_id','host_id','host_key_fingerprint'];
const CANARY_ARGV=Object.freeze(['option','get','home']);
export const originalReadCanaryArgv=()=>[...CANARY_ARGV];

// The snapshot must be read and independently verified by the original Core;
// a supplied flag or caller assertion cannot replace signed evidence.
export function planOriginalEnrollmentLifecycle(snapshot){
 const x=snapshot||{},site=x.site,transport=x.transport,grant=x.grant;
 if(!site||site.environment!=='staging'||typeof site.site_uuid!=='string'||
    !site.site_uuid||!site.canonical_origin?.startsWith('https://'))
   return blocked('BLOCKED_SITE_SCOPE','STAGING_SITE_PROFILE_NOT_VERIFIED');
 if(!transport||!grant)return blocked('WAITING_FOR_INITIAL_OWNER_ENROLLMENT','ORIGINAL_305_CHANNEL_AND_GRANT_REQUIRED',true);
 if(!eq(site,transport,scopeFields)||!eq(site,grant,scopeFields)||
    transport.full_wpcli_grant_id!==grant.grant_id||
    grant.transport_id!==transport.transport_id||
    grant.operation!=='company.remote.wpcli.full'||grant.grant_mode!=='persistent'||
    transport.capabilities?.includes('wpcli.full')!==true)
   return blocked('BLOCKED_SCOPE','ORIGINAL_SITE_GRANT_OR_TRANSPORT_MISMATCH');
 if(grant.state==='REVOKED'||transport.state==='REVOKED'||
    (grant.expires_epoch!=null&&grant.expires_epoch<=x.now))
   return blocked('REVOKED','ORIGINAL_CHANNEL_REVOKED');
 if(grant.state!=='CENTRAL_OPERATION_SCOPE_GRANTED')
   return blocked('WAITING_FOR_INITIAL_OWNER_ENROLLMENT','ORIGINAL_GRANT_NOT_ACTIVE',true);
 // A fresh Registry heartbeat for other SSH hosts cannot enroll this host.
 // Local target pinning is a separate, source-backed original Registry step.
 if(!x.localHostTarget||x.localHostTarget.registered!==true||
    x.localHostTarget.pinned_identity_verified!==true||
    x.localHostTarget.host_id!==transport.host_id||
    x.localHostTarget.authority_source!=='original_registered_ssh')
   return action('WAITING_FOR_LOCAL_HOST_TARGET','BIND_ORIGINAL_PINNED_HOST_TARGET');
 if(!x.signedRegistry?.verified||!eq(x.signedRegistry,site,scopeFields)||
    x.signedRegistry.transport_id!==transport.transport_id ||
    x.signedRegistry.host_id!==transport.host_id||
    x.signedRegistry.host_key_fingerprint!==transport.host_key_fingerprint ||
    x.signedRegistry.expires_epoch<=x.now)
   return action('WAITING_FOR_REGISTRY_PROOF','REFRESH_PINNED_HOST_REGISTRY_STATUS');
 const pending=transport.state==='PENDING_INDEPENDENT_SSH_VERIFICATION'&&
    transport.full_wpcli_active===false;
 const active=transport.state==='ENROLLED'&&
    transport.full_wpcli_active===true&&transport.host_authority_verified===true;
 if(!pending&&!active)return blocked('BLOCKED_TRANSPORT','ORIGINAL_CHANNEL_STATE_INVALID');
 const p=x.hostProof;
 if(pending && (!p||p.verified!==true||
    p.contract!=='mad4b.native-site-host-proof.v1'||
    !eq(p,site,scopeFields)||p.transport_id!==transport.transport_id||
    p.grant_id!==grant.grant_id||p.host_id!==transport.host_id||
    p.expires_epoch<=x.now))
   return action('WAITING_FOR_SITE_HOST_PROOF','ISSUE_AND_VERIFY_SITE_HOST_PROOF');
 // HostProof is short-lived initial enrollment evidence. Once activated,
 // revalidate the durable signed Native enrollment receipt; do not attempt
 // to issue a PENDING-only proof against an already active transport.
 const native=x.native;
 if(active&&(native?.enrollment_receipt_verified!==true||
    native?.enrollment_grant_id!==grant.grant_id||
    native?.enrollment_transport_id!==transport.transport_id))
   return blocked('BLOCKED_NATIVE_PROOF','SIGNED_NATIVE_ENROLLMENT_RECEIPT_REQUIRED');
 if(!native||native.registration_state!=='ENROLLED'||
    native.execute_registered!==true||native.grant_verifier_bound!==true||
    native.site_binding_verified!==true||native.host_key_verified!==true||
    native.site_uuid!==site.site_uuid||native.company!==site.company||
    native.host_id!==transport.host_id||
    native.host_key_fingerprint!==transport.host_key_fingerprint)
   return action('WAITING_FOR_NATIVE_EXECUTOR','REGISTER_NATIVE_FROM_SIGNED_HOST_PROOF');
 if(!x.dispatcher?.bound||x.dispatcher.site_uuid!==site.site_uuid||
    x.dispatcher.transport_id!==transport.transport_id||
    x.dispatcher.native_receipt_verified!==true)
   return action('WAITING_FOR_DISPATCHER','BIND_ORIGINAL_DISPATCHER_TO_NATIVE');
 if(transport.state!=='ENROLLED'||transport.full_wpcli_active!==true||
    transport.host_authority_verified!==true)
   return action('WAITING_FOR_CORE_ACTIVATION','COMMIT_ORIGINAL_305_CHANNEL_WITH_NATIVE_READBACK');
 if(!x.canary)return action('WAITING_FOR_READ_CANARY','ISSUE_ORIGINAL_CORE_FIXED_WPCLI_READ_CANARY');
 if(x.canary.operation!=='company.remote.wpcli.full'||
    x.canary.argv_digest_verified!==true||
    !eq(x.canary,site,scopeFields)||
    x.canary.transport_id!==transport.transport_id||
    x.canary.grant_id!==grant.grant_id)
   return blocked('BLOCKED_CANARY','NON_ORIGINAL_JOB_OR_SCOPE');
 if(x.canary.execution_state==='EXECUTION_UNCERTAIN_NO_RETRY'||
    x.canary.execution_state==='READBACK_UNCERTAIN_NO_RETRY')
   return blocked('PARKED_UNCERTAIN_NO_REPLAY','INVESTIGATE_ORIGINAL_JOB_JOURNAL');
 const r=x.receipt;
 if(!r)return action('WAITING_FOR_313','RECONCILE_ORIGINAL_313_INDEPENDENT_READBACK');
 if(r.contract!=='mad4b.broker-remote-wpcli-receipt.v1'||
    r.independent_host_readback_verified!==true||
    r.native_executor_certified!==true||
    r.job_id!==x.canary.job_id||
    r.command_sha256!==x.canary.command_sha256||
    r.site_uuid!==site.site_uuid||r.company!==site.company||
    r.transport_id!==transport.transport_id||r.grant_id!==grant.grant_id)
   return blocked('PARKED_UNVERIFIED_RECEIPT','SIGNED_313_READBACK_OR_SCOPE_NOT_VERIFIED');
 return blocked('CERTIFIED','ORIGINAL_WPCLI_READ_CANARY_AND_313_VERIFIED');
}

const operations=new Set([
 'BIND_ORIGINAL_PINNED_HOST_TARGET','REFRESH_PINNED_HOST_REGISTRY_STATUS','ISSUE_AND_VERIFY_SITE_HOST_PROOF',
 'REGISTER_NATIVE_FROM_SIGNED_HOST_PROOF','BIND_ORIGINAL_DISPATCHER_TO_NATIVE',
 'COMMIT_ORIGINAL_305_CHANNEL_WITH_NATIVE_READBACK',
 'ISSUE_ORIGINAL_CORE_FIXED_WPCLI_READ_CANARY',
 'RECONCILE_ORIGINAL_313_INDEPENDENT_READBACK'
]);

// Invoke one bounded transition per iteration. The original Core performs
// durable claim, current authorization check, signed receipt and readback.
// Do not retry an ambiguous result; a later tick must first read the journal.
export function createOriginalEnrollmentLifecycle({readOriginalSnapshot,runOriginalTypedTransition,audit}){
 if(typeof readOriginalSnapshot!=='function'||typeof runOriginalTypedTransition!=='function'||
    typeof audit!=='function')throw Error('ORIGINAL_LIFECYCLE_ADAPTERS_REQUIRED');
 return Object.freeze({
  async reconcileOnce(scope){
   if(!scope||scope.environment!=='staging'||!scope.site_uuid||!scope.company)
    return blocked('BLOCKED_SCOPE','EXACT_STAGING_SCOPE_REQUIRED');
   const snapshot=await readOriginalSnapshot(scope);
   // Caller scope never substitutes for the authoritative Site Profile.
   if(!eq(snapshot?.site,scope,scopeFields))
    return blocked('BLOCKED_SCOPE','CALLER_AND_ORIGINAL_SITE_PROFILE_MISMATCH');
   const state=planOriginalEnrollmentLifecycle(snapshot);
   if(!state.action)return state;
   if(!operations.has(state.action))return blocked('BLOCKED_UNKNOWN_TRANSITION','UNREGISTERED_TRANSITION');
   // No caller-supplied argv, shell, owner credentials, grant IDs, or tickets.
   const ticket=await runOriginalTypedTransition({
    operation:state.action,company:scope.company,site_uuid:scope.site_uuid,
    canonical_origin:snapshot.site.canonical_origin,environment:'staging',
    argv:state.action==='ISSUE_ORIGINAL_CORE_FIXED_WPCLI_READ_CANARY'
      ? originalReadCanaryArgv():undefined
   });
   if(ticket?.status==='UNCERTAIN'){
    await audit(scope,'ORIGINAL_LIFECYCLE_UNCERTAIN_NO_REPLAY');
    return blocked('PARKED_UNCERTAIN_NO_REPLAY','ORIGINAL_TRANSITION_JOURNAL_REQUIRED');
   }
   // All rejected/failed typed transitions share the same channel diagnosis.
   // No adapter retry or fallback happens within this reconciliation tick.
   if(ticket?.failure || ['DENIED','REJECTED','FAILED',
        'TOOL_POLICY_REJECTED','PROVIDER_POLICY_REJECTED'].includes(ticket?.status)){
    const failure={...(ticket.failure||{}),
     code:ticket.failure?.code||ticket.reason_code||ticket.status,
     security_denial:ticket.security_denial===true||
       ticket.failure?.security_denial===true,
     permission_denial:ticket.permission_denial===true||
       ticket.failure?.permission_denial===true};
    const decision=diagnoseInvocationFailure(failure);
    if(decision.category==='policy'||decision.category==='credentials'){
     await audit(scope,'ORIGINAL_LIFECYCLE_PROVIDER_AUTHORITY_DENIED');
     return blocked('BLOCKED_PROVIDER_AUTHORITY',
       decision.reason_code);
    }
    if(decision.category==='schema'){
     await audit(scope,'ORIGINAL_LIFECYCLE_INVOCATION_SCHEMA_INVALID');
     return blocked('BLOCKED_INVOCATION_CONTRACT',
       decision.reason_code);
    }
    await audit(scope,'ORIGINAL_LIFECYCLE_PROVIDER_OUTCOME_UNCERTAIN');
    return blocked('PARKED_UNCERTAIN_NO_REPLAY',decision.reason_code);
   }
   if(ticket?.status!=='ACCEPTED'||ticket.independent_readback_verified!==true){
    await audit(scope,'ORIGINAL_LIFECYCLE_AWAITING_READBACK');
    return blocked('WAITING_FOR_VERIFIED_TRANSITION','ORIGINAL_TRANSITION_EVIDENCE_PENDING',true);
   }
   await audit(scope,'ORIGINAL_LIFECYCLE_TRANSITION_READBACK_VERIFIED');
   return Object.freeze({state:'TRANSITION_VERIFIED_NEXT_TICK',action:state.action,retryable:true});
  }
 });
}
