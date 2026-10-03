import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/admin-settings-persistence.js', import.meta.url), 'utf8');

async function fixture(responseKind = 'success', profile = false, refreshMode = '') {
  const listeners = new Map();
  const controls = [
    { name: 'action', value: 'mad4b_site_profile_save', disabled: false },
    { name: '_wpnonce', value: 'valid-nonce', disabled: false },
    { name: 'environment', value: profile ? 'staging' : 'production', disabled: false },
    { name: 'expected_revision', value: '2', disabled: false },
    { name: 'expected_profile_digest', value: 'before-digest', disabled: false },
    { name: 'write_enabled', type: 'checkbox', value: '1', checked: true, disabled: false },
    { name: 'production_write_confirmed', type: 'checkbox', value: '1', checked: true, disabled: false },
    { name: 'production_write_confirmation', value: 'ENABLE GOVERNED PRODUCTION WRITE', disabled: false },
    { name: 'ignored', value: 'excluded', disabled: true },
    { name: 'unchecked', value: '1', type: 'checkbox', checked: false, disabled: false },
  ];
  const feedback = { style: {}, textContent: '', dataset: {} };
  const section = { hidden: false, querySelectorAll: () => controls.slice(6, 8) };
  const selector = refreshMode ? '#mad4b-workspace' : '';
  const form = {
    dataset: {},
    closest: () => form,
    setAttribute() {},
    removeAttribute() {},
    getAttribute: name => name === 'data-mad4b-refresh-selector' ? selector : '',
    matches: value => value === '.mad4b-settings-ajax-form',
    querySelectorAll: value => value === '[data-mad4b-one-time-confirm]' ? controls.slice(6, 8) : controls,
    querySelector: value => value === '[data-mad4b-settings-feedback]'
      ? feedback
      : value === '[data-mad4b-production-confirmation]'
        ? section
        : controls.find(c => value.includes(`"${c.name}"`)) || null,
  };

  let replacedWith = null;
  const currentWorkspace = { replaceWith(node) { replacedWith = node; } };
  const nextRevision = refreshMode === 'stale' ? '999' : '3';
  const nextDigest = refreshMode === 'stale' ? 'wrong-digest' : 'after-digest';
  const nextForm = {
    matches: value => value === '.mad4b-settings-ajax-form',
    querySelector: value => {
      if (value.includes('expected_revision')) return { value: nextRevision };
      if (value.includes('expected_profile_digest')) return { value: nextDigest };
      return null;
    },
  };
  const nextWorkspace = {
    matches: () => false,
    querySelector: value => value === '.mad4b-settings-ajax-form' ? nextForm : null,
  };

  let calls = 0, fallback = 0, postBody = null;
  const document = {
    querySelector: value => {
      if (profile && value === '#mad4b-site-profile-settings') return form;
      if (value === selector && selector) return currentWorkspace;
      return null;
    },
    addEventListener: (name, fn) => listeners.set(name, fn),
    dispatchEvent: event => listeners.get(event.type)?.(event),
  };

  class FormData {
    constructor() {
      this.entries = controls
        .filter(c => !c.disabled && (c.type !== 'checkbox' || c.checked))
        .map(c => [c.name, c.value]);
    }
    [Symbol.iterator]() { return this.entries[Symbol.iterator](); }
  }

  class DOMParser {
    parseFromString(html) {
      return {
        querySelector(value) {
          if (!selector || value !== selector) return null;
          if (html === 'LOGIN_PAGE' || refreshMode === 'missing') return null;
          return nextWorkspace;
        }
      };
    }
  }

  const context = {
    document,
    DOMParser,
    URLSearchParams,
    FormData,
    CustomEvent: class { constructor(type, detail) { this.type = type; this.detail = detail; } },
    window: {
      location: { href: '/wp-admin/admin.php?page=mad4b' },
      MAD4BAdminSettingsPersistence: {
        ajaxUrl: '/admin-ajax.php',
        savedViewRefreshFailed: 'Saved, but view refresh could not be verified.'
      },
      HTMLFormElement: {
        prototype: {
          submit() {
            fallback++;
            assert.equal(controls[0].disabled, false);
          }
        }
      }
    },
    fetch: async (url, options = {}) => {
      calls++;
      if ((options.method || 'GET').toUpperCase() === 'POST') {
        postBody = new URLSearchParams(options.body);
        const text = responseKind === 'sentinel'
          ? '0'
          : responseKind === 'malformed'
            ? '<html>bad</html>'
            : JSON.stringify(
              responseKind === 'nonce'
                ? { success: false, data: { code: 'nonce_invalid', message: 'Reload' } }
                : {
                    success: true,
                    data: {
                      persistence_verified: true,
                      message: 'Saved and verified.',
                      readback: { revision: 3, profile_digest: 'after-digest' }
                    }
                  }
            );
        return {
          ok: responseKind !== 'sentinel',
          status: responseKind === 'sentinel' ? 400 : 200,
          text: async () => text
        };
      }
      return {
        ok: true,
        status: 200,
        text: async () => refreshMode === 'missing' ? 'LOGIN_PAGE' : '<div>workspace</div>'
      };
    },
  };

  vm.runInNewContext(source, context);

  if (profile) {
    assert.equal(section.hidden, true);
    assert.equal(controls[6].checked, false);
    assert.equal(controls[7].value, '');
    controls[2].value = 'production';
    listeners.get('change')();
    assert.equal(section.hidden, false);
    assert.equal(controls[7].required, true);
    controls[6].checked = true;
    controls[7].value = 'ENABLE GOVERNED PRODUCTION WRITE';
  }

  const submit = listeners.get('submit');
  await submit({ target: form, preventDefault() {} });

  assert.equal(postBody?.get('action'), 'mad4b_site_profile_save');
  assert.equal(postBody?.get('_wpnonce'), 'valid-nonce');
  assert.equal(postBody?.get('environment'), 'production');
  assert.equal(postBody?.get('expected_revision'), '2');
  assert.equal(postBody?.get('production_write_confirmation'), 'ENABLE GOVERNED PRODUCTION WRITE');
  assert.equal(postBody?.get('ignored'), null);
  assert.equal(postBody?.get('unchecked'), null);
  assert.equal(controls[0].disabled, false);
  assert.equal(controls[8].disabled, true);
  assert.equal(fallback, responseKind === 'sentinel' ? 1 : 0);

  if (responseKind === 'success') {
    assert.equal(controls[3].value, '3');
    assert.equal(controls[4].value, 'after-digest');
    assert.equal(controls[6].checked, false);
    assert.equal(controls[7].value, '');
  }

  if (!refreshMode) {
    assert.equal(calls, 1);
  } else {
    assert.equal(calls, 2);
    if (refreshMode === 'ok') {
      assert.equal(form.dataset.mad4bViewStale, undefined);
      assert.equal(replacedWith, nextWorkspace);
    } else {
      assert.equal(form.dataset.mad4bViewStale, '1');
      assert.equal(replacedWith, null);
      assert.match(feedback.textContent, /Saved|saved/i);
      const callsBeforeRetry = calls;
      await submit({ target: form, preventDefault() {} });
      assert.equal(calls, callsBeforeRetry, 'stale workspace must block another POST until reload');
    }
  }
}

for (const kind of ['success', 'sentinel', 'nonce', 'malformed']) await fixture(kind);
await fixture('success', true);
await fixture('success', true, 'ok');
await fixture('success', true, 'missing');
await fixture('success', true, 'stale');

console.log('mad4b.admin-settings-persistence-runtime.v2: 8/8 PASS');
