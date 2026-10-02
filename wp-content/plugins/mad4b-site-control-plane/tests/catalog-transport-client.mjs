import assert from 'node:assert/strict';
import {webcrypto, createHash} from 'node:crypto';
import {createAbilityCatalogClient} from '../client/ability-catalog-client.mjs';
const digest = value => createHash('sha256').update(value).digest('hex');
const contract = 'mad4b.ability-catalog-transport.v2';
const scope = 'a'.repeat(64), snapshot = 'b'.repeat(64);
const raw = Buffer.from(JSON.stringify({inputSchema: {type: 'object', properties: {}, description: 'محتوى'.repeat(10000)}, outputSchema: {}}));
const sha = digest(raw); let calls = 0, manifestCalls = 0, prepareCalls = 0, inFlight = 0, maxInFlight = 0, corrupt = false, expired = false, denied = false, origin = 'https://ci.test', captured, wireSha = sha;
const row = {ability_name: 'vendor/read', source: {sha256: sha, bytes: raw.length}, wire: {sha256: sha, bytes: raw.length, tool_name: 'vendor-read'}, execution: {lane: 'read', execution_eligible: true, input_schema_sha256: sha, classification_sha256: scope}};
const currentRow = () => ({...row, wire: {...row.wire, sha256: wireSha}});
const fetchImpl = async (url, options) => {
  assert.equal(options.redirect, 'error'); assert.equal(options.credentials, 'omit'); assert.equal(options.headers.Authorization, 'Bearer test');
  if (denied) return new Response('{}', {status: 403});
  if (url.pathname.endsWith('/capability-gateway')) {
    const request = JSON.parse(options.body);
    if (request.action === 'search') return Response.json({contract: 'mad4b.unified-capability-gateway.v1', items: [{ability_name: row.ability_name}]});
    if (request.action === 'prepare') {
      prepareCalls++;
      assert.equal(request.client_capabilities.max_parallel_schema_fetches, 4);
    }
    return Response.json({
      contract: 'mad4b.unified-capability-gateway.v1',
      abilities: [{...currentRow(), snapshot, authority_scope_sha256: scope, classification: 'read', input_schema_sha256: sha, classification_sha256: scope, execution: {state: 'governed_dispatch'}}],
      transfer_policy: {recommended_parallel_schema_fetches: 3},
    });
  }
  if (url.pathname.endsWith('/capabilities')) return Response.json({contract, authority_scope_sha256: scope, rest_base_url: origin + '/wp-json/mad4b/v1/ability-catalog/', transports: ['authenticated_rest_binary', 'mcp_base64']});
  if (url.pathname.endsWith('/manifest')) { manifestCalls++; return Response.json({contract, authority_scope_sha256: scope, snapshot, items: [currentRow()], removed: [], delta: false, next_cursor: null}); }
  if (expired) { expired = false; return new Response('{}', {status: 410}); }
  calls++; inFlight++; maxInFlight = Math.max(maxInFlight, inFlight);
  await new Promise(resolve => setTimeout(resolve, 2));
  const index = Number(url.pathname.split('/').at(-1)), size = Number(url.searchParams.get('chunk_bytes'));
  const bytes = raw.subarray(index * size, (index + 1) * size);
  inFlight--;
  return new Response(bytes, {headers: {'X-MAD4B-Schema-SHA256': sha, 'X-MAD4B-Content-SHA256': corrupt ? scope : digest(bytes), 'X-MAD4B-Chunk-Count': String(Math.ceil(raw.length / size))}});
};
assert.throws(() => createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', maxPages: 0}), /page budget/);
assert.throws(() => createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', maxSchemaBytes: Infinity}), /memory budget/);
assert.throws(() => createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', maxParallelSchemaFetches: 0}), /parallelism budget/);
assert.throws(() => createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', credentialMode: 'include'}), /credential mode/);
let explicitCookieModeObserved = false;
const cookieClient = createAbilityCatalogClient({
  baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/',
  credentialMode: 'same-origin',
  fetchImpl: async (url, options) => {
    assert.equal(options.credentials, 'same-origin');
    explicitCookieModeObserved = true;
    return Response.json({contract, authority_scope_sha256: scope, rest_base_url: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', transports: ['authenticated_rest_binary', 'mcp_base64']});
  },
});
await cookieClient.negotiate();
assert.equal(explicitCookieModeObserved, true);
const client = createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', headers: async () => ({Authorization: 'Bearer test'}), fetchImpl, cryptoImpl: webcrypto, callTool: async (name, input) => {captured = {name, input}; return 'executed';}});
assert.equal((await client.search('booking')).items[0].ability_name, row.ability_name);
const lazy = (await client.prepare([row.ability_name])).catalogs.get(row.ability_name);
const lazySchema = await client.readSchema(lazy, row.ability_name);
assert.equal(lazySchema.sha256, sha);
assert.ok(maxInFlight > 1 && maxInFlight <= 4, 'REST schema transfer did not use bounded parallel windows');
assert.ok(lazySchema.state.parallelism >= 1 && lazySchema.state.parallelism <= 4, 'Adaptive parallelism escaped the client budget');
assert.equal(await client.execute(lazy, row.ability_name, {}), 'executed');
const catalog = await client.sync(null); const state = {chunks: new Map()};
const result = await client.readSchema(catalog, row.ability_name, {state});
assert.deepEqual(result.schema.inputSchema.properties, {}); assert.equal(result.sha256, sha);
const downloads = calls; await client.readSchema(catalog, row.ability_name, {state}); assert.equal(calls, downloads);
state.chunks.get(0).bytes[0] ^= 1; await client.readSchema(catalog, row.ability_name, {state}); assert.equal(calls, downloads + 1);
corrupt = true; await assert.rejects(client.readSchema(catalog, row.ability_name), /integrity/); corrupt = false;
const manifestsBeforeLeaseRenewal = manifestCalls, preparesBeforeLeaseRenewal = prepareCalls;
expired = true; await client.readSchema(catalog, row.ability_name);
assert.equal(manifestCalls, manifestsBeforeLeaseRenewal, 'Expired schema lease rebuilt the full catalog');
assert.equal(prepareCalls, preparesBeforeLeaseRenewal + 1, 'Expired schema lease did not renew the selected Ability only');
origin = 'https://attacker.invalid'; await assert.rejects(client.negotiate(), /credential origin/); origin = 'https://ci.test';
denied = true; await assert.rejects(client.sync(catalog), e => e.status === 403); denied = false;
assert.equal(await client.execute(catalog, row.ability_name, {value: 1}), 'executed');
assert.equal(captured.name, 'mad4b-read-execute'); assert.equal(captured.input.expected_input_schema_sha256, sha);
await assert.rejects(client.execute(catalog, row.ability_name, {}, {mode: 'direct'}), /confirmed/);
assert.equal(await client.execute(catalog, row.ability_name, {}, {mode: 'direct', directToolNames: ['vendor-read']}), 'executed');
wireSha = scope;
await assert.rejects(client.execute(catalog, row.ability_name, {}, {mode: 'direct', directToolNames: ['vendor-read']}), /wire contract changed/);
wireSha = sha;
const mcp = createAbilityCatalogClient({cryptoImpl: webcrypto, callDiscover: async input => {
  if (input.transport_action === 'capabilities') return {contract, authority_scope_sha256: scope, transports: ['mcp_base64']};
  if (input.transport_action === 'manifest') return {contract, authority_scope_sha256: scope, snapshot, items: [row], removed: [], delta: false};
  const bytes = raw.subarray(input.chunk_index * input.chunk_bytes, (input.chunk_index + 1) * input.chunk_bytes);
  return {contract, schema_sha256: sha, chunk_index: input.chunk_index, chunk_count: Math.ceil(raw.length / input.chunk_bytes), encoding: 'base64', data: bytes.toString('base64'), chunk_sha256: digest(bytes)};
}});
assert.equal((await mcp.readSchema(await mcp.sync(null), row.ability_name)).sha256, sha);
console.log('PASS client: bounded adaptive REST/MCP, resume, integrity, authority/origin, governed dispatch and wire-pinned direct execution');
