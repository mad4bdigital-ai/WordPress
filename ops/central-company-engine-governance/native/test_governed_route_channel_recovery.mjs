import test from 'node:test';
import assert from 'node:assert/strict';
import {createGovernedExecutionRoutes} from './governed_execution_routes.mjs';

const request={
 company:'tours-central',site_uuid:'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 canonical_origin:'https://tours.dev.mad4b.com',environment:'staging',
 host_id:'srv2030908',operation:'company.remote.wpcli.full',
 command_sha256:'a'.repeat(64),authorization_ref:'exact-original-core-grant',
 kind:'registered_semantic_action',source:'authenticated_operator',
 intent:'deployment'
};
const failure={...request,provider:'native_mcp',code:'TRANSPORT_UNREACHABLE',
 verified_source:'original_core_execution_journal',
 before_dispatch_verified:true,dispatch_started:false,claim_committed:false,
 effects_possible:false,original_job_claim_state:'NOT_CLAIMED'};
const grant={...request,authority_source:'original_core_305_312',
 verified:true,revoked:false,grant_state:'CENTRAL_OPERATION_SCOPE_GRANTED',
 original_claim_state:'NOT_CLAIMED',audit_journal_ready:true,
 allow_mutations:true,rollback_planned:true};
const channel={...request,provider:'container_exec',id:'staging-container-native',
 registered:true,available:true,independent_authorization_verified:true,
 current_provider_policy_verified:true,original_core_binding_verified:true,
 host_identity_pinned:true,operation_digest_verified:true,
 original_claim_state:'NOT_CLAIMED'};
function factory(){
 const calls={claim:0,read:0,audit:0,dispatch:0};
 const core={
  readOriginalJob:async()=>{calls.read++;throw Error('must not read job')},
  verifyOriginalAdmission:async()=>{calls.read++;throw Error('must not verify admission')},
  claimOriginalJobOnce:async()=>{calls.claim++;throw Error('must not claim')},
  verifyOriginalOperationEvidence:async()=>{calls.read++;throw Error('must not read evidence')},
  audit:async()=>{calls.audit++;throw Error('must not audit')}
 };
 const router=createGovernedExecutionRoutes({core,
  typed:{'wpcli.execute':async()=>{calls.dispatch++}},
  generalShell:{executeOriginalShellJob:async()=>{calls.dispatch++}}});
 return {router,calls,core};
}
test('shared diagnosis exposes schema error without invoking any provider',()=>{
 const {router,calls}=factory();
 const d=router.diagnoseChannelFailure({code:'ARGUMENT_CONTRACT_MISMATCH'});
 assert.equal(d.state,'REPAIR_INVOCATION_CONTRACT');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('original routes plan a newly authorized transport only, not execution',()=>{
 const {router,calls}=factory();
 const d=router.planChannelRecovery({
  request,failure,original_core_authority:grant,candidates:[channel]});
 assert.equal(d.state,'ALTERNATIVE_REQUIRES_FRESH_CORE_ADMISSION');
 assert.equal(d.provider,'container_exec');
 assert.equal(d.requires_original_305_312_and_readback,true);
 assert.equal(d.execution_performed,false);
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('tool policy denial blocks even with available alternate channel',()=>{
 const {router,calls}=factory();
 const d=router.planChannelRecovery({
  request,failure:{...failure,code:'TOOL_POLICY_REJECTED'},
  original_core_authority:grant,candidates:[channel]});
 assert.equal(d.state,'BLOCKED');
 assert.equal(d.reason_code,'POLICY_DENIAL_CANNOT_ROUTE_AROUND');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('original routes cannot turn a denied typed job into general shell',()=>{
 const {router,calls}=factory();
 const shell={...channel,provider:'vps_ssh',id:'shell',
  is_general_shell:true};
 const d=router.planChannelRecovery({
  request,failure,original_core_authority:grant,candidates:[shell]});
 assert.equal(d.state,'BLOCKED');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('uncertain transport outcome never plans alternate dispatch',()=>{
 const {router,calls}=factory();
 const d=router.planChannelRecovery({
  request,failure:{...failure,effects_possible:true},
  original_core_authority:grant,candidates:[channel]});
 assert.equal(d.state,'PARKED_NO_REPLAY');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});

test('signed original Core references drive dynamic provider discovery',async()=>{
 const {router,core,calls}=factory();
 core.readOriginalChannelRecoveryEvidence=async ({request_ref,failure_ref})=>({
  request_ref,failure_ref,
  verified_source:'original_core_execution_journal',
  registry_source:'original_core_registered_provider_registry',
  signed_evidence_verified:true,provider_registry_verified:true,
  request,failure,original_core_authority:grant,candidates:[channel]
 });
 const result=await router.planRegisteredChannelRecovery({
  request_ref:'request-record-1',failure_ref:'failure-record-1'
 });
 assert.equal(result.state,'ALTERNATIVE_REQUIRES_FRESH_CORE_ADMISSION');
 assert.equal(result.provider,'container_exec');
 assert.equal(result.execution_performed,false);
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('no Core registry evidence reader does not claim or dispatch',async()=>{
 const {router,calls}=factory();
 const result=await router.planRegisteredChannelRecovery({
  request_ref:'request-1',failure_ref:'failure-1'
 });
 assert.equal(result.reason_code,'ORIGINAL_CHANNEL_RECOVERY_EVIDENCE_READER_NOT_REGISTERED');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('unsigned provider inventory cannot be selected',async()=>{
 const {router,core,calls}=factory();
 core.readOriginalChannelRecoveryEvidence=async()=>({
  request_ref:'request-1',failure_ref:'failure-1',
  verified_source:'original_core_execution_journal',
  registry_source:'original_core_registered_provider_registry',
  signed_evidence_verified:false,provider_registry_verified:true,
  request,failure,original_core_authority:grant,candidates:[channel]
 });
 const result=await router.planRegisteredChannelRecovery({
  request_ref:'request-1',failure_ref:'failure-1'
 });
 assert.equal(result.reason_code,'ORIGINAL_CHANNEL_RECOVERY_EVIDENCE_UNVERIFIED');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('signed journal containing policy denial remains blocked',async()=>{
 const {router,core,calls}=factory();
 core.readOriginalChannelRecoveryEvidence=async()=>({
  request_ref:'request-1',failure_ref:'failure-1',
  verified_source:'original_core_execution_journal',
  registry_source:'original_core_registered_provider_registry',
  signed_evidence_verified:true,provider_registry_verified:true,
  request,failure:{...failure,code:'TOOL_POLICY_REJECTED'},
  original_core_authority:grant,candidates:[channel]
 });
 const result=await router.planRegisteredChannelRecovery({
  request_ref:'request-1',failure_ref:'failure-1'
 });
 assert.equal(result.reason_code,'POLICY_DENIAL_CANNOT_ROUTE_AROUND');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
test('original Core evidence read timeout parks without replay',async()=>{
 const {router,core,calls}=factory();
 core.readOriginalChannelRecoveryEvidence=async()=>{throw Error('transport timeout')};
 const result=await router.planRegisteredChannelRecovery({
  request_ref:'request-1',failure_ref:'failure-1'
 });
 assert.equal(result.state,'PARKED_UNCERTAIN_NO_REPLAY');
 assert.deepEqual(calls,{claim:0,read:0,audit:0,dispatch:0});
});
