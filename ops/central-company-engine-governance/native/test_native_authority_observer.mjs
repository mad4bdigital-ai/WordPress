import test from 'node:test';
import assert from 'node:assert/strict';
import {classifyCoreObservation,centralIdentityExpression} from './native-authority-observer.mjs';
const scope={
 contract:'mad4b.central-native-host-observation.v2',
 db_name:'etg_commerce_staging',home:'https://tours.dev.mad4b.com',
 wordpress_environment:'staging',environment:'staging',
 canonical_origin:'https://tours.dev.mad4b.com',
 site_uuid:'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
 host_id:'srv2030908',scope_kind:'central_native_host',
 workload:'tours-central',company_target:false,owner_enrolled:true,
 central_host_identity:'CENTRAL_HOST_IDENTITY_VERIFIED',
 principal_enrolled:true,ledger_verified:true
};
test('central host identity is verified without Wayfa Production write eligibility',()=>{
 const x=classifyCoreObservation(scope);
 assert.equal(x.state,'CENTRAL_STAGING_HOST_VERIFIED_EXECUTOR_PENDING');
 assert.equal(x.central_host_original_principal_verified,true);
 assert.equal(x.native_executor_certified,false);
 assert.equal(x.central_dispatcher_binding_verified,false);
 assert.equal(x.company_write_authorized,false);
 assert.equal(x.execution_authorized,false);
 assert.deepEqual(x.wayfa_production,{checked:false,mutated:false});
});
for(const [label,part] of [
 ['production WordPress runtime',{wordpress_environment:'production'}],
 ['production scope',{environment:'production'}],
 ['foreign origin',{canonical_origin:'https://wayfatours.com'}],
 ['foreign WordPress home',{home:'https://wayfatours.com'}],
 ['different database',{db_name:'other_db'}],
 ['different host',{host_id:'hosting-3'}],
 ['foreign workload',{workload:'wayfatours'}],
 ['incorrect host scope',{scope_kind:'company_target'}],
 ['company target substituted',{company_target:true}],
 ['non-enrolled owner',{owner_enrolled:false}],
 ['missing UUID',{site_uuid:null}],
 ['invalid UUID',{site_uuid:'missing'}],
 ['missing ledger evidence',{ledger_verified:null}],
 ['missing principal evidence',{principal_enrolled:null}],
 ['missing host state',{central_host_identity:null}]
])test('central Staging scope fail closed: '+label,()=>{
 const x=classifyCoreObservation({...scope,...part});
 assert.equal(x.verified,false);
 assert.equal(x.execution_authorized,false);
 assert.equal(x.company_write_authorized,false);
});
test('central host cannot certify itself with missing ledger',()=>{
 const x=classifyCoreObservation({...scope,ledger_verified:false});
 assert.equal(x.verified,true);
 assert.equal(x.central_host_original_principal_verified,false);
 assert.equal(x.state,'CENTRAL_STAGING_PRINCIPAL_NOT_VERIFIED');
});
test('central host cannot certify itself with altered original identity',()=>{
 const x=classifyCoreObservation({...scope,central_host_identity:'FAKE_AUTHORIZED'});
 assert.equal(x.central_host_original_principal_verified,false);
});
test('central host observer never queries or mutates Wayfa Production',()=>{
 assert.equal(centralIdentityExpression.includes('wayfatours'),false);
 assert.equal(centralIdentityExpression.includes('content.write'),false);
 assert.equal(centralIdentityExpression.includes('option update'),false);
 assert.equal(centralIdentityExpression.includes('wp_insert'),false);
 assert.ok(centralIdentityExpression.includes('etg_commerce_staging'));
});
