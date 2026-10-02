import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {createNetworkCatalogClient} from '../client/network-catalog-client.mjs';
const digest = c => c.repeat(64), calls = [];
function site(id, scope) {
 return {id, baseUrl: `https://network.test/${id}/wp-json/mad4b/v1/ability-catalog/`, authorityScopeSha256: scope, cryptoImpl: webcrypto,
 headers: async () => ({Authorization: `Bearer ${id}`}),
 fetchImpl: async (url, init) => {
  assert.equal(init.headers.Authorization, `Bearer ${id}`); assert.ok(url.href.includes(`/${id}/`));
  return new Response(JSON.stringify({contract:'mad4b.unified-capability-gateway.v1', abilities:[{ability_name:'fixture/read', source:{sha256:digest('a')}, authority_scope_sha256:scope, snapshot:digest('b'), classification:'read', execution_eligible:true, input_schema_sha256:digest('a'), classification_sha256:digest('c'), execution:{state:'governed_dispatch'}}]}));
 }, callTool: async (name, args) => {calls.push(id); assert.equal(args.expected_execution_lane,'read'); assert.equal(args.expected_authority_scope_sha256,scope); return id;}};
}
const a=site('a',digest('a')), b=site('b',digest('b')), network=createNetworkCatalogClient([a,b]);
const prepared=await network.prepare('a',['fixture/read']), catalog=prepared.catalogs.get('fixture/read');
await assert.rejects(network.execute('b',catalog,'fixture/read',{}), /different site/);
await assert.rejects(network.execute('a',catalog,'fixture/read',{}, {mode:'direct'}), /fixed dispatcher/);
await assert.rejects(network.prepare('foreign',['fixture/read']), /enrolled/);
assert.deepEqual(await network.run([{siteId:'a',abilityName:'fixture/read',input:{}},{siteId:'b',abilityName:'fixture/read',input:{}}]), [{siteId:'a',abilityName:'fixture/read',result:'a'},{siteId:'b',abilityName:'fixture/read',result:'b'}]);
assert.deepEqual(calls,['a','b']);
const drift=createNetworkCatalogClient([{...a,authorityScopeSha256:digest('f')}]);
await assert.rejects(drift.prepare('a',['fixture/read']), /authority drift/);
b.callTool=async()=>{throw Error('site b denied');};
const fail=createNetworkCatalogClient([a,b]); calls.length=0;
await assert.rejects(fail.run([{siteId:'a',abilityName:'fixture/read'},{siteId:'b',abilityName:'fixture/read'},{siteId:'a',abilityName:'fixture/read'}]), error => {assert.match(error.message,/site b denied/); assert.equal(error.failedSiteId,'b'); assert.equal(error.completed.length,1); assert.equal(error.completed[0].siteId,'a'); return true;});
assert.deepEqual(calls,['a']);
assert.throws(()=>createNetworkCatalogClient([a,{...b,baseUrl:a.baseUrl}]), /unique HTTPS/);
console.log('PASS network contexts: isolated credentials, per-site authority pins, cross-site denial, drift and stop without replay');
