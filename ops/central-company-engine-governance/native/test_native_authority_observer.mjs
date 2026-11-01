import test from 'node:test';
import assert from 'node:assert/strict';
import {classifyCoreObservation,centralIdentityExpression} from './native-authority-observer.mjs';

const scope={contract:'mad4b.central-host-scope.v1',
 workload:'tours-central',scope_kind:'central_native_host',company_target:false,
 host_id:'srv2030908',environment:'staging',
 authorizing:false,host_grant_issued:false,execution_performed:false,
 state:'CENTRAL_HOST_IDENTITY_VERIFIED',
 site_uuid:'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 canonical_origin:'https://tours.dev.mad4b.com',owner_enrolled:true};
const observed={
 contract:'mad4b.central-original-core-scope.v2',
 db_name:'etg_commerce_staging',home:scope.canonical_origin,
 runtime_environment:'staging',scope,
 principal_enrolled:true,ledger_verified:true};
const policy={contract:'mad4b.native-vps-operator.v1',
 revoked:false,host_id:'srv2030908',
 scopes:{
  'tours-central:staging':{site_uuid:scope.site_uuid,
   canonical_origin:scope.canonical_origin,site_environment:'staging'},
  // Wayfa Production has a different authorization lifecycle.
  'wayfatours:production':{allow_operator:true,allow_mutation:false}
 }};
const verify=(x=observed,p=policy)=>classifyCoreObservation(x,p);
test('original Central Staging binding works without company production readiness',()=>{
 const x=verify();
 assert.equal(x.state,'CENTRAL_STAGING_HOST_VERIFIED_EXECUTOR_PENDING');
 assert.equal(x.central_host_original_principal_verified,true);
 assert.equal(x.verified,true);
 assert.equal(x.central_dispatcher_binding_verified,false);
 assert.equal(x.company_write_authorized,false);
 assert.equal(x.native_executor_certified,false);
 assert.equal(x.execution_authorized,false);
 assert.equal(x.readiness.company_targets,'NOT_EVALUATED');
 assert.equal(x.next_action,'RECONCILE_NATIVE_EXECUTOR_FROM_INDEPENDENT_HOST_PROOF');
});
test('dynamic workload and staging hostname are derived from signed host binding',()=>{
 const s={...scope,workload:'another-central',site_uuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb',
   canonical_origin:'https://central-preview.example.com'};
 const x={...observed,home:s.canonical_origin,scope:s};
 const p={...policy,scopes:{'another-central:staging':{
  site_uuid:s.site_uuid,canonical_origin:s.canonical_origin}}};
 assert.equal(verify(x,p).central_host_original_principal_verified,true);
});
for(const [name,transform] of [
 ['Production runtime',x=>({...x,runtime_environment:'production'})],
 ['Production scope',x=>({...x,scope:{...x.scope,environment:'production'}})],
 ['cross-tenant origin',x=>({...x,home:'https://wayfatours.com'})],
 ['changed host',x=>({...x,scope:{...x.scope,host_id:'hosting-3'}})],
 ['changed site UUID',x=>({...x,scope:{...x.scope,site_uuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb'}})],
 ['wrong scoped workload',x=>({...x,scope:{...x.scope,workload:'wayfatours'}})],
 ['company scope substituted',x=>({...x,scope:{...x.scope,company_target:true}})],
 ['privileged scope asserted',x=>({...x,scope:{...x.scope,authorizing:true}})],
 ['fake grant issued',x=>({...x,scope:{...x.scope,host_grant_issued:true}})],
 ['fake execution marked',x=>({...x,scope:{...x.scope,execution_performed:true}})],
 ['owner not enrolled',x=>({...x,scope:{...x.scope,owner_enrolled:false}})],
 ['missing UUID',x=>({...x,scope:{...x.scope,site_uuid:null}})],
 ['invalid UUID',x=>({...x,scope:{...x.scope,site_uuid:'not-a-uuid'}})],
 ['malformed workload',x=>({...x,scope:{...x.scope,workload:'../admin'}})],
 ['missing ledger',x=>({...x,ledger_verified:null})],
 ['missing principal',x=>({...x,principal_enrolled:null})]
])test('staging scope mismatch fails closed: '+name,()=>{
 const result=verify(transform(observed));
 assert.equal(result.verified,false);
 assert.equal(result.execution_authorized,false);
});
for(const [name,change] of [
 ['revoked root host policy',{revoked:true}],
 ['different root host',{host_id:'hosting-1'}],
 ['missing scoped enrollment',{scopes:{}}],
 ['policy contract wrong',{contract:'incorrect'}],
 ['company Production binding mistaken for Central',{scopes:{'wayfatours:production':policy.scopes['wayfatours:production']}}],
 ['changed approved origin',{scopes:{'tours-central:staging':{...policy.scopes['tours-central:staging'],canonical_origin:'https://wrong.example.com'}}}],
 ['changed approved UUID',{scopes:{'tours-central:staging':{...policy.scopes['tours-central:staging'],site_uuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb'}}}],
 ['changed approved environment',{scopes:{'tours-central:staging':{...policy.scopes['tours-central:staging'],site_environment:'production'}}}]
])test('root policy binding rejection: '+name,()=>{
 const result=verify(observed,{...policy,...change});
 assert.equal(result.verified,false);
 assert.equal(result.execution_authorized,false);
});
for(const [name,x] of [
 ['ledger denied',{...observed,ledger_verified:false}],
 ['principal not enrolled',{...observed,principal_enrolled:false}],
 ['spoofed state',{...observed,scope:{...scope,state:'SPOOF'}}]
])test('host not certified when original evidence absent: '+name,()=>{
 const result=verify(x);
 assert.equal(result.verified,true);
 assert.equal(result.central_host_original_principal_verified,false);
 assert.equal(result.native_executor_certified,false);
});
test('WordPress observation is read-only and never queries a company target',()=>{
 assert.equal(centralIdentityExpression.includes('wayfatours'),false);
 assert.equal(centralIdentityExpression.includes('content.write'),false);
 assert.equal(centralIdentityExpression.includes('Company_Execution_Resolver'),false);
 assert.equal(centralIdentityExpression.includes('option update'),false);
 assert.equal(centralIdentityExpression.includes('wp_insert'),false);
 assert.ok(centralIdentityExpression.includes('MAD4B_Native_VPS_Principal_V1::ledger'));
});
test('one company production status cannot become a proxy for Central host readiness',()=>{
 const p={...policy,scopes:{...policy.scopes,'wayfatours:production':{
  allow_operator:false,allow_mutation:false,revoked:true}}};
 assert.equal(verify(observed,p).central_host_original_principal_verified,true);
 assert.equal(verify(observed,p).readiness.company_targets,'NOT_EVALUATED');
});
