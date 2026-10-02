import assert from 'node:assert/strict';
import {webcrypto, createHash} from 'node:crypto';
import {createAbilityCatalogClient} from '../client/ability-catalog-client.mjs';
const contract = 'mad4b.ability-catalog-transport.v2', gatewayContract = 'mad4b.unified-capability-gateway.v1';
const scope = 'a'.repeat(64), snapshot = 'b'.repeat(64), abilityName = 'vendor/read';
const bytes = Buffer.from('{"inputSchema":{"type":"object"},"outputSchema":{}}');
const sha = createHash('sha256').update(bytes).digest('hex');
const item = {preparation_receipt: 'receipt-failure-fixture', ability_name: abilityName, snapshot, authority_scope_sha256: scope, classification: 'read', input_schema_sha256: sha, classification_sha256: scope, source: {sha256: sha, bytes: bytes.length}, execution_eligible: true};
const catalog = {snapshot, authority_scope_sha256: scope, entries: new Map([[abilityName, {...item, execution: {classification_sha256: scope}}]])};
const capabilities = {contract, authority_scope_sha256: scope, rest_base_url: 'https://ci.test/wp-json/mad4b/v1/ability-catalog/', transports: ['authenticated_rest_binary', 'mcp_base64']};
const headers = {'X-MAD4B-Schema-SHA256': sha, 'X-MAD4B-Content-SHA256': sha, 'X-MAD4B-Chunk-Count': '1'};
const errorResult = status => ({isError: true, content: [{type: 'text', text: JSON.stringify({contract: 'mad4b.catalog-error.v1', code: status === 410 ? 'mad4b_catalog_snapshot_expired' : 'mad4b_catalog_forbidden', status, message: 'Catalog request unavailable.'})}]});
let renewals = 0, chunks = 0;
const mcp = createAbilityCatalogClient({cryptoImpl: webcrypto, callDiscover: async input => {
  if (input.transport_action === 'capabilities') return capabilities;
  if (input.gateway_action === 'prepare') { renewals++; return {contract: gatewayContract, abilities: [{...item, execution: {state: 'governed_dispatch'}}]}; }
  if (++chunks === 1) return errorResult(410);
  return {contract, schema_sha256: sha, chunk_index: 0, chunk_count: 1, encoding: 'base64', data: bytes.toString('base64'), chunk_sha256: sha};
}});
assert.equal((await mcp.readSchema(catalog, abilityName)).sha256, sha);
assert.equal(renewals, 1);
assert.equal(chunks, 2);
const denied = createAbilityCatalogClient({cryptoImpl: webcrypto, callDiscover: async input => input.transport_action === 'capabilities' ? capabilities : errorResult(403)});
await assert.rejects(denied.readSchema(catalog, abilityName), e => e.status === 403 && e.code === 'mad4b_catalog_forbidden');
const options = {baseUrl: capabilities.rest_base_url, cryptoImpl: webcrypto};
const controller = new AbortController(); let observedSignal;
const aborted = createAbilityCatalogClient({...options, fetchImpl: async (url, init) => {
  if (url.pathname.endsWith('/capabilities')) return Response.json(capabilities);
  observedSignal = init.signal; controller.abort(new Error('cancelled by caller'));
  return new Response(bytes, {headers});
}});
await assert.rejects(aborted.readSchema(catalog, abilityName, {signal: controller.signal}), /cancelled by caller/);
assert.equal(observedSignal.aborted, true);
const timeout = createAbilityCatalogClient({...options, requestTimeoutMs: 10, fetchImpl: async () => new Promise(() => {})});
await assert.rejects(timeout.negotiate(), e => e.status === 408);
const stalledBody = createAbilityCatalogClient({...options, requestTimeoutMs: 10, fetchImpl: async () => new Response(new ReadableStream({start() {}}))});
await assert.rejects(stalledBody.negotiate(), e => e.status === 408);
for (const advertiseLength of [false, true]) {
  let cancelled = false;
  const oversized = createAbilityCatalogClient({...options, fetchImpl: async url => {
    if (url.pathname.endsWith('/capabilities')) return Response.json(capabilities);
    return new Response(new ReadableStream({start(stream) {stream.enqueue(new Uint8Array(bytes.length + 1));}, cancel() {cancelled = true;}}), {headers: {...headers, ...(advertiseLength ? {'Content-Length': String(bytes.length + 1)} : {})}});
  }});
  await assert.rejects(oversized.readSchema(catalog, abilityName), /memory budget/);
  assert.equal(cancelled, true);
}
let executions = 0, state = 'requires_dynamic_projection', preparations = 0;
const execution = createAbilityCatalogClient({...options, callTool: async () => {executions++;}, fetchImpl: async url => {
  assert.ok(url.pathname.endsWith('/capability-gateway'), 'Execution rebuilt the full universe instead of preparing its selected target');
  preparations++;
  return Response.json({contract: gatewayContract, abilities: [{...item, execution: {state}}]});
}});
await assert.rejects(execution.execute(catalog, abilityName, {}), /refresh and confirm/);
state = 'blocked';
await assert.rejects(execution.execute(catalog, abilityName, {}), /not eligible/);
assert.equal(preparations, 2); assert.equal(executions, 0);
console.log('PASS failures: MCP expiry and denial, REST abort, headers/body timeout, bounded stream and selected-target execution');

// MCP text, multibyte, structured content and error paths obey the same budget.
for (const content of [
  {content: [{type: 'text', text: JSON.stringify({...capabilities, padding: 'x'.repeat(5000)})}]},
  {content: [{type: 'text', text: JSON.stringify({...capabilities, padding: '界'.repeat(1500)})}]},
  {structuredContent: {...capabilities, padding: 'x'.repeat(5000)}},
  {isError: true, content: [{type: 'text', text: 'x'.repeat(5000)}]},
]) {
  const bounded = createAbilityCatalogClient({maxResponseBytes: 4096, callDiscover: async () => content});
  await assert.rejects(bounded.negotiate(), /memory budget/);
}
for (const operation of ['negotiate', 'search']) {
  let fetched = false, headerSignal;
  const slowHeaders = createAbilityCatalogClient({...options, requestTimeoutMs: 10,
    headers: ({signal}) => {headerSignal = signal; return new Promise(() => {});},
    fetchImpl: async () => {fetched = true;},
  });
  await assert.rejects(slowHeaders[operation]('task'), e => e.status === 408);
  assert.equal(headerSignal.aborted, true);
  assert.equal(fetched, false);
}
console.log('PASS MCP byte budgets and credential-provider timeout');

const headerAbort = new AbortController();
const cancellableHeaders = createAbilityCatalogClient({...options, headers: ({signal}) => {
  assert.equal(signal.aborted, false);
  headerAbort.abort(new Error('credential acquisition cancelled'));
  return new Promise(() => {});
}});
await assert.rejects(cancellableHeaders.search('task', {signal: headerAbort.signal}), /credential acquisition cancelled/);
