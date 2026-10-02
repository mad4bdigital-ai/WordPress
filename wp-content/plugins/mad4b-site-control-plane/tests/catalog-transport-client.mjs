import assert from 'node:assert/strict';
import {webcrypto, createHash} from 'node:crypto';
import {createAbilityCatalogClient} from '../client/ability-catalog-client.mjs';
const digest = value => createHash('sha256').update(value).digest('hex');
const contract = 'mad4b.ability-catalog-transport.v2';
const scope = 'a'.repeat(64), snapshot = 'b'.repeat(64);
const raw = Buffer.from(JSON.stringify({inputSchema: {type: 'object', properties: {}, description: 'محتوى'.repeat(10000)}, outputSchema: {}}));
const sha = digest(raw); let calls = 0, corrupt = false, expired = false, denied = false, origin = 'https://ci.test', captured;
const row = {ability_name: 'vendor/read', source: {sha256: sha, bytes: raw.length}, wire: {sha256: sha, bytes: raw.length, tool_name: 'vendor-read'}, execution: {lane: 'read', execution_eligible: true, input_schema_sha256: sha, classification_sha256: scope}};
const fetchImpl = async (url, options) => {
  assert.equal(options.redirect, 'error'); assert.equal(options.headers.Authorization, 'Bearer test');
  if (denied) return new Response('{}', {status: 403});
  if (url.pathname.endsWith('/capability-gateway')) {
    const request = JSON.parse(options.body);
    if (request.action === 'search') return Response.json({contract: 'mad4b.unified-capability-gateway.v1', items: [{ability_name: row.ability_name}]});
    return Response.json({contract: 'mad4b.unified-capability-gateway.v1', abilities: [{...row, snapshot, authority_scope_sha256: scope, classification: 'read', input_schema_sha256: sha, classification_sha256: scope, execution: {state: 'governed_dispatch'}}]});
  }
  if (url.pathname.endsWith('/capabilities')) return Response.json({contract, authority_scope_sha256: scope, rest_base_url: origin + '/wp-json/mad4b/v1/ability-catalog/', transports: ['authenticated_rest_binary', 'mcp_base64']});
  if (url.pathname.endsWith('/manifest')) return Response.json({contract, authority_scope_sha256: scope, snapshot, items: [row], removed: [], delta: false, next_cursor: null});
  if (expired) { expired = false; return new Response('{}', {status: 410}); }
  calls++;
  const index = Number(url.pathname.split('/').at(-1)), size = Number(url.searchParams.get('chunk_bytes'));
  const bytes = raw.subarray(index * size, (index + 1) * size);
  return new Response(bytes, {headers: {'X-MAD4B-Schema-SHA256': sha, 'X-MAD4B-Content-SHA256': corrupt ? scope : digest(bytes), 'X-MAD4B-Chunk-Count': String(Math.ceil(raw.length / size))}});
};
const client = createAbilityCatalogClient({baseUrl: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', headers: async () => ({Authorization: 'Bearer test'}), fetchImpl, cryptoImpl: webcrypto, callTool: async (name, input) => {captured = {name, input}; return 'executed';}});
assert.equal((await client.search('booking')).items[0].ability_name, row.ability_name);
const lazy = (await client.prepare([row.ability_name])).catalogs.get(row.ability_name);
assert.equal((await client.readSchema(lazy, row.ability_name)).sha256, sha);
assert.equal(await client.execute(lazy, row.ability_name, {}), 'executed');
const catalog = await client.sync(null); const state = {chunks: new Map()};
const result = await client.readSchema(catalog, row.ability_name, {state});
assert.deepEqual(result.schema.inputSchema.properties, {}); assert.equal(result.sha256, sha);
const downloads = calls; await client.readSchema(catalog, row.ability_name, {state}); assert.equal(calls, downloads);
state.chunks.get(0).bytes[0] ^= 1; await client.readSchema(catalog, row.ability_name, {state}); assert.equal(calls, downloads + 1);
corrupt = true; await assert.rejects(client.readSchema(catalog, row.ability_name), /integrity/); corrupt = false;
expired = true; await client.readSchema(catalog, row.ability_name);
origin = 'https://attacker.invalid'; await assert.rejects(client.negotiate(), /credential origin/); origin = 'https://ci.test';
denied = true; await assert.rejects(client.sync(catalog), e => e.status === 403); denied = false;
assert.equal(await client.execute(catalog, row.ability_name, {value: 1}), 'executed');
assert.equal(captured.name, 'mad4b-read-execute'); assert.equal(captured.input.expected_input_schema_sha256, sha);
await assert.rejects(client.execute(catalog, row.ability_name, {}, {mode: 'direct'}), /confirmed/);
assert.equal(await client.execute(catalog, row.ability_name, {}, {mode: 'direct', directToolNames: ['vendor-read']}), 'executed');
const mcp = createAbilityCatalogClient({cryptoImpl: webcrypto, callDiscover: async input => {
  if (input.transport_action === 'capabilities') return {contract, authority_scope_sha256: scope, transports: ['mcp_base64']};
  if (input.transport_action === 'manifest') return {contract, authority_scope_sha256: scope, snapshot, items: [row], removed: [], delta: false};
  const bytes = raw.subarray(input.chunk_index * input.chunk_bytes, (input.chunk_index + 1) * input.chunk_bytes);
  return {contract, schema_sha256: sha, chunk_index: input.chunk_index, chunk_count: Math.ceil(raw.length / input.chunk_bytes), encoding: 'base64', data: bytes.toString('base64'), chunk_sha256: digest(bytes)};
}});
assert.equal((await mcp.readSchema(await mcp.sync(null), row.ability_name)).sha256, sha);
console.log('PASS client: REST/MCP, resume, corrupt chunks, scope/origin, expiry, governed dispatch and confirmed direct execution');
