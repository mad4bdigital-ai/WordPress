import assert from 'node:assert/strict';
import {createAbilityCatalogClient, CatalogError} from '../client/ability-catalog-client.mjs';

const SNAP = 'd'.repeat(64);
const items = Array.from({length: 73}, (_, i) => ({ability_name: 'fixture/task-' + String(i).padStart(3, '0')}));
const requests = [];
let executionCalls = 0;
const discover = async (input) => {
  requests.push(input);
  if (input.gateway_action === 'sources') {
    return {contract: 'mad4b.unified-capability-gateway.v1',
      mode: input.source_id ? 'detail' : 'summary', source_id: input.source_id ?? null,
      sources: input.source_id ? [{source_id: input.source_id, coverage_state: 'NOT_INSPECTED'}] : []};
  }
  if (input.gateway_action === 'recovery') {
    return {contract: 'mad4b.unified-capability-gateway.v1', goal: input.goal,
      original_blockers: input.blockers, automatic_mutation_allowed: false,
      recommended_route_id: 'wordpress_native_cron_build'};
  }
  if (input.gateway_action === 'dependencies') {
    return {contract: 'mad4b.unified-capability-gateway.v1', operation_id: input.operation,
      execution_ready: false, authority_effect: 'none'};
  }
  assert.equal(input.gateway_action, 'search');
  const offset = input.cursor ? Number(input.cursor.replace('p-', '')) : 0;
  const limit = Math.min(25, input.limit ?? 25);
  const page = items.slice(offset, offset + limit);
  const next = offset + page.length < items.length ? 'p-' + (offset + page.length) : null;
  return {contract: 'mad4b.unified-capability-gateway.v1',
    count: page.length, total_match_count: items.length, page_offset: offset, page_limit: limit,
    search_snapshot_sha256: SNAP, items: page, next_cursor: next, has_more: next !== null};
};
const client = createAbilityCatalogClient({
  callDiscover: discover,
  callTool: () => { executionCalls++; throw new Error('Must never execute during discovery'); }
});
const seen = [];
let count = 0;
for await (const page of client.searchPages('task', {limit: 25, category: 'read'})) {
  count++;
  for (const item of page.items) seen.push(item.ability_name);
}
assert.equal(count, 3);
assert.deepEqual(seen, items.map(x => x.ability_name));
assert.equal(new Set(seen).size, 73);
assert.equal(requests.filter(x => x.gateway_action === 'search').length, 3);
assert.equal(requests.filter(x => x.gateway_action === 'search')[1].cursor, 'p-25');
assert.equal(requests.filter(x => x.gateway_action === 'search')[1].category, 'read');
// Several independent search consumers can traverse their own cursors
// concurrently without sharing offsets or performing any governed mutation.
const parallelCounts = await Promise.all(Array.from({length: 4}, async () => {
  let total = 0;
  for await (const item of client.searchItems('task', {limit: 25})) {
    assert.ok(item.ability_name.startsWith('fixture/task-'));
    total++;
  }
  return total;
}));
assert.deepEqual(parallelCounts, [73, 73, 73, 73]);
const source = await client.sources({source_id: 'managed_skills'});
assert.equal(source.sources[0].source_id, 'managed_skills');
const fallback = await client.recovery('staging_install', {expected_head: 'a'.repeat(40),
  blockers: ['mad4b_selected_head_certified_artifact_unavailable']});
assert.equal(fallback.recommended_route_id, 'wordpress_native_cron_build');
assert.equal(fallback.automatic_mutation_allowed, false);
assert.deepEqual(fallback.original_blockers, ['mad4b_selected_head_certified_artifact_unavailable']);
const dependency = await client.dependencies('fixture-operation');
assert.equal(dependency.execution_ready, false);
await assert.rejects(() => client.dependencies('bad/operation'), CatalogError);
const controller = new AbortController();
const iterator = client.searchPages('task', {signal: controller.signal})[Symbol.asyncIterator]();
await iterator.next();
controller.abort(new CatalogError('Fixture cancelled'));
await assert.rejects(() => iterator.next());
assert.equal(executionCalls, 0);
let forged = 0;
const bad = createAbilityCatalogClient({callDiscover: async input => {
  if (input.gateway_action !== 'search') throw new Error('unexpected gateway action');
  forged++;
  return {contract:'mad4b.unified-capability-gateway.v1',
    count: 1, total_match_count: 3, page_offset: (forged - 1),
    search_snapshot_sha256: forged === 1 ? SNAP : 'a'.repeat(64),
    next_cursor: 'next-' + forged, items:[{ability_name:'fixture/' + forged}]};
}});
await assert.rejects(async () => {
  for await (const page of bad.searchPages('task')) void page;
}, /snapshot changed/);
console.log('PASS unified capability client: 73 descriptors, 3 pages, 4 concurrent consumers, facets, source/dependency routing, cancellation, drift; no writes');
