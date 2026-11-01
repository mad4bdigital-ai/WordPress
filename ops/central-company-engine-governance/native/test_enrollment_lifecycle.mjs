import test from 'node:test';
import assert from 'node:assert/strict';
import {planOriginalEnrollmentLifecycle as plan,createOriginalEnrollmentLifecycle} from './enrollment_lifecycle.mjs';
const t=1800000000,company='all-royal-egypt',site_uuid='49c562d1-8f2f-456f-b454-26816c6ba4cb';
const canonical_origin='https://staging.allroyalegypt.com';
const scope={company,site_uuid,canonical_origin,environment:'staging'};
function fixture(){
 const site={...scope},transport={...scope,transport_id:'a'.repeat(32),
  full_wpcli_grant_id:'b'.repeat(32),host_id:'hosting-2',
  host_key_fingerprint:'SHA256:pinned',state:'PENDING_INDEPENDENT_SSH_VERIFICATION',
  capabilities:['wpcli.full'],host_authority_verified:false,full_wpcli_active:false};
 const grant={...scope,grant_id:'b'.repeat(32),transport_id:'a'.repeat(32),
  operation:'company.remote.wpcli.full',grant_mode:'persistent',
  state:'CENTRAL_OPERATION_SCOPE_GRANTED',expires_epoch:null};
 return {now:t,site,transport,grant,localHostTarget:{
  registered:true,pinned_identity_verified:true,host_id:'hosting-2',
  authority_source:'original_registered_ssh'}};
}
function registry(x){x.signedRegistry={...scope,verified:true,
 transport_id:x.transport.transport_id,host_id:x.transport.host_id,
 host_key_fingerprint:x.transport.host_key_fingerprint,expires_epoch:t+120};return x}
function proof(x){registry(x);x.hostProof={...scope,verified:true,
 contract:'mad4b.native-site-host-proof.v1',transport_id:x.transport.transport_id,
 grant_id:x.grant.grant_id,host_id:x.transport.host_id,expires_epoch:t+90};return x}
function native(x){proof(x);x.native={registration_state:'ENROLLED',execute_registered:true,
 grant_verifier_bound:true,site_binding_verified:true,host_key_verified:true,
 enrollment_receipt_verified:true,enrollment_grant_id:x.grant.grant_id,
 enrollment_transport_id:x.transport.transport_id,
 site_uuid,company,host_id:'hosting-2',host_key_fingerprint:'SHA256:pinned'};return x}
function dispatcher(x){native(x);x.dispatcher={bound:true,site_uuid,
 transport_id:x.transport.transport_id,native_receipt_verified:true};return x}
function active(x){dispatcher(x);Object.assign(x.transport,
 {state:'ENROLLED',full_wpcli_active:true,host_authority_verified:true});return x}
function canary(x){active(x);x.canary={...scope,transport_id:x.transport.transport_id,
 grant_id:x.grant.grant_id,job_id:'c'.repeat(32),operation:'company.remote.wpcli.full',
 command_sha256:'d'.repeat(64),argv_digest_verified:true,execution_state:'EXECUTED'};return x}
function receipt(x){canary(x);x.receipt={contract:'mad4b.broker-remote-wpcli-receipt.v1',
 independent_host_readback_verified:true,native_executor_certified:true,
 job_id:x.canary.job_id,command_sha256:x.canary.command_sha256,site_uuid,company,
 transport_id:x.transport.transport_id,grant_id:x.grant.grant_id};return x}
for(const [label,make,expected] of [
 ['no initial grant',()=>{const x=fixture();delete x.grant;return x},null],
 ['host target not yet pinned',()=>{const x=fixture();delete x.localHostTarget;return x},'BIND_ORIGINAL_PINNED_HOST_TARGET'],
 ['stale registry',()=>fixture(),'REFRESH_PINNED_HOST_REGISTRY_STATUS'],
 ['missing host proof',()=>registry(fixture()),'ISSUE_AND_VERIFY_SITE_HOST_PROOF'],
 ['native not enrolled',()=>proof(fixture()),'REGISTER_NATIVE_FROM_SIGNED_HOST_PROOF'],
 ['dispatcher not registered',()=>native(fixture()),'BIND_ORIGINAL_DISPATCHER_TO_NATIVE'],
 ['Core channel not activated',()=>dispatcher(fixture()),'COMMIT_ORIGINAL_305_CHANNEL_WITH_NATIVE_READBACK'],
 ['approved channel lacks read canary',()=>active(fixture()),'ISSUE_ORIGINAL_CORE_FIXED_WPCLI_READ_CANARY'],
 ['pending independent 313',()=>canary(fixture()),'RECONCILE_ORIGINAL_313_INDEPENDENT_READBACK']
 ])test(label,()=>assert.equal(plan(make()).action,expected));
