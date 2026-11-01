import test from 'node:test';
import assert from 'node:assert/strict';
import {generateKeyPairSync,sign} from 'node:crypto';
import {CONTRACT,native313Bytes,verifyIndependent313Readback as verify313} from './independent_313_readback.mjs';
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const now=1800000000;
const job_id='a'.repeat(32),transport_id='b'.repeat(32),grant_id='c'.repeat(32);
const sha='d'.repeat(64),out='e'.repeat(64),read='f'.repeat(64);
const base={
 job_id,company:'all-royal-egypt',site_uuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb',
 origin:'https://staging.allroyalegypt.com',environment:'staging',
 host_id:'hosting-2',transport_id,grant_id,command_sha256:sha,
 output_sha256:out,readback_sha256:read,exit_code:0
};
function fixture(){
 const originalJob={
  job_id,company:base.company,site_uuid:base.site_uuid,
  canonical_origin:base.origin,environment:base.environment,
  target_host_id:base.host_id,transport_id,grant_id,command_sha256:sha,
  operation:'company.remote.wpcli.full',target_scope:'wordpress'};
 const pending313={...base,target_id:base.host_id,
  contract:'mad4b.broker-remote-wpcli-receipt.v1',
  state:'PENDING_INDEPENDENT_NATIVE_READBACK',
  host_signed_attestation_received:true,
  native_executor_certified:false,independent_host_readback_verified:false};
 const registeredNative={company:base.company,site_uuid:base.site_uuid,
  canonical_origin:base.origin,environment:base.environment,
  host_id:base.host_id,transport_id,grant_id,registration_state:'ENROLLED',
  execute_registered:true,independent_readback_observer_enrolled:true,
  observer_signer_is_execution_signer:false,observer_key_pinned:true,
  observer_key_id:'observer-key-001'};
 const readback={contract:CONTRACT,...base,
  observed_wordpress_home:base.origin,issued_epoch:now-4,
  expires_epoch:now+45,nonce:'9'.repeat(32)};
 return {originalJob,pending313,registeredNative,readback,now,
  pinnedObserverKey:publicKey,pinnedObserverKeyId:'observer-key-001',
  pinnedObserverKeySource:'original_core_pinned_registry'};
}
function run(f,signer=privateKey){
 const signature=sign(null,native313Bytes(f.readback),signer).toString('base64');
 return verify313({...f,signature});
}
test('valid independent observer signature verifies only, never finalizes',()=>{
 const f=fixture(),r=run(f);
 assert.equal(r.verified,true);assert.equal(r.finalized,false);
 assert.equal(r.next_operation,'ORIGINAL_CORE_ATOMIC_313_FINALIZE');
 assert.equal(f.pending313.state,'PENDING_INDEPENDENT_NATIVE_READBACK');
 assert.equal(f.pending313.native_executor_certified,false);
});
for(const [name,part,key,value,reason] of [
 ['foreign company','registeredNative','company','other','NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['foreign site','readback','site_uuid','other','NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['wrong target','readback','host_id','hosting-3','NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['wrong grant','readback','grant_id','0'.repeat(32),'NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['wrong argv hash','readback','command_sha256','1'.repeat(64),'NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['wrong readback hash','readback','readback_sha256','2'.repeat(64),'NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['wrong site home','readback','observed_wordpress_home','https://other.test','NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['production job','originalJob','environment','production','NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH'],
 ['unregistered Native','registeredNative','registration_state','INSTALLED_UNENROLLED','PINNED_INDEPENDENT_NATIVE_OBSERVER_REQUIRED'],
 ['same execution signer','registeredNative','observer_signer_is_execution_signer',true,'PINNED_INDEPENDENT_NATIVE_OBSERVER_REQUIRED'],
 ['no observer pin','registeredNative','observer_key_pinned',false,'PINNED_INDEPENDENT_NATIVE_OBSERVER_REQUIRED'],
 ['old receipt already certified','pending313','state','VERIFIED','ORIGINAL_313_PENDING_RECEIPT_REQUIRED'],
 ['expired observer proof','readback','expires_epoch',now-1,'NATIVE_313_FRESHNESS_OR_DIGEST_INVALID'],
 ['tampered signature scope','readback','nonce','x'.repeat(32),'NATIVE_313_FRESHNESS_OR_DIGEST_INVALID']
])test(name,()=>{
 const f=fixture();f[part][key]=value;
 assert.equal(run(f).reason,reason);
});
test('a self-supplied/unregistered observer key cannot establish trust',()=>{
 const f=fixture();f.pinnedObserverKeySource='request_payload';
 assert.equal(run(f).reason,'PINNED_INDEPENDENT_NATIVE_OBSERVER_REQUIRED');
});
test('observer key rotation mismatch must deny',()=>{
 const f=fixture();f.pinnedObserverKeyId='different-pinned-observer';
 assert.equal(run(f).reason,'PINNED_INDEPENDENT_NATIVE_OBSERVER_REQUIRED');
});
test('wrong pinned public key rejected',()=>{
 const f=fixture(),other=generateKeyPairSync('ed25519');
 f.pinnedObserverKey=other.publicKey;
 assert.equal(run(f).reason,'NATIVE_313_SIGNATURE_INVALID');
});
test('signature from another key rejected',()=>{
 const f=fixture(),other=generateKeyPairSync('ed25519');
 assert.equal(run(f,other.privateKey).reason,'NATIVE_313_SIGNATURE_INVALID');
});
test('missing original pending 313 blocks certification',()=>{
 const f=fixture();delete f.pending313;
 assert.equal(run(f).reason,'NATIVE_313_SHAPE_INVALID');
});
test('signed receipt does not itself authorize execution',()=>{
 const f=fixture(),r=run(f);
 assert.equal(r.verified,true);
 assert.equal('execute' in r,false);
 assert.equal('authorized' in r,false);
});
