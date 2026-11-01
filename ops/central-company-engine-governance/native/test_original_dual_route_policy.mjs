import test from 'node:test';
import assert from 'node:assert/strict';
import {routeDual,planOriginalCoreTransportRecovery} from './original_dual_route_policy.mjs';
const policy={contract:'mad4b.central-dual-execution-policy.v1'};
const request={
 lane:'vps_ssh',kind:'general_vps_administration',source:'authenticated_operator',
 principal_id:'7',authorization_ref:'original-shell-grant',environment:'staging',
 correlation_id:'staging-probe-01',intent:'diagnostic',host_id:'hosting-2',
 target_scope:'wordpress',command_summary:'approved fixed read-only diagnostic',
 command_sha256:'a'.repeat(64),transport_session_id:'original-session',
 site_uuid:'staging-site',origin:'https://staging.allroyalegypt.com'};
const context={host_id:'hosting-2',ssh_authenticated:true,
 ssh_host_fingerprint_verified:true,security_denial:false,
 grant:{principal_id:'7',authorization_ref:'original-shell-grant',
 environment:'staging',host_id:'hosting-2',allow_general_shell:true,
 revoked:false,allow_mutations:false,site_uuid:'staging-site',
 origin:'https://staging.allroyalegypt.com'}};
test('original general shell is retained as independent staging route',()=>{
 const out=routeDual(policy,request,context);
 assert.equal(out.status,'AUTHORIZED_FOR_DISPATCH');
 assert.equal(out.lane,'vps_ssh');
 assert.equal(out.execution_performed,false);
});
test('semantic executor operation cannot elevate to general shell lane',()=>{
 const out=routeDual(policy,{...request,kind:'registered_semantic_action'},context);
 assert.equal(out.status,'DENIED');
 assert.equal(out.reason,'EXECUTOR_JOB_CANNOT_ELEVATE_TO_SSH');
});
test('shell route cannot operate after explicit security denial',()=>{
 const out=routeDual(policy,request,{...context,security_denial:true});
 assert.equal(out.status,'DENIED');
 assert.equal(out.reason,'SECURITY_OR_PERMISSION_DENIAL');
});
test('shell route cannot use wrong host pin',()=>{
 const out=routeDual(policy,request,{...context,ssh_host_fingerprint_verified:false});
 assert.equal(out.status,'DENIED');
});
test('shell requires its own original general-shell grant',()=>{
 const out=routeDual(policy,request,{...context,
   grant:{...context.grant,allow_general_shell:false}});
 assert.equal(out.reason,'SSH_GENERAL_SHELL_NOT_AUTHORIZED');
});
test('shell cannot execute unaudited mutations without rollback plan',()=>{
 const out=routeDual(policy,{...request,intent:'repair'},{
   ...context,grant:{...context.grant,allow_mutations:true},
   audit_journal_ready:false,rollback_planned:false});
 assert.equal(out.reason,'SSH_MUTATION_PRECONDITIONS_MISSING');
});
test('production mutation is not authorized by staging grant',()=>{
 const out=routeDual(policy,{...request,environment:'production',intent:'deployment'},context);
 assert.equal(out.status,'DENIED');
});

const recoveryBind={principal_id:'7',authorization_ref:'original-recovery-grant',
 company:'etg',site_uuid:'staging-site',origin:'https://tours.dev.mad4b.com',
 environment:'staging',host_id:'hostinger-primary',
 command_sha256:'a'.repeat(64),operation_contract:'mad4b.original-cutover-v1'};
const recoveryRequest={...recoveryBind,kind:'general_vps_administration',
 source:'authenticated_operator',intent:'diagnostic'};
const recoveryPrevious={...recoveryBind,status:'TRANSPORT_FAILED_BEFORE_DISPATCH',
 reason:'TRANSPORT_UNREACHABLE',provider:'container_exec',
 verified_source:'original_core_execution_journal',
 before_dispatch_verified:true,dispatch_started:false,
 claim_committed:false,effects_possible:false,job_id:'b'.repeat(32)};
