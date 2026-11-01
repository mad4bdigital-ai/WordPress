import test from 'node:test';
import assert from 'node:assert/strict';
import {createGovernedExecutionRoutes,evaluateFullWpcliLiveCertification,
  availableGovernedRoutes} from './governed_execution_routes.mjs';
const site={company:'all-royal-egypt',site_uuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb',
 canonical_origin:'https://staging.allroyalegypt.com',environment:'staging'};
const binding={...site,transport_id:'b'.repeat(32),grant_id:'c'.repeat(32),target_host_id:'hosting-2'};
const jobId='a'.repeat(32),command_sha256='d'.repeat(64);
function fixture(operation='verify_host_binding',change={}){
 const job={...binding,job_id:jobId,
   operation:operation==='maintenance.shell'?'maintenance.shell':'company.remote.wpcli.full',
   command_sha256,...change.job};
 const admission={...binding,verified:true,eligible:true,
   original_core_source_verified:true,verified_action:operation,
   grant_state:'CENTRAL_OPERATION_SCOPE_GRANTED',
   transport_revoked:false,delegate_revoked:false,
   granted_operation:operation==='maintenance.shell'?'maintenance.shell':'company.remote.wpcli.full',
   shell_fallback_allowed:operation==='maintenance.shell',native_scope_valid:true,
   original_enrollment_scope_valid:true,independent_host_proof_verified:true,
   original_job_claim_verified:true,original_312_verified:true,
   native_enrollment_receipt_verified:true,...change.admission};
 const evidenceKind=operation==='verify_host_binding'?'HOST_PROOF':
   operation==='register_native_executor'?'NATIVE_ENROLLMENT':
   operation==='maintenance.shell'?'ORIGINAL_SHELL_JOURNAL':'WPCLI_313';
 const readback={...binding,job_id:jobId,command_sha256,
   evidence_kind:evidenceKind,original_core_signature_verified:true,
   independent_host_proof_verified:true,durable_native_enrollment_verified:true,
   original_shell_journal_verified:true,
   original_313_verified:true,independent_host_readback_verified:true,
   ...change.readback};
 const calls={typed:0,shell:0,claim:0,readback:0,audit:[]};
 const core={
  readOriginalJob:async()=>job,
  verifyOriginalAdmission:async()=>admission,
  authorizeOriginalDualRouteShell:async()=>({
    contract:'mad4b.central-execution-route-decision.v1',
    status:change.shell_policy_denied?'DENIED':'AUTHORIZED_FOR_DISPATCH',
    lane:'vps_ssh',host_id:job.target_host_id,execution_performed:false
  }),
  claimOriginalJobOnce:async()=>{calls.claim++;return change.claim??true},
  verifyOriginalOperationEvidence:async()=>{calls.readback++;return readback},
  audit:async p=>{calls.audit.push(p)}
 };
 const typed={};
 for(const name of availableGovernedRoutes().typed)
  typed[name]=async()=>{calls.typed++;return {state:'READ_VERIFIED'}};
 const generalShell={executeOriginalShellJob:async()=>{calls.shell++;return {state:'EXECUTED'}}};
 return {core,typed,generalShell,calls,job,admission,readback};
}
test('typed host proof stays on Core route and checks independent 313 readback',async()=>{
 const f=fixture();
 const r=await createGovernedExecutionRoutes(f).execute({operation:'verify_host_binding',job_id:jobId});
 assert.equal(r.state,'VERIFIED');assert.equal(r.route,'typed_original_core');
 assert.equal(f.calls.typed,1);assert.equal(f.calls.shell,0);assert.equal(f.calls.readback,1);
});
test('Host Proof can verify before Native and without a 313 receipt',async()=>{
 const f=fixture('verify_host_binding',{readback:{original_313_verified:false}});
 const r=await createGovernedExecutionRoutes(f).execute({
   operation:'verify_host_binding',job_id:jobId});
 assert.equal(r.state,'VERIFIED');assert.equal(f.calls.typed,1);
});
test('initial Native enrollment uses signed registration, not a 313 receipt',async()=>{
 const f=fixture('register_native_executor',{readback:{
   original_313_verified:false,independent_host_readback_verified:false}});
 const r=await createGovernedExecutionRoutes(f).execute({
   operation:'register_native_executor',job_id:jobId});
 assert.equal(r.state,'VERIFIED');
});
test('313 reconciles the existing original claim without attempting another claim',async()=>{
 const f=fixture('reconcile_313_receipt',{claim:false});
 const result=await createGovernedExecutionRoutes(f).execute({
   operation:'reconcile_313_receipt',job_id:jobId});
 assert.equal(result.state,'VERIFIED');
 assert.equal(result.executed,false);
 assert.equal(f.calls.claim,0);
 assert.equal(f.calls.typed,1);
 assert.equal(f.calls.shell,0);
 assert.equal(f.calls.readback,1);
});
test('313 rejects a claim without original 312 verification',async()=>{
 const f=fixture('reconcile_313_receipt',{admission:{original_312_verified:false}});
 const result=await createGovernedExecutionRoutes(f).execute({
   operation:'reconcile_313_receipt',job_id:jobId});
 assert.equal(result.reason_code,'ORIGINAL_312_JOB_CLAIM_REQUIRED');
 assert.equal(f.calls.typed,0);
 assert.equal(f.calls.claim,0);
});
test('313 with invalid independent receipt is parked, without replay',async()=>{
 const f=fixture('reconcile_313_receipt',{readback:{
   independent_host_readback_verified:false}});
 const result=await createGovernedExecutionRoutes(f).execute({
   operation:'reconcile_313_receipt',job_id:jobId});
 assert.equal(result.state,'PARKED_UNVERIFIED_READBACK');
 assert.equal(f.calls.claim,0);
 assert.equal(f.calls.shell,0);
});
test('general shell fallback is maintained for its separate original maintenance.shell grant',async()=>{
 const f=fixture('maintenance.shell');
 const r=await createGovernedExecutionRoutes(f).execute({operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.state,'VERIFIED');assert.equal(r.route,'general_shell_fallback');
 assert.equal(f.calls.shell,1);assert.equal(f.calls.typed,0);
});
test('diagnostic general shell uses original journal, no forced WPCLI 313',async()=>{
 const f=fixture('maintenance.shell',{readback:{
   original_313_verified:false,independent_host_readback_verified:false}});
 const r=await createGovernedExecutionRoutes(f).execute({
   operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.state,'VERIFIED');assert.equal(f.calls.shell,1);
});
test('mutating general shell still needs independent host readback',async()=>{
 const f=fixture('maintenance.shell',{admission:{shell_mutates:true},
   readback:{independent_host_readback_verified:false}});
 const r=await createGovernedExecutionRoutes(f).execute({
   operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.state,'PARKED_UNVERIFIED_READBACK');
});
test('typed provider missing parks, cannot fall back to shell',async()=>{
 const f=fixture('register_native_executor');delete f.typed.register_native_executor;
 const r=await createGovernedExecutionRoutes(f).execute({operation:'register_native_executor',job_id:jobId});
 assert.equal(r.state,'PARKED_PROVIDER_REVIEW_REQUIRED');
 assert.equal(r.reason_code,'TYPED_PROVIDER_NOT_REGISTERED');
 assert.equal(f.calls.shell,0);assert.equal(f.calls.claim,0);
});
test('typed security refusal parks without falling back to shell',async()=>{
 const f=fixture('register_native_executor');
 f.typed.register_native_executor=async()=>({state:'TOOL_POLICY_REJECTED'});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'register_native_executor',job_id:jobId});
 assert.equal(r.reason_code,'TOOL_OR_PROVIDER_POLICY_REJECTED');
 assert.equal(f.calls.shell,0);assert.equal(f.calls.claim,1);
});
test('original shell grant is never inferred from full wpcli grant',async()=>{
 const f=fixture('maintenance.shell',{admission:{granted_operation:'company.remote.wpcli.full'}});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.reason_code,'INDEPENDENT_GENERAL_SHELL_GRANT_REQUIRED');
 assert.equal(f.calls.shell,0);
});
test('legacy vps_ssh policy denial never falls through to general Shell',async()=>{
 const f=fixture('maintenance.shell',{shell_policy_denied:true});
 const result=await createGovernedExecutionRoutes(f).execute({
   operation:'maintenance.shell',job_id:jobId});
 assert.equal(result.reason_code,'ORIGINAL_DUAL_ROUTE_SHELL_NOT_AUTHORIZED');
 assert.equal(f.calls.shell,0);assert.equal(f.calls.claim,0);
});
test('missing legacy dual-route policy parks Shell rather than opening new authority',async()=>{
 const f=fixture('maintenance.shell');delete f.core.authorizeOriginalDualRouteShell;
 const result=await createGovernedExecutionRoutes(f).execute({
   operation:'maintenance.shell',job_id:jobId});
 assert.equal(result.reason_code,'ORIGINAL_DUAL_ROUTE_SHELL_POLICY_UNAVAILABLE');
 assert.equal(f.calls.shell,0);
});
test('initial host verification can run before Native enrollment',async()=>{
 const f=fixture('verify_host_binding',{admission:{
   native_scope_valid:false,native_enrollment_receipt_verified:false}});
 const r=await createGovernedExecutionRoutes(f).execute({
   operation:'verify_host_binding',job_id:jobId});
 assert.equal(r.state,'VERIFIED');
});
test('native registration requires independent host proof',async()=>{
 const f=fixture('register_native_executor',{admission:{
   independent_host_proof_verified:false}});
 const r=await createGovernedExecutionRoutes(f).execute({
   operation:'register_native_executor',job_id:jobId});
 assert.equal(r.reason_code,'INDEPENDENT_HOST_PROOF_REQUIRED');
 assert.equal(f.calls.typed,0);
});
test('real WPCLI execution requires Native enrollment receipt and original 312',async()=>{
 const f=fixture('wpcli.execute',{admission:{
   native_enrollment_receipt_verified:false}});
 const r=await createGovernedExecutionRoutes(f).execute({
   operation:'wpcli.execute',job_id:jobId});
 assert.equal(r.reason_code,'CERTIFIED_NATIVE_AND_312_REQUIRED');
 assert.equal(f.calls.typed,0);
});
test('typed full wpcli grant is never inferred from shell grant',async()=>{
 const f=fixture('wpcli.execute',{admission:{granted_operation:'maintenance.shell'}});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'wpcli.execute',job_id:jobId});
 assert.equal(r.reason_code,'ORIGINAL_FULL_WPCLI_GRANT_REQUIRED');
});
test('foreign site blocked before native provider',async()=>{
 const f=fixture('wpcli.execute',{admission:{site_uuid:'foreign'}});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'wpcli.execute',job_id:jobId});
 assert.equal(r.reason_code,'ORIGINAL_AUTHORITY_OR_EXACT_SITE_MISMATCH');
 assert.equal(f.calls.typed,0);
});
test('production blocked before anything',async()=>{
 const f=fixture('maintenance.shell',{job:{environment:'production'}});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.reason_code,'ORIGINAL_JOB_OR_STAGING_SCOPE_INVALID');
 assert.equal(f.calls.shell,0);
});
test('revoked grant blocks both routes',async()=>{
 const f=fixture('maintenance.shell',{admission:{transport_revoked:true}});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.state,'DENIED');assert.equal(f.calls.shell,0);
});
test('duplicate durable claim does not execute twice',async()=>{
 const f=fixture('maintenance.shell',{claim:false});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.reason_code,'ORIGINAL_JOB_ALREADY_CLAIMED');
 assert.equal(f.calls.shell,0);
});
test('unknown provider outcome is parked without replay',async()=>{
 const f=fixture('wpcli.execute');
 f.typed['wpcli.execute']=async()=>{throw Error('timeout')};
 const r=await createGovernedExecutionRoutes(f).execute({operation:'wpcli.execute',job_id:jobId});
 assert.equal(r.state,'PARKED_UNCERTAIN_NO_REPLAY');
 assert.equal(f.calls.shell,0);
});
test('313 independent readback must match original site and job',async()=>{
 const f=fixture('maintenance.shell',{readback:{command_sha256:'e'.repeat(64)}});
 const r=await createGovernedExecutionRoutes(f).execute({operation:'maintenance.shell',job_id:jobId});
 assert.equal(r.state,'PARKED_UNVERIFIED_READBACK');
});
test('unregistered providers must reject rather than use generic Shell',()=>{
 assert.throws(()=>createGovernedExecutionRoutes({core:{},typed:{}}));
 assert.deepEqual(availableGovernedRoutes().typed,[
  'verify_host_binding','register_native_executor','reconcile_313_receipt','wpcli.execute']);
});
const siteProof={...site};
const channel={...site,host_id:'hosting-2',transport_id:binding.transport_id,
 full_wpcli_grant_id:binding.grant_id,state:'ENROLLED',full_wpcli_active:true,
 host_authority_verified:true};
