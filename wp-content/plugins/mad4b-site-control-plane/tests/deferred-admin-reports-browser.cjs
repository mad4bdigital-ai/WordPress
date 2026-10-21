const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(__dirname + '/../assets/deferred-admin-reports.js', 'utf8');
async function scenario(kind) {
 let click, count = 0, timer, resolve;
 const button = {dataset: {report: 'write'}, addEventListener: (_, cb) => {click = cb;}};
 const result = {}, content = {replaceChildren() {}};
 const root = {dataset: {url: '/wp-admin/admin-ajax.php', nonce: 'nonce'}, querySelectorAll: () => [button], querySelector: s => s.includes('result') ? result : content};
 const promise = new Promise(r => {resolve = r;});
 vm.runInNewContext(source, {document: {querySelectorAll: () => [root]}, AbortController, URLSearchParams,
  setTimeout: cb => {timer = cb; return 1;}, clearTimeout() {},
  fetch: () => {count++; return promise;}});
 assert.equal(count, 0, 'no automatic probe');
 const run = click(); await click(); assert.equal(count, 1, 'no concurrent probe');
 if (kind === 'timeout') timer();
 resolve({ok: kind !== '504', status: 504, json: async () => ({success: true, data: {state: 'MEASURED', ready: null, elapsed_ms: 2, query_count: 3}})});
 await run;
 if (kind !== 'success') {
  assert(result.textContent.includes('Not checked'));
  assert.equal(button.disabled, true); await click(); assert.equal(count, 1, 'no retry after uncertain result');
 } else { assert(result.textContent.includes('not verified')); assert.equal(button.disabled, false); }
}
(async () => {for (const kind of ['success', '504', 'timeout']) await scenario(kind); console.log('DEFERRED_ADMIN_REPORTS_BROWSER: PASS');})().catch(e => { console.error(e); process.exit(1); });