const recoveryCore={...recoveryBind,authority_source:'original_core_305_312',
 verified:true,revoked:false,original_claim_state:'NOT_CLAIMED',
 grant_state:'CENTRAL_OPERATION_SCOPE_GRANTED',audit_journal_ready:true,
 allow_mutations:false,rollback_planned:false};
const recoveryProvider={...recoveryBind,provider:'coolify_service',available:true,
 independently_admitted:true,original_core_binding_verified:true,
 provider_policy_verified:true,host_identity_pinned:true,
 operation_digest_verified:true,original_job_claim_state:'NOT_CLAIMED'};
const recover=(overrides={})=>planOriginalCoreTransportRecovery({
 request:recoveryRequest,previous:recoveryPrevious,core:recoveryCore,
 candidates:[recoveryProvider],...overrides});
test('recover connectivity failure before dispatch only as a new Core job',()=>{
 const r=recover();assert.equal(r.provider,'coolify_service');
 assert.equal(r.new_core_job_required,true);
 assert.equal(r.execution_performed,false);
 assert.equal(r.replay_permitted,false);
});
for(const status of ['TOOL_POLICY_REJECTED','PROVIDER_POLICY_REJECTED',
 'SECURITY_DENIED','AUTHORIZATION_DENIED'])
test('security refusal never fails over to another transport: '+status,()=>{
 const r=recover({previous:{...recoveryPrevious,status}});
 assert.equal(r.state,'BLOCKED');
 assert.equal(r.reason,'POLICY_DENIAL_CANNOT_ROUTE_AROUND');
});
test('explicit denial cannot be disguised as a network error',()=>{
 assert.equal(recover({previous:{...recoveryPrevious,denied:true}}).state,'BLOCKED');
});
for(const field of ['dispatch_started','claim_committed','effects_possible'])
test('uncertain side effects park the original execution: '+field,()=>{
 assert.equal(recover({previous:{...recoveryPrevious,[field]:true}}).state,
  'PARKED_NO_REPLAY');
});
test('previous signed journal must bind the same command digest',()=>{
 assert.equal(recover({previous:{...recoveryPrevious,
  command_sha256:'f'.repeat(64)}}).state,'PARKED_NO_REPLAY');
});
test('unknown transport failure is not a retry',()=>{
 assert.equal(recover({previous:{...recoveryPrevious,
  reason:'RATE_LIMIT'}}).state,'PARKED_NO_REPLAY');
});
test('typed executor cannot convert to generic shell',()=>{
 assert.equal(recover({request:{...recoveryRequest,
  kind:'registered_semantic_action'}}).state,'BLOCKED');
});
test('alternate provider cannot switch tenant or site',()=>{
 assert.equal(recover({candidates:[{...recoveryProvider,
  site_uuid:'other'}]}).state,'BLOCKED');
});
test('unverified provider is not a valid fallback',()=>{
 assert.equal(recover({candidates:[{...recoveryProvider,
  provider_policy_verified:false}]}).state,'BLOCKED');
});
test('revoked original grant denies all alternate transports',()=>{
 assert.equal(recover({core:{...recoveryCore,revoked:true}}).state,'BLOCKED');
});
test('mutating job requires the original authorized rollback plan',()=>{
 assert.equal(recover({request:{...recoveryRequest,intent:'deployment'}}).reason,
  'ORIGINAL_MUTATION_AND_ROLLBACK_REQUIRED');
});
test('production mutation requires its own verified scope',()=>{
 const request={...recoveryRequest,environment:'production',intent:'deployment'};
 const previous={...recoveryPrevious,environment:'production'};
 const core={...recoveryCore,environment:'production',
  allow_mutations:true,rollback_planned:true};
 const provider={...recoveryProvider,environment:'production'};
 assert.equal(recover({request,previous,core,candidates:[provider]}).reason,
  'PRODUCTION_MUTATION_NOT_AUTHORIZED');
});
test('already-claimed alternate provider cannot be selected',()=>{
 assert.equal(recover({candidates:[{...recoveryProvider,
  original_job_claim_state:'CLAIMED'}]}).state,'BLOCKED');
});
