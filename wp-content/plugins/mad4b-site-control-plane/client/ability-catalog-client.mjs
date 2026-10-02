const CONTRACT = 'mad4b.ability-catalog-transport.v2';
const DIGEST = /^[a-f0-9]{64}$/;
export class CatalogError extends Error {
  constructor(message, status = 0) { super(message); this.status = status; }
}

/** Host adapter: explicit trusted REST origin or explicit MCP discovery callback. No implicit credentials. */
export function createAbilityCatalogClient({ baseUrl, headers = async () => ({}), fetchImpl = globalThis.fetch,
  callDiscover, callTool, cryptoImpl = globalThis.crypto, maxPages = 1024, maxSchemaBytes = 33554432,
  minChunkBytes = 32768, maxChunkBytes = 262144, targetLatencyMs = 750, maxParallelSchemaFetches = 4,
  credentialMode = 'omit', requestTimeoutMs = 15000, maxResponseBytes = 1048576, onProgress = () => {} } = {}) {
  const base = baseUrl ? new URL(baseUrl) : null;
  if (base && (!['http:', 'https:'].includes(base.protocol) || base.username || base.password)) throw new CatalogError('Invalid trusted REST base');
  if (!base && !callDiscover) throw new CatalogError('A REST base or MCP discovery callback is required');
  const transport = base ? 'authenticated_rest_binary' : 'mcp_base64';
  const bounds = [minChunkBytes, maxChunkBytes];
  if (bounds.some(n => !Number.isInteger(n) || n < 1024 || n > 1048576) || minChunkBytes > maxChunkBytes) throw new CatalogError('Invalid transfer bounds');
  if (!Number.isInteger(maxPages) || maxPages < 1 || maxPages > 10000) throw new CatalogError('Invalid manifest page budget');
  if (!Number.isSafeInteger(maxSchemaBytes) || maxSchemaBytes < 1024 || maxSchemaBytes > 1073741824) throw new CatalogError('Invalid schema memory budget');
  if (!Number.isInteger(maxParallelSchemaFetches) || maxParallelSchemaFetches < 1 || maxParallelSchemaFetches > 8) throw new CatalogError('Invalid schema parallelism budget');
  if (!['omit', 'same-origin'].includes(credentialMode)) throw new CatalogError('Invalid credential mode');
  if (!Number.isInteger(requestTimeoutMs) || requestTimeoutMs < 1 || requestTimeoutMs > 300000) throw new CatalogError('Invalid request timeout');
  if (!Number.isSafeInteger(maxResponseBytes) || maxResponseBytes < 4096 || maxResponseBytes > 16777216) throw new CatalogError('Invalid response budget');
  let chunkBytes = minChunkBytes, parallelFetches = base ? Math.min(2, maxParallelSchemaFetches) : 1;
  const hash = async bytes => [...new Uint8Array(await cryptoImpl.subtle.digest('SHA-256', bytes))].map(n => n.toString(16).padStart(2, '0')).join('');
  function unwrapError(result) {
    if (!result?.isError && !result?.error) return;
    let detail = result.structuredContent ?? result.error;
    if (!detail) {
      try { detail = JSON.parse(result.content?.find(x => x.type === 'text')?.text ?? '{}'); } catch { detail = {}; }
    }
    detail = detail?.error ?? detail;
    const status = detail?.data?.status ?? detail?.status ?? 0;
    const error = new CatalogError(detail?.message ?? 'MCP request failed', Number.isInteger(status) ? status : 0);
    error.code = detail?.code;
    throw error;
  }
  async function boundedRequest(operation, signal) {
    const controller = new AbortController();
    const abort = () => controller.abort(signal.reason ?? new CatalogError('Transfer cancelled'));
    if (signal?.aborted) abort(); else signal?.addEventListener('abort', abort, {once: true});
    const timer = setTimeout(() => controller.abort(new CatalogError('Catalog request timed out', 408)), requestTimeoutMs);
    let rejectAbort;
    const interrupted = new Promise((_, reject) => { rejectAbort = () => reject(controller.signal.reason); });
    controller.signal.addEventListener('abort', rejectAbort, {once: true});
    try {
      controller.signal.throwIfAborted();
      return await Promise.race([operation(controller.signal), interrupted]);
    } finally {
      clearTimeout(timer); signal?.removeEventListener('abort', abort);
      controller.signal.removeEventListener('abort', rejectAbort);
    }
  }
  async function readBounded(response, limit, signal) {
    const length = response.headers.get('Content-Length');
    if (length !== null && (!/^\d+$/.test(length) || Number(length) > limit)) {
      void response.body?.cancel().catch(() => {});
      throw new CatalogError('Response memory budget exceeded');
    }
    if (!response.body) return new Uint8Array();
    const reader = response.body.getReader(); const parts = []; let total = 0;
    const cancel = () => { void reader.cancel(signal.reason).catch(() => {}); };
    signal.addEventListener('abort', cancel, {once: true});
    try {
      while (true) {
        signal.throwIfAborted();
        const {done, value} = await reader.read();
        signal.throwIfAborted();
        if (done) break;
        total += value.byteLength;
        if (total > limit) throw new CatalogError('Response memory budget exceeded');
        parts.push(value);
      }
      const bytes = new Uint8Array(total); let offset = 0;
      for (const part of parts) { bytes.set(part, offset); offset += part.byteLength; }
      return bytes;
    } catch (error) { void reader.cancel(error).catch(() => {}); throw error; }
    finally { signal.removeEventListener('abort', cancel); reader.releaseLock(); }
  }
  async function http(endpoint, init, limit, signal) {
    return boundedRequest(async requestSignal => {
      const response = await fetchImpl(endpoint, {...init, signal: requestSignal});
      requestSignal.throwIfAborted();
      if (!response.ok) throw new CatalogError('Catalog HTTP request failed', response.status);
      const bytes = await readBounded(response, limit, requestSignal);
      requestSignal.throwIfAborted();
      return {response, bytes};
    }, signal);
  }
  const decode = bytes => JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(bytes));
  const unwrap = result => {
    unwrapError(result);
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
  async function rest(path, params = {}, {signal, limit = maxResponseBytes} = {}) {
    return http(url(path, params), {method: 'GET', headers: await headers(), credentials: credentialMode, redirect: 'error', cache: 'no-store'}, limit, signal);
  }
  async function request(action, input = {}, {signal} = {}) {
    if (!base) return unwrap(await boundedRequest(requestSignal => callDiscover({transport_action: action, ...input}, {signal: requestSignal}), signal));
    const {bytes} = await rest(action, input, {signal}); return unwrap(decode(bytes));
  }
  async function negotiate({signal} = {}) {
    const caps = await request('capabilities', {}, {signal});
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
    const caps = await negotiate({signal});
    if (caps.authority_scope_sha256 !== catalog.authority_scope_sha256) throw new CatalogError('Authority changed; rediscover');
    const descriptor = catalog.entries.get(abilityName)?.[format];
    if (!descriptor || !DIGEST.test(descriptor.sha256) || !Number.isSafeInteger(descriptor.bytes) || descriptor.bytes < 1 || descriptor.bytes > maxSchemaBytes) throw new CatalogError('Schema unavailable or host memory budget exceeded');
    // Chunk size is pinned for one download/resume namespace; REST parallelism may adapt between bounded windows.
    const size = state.chunkBytes ?? chunkBytes;
    if (!Number.isInteger(size) || size < minChunkBytes || size > maxChunkBytes) throw new CatalogError('Invalid resume chunk size');
    const namespace = [base?.origin ?? 'mcp', catalog.authority_scope_sha256, format, descriptor.sha256, size].join(':');
    if (state.namespace && state.namespace !== namespace) throw new CatalogError('Resume authority or schema mismatch');
    state.namespace = namespace; state.chunkBytes = size;
    if (!(state.chunks instanceof Map)) throw new CatalogError('Invalid resume chunk cache');
    const serverRecommended = catalog.transferPolicy?.recommended_parallel_schema_fetches;
    let serverParallelCeiling = base && Number.isInteger(serverRecommended)
      ? Math.max(1, Math.min(maxParallelSchemaFetches, serverRecommended))
      : maxParallelSchemaFetches;
    if (state.parallelism !== undefined && (!Number.isInteger(state.parallelism) || state.parallelism < 1 || state.parallelism > maxParallelSchemaFetches)) throw new CatalogError('Invalid resume parallelism');
    let activeParallel = base
      ? Math.max(1, Math.min(serverParallelCeiling, state.parallelism ?? parallelFetches))
      : 1;
    state.parallelism = activeParallel;
    const count = Math.ceil(descriptor.bytes / size);
    let snapshot = catalog.snapshot, renewed = false, completedBytes = 0;
    const remaining = new Set();
    for (let i = 0; i < count; i++) {
      const expected = Math.min(size, descriptor.bytes - i * size), cached = state.chunks.get(i);
      const valid = cached?.bytes instanceof Uint8Array
        && cached.bytes.length === expected
        && DIGEST.test(cached.sha256)
        && await hash(cached.bytes) === cached.sha256;
      if (valid) completedBytes += expected;
      else { state.chunks.delete(i); remaining.add(i); }
    }
    const fetchChunk = async i => {
      if (signal?.aborted) throw signal.reason ?? new CatalogError('Transfer cancelled');
      const expected = Math.min(size, descriptor.bytes - i * size);
      let bytes, checksum; const started = performance.now();
      const input = {snapshot, schema_sha256: descriptor.sha256, schema_format: format, chunk_bytes: size, chunk_index: i};
      if (base) {
        const {response, bytes: bodyBytes} = await rest(`schemas/${descriptor.sha256}/chunks/${i}`, input, {signal, limit: expected});
        if (response.headers.get('X-MAD4B-Schema-SHA256') !== descriptor.sha256 || Number(response.headers.get('X-MAD4B-Chunk-Count')) !== count) throw new CatalogError('Chunk identity mismatch');
        bytes = bodyBytes; checksum = response.headers.get('X-MAD4B-Content-SHA256');
      } else {
        const part = await request('chunk', input, {signal});
        if (part.schema_sha256 !== descriptor.sha256 || part.chunk_index !== i || part.chunk_count !== count || part.encoding !== 'base64') throw new CatalogError('MCP chunk identity mismatch');
        if (typeof part.data !== 'string' || part.data.length > 4 * Math.ceil(expected / 3)) throw new CatalogError('Response memory budget exceeded');
        bytes = Uint8Array.from(atob(part.data), c => c.charCodeAt(0)); checksum = part.chunk_sha256;
      }
      if (bytes.length !== expected || !DIGEST.test(checksum ?? '') || await hash(bytes) !== checksum) throw new CatalogError('Chunk integrity failure');
      state.chunks.set(i, {bytes, sha256: checksum});
      completedBytes += bytes.length;
      const elapsed = performance.now() - started;
      onProgress({abilityName, completedBytes: Math.min(descriptor.bytes, completedBytes), totalBytes: descriptor.bytes, transport, elapsedMs: elapsed, parallelism: activeParallel});
      return {index: i, elapsed};
    };
    while (remaining.size) {
      if (signal?.aborted) throw signal.reason ?? new CatalogError('Transfer cancelled');
      const window = Array.from(remaining).slice(0, activeParallel);
      const settled = await Promise.allSettled(window.map(fetchChunk));
      const successful = [], failures = [];
      for (const result of settled) {
        if (result.status === 'fulfilled') { successful.push(result.value); remaining.delete(result.value.index); }
        else failures.push(result.reason);
      }
      if (failures.length) {
        const expired = failures.find(error => error?.status === 410);
        if (expired && !renewed) {
          const fresh = (await prepare([abilityName], {signal})).catalogs.get(abilityName);
          if (!fresh) throw new CatalogError('Ability disappeared; replan');
          const item = fresh.entries.get(abilityName);
          if (fresh.authority_scope_sha256 !== catalog.authority_scope_sha256 || item?.[format]?.sha256 !== descriptor.sha256) throw new CatalogError('Schema changed; replan');
          snapshot = fresh.snapshot; renewed = true;
          const refreshedRecommendation = fresh.transferPolicy?.recommended_parallel_schema_fetches;
          if (base && Number.isInteger(refreshedRecommendation)) {
            serverParallelCeiling = Math.max(1, Math.min(maxParallelSchemaFetches, refreshedRecommendation));
            activeParallel = Math.min(activeParallel, serverParallelCeiling);
          }
          state.parallelism = activeParallel;
          continue;
        }
        if (failures.some(error => [429, 502, 503, 504].includes(error?.status))) chunkBytes = Math.max(minChunkBytes, Math.floor(size / 2));
        if (base) {
          activeParallel = Math.max(1, Math.floor(activeParallel / 2));
          parallelFetches = activeParallel; state.parallelism = activeParallel;
        }
        throw failures[0];
      }
      if (successful.length) {
        const averageElapsed = successful.reduce((sum, item) => sum + item.elapsed, 0) / successful.length;
        chunkBytes = averageElapsed < targetLatencyMs / 2 ? Math.min(maxChunkBytes, size * 2) : averageElapsed > targetLatencyMs ? Math.max(minChunkBytes, Math.floor(size / 2)) : size;
        if (base) {
          activeParallel = averageElapsed < targetLatencyMs / 2
            ? Math.min(serverParallelCeiling, activeParallel + 1)
            : averageElapsed > targetLatencyMs ? Math.max(1, activeParallel - 1) : activeParallel;
          parallelFetches = activeParallel; state.parallelism = activeParallel;
        }
      }
    }
    if (signal?.aborted) throw signal.reason ?? new CatalogError('Transfer cancelled');
    const bytes = new Uint8Array(descriptor.bytes);
    for (let i = 0; i < count; i++) bytes.set(state.chunks.get(i).bytes, i * size);
    if (await hash(bytes) !== descriptor.sha256) throw new CatalogError('Schema aggregate integrity failure');
    const json = new TextDecoder('utf-8', {fatal: true}).decode(bytes);
    if (signal?.aborted) throw signal.reason ?? new CatalogError('Transfer cancelled');
    return {schema: JSON.parse(json), json, sha256: descriptor.sha256, state};
  }
  async function gateway(action, input = {}, {signal} = {}) {
    let value;
    if (!base) {
      const result = await boundedRequest(requestSignal => callDiscover({gateway_action: action, ...input}, {signal: requestSignal}), signal);
      unwrapError(result);
      value = result?.structuredContent ?? (Array.isArray(result?.content) ? JSON.parse(result.content.find(x => x.type === 'text')?.text ?? '{}') : result);
    } else {
      const endpoint = new URL(base);
      if (endpoint.searchParams.has('rest_route')) endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route').replace(/ability-catalog\/?$/, 'capability-gateway'));
      else endpoint.pathname = endpoint.pathname.replace(/ability-catalog\/?$/, 'capability-gateway');
      const {bytes} = await http(endpoint, {method: 'POST', headers: {...await headers(), 'Content-Type': 'application/json'}, body: JSON.stringify({action, ...input}), credentials: credentialMode, redirect: 'error', cache: 'no-store'}, maxResponseBytes, signal);
      value = decode(bytes);
    }
    if (value?.contract !== 'mad4b.unified-capability-gateway.v1') throw new CatalogError('Unsupported gateway contract');
    return value;
  }
  async function search(task, {signal, ...options} = {}) { return gateway('search', {task, ...options}, {signal}); }
  async function prepare(abilityNames, options = {}) {
    const {client_capabilities: suppliedCapabilities = {}, signal, ...gatewayOptions} = options;
    const requestedParallel = Number.isInteger(suppliedCapabilities.max_parallel_schema_fetches)
      ? Math.min(maxParallelSchemaFetches, Math.max(1, suppliedCapabilities.max_parallel_schema_fetches))
      : maxParallelSchemaFetches;
    const result = await gateway('prepare', {
      ability_names: abilityNames,
      ...gatewayOptions,
      client_capabilities: {...suppliedCapabilities, max_parallel_schema_fetches: requestedParallel},
    }, {signal});
    const catalogs = new Map();
    for (const item of result.abilities ?? []) {
      if (!item.source || !DIGEST.test(item.authority_scope_sha256 ?? '') || !DIGEST.test(item.snapshot ?? '')) continue;
      const row = {...item, execution: {lane: item.classification, execution_eligible: item.execution_eligible !== false && ['governed_dispatch', 'requires_dynamic_projection', 'requires_operation_resolution'].includes(item.execution?.state), dispatch_state: item.execution?.state, input_schema_sha256: item.input_schema_sha256, classification_sha256: item.classification_sha256}};
      catalogs.set(item.ability_name, {snapshot: item.snapshot, authority_scope_sha256: item.authority_scope_sha256, entries: new Map([[item.ability_name, row]]), transferPolicy: result.transfer_policy, lazy: true});
    }
    return {...result, catalogs};
  }
  async function execute(catalog, abilityName, input, {mode = 'dispatch', directToolNames = [], operation} = {}) {
    if (!callTool) throw new CatalogError('Host tool execution callback required');
    // Always resolve the selected target through one authoritative descriptor.
    const fresh = (await prepare([abilityName])).catalogs.get(abilityName);
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
