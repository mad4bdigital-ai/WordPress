'use strict';
// Execute the production script with a small DOM/transport harness. This is
// deterministic interaction coverage, not a live WordPress/browser certificate.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const handlers = {}, requests = [], timers = new Map();
let timerId = 0;
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.hidden = true; this.value = ''; }
  set textContent(value) { this.text = String(value); this.children = []; }
  get textContent() { return (this.text || '') + this.children.map(x => x.textContent).join(' '); }
  appendChild(child) { this.children.push(child); }
}
const result = new Element('div'), button = { disabled: false };
const form = {
  fields: {source_id: 'sample', ref_type: 'branch', reference: 'A', reason: 'Read-only review'},
  closest() { return this; },
  querySelector(q) { return q.includes('preview-result') ? result : button; },
  getAttribute() { return '/same-origin-ajax'; },
  setAttribute(k, v) { this[k] = v; },
  removeAttribute(k) { delete this[k]; }
};
class FormData {
  constructor(f) { this.fields = {...f.fields}; }
  get(k) { return this.fields[k]; }
  set(k, v) { this.fields[k] = v; }
}
const window = {
  FormData, AbortController,
  MAD4BUpdateDialog: {eligible: 'مؤهل لطلب موافقة مستقلة'},
  setTimeout(fn, delay) { assert.equal(delay, 30000); const id = ++timerId; timers.set(id, fn); return id; },
  clearTimeout(id) { timers.delete(id); },
  fetch(url, options) {
    assert.equal(url, '/same-origin-ajax');
    assert.equal(options.credentials, 'same-origin');
    return new Promise((resolve, reject) => requests.push({resolve, reject, options}));
  }
};
const document = {
  querySelectorAll() { return []; },
  addEventListener(type, fn) { (handlers[type] ||= []).push(fn); },
  createElement(tag) { return new Element(tag); }
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/plugin-update-dialog.js'), 'utf8'),
  {window, document, FormData, WeakMap, Error, Array, String, JSON});
const settle = () => new Promise(resolve => setImmediate(resolve));
function submit() { handlers.submit[0]({target: form, preventDefault() {}}); }
function change(reference) { form.fields.reference = reference; handlers.input[0]({target: form}); }
function answer(index, sha, state = 'PLAN_ELIGIBLE_NOT_AUTHORIZED') {
  requests[index].resolve({json: async () => ({success: true, data: {state, resolved_sha: sha}})});
}
(async () => {
  submit(); submit();
  assert.equal(requests.length, 1, 'Duplicate submission must not dispatch twice');
  assert(button.disabled);
  change('B');
  assert(requests[0].options.signal.aborted, 'Editing must cancel the old transport');
  assert(!button.disabled);
  submit(); answer(1, 'SHA_B'); await settle();
  assert(result.textContent.includes('SHA_B'));
  assert(result.textContent.includes('مؤهل لطلب موافقة مستقلة'), 'Localized state label missing');
  answer(0, 'SHA_A'); await settle();
  assert(!result.textContent.includes('SHA_A'), 'Late A response overwrote current B');
  assert(result.textContent.includes('SHA_B'));
  change('C');
  assert(!result.textContent.includes('SHA_B'), 'Completed preview survived input drift');
  submit(); form.fields.reference = 'D'; answer(2, 'SHA_C'); await settle();
  assert(!result.textContent.includes('SHA_C'), 'Programmatic input drift escaped snapshot validation');
  submit();
  for (const fn of [...timers.values()]) fn();
  assert(!button.disabled && result.textContent.includes('timed out'));
  answer(3, 'SHA_D'); await settle();
  assert(!result.textContent.includes('SHA_D'), 'Timed-out response resurfaced');
  submit(); requests[4].reject(new Error('offline')); await settle();
  assert(!button.disabled && result.textContent.includes('could not be completed'));
  submit(); answer(5, '', 'NOT_AUTHORIZED'); await settle();
  assert(result.textContent.includes('Not authorized'));
  submit();
  const dialog = {matches: () => true, querySelectorAll: () => [form]};
  handlers.close[0]({target: dialog});
  answer(6, 'SHA_AFTER_CLOSE'); await settle();
  assert(!result.textContent.includes('SHA_AFTER_CLOSE'), 'Closed dialog accepted stale response');
  assert.equal(timers.size, 0, 'Preview timers leaked');
  console.log('PLUGIN_UPDATE_DIALOG: PASS (drift, ordering, duplicate, timeout, offline, denied, close, localization)');
})().catch(error => { console.error(error); process.exitCode = 1; });
