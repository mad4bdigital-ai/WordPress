import {createAbilityCatalogClient, CatalogError} from './ability-catalog-client.mjs';

/** Explicit per-blog endpoints and credentials. Each site gets a fresh REST registry context. */
export function createNetworkCatalogClient(sites, {maxSites = 100} = {}) {
  if (!Array.isArray(sites) || !Number.isInteger(maxSites) || maxSites < 1 || maxSites > 100 || sites.length > maxSites) throw new CatalogError('Invalid network site budget');
  const contexts = new Map(), owners = new WeakMap(), endpoints = new Set();
  for (const site of sites) {
    if (!site || typeof site.id !== 'string' || !/^[a-zA-Z0-9_-]{1,80}$/.test(site.id) || contexts.has(site.id)) throw new CatalogError('Explicit unique site identity required');
    const url = new URL(site.baseUrl);
    if (url.protocol !== 'https:' || url.username || url.password || url.hash || endpoints.has(url.href) || !/\/ability-catalog\/?$/.test(url.pathname)) throw new CatalogError('Explicit unique HTTPS catalog endpoint required');
    if (typeof site.headers !== 'function' || typeof site.callTool !== 'function' || !/^[a-f0-9]{64}$/.test(site.authorityScopeSha256 ?? '')) throw new CatalogError('Per-site credentials, tool transport and enrolled authority digest required');
    endpoints.add(url.href);
    contexts.set(site.id, {scope: site.authorityScopeSha256, client: createAbilityCatalogClient({baseUrl: url.href, expectedAuthorityScopeSha256: site.authorityScopeSha256, headers: site.headers, callTool: site.callTool, fetchImpl: site.fetchImpl, cryptoImpl: site.cryptoImpl})});
  }
  function context(id) { const value = contexts.get(id); if (!value) throw new CatalogError('Site is not explicitly enrolled'); return value; }
  async function prepare(id, names, options = {}) {
    const {client, scope} = context(id), result = await client.prepare(names, options);
    for (const catalog of result.catalogs.values()) {
      if (catalog.authority_scope_sha256 !== scope) throw new CatalogError('Site authority drift; reenroll explicitly');
      owners.set(catalog, id);
    }
    return result;
  }
  async function execute(id, catalog, name, input, options = {}) {
    const {client, scope} = context(id);
    if (owners.get(catalog) !== id || catalog.authority_scope_sha256 !== scope) throw new CatalogError('Catalog belongs to a different site context');
    // No mutation retries, shared hot set, credentials, or cross-site fallback.
    if (!['read', 'write', 'content', 'admin', 'developer'].includes(catalog.entries.get(name)?.execution?.lane)) throw new CatalogError('Network target requires an explicit exceptional authority route');
    if (options.mode && options.mode !== 'dispatch') throw new CatalogError('Network execution requires the site-pinned fixed dispatcher');
    return client.execute(catalog, name, input, options);
  }
  async function run(jobs, {signal} = {}) {
    if (!Array.isArray(jobs) || jobs.length > maxSites) throw new CatalogError('Network job budget exceeded');
    const results = [];
    for (const job of jobs) {
      try {
        signal?.throwIfAborted();
        const prepared = await prepare(job.siteId, [job.abilityName], {signal});
        const catalog = prepared.catalogs.get(job.abilityName);
        if (!catalog) throw new CatalogError('Target is unavailable at this site');
        const result = await execute(job.siteId, catalog, job.abilityName, job.input, {
          signal,
          approvalTicketId: job.approvalTicketId,
          contextReceipt: job.contextReceipt,
        });
        results.push({siteId: job.siteId, abilityName: job.abilityName, result});
      } catch (cause) {
        const error = new CatalogError(cause?.message ?? String(cause));
        error.cause = cause; error.completed = [...results]; error.failedSiteId = job?.siteId;
        throw error;
      }
    }
    return results;
  }
  return {prepare, execute, run};
}
