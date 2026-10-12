'use strict';
const assert = require('node:assert/strict');
const api = require('../assets/connection-endpoint-diagnostics.js');

const requested = [];
const env = {
  AbortController: globalThis.AbortController,
  setTimeout, clearTimeout,
  fetch: async (_url, options) => {
    requested.push(options.body);
    return {
      status: 409,
      ok: false,
      body: null,
      text: async () => JSON.stringify({
        success: false,
        data: {
          code: 'mad4b_endpoint_diagnostic_mu_bootstrap_not_ready',
          runtime_bootstrap: {
            state: 'exact_adapter_profile_unavailable',
            executed: true,
            eligible: true,
            canonical_symbols_pinned: false,
            critical_class_set_pinned: false,
            diagnostic_mu_proof_valid: true,
            runtime_from_official_plugin: false,
            secret_path: '/private/config/key',
          },
          runtime_recovery: {
            state: 'managed_mu_stale',
            blocker: 'audit_unavailable_for_mu_refresh',
            ownership_receipt_valid: false,
            owned_history_valid: true,
            background_recovery_scheduled: true,
            unexpected_secret: 'never-show',
          },
        }
      })
    };
  }
};
(async () => {
  const config = {
    url: '/wp-admin/admin-ajax.php',
    action: 'mad4b_connection_endpoint_diagnostic',
    servers: ['mad4b-chatgpt'],
    nonce: 'test',
    build: 'a'.repeat(64),
    muProof: 'b'.repeat(64),
    timeoutMs: 5000
  };
  let error = null;
  try { await api.request(config, 'mad4b-chatgpt', env); } catch (e) { error = e; }
  assert.ok(error, 'HTTP 409 must fail closed');
  assert.equal(error.code, 'mad4b_endpoint_diagnostic_mu_bootstrap_not_ready');
  assert.equal(error.status, 409);
  assert.equal(error.bootstrap.state, 'exact_adapter_profile_unavailable');
  assert.equal(error.bootstrap.canonical_symbols_pinned, false);
  assert.equal(error.recovery.blocker, 'audit_unavailable_for_mu_refresh');
  assert.equal(error.recovery.background_recovery_scheduled, true);
  assert.equal(error.recovery.owned_history_valid, true);
  assert.equal(error.bootstrap.secret_path, undefined, 'Do not leak paths');
  assert.equal(error.recovery.unexpected_secret, undefined, 'Do not leak arbitrary payload fields');
  assert.equal(requested.length, 1, 'Uncertain diagnostic writes must not be retried');
  assert.ok(requested[0].includes('mu_proof='), 'Original signed routing proof retained');
  console.log('PASS: bounded MU diagnostic 409 evidence, no secret leakage and no retry');
})().catch(err => { console.error(err); process.exitCode = 1; });
