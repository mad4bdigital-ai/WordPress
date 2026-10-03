import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../assets/admin-settings-persistence.js', import.meta.url), 'utf8');

async function fixture(responseKind = 'success', profile = false) {
  const listeners = new Map();
  const controls = [
    { name: 'action', value: 'mad4b_site_profile_save', disabled: false },
    { name: '_wpnonce', value: 'valid-nonce', disabled: false },
    { name: 'environment', value: profile ? 'staging' : 'production', disabled: false },
    { name: 'expected_revision', value: '2', disabled: false },
    { name: 'write_enabled', type: 'checkbox', value: '1', checked: true, disabled: false },
    { name: 'production_write_confirmed', type: 'checkbox', value: '1', checked: true, disabled: false },
    { name: 'production_write_confirmation', value: 'ENABLE GOVERNED PRODUCTION WRITE', disabled: false },
    { name: 'ignored', value: 'excluded', disabled: true },
    { name: 'unchecked', value: '1', type: 'checkbox', checked: false, disabled: false },
  ];
  const feedback = { style: {} };
  const section = { hidden: false, querySelectorAll: () => controls.slice(5, 7) };
  const form = {
    dataset: {}, closest: () => form, setAttribute() {}, removeAttribute() {}, getAttribute: () => '',
    querySelectorAll: selector => selector === '[data-mad4b-one-time-confirm]' ? controls.slice(5, 7) : controls,
    querySelector: selector => selector === '[data-mad4b-settings-feedback]' ? feedback : selector === '[data-mad4b-production-confirmation]' ? section : controls.find(c => selector.includes(`"${c.name}"`)),
  };
  let calls = 0, fallback = 0, body;
  const document = {
    querySelector: selector => profile && selector === '#mad4b-site-profile-settings' ? form : null,
    addEventListener: (name, fn) => listeners.set(name, fn),
    dispatchEvent: event => listeners.get(event.type)?.(event),
  };
  class FormData {
    constructor(form) { this.entries = controls.filter(c => !c.disabled && (c.type !== 'checkbox' || c.checked)).map(c => [c.name, c.value]); }
    [Symbol.iterator]() { return this.entries[Symbol.iterator](); }
  }
  const context = {
    document, URLSearchParams, FormData,
    CustomEvent: class { constructor(type, detail) { this.type = type; this.detail = detail; } },
    window: { MAD4BAdminSettingsPersistence: { ajaxUrl: '/admin-ajax.php' }, HTMLFormElement: { prototype: { submit() { fallback++; assert.equal(controls[0].disabled, false); } } } },
    fetch: async (url, options) => {
      calls++; body = new URLSearchParams(options.body);
      return { status: responseKind === 'sentinel' ? 400 : 200, text: async () => responseKind === 'sentinel' ? '0' : responseKind === 'malformed' ? '<html>bad</html>' : JSON.stringify(responseKind === 'nonce' ? { success: false, data: { code: 'nonce_invalid', message: 'Reload' } } : { success: true, data: { persistence_verified: true, readback: { revision: 1 } } }) };
    },
  };
  vm.runInNewContext(source, context);
  if (profile) {
    assert.equal(section.hidden, true);
    assert.equal(controls[5].checked, false);
    assert.equal(controls[6].value, '');
    controls[2].value = 'production'; listeners.get('change')();
    assert.equal(section.hidden, false);
    assert.equal(controls[6].required, true);
    controls[5].checked = true; controls[6].value = 'ENABLE GOVERNED PRODUCTION WRITE';
  }
  await listeners.get('submit')({ target: form, preventDefault() {} });
  assert.equal(body.get('action'), 'mad4b_site_profile_save');
  assert.equal(body.get('_wpnonce'), 'valid-nonce');
  assert.equal(body.get('environment'), 'production');
  assert.equal(body.get('expected_revision'), '2');
  assert.equal(body.get('production_write_confirmation'), 'ENABLE GOVERNED PRODUCTION WRITE');
  assert.equal(body.get('ignored'), null); assert.equal(body.get('unchecked'), null);
  assert.equal(controls[0].disabled, false); assert.equal(controls[7].disabled, true);
  assert.equal(fallback, responseKind === 'sentinel' ? 1 : 0);
  assert.equal(calls, 1);
  if (responseKind === 'success') {
    assert.equal(controls[3].value, '1');
    assert.equal(controls[5].checked, false); assert.equal(controls[6].value, '');
  }
}
for (const kind of ['success', 'sentinel', 'nonce', 'malformed']) await fixture(kind);
await fixture('success', true);
console.log('mad4b.admin-settings-persistence-runtime.v1: 5/5 PASS');