test('missing owner grant stays parked, no fabricated authority',()=>{
 const x=fixture();delete x.grant;const r=plan(x);
 assert.equal(r.state,'WAITING_FOR_INITIAL_OWNER_ENROLLMENT');assert.equal(r.action,null);
});
test('Production site cannot enter Staging lifecycle',()=>{
 const x=fixture();x.site.environment='production';
 assert.equal(plan(x).reason,'STAGING_SITE_PROFILE_NOT_VERIFIED');
});
test('cross-company grant is rejected',()=>{
 const x=fixture();x.grant.company='foreign';
 assert.equal(plan(x).state,'BLOCKED_SCOPE');
});
test('revoked standing grant cannot trigger host proof',()=>{
 const x=registry(fixture());x.grant.state='REVOKED';
 assert.equal(plan(x).state,'REVOKED');
});
test('uncertain native execution is parked without replay',()=>{
 const x=canary(fixture());x.canary.execution_state='READBACK_UNCERTAIN_NO_RETRY';
 assert.equal(plan(x).state,'PARKED_UNCERTAIN_NO_REPLAY');
});
test('activated channel uses signed durable enrollment receipt, not expired initial proof',()=>{
 const x=active(fixture());delete x.hostProof;
 assert.equal(plan(x).action,'ISSUE_ORIGINAL_CORE_FIXED_WPCLI_READ_CANARY');
});
test('active channel without independent enrollment receipt fails closed',()=>{
 const x=active(fixture());x.native.enrollment_receipt_verified=false;
 assert.equal(plan(x).state,'BLOCKED_NATIVE_PROOF');
});
test('unverified 313 receipt never certifies',()=>{
 const x=receipt(fixture());x.receipt.independent_host_readback_verified=false;
 assert.equal(plan(x).state,'PARKED_UNVERIFIED_RECEIPT');
});
test('only exact independently verified 313 readback certifies',()=>{
 const x=receipt(fixture());const s=plan(x);
 assert.equal(s.state,'CERTIFIED');assert.equal(s.action,null);
});
test('reconcile with no owner enrollment must not invoke mutation adapter',async()=>{
 let calls=0;const x=fixture();delete x.grant;
 const runner=createOriginalEnrollmentLifecycle({
  readOriginalSnapshot:async()=>x,runOriginalTypedTransition:async()=>{calls++},audit:async()=>{}});
 const r=await runner.reconcileOnce(scope);
 assert.equal(r.state,'WAITING_FOR_INITIAL_OWNER_ENROLLMENT');assert.equal(calls,0);
});
test('caller cannot substitute target origin',async()=>{
 let calls=0;
 const runner=createOriginalEnrollmentLifecycle({
  readOriginalSnapshot:async()=>active(fixture()),
  runOriginalTypedTransition:async()=>{calls++},audit:async()=>{}});
 const s=await runner.reconcileOnce({...scope,canonical_origin:'https://evil.example'});
 assert.equal(s.reason,'CALLER_AND_ORIGINAL_SITE_PROFILE_MISMATCH');
 assert.equal(calls,0);
});
test('approved channel issues only fixed read canary through original provider',async()=>{
 const invoked=[];const audited=[];
 const runner=createOriginalEnrollmentLifecycle({
  readOriginalSnapshot:async()=>active(fixture()),
  runOriginalTypedTransition:async req=>{invoked.push(req);return {
    status:'ACCEPTED',independent_readback_verified:true}},
  audit:async(...args)=>audited.push(args)});
 const res=await runner.reconcileOnce(scope);
 assert.equal(res.state,'TRANSITION_VERIFIED_NEXT_TICK');
 assert.equal(invoked[0].operation,'ISSUE_ORIGINAL_CORE_FIXED_WPCLI_READ_CANARY');
 assert.deepEqual(invoked[0].argv,['option','get','home']);
 assert.equal(invoked[0].canonical_origin,canonical_origin);assert.equal(audited.length,1);
});
test('accepted transition without independent evidence remains pending',async()=>{
 const runner=createOriginalEnrollmentLifecycle({
  readOriginalSnapshot:async()=>registry(fixture()),
  runOriginalTypedTransition:async()=>({status:'ACCEPTED',independent_readback_verified:false}),
  audit:async()=>{}});
 const r=await runner.reconcileOnce(scope);
 assert.equal(r.state,'WAITING_FOR_VERIFIED_TRANSITION');
});
test('uncertain transition is never blindly reissued',async()=>{
 const runner=createOriginalEnrollmentLifecycle({
  readOriginalSnapshot:async()=>registry(fixture()),
  runOriginalTypedTransition:async()=>({status:'UNCERTAIN'}),audit:async()=>{}});
 const r=await runner.reconcileOnce(scope);assert.equal(r.state,'PARKED_UNCERTAIN_NO_REPLAY');
});
