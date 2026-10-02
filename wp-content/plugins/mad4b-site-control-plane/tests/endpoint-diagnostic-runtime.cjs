'use strict';
const assert = require('node:assert/strict');
const {request, run, mount} = require('../assets/connection-endpoint-diagnostics.js');
const config = {url: '/wp-admin/admin-ajax.php', action: 'mad4b_connection_endpoint_diagnostic', nonce: 'fixture', build: 'fixture-build', servers: ['mad4b-chatgpt', 'mad4b-read'], timeoutMs: 15};
const valid = id => ({success: true, data: {contract: 'mad4b.endpoint-diagnostic.v1', build: config.build, certification_performed: false, connection_certified: false, tool_execution_performed: false, outbound_discovery_performed: false, server: {server_id: id, registered: true, route_registered: true, permission_callback_match: true, local_endpoint_ready: true, catalog_materialized: true, tool_count: 1, preflight_failures: []}}});
const response = (payload, status = 200) => ({ok: status === 200, status, text: async () => typeof payload === 'string' ? payload : JSON.stringify(payload)});
const env = fetch => ({fetch, AbortController, setTimeout, clearTimeout});
const expectCode = (promise, code) => assert.rejects(promise, error => error.code === code);
(async function () {
  let active = 0, peak = 0, calls = 0;
  const success = env(async (_url, options) => {
    calls++; active++; peak = Math.max(peak, active);
    assert.equal(options.method, 'POST'); assert.equal(options.credentials, 'same-origin');
    const body = new URLSearchParams(options.body);
    assert.equal(body.get('nonce'), config.nonce); assert.equal(body.get('build'), config.build);
    await new Promise(resolve => setTimeout(resolve, 1)); active--;
    return response(valid(body.get('server_id')));
  });
  assert.equal((await run(config, config.servers, null, null, success)).length, 2);
  assert.equal(peak, 1); assert.equal(calls, 2);
  const started = [], completed = [];
  await expectCode(run(config, config.servers, id => started.push(id), result => completed.push(result), env(() => new Promise(() => {}))), 'diagnostic_timeout');
  assert.deepEqual(started, ['mad4b-chatgpt']); assert.equal(completed.length, 0);
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => ({ok: true, status: 200, text: () => new Promise(() => {})}))), 'diagnostic_timeout');
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response('<h1>504 Gateway Time-out</h1>', 504))), 'invalid_response');
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response('-1', 403))), 'request_failed');
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response({success: false, data: {code: 'mad4b_endpoint_diagnostic_nonce_invalid'}}, 403))), 'mad4b_endpoint_diagnostic_nonce_invalid');
  const stale = valid('mad4b-chatgpt'); stale.data.build = 'new-build';
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response(stale))), 'result_identity_invalid');
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response(valid('mad4b-read')))), 'result_identity_invalid');
  const invented = valid('mad4b-chatgpt'); invented.data.server.route_registered = 'yes';
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response(invented))), 'result_measurement_invalid');
  const certified = valid('mad4b-chatgpt'); certified.data.connection_certified = true;
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response(certified))), 'result_identity_invalid');
  await expectCode(request(config, 'foreign', success), 'unknown_endpoint');
  await expectCode(request(config, 'mad4b-chatgpt', env(async () => response('x'.repeat(65537)))), 'response_too_large');
  let aborted = false, lateResults = 0;
  await expectCode(run(config, config.servers, null, () => lateResults++, env((_url, options) => {
    options.signal.addEventListener('abort', () => { aborted = true; });
    return new Promise(resolve => setTimeout(() => resolve(response(valid('mad4b-chatgpt'))), 30));
  })), 'diagnostic_timeout');
  await new Promise(resolve => setTimeout(resolve, 35));
  assert.equal(aborted, true); assert.equal(lateResults, 0);
  let secondCalls = 0, preservedResults = [];
  await expectCode(run(config, config.servers, null, result => preservedResults.push(result), env(async () => ++secondCalls === 1 ? response(valid('mad4b-chatgpt')) : response('Gateway Time-out', 504))), 'invalid_response');
  assert.equal(secondCalls, 2); assert.equal(preservedResults.length, 1);
  // Merely mounting the page installs a listener and starts no request.
  let submit;
  const button = {}, select = {value: 'mad4b-chatgpt'}, progress = {}, results = {replaceChildren() {}};
  const doc = {getElementById: id => ({'mad4b-endpoint-diagnostic-form': {querySelector: () => button, addEventListener: (_event, cb) => { submit = cb; }}, 'mad4b-endpoint-diagnostic-progress': progress, 'mad4b-endpoint-diagnostic-results': results, 'mad4b-endpoint-diagnostic-server': select}[id]), querySelectorAll: () => []};
  mount(doc, {...config, labels: {stopped: 'Stopped', timeout: 'Timeout', running: 'Checking'}});
  assert.equal(typeof submit, 'function'); assert.equal(button.disabled, undefined);
  // Duplicate clicks and clicks after a timeout cannot start overlapping work.
  const priorFetch = globalThis.fetch;
  let mountedCalls = 0;
  globalThis.fetch = () => { mountedCalls++; return new Promise(() => {}); };
  try {
    const pending = submit({preventDefault() {}});
    await submit({preventDefault() {}});
    assert.equal(mountedCalls, 1);
    await pending;
    assert.equal(button.disabled, true); assert.equal(select.disabled, true);
    assert.match(progress.textContent, /mad4b-chatgpt.*Timeout/);
    await submit({preventDefault() {}});
    assert.equal(mountedCalls, 1);
  } finally { globalThis.fetch = priorFetch; }
  console.log('PASS endpoint diagnostic client: serialized jobs, fetch/body deadlines, 504/auth/schema faults, stale builds, late results, partial results and passive mounting');
})().catch(error => { console.error(error); process.exitCode = 1; });
