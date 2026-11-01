import test from 'node:test';
import assert from 'node:assert/strict';
import {generateKeyPairSync,sign} from 'node:crypto';
import {CONTRACT as READBACK,native313Bytes} from './independent_313_readback.mjs';
import {CONTRACT,createOriginalCore313Finalizer} from './original_core_313_finalize.mjs';

const keys=generateKeyPairSync('ed25519');
const now=1800000000;
const job_id='a'.repeat(32),transport_id='b'.repeat(32),grant_id='c'.repeat(32);
const command_sha256='d'.repeat(64),output_sha256='e'.repeat(64),readback_sha256='f'.repeat(64);
const scope={job_id,company:'all-royal-egypt',site_uuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb',
 origin:'https://staging.allroyalegypt.com',environment:'staging',host_id:'hosting-2',
 transport_id,grant_id,command_sha256,output_sha256,readback_sha256,exit_code:0};
function fixture(){
 const originalJob={...scope,canonical_origin:scope.origin,target_host_id:scope.host_id,
  operation:'company.remote.wpcli.full',target_scope:'wordpress',
  state:'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',executed:false};
 const pending313={...scope,contract:'mad4b.broker-remote-wpcli-receipt.v1',
  target_id:scope.host_id,state:'PENDING_INDEPENDENT_NATIVE_READBACK',
  host_signed_attestation_received:true,native_executor_certified:false,
  independent_host_readback_verified:false};
 const registeredNative={...scope,canonical_origin:scope.origin,
  registration_state:'ENROLLED',execute_registered:true,
  independent_readback_observer_enrolled:true,
  observer_signer_is_execution_signer:false,observer_key_pinned:true,
  observer_key_id:'observer-01'};
 const observerRegistry={authority_source:'original_core_pinned_registry',
  enrolled:true,revoked:false,site_uuid:scope.site_uuid,
  host_id:scope.host_id,key_id:'observer-01',public_key:keys.publicKey};
 const authority={...scope,job_id,target_id:scope.host_id,
  verified:true,eligible:true,operation:'company.remote.wpcli.full',
  owner_enrolled_wpcli:true};
 const readback={contract:READBACK,...scope,
  observed_wordpress_home:scope.origin,
  issued_epoch:now-2,expires_epoch:now+50,nonce:'9'.repeat(32)};
 const signature=sign(null,native313Bytes(readback),keys.privateKey).toString('base64');
 return {originalJob,pending313,registeredNative,observerRegistry,authority,readback,signature};
}
function runner(f,options={}){
 const counters={commit:0,audit:0,readback:0,verify312:0};
 let saved=null;
 const core={
  async readOriginal313Context(){return f},
  async verifyCurrent312(){counters.verify312++;return f.authority},
  async commit313Atomically(row){
   counters.commit++;
   if(options.commitThrows)throw Error('uncertain');
   if(options.commitReject)return {committed:false};
   saved={...row,state:'CORE_313_FINALIZED',independent_readback_verified:true,
    native_executor_certified:true,journal_sha256:'1'.repeat(64)};
   if(options.corruptJournal)saved.journal_sha256='bad';
   if(options.corruptScope)saved.grant_id='other';
   return {committed:true};
  },
  async readFinalized313(){counters.readback++;return options.noDurable?null:saved},
  async audit313(){counters.audit++;if(options.auditThrows)throw Error('no audit')}
 };
 return {execute:()=>createOriginalCore313Finalizer({core}).finalizeOnce({
  job_id,readback:f.readback,signature:f.signature,now}),counters};
}
test('valid independent proof and live 312 become certified only after atomic Core journal readback',async()=>{
 const a=runner(fixture()),v=await a.execute();
 assert.equal(v.state,'CORE_313_FINALIZED');assert.equal(v.certified,true);
 assert.equal(v.executed_again,false);
 assert.deepEqual(a.counters,{commit:1,audit:1,readback:1,verify312:1});
});
test('signed readback alone cannot certify without Core durable commit',async()=>{
 const a=runner(fixture(),{commitReject:true}),v=await a.execute();
 assert.equal(v.state,'PARKED_NO_REPLAY');assert.equal(v.certified,false);
 assert.equal(a.counters.audit,0);assert.equal(a.counters.commit,1);
});
test('an uncertain Core commit must not be retried or interpreted as failure-to-execute',async()=>{
 const a=runner(fixture(),{commitThrows:true}),v=await a.execute();
 assert.equal(v.state,'PARKED_NO_REPLAY');assert.equal(v.executed_again,false);
 assert.equal(a.counters.commit,1);assert.equal(a.counters.readback,0);
});
test('missing durable post-commit readback remains parked',async()=>{
 const a=runner(fixture(),{noDurable:true}),v=await a.execute();
 assert.equal(v.reason,'CORE_313_POSTCOMMIT_SCOPE_OR_JOURNAL_MISMATCH');
 assert.equal(v.certified,false);
});
test('bad durable journal is denied after commit without replay',async()=>{
 const a=runner(fixture(),{corruptJournal:true}),v=await a.execute();
 assert.equal(v.reason,'CORE_313_POSTCOMMIT_SCOPE_OR_JOURNAL_MISMATCH');
});
test('postcommit grant drift cannot certify',async()=>{
 const a=runner(fixture(),{corruptScope:true}),v=await a.execute();
 assert.equal(v.reason,'CORE_313_POSTCOMMIT_SCOPE_OR_JOURNAL_MISMATCH');
});
test('audit uncertainty is not success',async()=>{
 const a=runner(fixture(),{auditThrows:true}),v=await a.execute();
 assert.equal(v.reason,'CORE_313_AUDIT_UNCERTAIN');assert.equal(v.certified,false);
});
for(const [label,change,reason] of [
 ['previously finalized job',f=>f.originalJob.state='CORE_313_FINALIZED','ORIGINAL_PENDING_JOB_AND_313_REQUIRED'],
 ['already executed job',f=>f.originalJob.executed=true,'ORIGINAL_PENDING_JOB_AND_313_REQUIRED'],
 ['production job',f=>f.originalJob.environment='production','ORIGINAL_PENDING_JOB_AND_313_REQUIRED'],
 ['unregistered observer',f=>f.observerRegistry.enrolled=false,'ORIGINAL_CORE_OBSERVER_ENROLLMENT_REQUIRED'],
 ['observer is revoked',f=>f.observerRegistry.revoked=true,'ORIGINAL_CORE_OBSERVER_ENROLLMENT_REQUIRED'],
 ['observer foreign site',f=>f.observerRegistry.site_uuid='other','ORIGINAL_CORE_OBSERVER_ENROLLMENT_REQUIRED'],
 ['pending 313 missing',f=>f.pending313.state='FINALIZED','ORIGINAL_PENDING_JOB_AND_313_REQUIRED'],
 ['wrong signing key',f=>f.signature=sign(null,native313Bytes(f.readback),
    generateKeyPairSync('ed25519').privateKey).toString('base64'),'NATIVE_313_SIGNATURE_INVALID'],
 ['tampered readback',f=>f.readback.grant_id='other','NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['revoked live 312',f=>f.authority.eligible=false,'ORIGINAL_312_SCOPE_RECHECK_DENIED'],
 ['foreign 312 company',f=>f.authority.company='other','ORIGINAL_312_SCOPE_RECHECK_DENIED']
])test(label,async()=>{
 const f=fixture();change(f);const a=runner(f),v=await a.execute();
 assert.equal(v.reason,reason);assert.equal(v.certified,false);
 assert.equal(a.counters.commit,0);assert.equal(a.counters.audit,0);
});
test('rejects incomplete Core adapters without accepting a caller-issued verified flag',()=>{
 assert.throws(()=>createOriginalCore313Finalizer({core:{}}),/ORIGINAL_CORE_313_ATOMIC_PROVIDER_REQUIRED/);
});