const native={registration_state:'ENROLLED',execute_registered:true,
 original_core_312_bound:true,grant_verifier_bound:true,
 durable_enrollment_receipt_verified:true,host_id:'hosting-2',
 transport_id:binding.transport_id,site_uuid:site.site_uuid};
const canary={...site,transport_id:binding.transport_id,grant_id:binding.grant_id,
 job_id:jobId,command_sha256,command_kind:'wp.option.get.home',
 state:'EXECUTED',original_core_claim_verified:true,argv_digest_verified:true};
const receipt={...site,transport_id:binding.transport_id,grant_id:binding.grant_id,
 job_id:jobId,command_sha256,original_313_verified:true,
 independent_host_readback_verified:true,native_executor_certified:true,
 readback_origin:site.canonical_origin};
test('approval is denied while Native remains installed_unenrolled',()=>{
 const result=evaluateFullWpcliLiveCertification({site:siteProof,channel,
  native:{...native,registration_state:'INSTALLED_UNENROLLED'},canary,receipt});
 assert.equal(result.approved,false);
 assert.equal(result.reason_code,'ORIGINAL_NATIVE_REGISTRATION_NOT_CERTIFIED');
});
test('approval requires signed exact 313 readback',()=>{
 const result=evaluateFullWpcliLiveCertification({site:siteProof,channel,native,
  canary,receipt:{...receipt,independent_host_readback_verified:false}});
 assert.equal(result.approved,false);
 assert.equal(result.reason_code,'INDEPENDENT_313_READBACK_REQUIRED');
});
test('approval requires original Core canary and correct grant',()=>{
 const result=evaluateFullWpcliLiveCertification({site:siteProof,channel,native,
  canary:{...canary,grant_id:'foreign'},receipt});
 assert.equal(result.approved,false);
});
test('only complete staging Native + canary + independent 313 can certify',()=>{
 const result=evaluateFullWpcliLiveCertification({site:siteProof,channel,native,canary,receipt});
 assert.equal(result.approved,true);
 assert.equal(result.reason_code,'ORIGINAL_FULL_WPCLI_LIVE_CERTIFIED');
});
test('Production cannot become certified by Staging report',()=>{
 const result=evaluateFullWpcliLiveCertification({site:{...site,environment:'production'},
  channel,native,canary,receipt});
 assert.equal(result.approved,false);
});

