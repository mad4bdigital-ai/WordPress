const CONTRACT = 'mad4b.ability-catalog-transport.v2';
const DIGEST = /^[a-f0-9]{64}$/;
export class CatalogError extends Error {
  constructor(message, status = 0) { super(message); this.status = status; }
}

/** Host adapter: explicit trusted REST origin or explicit MCP discovery callback. No implicit credentials. */
export function createAbilityCatalogClient({ baseUrl, headers = async () => ({}), fetchImpl = globalThis.fetch,
  callDiscover, callTool, cryptoImpl = globalThis.crypto, maxPages = 1024, maxSchemaBytes = 33554432,
  minChunkBytes = 32768, maxChunkBytes = 262144, targetLatencyMs = 750, onProgress = () => {} } = {}) {
  const base = baseUrl ? new URL(baseUrl) : null;
  if (base && (!['http:', 'https:'].includes(base.protocol) || base.username || base.password)) throw new CatalogError('Invalid trusted REST base');
  if (!base && !callDiscover) throw new CatalogError('A REST base or MCP discovery callback is required');
  const transport = base ? 'authenticated_rest_binary' : 'mcp_base64';
  const bounds = [minChunkBytes, maxChunkBytes];
  if (bounds.some(n => !Number.isInteger(n) || n < 1024 || n > 1048576) || minChunkBytes > maxChunkBytes) throw new CatalogError('Invalid transfer bounds');
  if (!Number.isInteger(maxPages) || maxPages < 1 || maxPages > 10000) throw new CatalogError('Invalid manifest page budget');
  if (!Number.isSafeInteger(maxSchemaBytes) || maxSchemaBytes < 1024 || maxSchemaBytes > 1073741824) throw new CatalogError('Invalid schema memory budget');
  let chunkBytes = minChunkBytes;
  const hash = async bytes => [...new Uint8Array(await cryptoImpl.subtle.digest('SHA-256', bytes))].map(n => n.toString(16).padStart(2, '0')).join('');
  const unwrap = result => {
    if (result?.isError) throw new CatalogError('MCP discovery denied');
    const value = result?.structuredContent ?? (Array.isArray(result?.content) ? JSON.parse(result.content.find(x => x.type === 'text')?.text ?? '{}') : result);
    if (value?.contract !== CONTRACT) throw new CatalogError('Unsupported catalog contract');
    return value;
  };
  function url(path, params) {
    const u = new URL(base);
    if (u.searchParams.has('rest_route')) u.searchParams.set('rest_route', u.searchParams.get('rest_route').replace(/\/$/, '') + '/' + path);
    else u.pathname = u.pathname.replace(/\/$/, '') + '/' + path;
    for (const [key, value] of Object.entries(params)) if (value !== undefined && value !== '') u.searchParams.set(key, String(value));
    return u;
  }
  async function rest(path, params = {}) {
    const response = await fetchImpl(url(path, params), {method: 'GET', headers: await headers(), credentials: 'same-origin', redirect: 'error', cache: 'no-store'});
    if (!response.ok) throw new CatalogError('Catalog HTTP request failed', response.status);
    return response;
  }
  async function request(action, input = {}) {
    if (!base) return unwrap(await callDiscover({transport_action: action, ...input}));
    const response = await rest(action, input); return unwrap(await response.json());
  }
  async function negotiate() {
    const caps = await request('capabilities');
    if (!DIGEST.test(caps.authority_scope_sha256) || !caps.transports?.includes(transport)) throw new CatalogError('Incompatible transport or authority scope');
    if (base && new URL(caps.rest_base_url).origin !== base.origin) throw new CatalogError('Server advertised a different credential origin');
    return caps;
  }
  async function manifest(input = {}, expectedScope) {
    let cursor, snapshot, scope, delta, pages = 0; const items = [], removed = [], seen = new Set(), events = new Set();
    do {
      if (++pages > maxPages) throw new CatalogError('Manifest page budget exhausted');
      const page = await request('manifest', cursor ? {cursor} : input);
      if (!DIGEST.test(page.snapshot) || !DIGEST.test(page.authority_scope_sha256) || !Array.isArray(page.items) || !Array.isArray(page.removed)) throw new CatalogError('Invalid manifest');
      snapshot ??= page.snapshot; scope ??= page.authority_scope_sha256; delta ??= page.delta;
      if (snapshot !== page.snapshot || scope !== page.authority_scope_sha256 || (expectedScope && scope !== expectedScope) || delta !== page.delta) throw new CatalogError('Manifest authority or revision changed');
      for (const item of page.items) { if (typeof item.ability_name !== 'string' || events.has(item.ability_name)) throw new CatalogError('Duplicate or invalid manifest event'); events.add(item.ability_name); items.push(item); }
      for (const name of page.removed) { if (typeof name !== 'string' || events.has(name)) throw new CatalogError('Duplicate or invalid removal'); events.add(name); removed.push(name); }
      cursor = page.next_cursor;
      if (cursor && (typeof cursor !== 'string' || seen.has(cursor))) throw new CatalogError('Repeated manifest cursor');
      if (cursor) seen.add(cursor);
    } while (cursor);
    return {contract: CONTRACT, snapshot, authority_scope_sha256: scope, delta, items, removed};
  }
  async function sync(previous, query = '') {
    const caps = await negotiate();
    const compatible = previous?.authority_scope_sha256 === caps.authority_scope_sha256 && previous.query === query;
    let result;
    try { result = await manifest({query, known_snapshot: compatible ? previous.snapshot : undefined}, caps.authority_scope_sha256); }
    catch (e) { if (!compatible || e.status !== 410) throw e; result = await manifest({query}, caps.authority_scope_sha256); }
    const entries = compatible && result.delta ? new Map(previous.entries) : new Map();
    for (const name of result.removed) entries.delete(name);
    for (const item of result.items) entries.set(item.ability_name, item);
    return {...result, entries, query}; // Caller publishes the complete revision atomically.
  }
  async function readSchema(catalog, abilityName, {format = 'source', state = {chunks: new Map()}, signal} = {}) {
    if (!['source', 'wire'].includes(format)) throw new CatalogError('Invalid schema format');
    const caps = await negotiate();
    if (caps.authority_scope_sha256 !== catalog.authority_scope_sha256) throw new CatalogError('Authority changed; rediscover');
    const descriptor = catalog.entries.get(abilityName)?.[format];
    if (!descriptor || !DIGEST.test(descriptor.sha256) || !Number.isSafeInteger(descriptor.bytes) || descriptor.bytes < 1 || descriptor.bytes > maxSchemaBytes) throw new CatalogError('Schema unavailable or host memory budget exceeded');
    // The fixed chunk size is pinned for this download and its resume state.
    const size = state.chunkBytes ?? chunkBytes;
    if (!Number.isInteger(size) || size < minChunkBytes || size > maxChunkBytes) throw new CatalogError('Invalid resume chunk size');
    const namespace = [base?.origin ?? 'mcp', catalog.authority_scope_sha256, format, descriptor.sha256, size].join(':');
    if (state.namespace && state.namespace !== namespace) throw new CatalogError('Resume authority or schema mismatch');
    state.namespace = namespace; state.chunkBytes = size;
    if (!(state.chunks instanceof Map)) throw new CatalogError('Invalid resume chunk cache');
    const count = Math.ceil(descriptor.bytes / size); let snapshot = catalog.snapshot; let renewed = false;
    for (let i = 0; i < count; i++) {
      if (signal?.aborted) throw signal.reason ?? new CatalogError('Transfer cancelled');
      const expected = Math.min(size, descriptor.bytes - i * size); const cached = state.chunks.get(i);
      if (cached?.bytes instanceof Uint8Array && cached.bytes.length === expected && DIGEST.test(cached.sha256) && await hash(cached.bytes) === cached.sha256) continue;
      let bytes, checksum; const started = performance.now();
      const input = {snapshot, schema_sha256: descriptor.sha256, schema_format: format, chunk_bytes: size, chunk_index: i};
      try {
        if (base) {
          const response = await rest(`schemas/${descriptor.sha256}/chunks/${i}`, input);
          if (response.headers.get('X-MAD4B-Schema-SHA256') !== descriptor.sha256 || Number(response.headers.get('X-MAD4B-Chunk-Count')) !== count) throw new CatalogError('Chunk identity mismatch');
          bytes = new Uint8Array(await response.arrayBuffer()); checksum = response.headers.get('X-MAD4B-Content-SHA256');
        } else {
          const part = await request('chunk', input);
          if (part.schema_sha256 !== descriptor.sha256 || part.chunk_index !== i || part.chunk_count !== count || part.encoding !== 'base64') throw new CatalogError('MCP chunk identity mismatch');
          bytes = Uint8Array.from(atob(part.data), c => c.charCodeAt(0)); checksum = part.chunk_sha256;
        }
      } catch (e) {
        if (e.status === 410 && !renewed) {
          const fresh = catalog.lazy ? (await prepare([abilityName])).catalogs.get(abilityName) : await sync(null);
          if (!fresh) throw new CatalogError('Ability disappeared; replan');
          const item = fresh.entries.get(abilityName);
          if (fresh.authority_scope_sha256 !== catalog.authority_scope_sha256 || item?.[format]?.sha256 !== descriptor.sha256) throw new CatalogError('Schema changed; replan');
          snapshot = fresh.snapshot; renewed = true; i--; continue;
        }
        if ([429, 502, 503, 504].includes(e.status)) chunkBytes = Math.max(minChunkBytes, Math.floor(size / 2));
        throw e; // Never replay mutations or silently switch credentials/transports.
      }
      if (bytes.length !== expected || !DIGEST.test(checksum ?? '') || await hash(bytes) !== checksum) throw new CatalogError('Chunk integrity failure');
      state.chunks.set(i, {bytes, sha256: checksum});
      const elapsed = performance.now() - started;
      chunkBytes = elapsed < targetLatencyMs / 2 ? Math.min(maxChunkBytes, size * 2) : elapsed > targetLatencyMs ? Math.max(minChunkBytes, Math.floor(size / 2)) : size;
      onProgress({abilityName, completedBytes: Math.min(descriptor.bytes, (i + 1) * size), totalBytes: descriptor.bytes, transport, elapsedMs: elapsed});
    }
    const bytes = new Uint8Array(descriptor.bytes);
    for (let i = 0; i < count; i++) bytes.set(state.chunks.get(i).bytes, i * size);
    if (await hash(bytes) !== descriptor.sha256) throw new CatalogError('Schema aggregate integrity failure');
    const json = new TextDecoder('utf-8', {fatal: true}).decode(bytes);
    return {schema: JSON.parse(json), json, sha256: descriptor.sha256, state};
  }
  async function gateway(action, input = {}) {
    let value;
    if (!base) {
      const result = await callDiscover({gateway_action: action, ...input});
      if (result?.isError) throw new CatalogError('Gateway discovery denied');
      value = result?.structuredContent ?? (Array.isArray(result?.content) ? JSON.parse(result.content.find(x => x.type === 'text')?.text ?? '{}') : result);
    } else {
      const endpoint = new URL(base);
      if (endpoint.searchParams.has('rest_route')) endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route').replace(/ability-catalog\/?$/, 'capability-gateway'));
      else endpoint.pathname = endpoint.pathname.replace(/ability-catalog\/?$/, 'capability-gateway');
      const response = await fetchImpl(endpoint, {method: 'POST', headers: {...await headers(), 'Content-Type': 'application/json'}, body: JSON.stringify({action, ...input}), credentials: 'same-origin', redirect: 'error', cache: 'no-store'});
      if (!response.ok) throw new CatalogError('Capability gateway request failed', response.status);
      value = await response.json();
    }
    if (value?.contract !== 'mad4b.unified-capability-gateway.v1') throw new CatalogError('Unsupported gateway contract');
    return value;
  }
  async function search(task, options = {}) { return gateway('search', {task, ...options}); }
  async function prepare(abilityNames, options = {}) {
    const result = await gateway('prepare', {ability_names: abilityNames, ...options});
    const catalogs = new Map();
    for (const item of result.abilities ?? []) {
      if (!item.source || !DIGEST.test(item.authority_scope_sha256 ?? '') || !DIGEST.test(item.snapshot ?? '')) continue;
      const row = {...item, execution: {lane: item.classification, execution_eligible: item.execution_eligible ?? item.execution?.state === 'governed_dispatch', dispatch_state: item.execution?.state, input_schema_sha256: item.input_schema_sha256, classification_sha256: item.classification_sha256}};
      catalogs.set(item.ability_name, {snapshot: item.snapshot, authority_scope_sha256: item.authority_scope_sha256, entries: new Map([[item.ability_name, row]]), lazy: true});
    }
    return {...result, catalogs};
  }
  async function execute(catalog, abilityName, input, {mode = 'dispatch', directToolNames = [], operation} = {}) {
    if (!callTool) throw new CatalogError('Host tool execution callback required');
    const fresh = catalog.lazy ? (await prepare([abilityName])).catalogs.get(abilityName) : await sync(catalog, catalog.query);
    if (!fresh || fresh.authority_scope_sha256 !== catalog.authority_scope_sha256) throw new CatalogError('Authority changed; rediscover');
    const item = fresh.entries.get(abilityName), old = catalog.entries.get(abilityName);
    if (!item || item.source?.sha256 !== old?.source?.sha256 || item.execution?.classification_sha256 !== old?.execution?.classification_sha256) throw new CatalogError('Ability contract changed; replan');
    const execution = item.execution;
    if (!execution?.execution_eligible || !DIGEST.test(execution.input_schema_sha256)) throw new CatalogError('Ability is not eligible for governed execution');
    if (mode === 'direct') {
      if (!old?.wire?.sha256 || !item.wire?.sha256 || item.wire.sha256 !== old.wire.sha256 || item.wire.tool_name !== old.wire.tool_name) throw new CatalogError('Direct tool wire contract changed; refresh and replan');
      if (!item.wire?.tool_name || !directToolNames.includes(item.wire.tool_name)) throw new CatalogError('Host has not confirmed this direct tool');
      return callTool(item.wire.tool_name, input);
    }
    if (mode !== 'dispatch') throw new CatalogError('Invalid execution mode');
    if (execution.dispatch_state === 'requires_dynamic_projection') throw new CatalogError('Host must refresh and confirm this direct projection');
    if (execution.lane === 'read') return callTool('mad4b-read-execute', {ability_name: abilityName, expected_input_schema_sha256: execution.input_schema_sha256, input});
    if (['write', 'developer'].includes(execution.lane)) return callTool(`mad4b-${execution.lane}-execute`, {ability_name: abilityName, expected_input_schema_sha256: execution.input_schema_sha256, input});
    if (execution.lane === 'enrollment' && operation?.ability_name === abilityName && DIGEST.test(operation.expected_registration_digest) && DIGEST.test(operation.expected_dispatch_policy_digest)) return callTool('mad4b-enrollment-execute', {operation_id: operation.operation_id, expected_registration_digest: operation.expected_registration_digest, expected_dispatch_policy_digest: operation.expected_dispatch_policy_digest, expected_input_schema_sha256: execution.input_schema_sha256, input});
    throw new CatalogError('Use the original explicit authority route for this lane');
  }
  return {negotiate, search, prepare, manifest, sync, readSchema, execute, transport};
}
