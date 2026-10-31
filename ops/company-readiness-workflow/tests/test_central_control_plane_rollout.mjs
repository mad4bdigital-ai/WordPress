import assert from 'node:assert/strict';
import test from 'node:test';
import {validateRelease,plan,verify} from '../executor/central_control_plane_rollout.mjs';

const hash='a'.repeat(64),baseHash='b'.repeat(64);
const origin='https://tours.dev.mad4b.com';
const site_uuid='123e4567-e89b-42d3-a456-426614174000';
const release={
  contract:'mad4b.central-control-plane-release.v1',
  target_plugin:'mad4b-site-control-plane/mad4b-site-control-plane.php',
  expected_live_base_version:'0.4.0-rc.118',
  next_version_intent:'0.4.0-rc.119',
  site_selection:{canary_site:origin},
  release_artifact:{state:'VERIFIED',sha256:hash,critical_file_sha256:{profile:hash,admin:baseHash}}
};
const canary={site_uuid,origin,environment:'staging',
  enrollment_state:'BOUND_EXACT',origin_bound:true,
  plugin_version:'0.4.0-rc.118',plugin_inventory_sha256:baseHash};
const inventory={contract:'mad4b.central-site-profile-inventory.v1',
  trusted_origin:'MAD4B_Site_Profile_Registry',snapshot_sha256:hash,
  sites:[canary,{...canary,origin:'https://production.example',
    site_uuid:'223e4567-e89b-42d3-a456-426614174000',environment:'production'}]};
const receipt={site_uuid,origin,source_inventory_sha256:baseHash,
  artifact_sha256:hash,plugin_version:'0.4.0-rc.119',
  provider_isolation_effective:true,mcp_routes_verified:true,
  oauth_resource_metadata_verified:true,new_write_grants:0,
  customer_data_mutation:false,rollback_snapshot_verified:true};

test('accepts exact contract with critical artifact hashes',()=>assert.equal(validateRelease(release),true));
test('rejects old plugin base used for a downgraded release',()=>assert.throws(()=>validateRelease({...release,expected_live_base_version:'0.4.0-rc.104'}),/WRONG_BASE/));
test('missing candidate artifact blocks staging',()=>{
 const p=plan({...release,release_artifact:{state:'NOT_BUILT'}},inventory);
 assert.equal(p.sites[0].state,'BLOCKED_RELEASE_ARTIFACT');
 assert.equal(p.mutation_executed,false);
});
test('real artifact enables canary planning but not execution',()=>{
 const p=plan(release,inventory);
 assert.equal(p.sites[0].state,'READY_FOR_AUTHENTICATED_CANARY_PLAN');
 assert.equal(p.sites[0].write_authorized,false);
});
test('production is always fenced in central plan',()=>{
 assert.equal(plan(release,inventory).sites[1].state,'BLOCKED_PRODUCTION_APPROVAL');
});
test('source drift fails closed',()=>{
 const i={...inventory,sites:[{...canary,plugin_version:'0.4.0-rc.104'}]};
 assert.equal(plan(release,i).sites[0].state,'BLOCKED_SOURCE_DRIFT');
});
test('duplicate site, untrusted registry and missing canary fail closed',()=>{
 assert.throws(()=>plan(release,{...inventory,sites:[canary,canary]}),/DUPLICATE_SITE/);
 assert.throws(()=>plan(release,{...inventory,trusted_origin:'caller'}),/INVENTORY_NOT_TRUSTED/);
 assert.throws(()=>plan(release,{...inventory,sites:[inventory.sites[1]]}),/CANARY_MISSING/);
});
test('independent readback accepts exact healthy canary',()=>{
 assert.equal(verify(plan(release,inventory),receipt).verified,true);
});
test('readback rejects isolation, OAuth or MCP regression',()=>{
 const p=plan(release,inventory);
 for(const fields of [{provider_isolation_effective:false},{mcp_routes_verified:false},{oauth_resource_metadata_verified:false}])
 assert.equal(verify(p,{...receipt,...fields}).verified,false);
});
test('readback rejects new grants, customer mutations, missing rollback',()=>{
 const p=plan(release,inventory);
 for(const fields of [{new_write_grants:1},{customer_data_mutation:true},{rollback_snapshot_verified:false}])
 assert.equal(verify(p,{...receipt,...fields}).verified,false);
});
test('readback rejects identity, wrong package and wrong target version',()=>{
 const p=plan(release,inventory);
 for(const fields of [{site_uuid:inventory.sites[1].site_uuid},{artifact_sha256:baseHash},{plugin_version:'0.4.0-rc.118'}])
 assert.equal(verify(p,{...receipt,...fields}).verified,false);
});
test('production readback cannot certify staging rollout',()=>{
 const p=plan(release,inventory);
 const prod=inventory.sites[1];
 assert.equal(verify(p,{...receipt,site_uuid:prod.site_uuid,origin:prod.origin}).verified,false);
});