function recoveryFixture(overrides={}){
 const f=fixture('maintenance.shell');
 const identity={principal_id:'7',authorization_ref:'grant-shell-01',
   company:site.company,site_uuid:site.site_uuid,origin:site.canonical_origin,
   environment:'staging',host_id:binding.target_host_id,
   command_sha256,operation_contract:'maintenance.shell'};
 const request={...identity,source:'authenticated_operator',
   kind:'general_vps_administration',intent:'diagnostic'};
 const previous={...identity,job_id:jobId,
   provider:'container_exec',status:'TRANSPORT_FAILED_BEFORE_DISPATCH',
   reason:'TRANSPORT_UNREACHABLE',verified_source:'original_core_execution_journal',
   before_dispatch_verified:true,dispatch_started:false,
   claim_committed:false,effects_possible:false};
 const authority={...identity,authority_source:'original_core_305_312',
   verified:true,grant_state:'CENTRAL_OPERATION_SCOPE_GRANTED',
   revoked:false,original_claim_state:'NOT_CLAIMED',audit_journal_ready:true};
 const alternative={...identity,provider:'coolify_service',available:true,
   independently_admitted:true,original_core_binding_verified:true,
   provider_policy_verified:true,host_identity_pinned:true,
   operation_digest_verified:true,original_job_claim_state:'NOT_CLAIMED'};
 const record={request,previous,core:authority,candidates:[alternative],
   ...overrides};
 let created=0;
 let lastCreate=null;
 const newJob='e'.repeat(32);
 f.core.readOriginalRecoveryEvidence=async()=>record;
 f.core.createOriginalRecoveryJobOnce=async input=>{
  created++;lastCreate=input;
  return {state:'ORIGINAL_CORE_RECOVERY_JOB_COMMITTED',
    original_job_id:jobId,new_job_id:newJob,operation:'maintenance.shell',
    provider:'coolify_service',command_sha256,company:site.company,
    site_uuid:site.site_uuid,canonical_origin:site.canonical_origin,
    environment:'staging',target_host_id:binding.target_host_id,
    executed:false};
 };
 return {f,record,get created(){return created},
   get lastCreate(){return lastCreate},newJob};
}
test('original Core bridge creates a new unexecuted job for proven transport outage',async()=>{
 const s=recoveryFixture();
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId});
 assert.equal(r.state,'NEW_ORIGINAL_CORE_JOB_READY');
 assert.equal(r.provider,'coolify_service');
 assert.equal(r.new_job_id,s.newJob);
 assert.equal(r.requires_original_dispatcher,true);
 assert.equal(r.executed,false);
 assert.equal(s.created,1);
 assert.equal(s.f.calls.claim,0);
 assert.equal(s.f.calls.shell,0);
 assert.equal(s.f.calls.typed,0);
 assert.equal(s.lastCreate.command_sha256,command_sha256);
});
test('refused provider policy never enters Core recovery transaction',async()=>{
 const s=recoveryFixture({previous:{...recoveryFixture().record.previous,
   status:'TOOL_POLICY_REJECTED'}});
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId});
 assert.equal(r.state,'BLOCKED');
 assert.equal(s.created,0);
});
test('no fallback after any possible side effect',async()=>{
 const seed=recoveryFixture().record.previous;
 const s=recoveryFixture({previous:{...seed,effects_possible:true}});
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId});
 assert.equal(r.state,'PARKED_NO_REPLAY');
 assert.equal(s.created,0);
});
test('Core refuses missing alternate operation adapter before opening job',async()=>{
 const s=recoveryFixture();delete s.f.core.createOriginalRecoveryJobOnce;
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId});
 assert.equal(r.reason_code,'ORIGINAL_CORE_RECOVERY_ADAPTER_NOT_REGISTERED');
 assert.equal(s.f.calls.shell,0);
});
test('recovery cannot convert a typed WP-CLI job to maintenance.shell',async()=>{
 const s=recoveryFixture();
 s.f.job.operation='company.remote.wpcli.full';
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId});
 assert.equal(r.reason_code,'ORIGINAL_JOB_RECOVERY_SCOPE_MISMATCH');
 assert.equal(s.created,0);
});
test('replacement admission does not certify mismatched Core commit',async()=>{
 const s=recoveryFixture();
 s.f.core.createOriginalRecoveryJobOnce=async()=>({state:'QUEUED',new_job_id:s.newJob});
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId});
 assert.equal(r.state,'PARKED_UNCERTAIN_NO_REPLAY');
 assert.equal(s.f.calls.shell,0);
});
test('Core journal is the authority for original job, not caller-supplied provider',async()=>{
 const s=recoveryFixture();
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId,
   provider:'vps_ssh',command:'do-not-run'});
 assert.equal(r.provider,'coolify_service');
 assert.equal(r.executed,false);
 assert.equal(s.f.calls.shell,0);
});
test('wrong original scope parks before creating a replacement',async()=>{
 const s=recoveryFixture({request:{...recoveryFixture().record.request,
   site_uuid:'foreign-site'}});
 const r=await createGovernedExecutionRoutes(s.f).recoverPreDispatch({job_id:jobId});
 assert.equal(r.state,'DENIED');
 assert.equal(s.created,0);
});
