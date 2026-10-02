(function (root) {
  'use strict';
  const CONTRACT = 'mad4b.endpoint-diagnostic.v1';
  const MAX_BODY = 65536;
  function failure(code, status) {
    const error = new Error(code);
    error.code = code;
    error.status = status || 0;
    return error;
  }
  async function readBody(response) {
    if (!response.body || !response.body.getReader) {
      const value = await response.text();
      if (value.length > MAX_BODY) throw failure('response_too_large');
      return value;
    }
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let bytes = 0;
    let value = '';
    try {
      while (true) {
        const part = await reader.read();
        if (part.done) break;
        bytes += part.value.byteLength;
        if (bytes > MAX_BODY) throw failure('response_too_large');
        value += decoder.decode(part.value, {stream: true});
      }
      return value + decoder.decode();
    } finally {
      // Cancellation is best effort; never await a blocked provider stream.
      if (bytes > MAX_BODY) reader.cancel().catch(function () {});
      reader.releaseLock();
    }
  }
  async function request(config, serverId, environment) {
    const env = environment || root;
    if (!config.servers.includes(serverId)) throw failure('unknown_endpoint');
    const controller = new env.AbortController();
    let timer;
    const work = (async function () {
      const response = await env.fetch(config.url, {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({action: config.action, nonce: config.nonce, server_id: serverId, build: config.build}).toString()
      });
      const content = await readBody(response);
      let payload;
      try { payload = JSON.parse(content); } catch (_) { throw failure('invalid_response', response.status); }
      if (!response.ok || !payload || payload.success !== true) {
        const code = payload && payload.data && typeof payload.data.code === 'string' ? payload.data.code.slice(0, 160) : 'request_failed';
        throw failure(code, response.status);
      }
      const data = payload.data;
      if (!data || data.contract !== CONTRACT || data.build !== config.build || !data.server || data.server.server_id !== serverId || data.certification_performed !== false || data.connection_certified !== false || data.tool_execution_performed !== false || data.outbound_discovery_performed !== false) throw failure('result_identity_invalid');
      for (const key of ['registered', 'route_registered', 'permission_callback_match']) {
        if (data.server[key] !== null && typeof data.server[key] !== 'boolean') throw failure('result_measurement_invalid');
      }
      if (typeof data.server.local_endpoint_ready !== 'boolean' || typeof data.server.catalog_materialized !== 'boolean' || data.server.tool_count !== null && (!Number.isInteger(data.server.tool_count) || data.server.tool_count < 0)) throw failure('result_measurement_invalid');
      if (!Array.isArray(data.server.preflight_failures) || data.server.preflight_failures.length > 12) throw failure('result_evidence_invalid');
      return data;
    })();
    const deadline = new Promise(function (_, reject) {
      timer = env.setTimeout(function () {
        // Reject before abort: browsers may deliver AbortError immediately.
        reject(failure('diagnostic_timeout'));
        controller.abort();
      }, config.timeoutMs);
    });
    try { return await Promise.race([work, deadline]); }
    finally { env.clearTimeout(timer); controller.abort(); }
  }
  async function run(config, ids, onStart, onResult, environment) {
    const results = [];
    for (const serverId of ids) {
      if (onStart) onStart(serverId);
      const result = await request(config, serverId, environment);
      results.push(result);
      if (onResult) onResult(result);
    }
    return results;
  }
  function mount(document, config) {
    const form = document.getElementById('mad4b-endpoint-diagnostic-form');
    if (!form || !config) return;
    const progress = document.getElementById('mad4b-endpoint-diagnostic-progress');
    const results = document.getElementById('mad4b-endpoint-diagnostic-results');
    const button = form.querySelector('input[type="submit"]');
    const select = document.getElementById('mad4b-endpoint-diagnostic-server');
    const label = config.labels;
    let busy = false;
    let stopped = false;
    function measured(value) { return typeof value === 'boolean' ? (value ? 'yes' : 'no') : label.notChecked; }
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (busy || stopped) return;
      busy = true;
      button.disabled = true;
      select.disabled = true;
      const ids = select.value === 'all' ? config.servers : [select.value];
      results.replaceChildren();
      let current = '';
      // A new job never presents an earlier run's result as current evidence.
      document.querySelectorAll('[data-mad4b-endpoint]').forEach(function (row) {
        if (ids.includes(row.getAttribute('data-mad4b-endpoint'))) {
          ['registered', 'route_registered', 'permission_callback_match'].forEach(function (key) { row.querySelector('[data-check="' + key + '"]').textContent = label.notChecked; });
          row.querySelector('[data-check="permission_callback"]').textContent = '';
        }
      });
      if (ids.includes('mad4b-write')) document.querySelectorAll('[data-write-check]').forEach(function (cell) { cell.textContent = label.notChecked; });
      try {
        await run(config, ids, function (id) {
          current = id;
          progress.textContent = label.running + ' ' + id + '…';
        }, function (result) {
          const server = result.server;
          if (server.server_id === 'mad4b-write') document.querySelectorAll('[data-write-check]').forEach(function (cell) {
            const key = cell.getAttribute('data-write-check');
            cell.textContent = key === 'tool_count' ? (Number.isInteger(server[key]) ? String(server[key]) : label.notChecked) : measured(server[key]);
          });
          document.querySelectorAll('[data-mad4b-endpoint]').forEach(function (row) {
            if (row.getAttribute('data-mad4b-endpoint') !== server.server_id) return;
            ['registered', 'route_registered', 'permission_callback_match'].forEach(function (key) { row.querySelector('[data-check="' + key + '"]').textContent = measured(server[key]); });
            row.querySelector('[data-check="permission_callback"]').textContent = server.permission_callback || '';
          });
          const details = document.createElement('details');
          const title = document.createElement('summary');
          title.textContent = server.server_id + ': ' + (server.local_endpoint_ready ? 'ready' : 'blocked') + ' (' + result.elapsed_ms + ' ms)' + (server.registration_error ? ' — ' + server.registration_error : '');
          details.appendChild(title);
          const evidence = document.createElement('pre');
          evidence.style.whiteSpace = 'pre-wrap';
          evidence.style.overflowWrap = 'anywhere';
          evidence.textContent = JSON.stringify(server, null, 2);
          details.appendChild(evidence);
          details.open = !server.local_endpoint_ready || server.preflight_failure_count > 0;
          results.appendChild(details);
        });
        progress.textContent = label.complete;
      } catch (error) {
        // A timeout/504 can leave PHP running. Require a page reload before
        // another attempt; never automatically retry or start sibling jobs.
        stopped = true;
        progress.textContent = label.stopped + ' — ' + current + ': ' + (error.code === 'diagnostic_timeout' ? label.timeout : (error.code || 'request_failed') + (error.status ? ' (HTTP ' + error.status + ')' : '') + '. Reload this page before starting a new diagnostic.');
      } finally {
        busy = false;
        button.disabled = stopped;
        select.disabled = stopped;
      }
    });
  }
  const api = {request: request, run: run, mount: mount};
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root.document) root.document.addEventListener('DOMContentLoaded', function () { mount(root.document, root.mad4bEndpointDiagnostics); });
})(typeof window !== 'undefined' ? window : globalThis);
