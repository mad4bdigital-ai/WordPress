import test from 'node:test';
import assert from 'node:assert/strict';
import {diagnoseInvocationFailure,planAuthorizedChannelAlternatives}
 from './authorized_channel_recovery.mjs';

const scope={
 company:'tours-central',site_uuid:'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 canonical_origin:'https://tours.dev.mad4b.com',
 environment:'staging',host_id:'srv2030908',
 operation:'company.remote.wpcli.full',command_sha256:'a'.repeat(64),
 authorization_ref:'core-grant-v1',kind:'registered_semantic_action',
 source:'authenticated_operator',intent:'deployment'
};
const failure={...scope,code:'TRANSPORT_UNREACHABLE',provider:'native_mcp',
 status:'TRANSPORT_FAILED_BEFORE_DISPATCH',
 verified_source:'original_core_execution_journal',
 before_dispatch_verified:true,dispatch_started:false,claim_committed:false,
 effects_possible:false,original_job_claim_state:'NOT_CLAIMED'};
const core={...scope,authority_source:'original_core_305_312',
 verified:true,revoked:false,grant_state:'CENTRAL_OPERATION_SCOPE_GRANTED',
 original_claim_state:'NOT_CLAIMED',audit_journal_ready:true,
 allow_mutations:true,rollback_planned:true};
const candidate={...scope,id:'container-docker-staging',
 provider:'container_exec',registered:true,available:true,
 independent_authorization_verified:true,current_provider_policy_verified:true,
 original_core_binding_verified:true,host_identity_pinned:true,
 operation_digest_verified:true,original_claim_state:'NOT_CLAIMED',preference:1};
const plan=(f=failure,c=core,x=[candidate],r=scope)=>
 planAuthorizedChannelAlternatives({request:r,failure:f,core:c,candidates:x});

test('documented argument mismatch recommends same channel, never alternate',()=>{
 const out=plan({
  code:'ARGUMENT_CONTRACT_MISMATCH',
  provider_received:false,before_dispatch_verified:true,
  effects_possible:false,documented_input_schema_verified:true
 });
 assert.equal(out.state,'REBUILD_SAME_CHANNEL_INVOCATION');
 assert.equal(out.new_core_job_required,false);
 assert.equal(out.execution_performed,false);
});
test('argument mismatch without verified pre-dispatch parks uncertain',()=>{
 assert.equal(plan({code:'REQUIRED_ARGUMENT_MISSING'}).state,'PARKED_NO_REPLAY');
});
for(const code of [
 'TOOL_POLICY_REJECTED','PROVIDER_POLICY_REJECTED','SECURITY_DENIED',
 'AUTHORIZATION_DENIED','PERMISSION_DENIED','HOST_POLICY_DENIED'
])test('policy denial never selects alternate '+code,()=>{
 const out=plan({...failure,code});
 assert.equal(out.state,'BLOCKED');assert.equal(out.category,'policy');
 assert.equal(out.reason_code,'POLICY_DENIAL_CANNOT_ROUTE_AROUND');
});
test('policy denial overrides transport indicator',()=>{
 assert.equal(plan({...failure,security_denial:true}).state,'BLOCKED');
});
test('expired SSH credentials need their own enrollment',()=>{
 assert.equal(plan({...failure,code:'SSH_KEY_REVOKED'}).category,'credentials');
});
test('unknown tool exception not mapped to transport',()=>{
 assert.equal(plan({...failure,code:'UNCLASSIFIED_FAILED'}).state,'PARKED_NO_REPLAY');
});
test('verified outage can discover independent enrolled provider',()=>{
 const r=plan();
 assert.equal(r.state,'ALTERNATIVE_REQUIRES_FRESH_CORE_ADMISSION');
 assert.equal(r.provider,'container_exec');
 assert.equal(r.channel_id,'container-docker-staging');
 assert.equal(r.requires_original_305_312_and_readback,true);
 assert.equal(r.execution_performed,false);
 assert.equal(r.previous_job_replayed,false);
});
test('supports newly registered provider with no code-level allowlist',()=>{
 const out=plan(failure,core,[{...candidate,id:'new-broker',provider:'new_broker_adapter'}]);
 assert.equal(out.provider,'new_broker_adapter');
});
test('ranked providers chosen without credential disclosure or execution',()=>{
 const out=plan(failure,core,[
  {...candidate,id:'ssh',provider:'vps_ssh',preference:20},
  {...candidate,id:'coolify',provider:'coolify_service',preference:5}
 ]);
 assert.equal(out.provider,'coolify_service');
});
for(const [reason,f] of [
 ['no proven before-dispatch',{before_dispatch_verified:false}],
 ['claim committed',{claim_committed:true}],
 ['effects possible',{effects_possible:true}],
 ['dispatch started',{dispatch_started:true}],
 ['unverified journal',{verified_source:'tool_result'}]
])test('uncertain failure does not replay '+reason,()=>{
 assert.equal(plan({...failure,...f}).state,'PARKED_NO_REPLAY');
});
for(const [reason,c] of [
 ['missing independent grant',{independent_authorization_verified:false}],
 ['unregistered',{registered:false}],
 ['revoked policy',{current_provider_policy_verified:false}],
 ['changed company',{company:'wayfatours'}],
 ['changed environment',{environment:'production'}],
 ['changed host',{host_id:'hosting-2'}],
 ['changed digest',{command_sha256:'b'.repeat(64)}],
 ['changed source',{source:'web_content'}],
 ['general shell escalation',{is_general_shell:true}]
])test('provider is not eligible '+reason,()=>{
 const out=plan(failure,core,[{...candidate,...c}]);
 assert.equal(out.state,'BLOCKED');
 assert.equal(out.reason_code,'NO_INDEPENDENTLY_AUTHORIZED_ALTERNATE_CHANNEL');
});
for(const [reason,c] of [
 ['grant revoked',{revoked:true}],
 ['not verified',{verified:false}],
 ['changed scope',{site_uuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb'}],
 ['claim state uncertain',{original_claim_state:'CLAIMED'}],
 ['missing audit',{audit_journal_ready:false}],
 ['rollback absent',{rollback_planned:false}]
])test('core rejection blocks transport discovery '+reason,()=>{
 const out=plan(failure,{...core,...c});
 assert.equal(out.state,'BLOCKED');
});
test('no implicit production authorization even if provider available',()=>{
 const prod={...scope,environment:'production'};
 const out=plan({...failure,environment:'production'},
  {...core,environment:'production',allow_production_mutations:false},
  [{...candidate,environment:'production'}],prod);
 assert.equal(out.reason_code,'PRODUCTION_NOT_AUTHORIZED');
});
test('claimed jobs never find new channel',()=>{
 const out=plan({...failure,original_job_claim_state:'CLAIMED'});
 assert.equal(out.state,'BLOCKED');
});
test('bounded candidates avoid untrusted flood',()=>{
 const out=plan(failure,core,Array.from({length:31},()=>candidate));
 assert.equal(out.state,'BLOCKED');
});
