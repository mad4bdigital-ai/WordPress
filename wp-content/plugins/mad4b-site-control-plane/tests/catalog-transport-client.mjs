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
assert.throws(() => createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', maxManifestEvents: 0}), /event budget/);
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
const eventBounded = createAbilityCatalogClient({
  maxManifestEvents: 1,
  callDiscover: async input => {
    if (input.transport_action === 'capabilities') return {contract, authority_scope_sha256: scope, transports: ['mcp_base64']};
    return {contract, authority_scope_sha256: scope, snapshot, items: [row, {...row, ability_name: 'vendor/read-2'}], removed: [], delta: false, next_cursor: null};
  },
});
await assert.rejects(eventBounded.sync(null), /event budget/);

let syncSignalObserved = false;
const syncAbort = new AbortController();
const cancellableSync = createAbilityCatalogClient({
  callDiscover: async (input, {signal} = {}) => {
    if (input.transport_action === 'capabilities') return {contract, authority_scope_sha256: scope, transports: ['mcp_base64']};
    syncSignalObserved = signal instanceof AbortSignal;
    syncAbort.abort(new Error('sync cancelled'));
    return new Promise(() => {});
  },
});
await assert.rejects(cancellableSync.sync(null, '', {signal: syncAbort.signal}), /sync cancelled/);
assert.equal(syncSignalObserved, true);
const client = createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', headers: async () => ({Authorization: 'Bearer test'}), fetchImpl, cryptoImpl: webcrypto, callTool: async (name, input, options = {}) => {captured = {name, input, options}; return 'executed';}});
assert.equal((await client.search('booking')).items[0].ability_name, row.ability_name);
const lazy = (await client.prepare([row.ability_name])).catalogs.get(row.ability_name);
const lazySchema = await client.readSchema(lazy, row.ability_name);
assert.equal(lazySchema.sha256, sha);
assert.ok(maxInFlight > 1 && maxInFlight <= 3, 'REST schema transfer ignored the bounded server parallelism recommendation');
assert.ok(lazySchema.state.parallelism >= 1 && lazySchema.state.parallelism <= 3, 'Adaptive parallelism escaped the server/client budget');
maxInFlight = 0;
await client.readSchema(lazy, row.ability_name, {state: {chunks: new Map(), parallelism: 4}});
assert.ok(maxInFlight > 1 && maxInFlight <= 3, 'Resume parallelism exceeded the current server recommendation');
await assert.rejects(client.readSchema(lazy, row.ability_name, {state: {chunks: new Map(), parallelism: 9}}), /resume parallelism/);
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
const executeSignal = new AbortController();
assert.equal(await client.execute(catalog, row.ability_name, {value: 2}, {signal: executeSignal.signal}), 'executed');
assert.equal(captured.options.signal, executeSignal.signal);
executeSignal.abort(new Error('execute cancelled'));
await assert.rejects(client.execute(catalog, row.ability_name, {}, {signal: executeSignal.signal}), /execute cancelled/);
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

// The client must share server dispatch semantics without needing a projected
// tool or a tools/listChanged notification from a caching host.
let preparedLane = 'content', preparedClassification = scope;
const laneClient = createAbilityCatalogClient({
  callDiscover: async request => ({
    contract: 'mad4b.unified-capability-gateway.v1',
    abilities: [{ability_name: 'vendor/mutation', snapshot, authority_scope_sha256: scope,
      classification: preparedLane, input_schema_sha256: sha, classification_sha256: preparedClassification,
      source: {sha256: sha, bytes: raw.length}, execution_eligible: true,
      execution: {state: 'governed_dispatch'}}],
    server_tools_list_changed: false,
    exposure: {mode: 'fixed_dispatch'},
  }),
  callTool: async (name, input) => { captured = {name, input}; return 'executed'; },
});
for (const [lane, tool] of [['read', 'read'], ['write', 'write'], ['content', 'write'], ['admin', 'write'], ['developer', 'developer']]) {
  preparedLane = lane;
  const prepared = (await laneClient.prepare(['vendor/mutation'])).catalogs.get('vendor/mutation');
  assert.equal(await laneClient.execute(prepared, 'vendor/mutation', {}), 'executed');
  assert.equal(captured.name, `mad4b-${tool}-execute`);
  assert.equal(captured.input.expected_execution_lane, lane);
  assert.equal(captured.input.expected_classification_sha256, scope);
  preparedClassification = snapshot;
  await assert.rejects(laneClient.execute(prepared, 'vendor/mutation', {}), /contract changed/);
  preparedClassification = scope;
}
for (const lane of ['internal', 'breakglass', 'developer-breakglass', '__proto__']) {
  preparedLane = lane;
  const prepared = (await laneClient.prepare(['vendor/mutation'])).catalogs.get('vendor/mutation');
  await assert.rejects(laneClient.execute(prepared, 'vendor/mutation', {}), /explicit authority route/);
}
console.log('PASS client dispatcher parity: content/admin, classification and original-lane pins, no-refresh host and exceptional lane denial');
