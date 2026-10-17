(function () {
  'use strict';
  const cfg = window.MAD4BNativeHybrid;
  const button = document.getElementById('mad4b-native-hybrid-run');
  const host = document.getElementById('mad4b-native-hybrid-host');
  const status = document.getElementById('mad4b-native-hybrid-status');
  const filterOpt = document.getElementById('mad4b-native-hybrid-filter');
  if (!cfg || !cfg.staging_only || !button || !host || !status) return;

  const origin = new URL(cfg.origin);
  const maxSeconds = Math.min(30, Math.max(5, Number(cfg.maximum_seconds) || 30));
  let busy = false;

  function report(text) { status.textContent = text; }
  function safeSameOrigin(value) {
    try {
      const u = new URL(value, origin.href);
      if (u.origin !== origin.origin || !/^https:$/.test(u.protocol) ||
          u.username || u.password || u.hash ||
          /\/(wp-admin|wp-login|wp-json\/.*\/users|checkout|cart|my-account)(\/|$)/i.test(u.pathname)) return null;
      return u;
    } catch (_) { return null; }
  }

  function loadFrame(href, maxMs) {
    return new Promise((resolve, reject) => {
      const url = safeSameOrigin(href);
      if (!url) return reject(new Error('unsafe_route'));
      const f = document.createElement('iframe');
      f.setAttribute('sandbox', 'allow-scripts allow-same-origin');
      f.setAttribute('referrerpolicy', 'same-origin');
      f.setAttribute('title', 'MAD4B read-only native browser probe');
      f.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none';
      let finished = false;
      const timeout = window.setTimeout(() => end(new Error('navigation_timeout')), maxMs);
      function end(err) {
        if (finished) return;
        finished = true;
        window.clearTimeout(timeout);
        f.removeEventListener('load', loaded);
        f.removeEventListener('error', failed);
        if (err) { f.remove(); reject(err); }
        else resolve(f);
      }
      function failed() { end(new Error('iframe_blocked')); }
      function loaded() {
        try {
          const w = f.contentWindow;
          // Appending an iframe can fire a transient about:blank load before
          // the requested navigation. Ignore only that initial empty frame.
          if (w && w.location.href === 'about:blank') return;
          if (!w || w.location.origin !== origin.origin || !w.document || !w.document.body) {
            return end(new Error('iframe_blocked'));
          }
          // Errors emitted after DOM load are observable here; early errors
          // remain unknown, and this local probe cannot certify JS parity.
          f.__mad4bLateErrors = 0;
          w.addEventListener('error', () => { f.__mad4bLateErrors++; });
          w.addEventListener('unhandledrejection', () => { f.__mad4bLateErrors++; });
          end(null);
        } catch (_) { end(new Error('iframe_blocked')); }
      }
      f.addEventListener('load', loaded);
      f.addEventListener('error', failed);
      host.appendChild(f);
      f.src = url.href;
    });
  }
  function snapshot(frame) {
    const doc = frame.contentWindow.document;
    const current = safeSameOrigin(frame.contentWindow.location.href);
    if (!current) throw new Error('unsafe_route');
    const canonicalEl = doc.querySelector('link[rel=canonical]');
    const canonical = canonicalEl ? safeSameOrigin(canonicalEl.href) : null;
    const ids = [...new Set([...doc.querySelectorAll('[data-post-id],.jet-listing-grid__item[data-post-id]')]
      .map(n => (n.getAttribute('data-post-id') || '').trim())
      .filter(v => /^[0-9]{1,12}$/.test(v)))].sort();
    const listingElements = doc.querySelectorAll('.jet-listing-grid__item,[data-post-id]').length;
    const controls = doc.querySelectorAll('.jet-smart-filters-checkboxes input[type=checkbox],.jet-filter input[type=checkbox]').length;
    return {
      url: current.href, status: !!doc.body && doc.readyState !== 'loading',
      canonical: !!canonical && canonical.origin === current.origin,
      hreflang: Math.min(64, doc.querySelectorAll('link[hreflang]').length),
      listings: Math.min(10000, listingElements), unique: listingElements === 0 || ids.length > 0,
      ids, controls: Math.min(10000, controls)
    };
  }
  async function digest(ids) {
    if (!window.crypto || !crypto.subtle) return '';
    const bytes = new TextEncoder().encode(ids.join(','));
    const hash = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(hash), b => b.toString(16).padStart(2, '0')).join('');
  }

  function navigationOK(frame) {
    // Navigation Timing Level 2 is supported by modern Chrome. A DOM that
    // happens to render is not sufficient proof of HTTP 200.
    try {
      const entry = frame.contentWindow.performance.getEntriesByType('navigation')[0];
      return !!entry && entry.responseStatus === 200;
    } catch (_) { return false; }
  }
  function archiveLink(frame) {
    const doc = frame.contentWindow.document;
    const links = [...doc.querySelectorAll('a[href]')].slice(0, 300);
    for (const link of links) {
      const u = safeSameOrigin(link.href);
      if (!u || u.pathname === '/' || !/\/(tours-and-activities|activities|tours|shop|products|destinations)(\/|$)/i.test(u.pathname)) continue;
      if (u.search) continue;
      return u.href;
    }
    return null;
  }

  // The only attempted native interaction is a checkbox, opt-in per run.
  // Block all non-GET fetch/XHR and form submissions in the test frame.
  // No click is sent to a POST form, purchase or publish action.
  async function inspectFilter(frame, before) {
    const w = frame.contentWindow;
    const doc = w.document;
    const input = doc.querySelector('.jet-smart-filters-checkboxes input[type=checkbox],.jet-filter input[type=checkbox]');
    if (!input || input.closest('form[method=post],form[method=POST]')) return { attempted: false, ajax: false, changed: false, digest: '' };
    const originalFetch = w.fetch;
    const originalOpen = w.XMLHttpRequest && w.XMLHttpRequest.prototype.open;
    let getCalls = 0;
    let prevented = 0;
    const submitGuard = function (e) { e.preventDefault(); prevented++; };
    const beforeResources = w.performance.getEntriesByType('resource').length;
    doc.addEventListener('submit', submitGuard, true);
    try {
      if (typeof originalFetch === 'function') {
        w.fetch = function (request, opts) {
          const method = ((opts && opts.method) || (request && request.method) || 'GET').toUpperCase();
          const target = typeof request === 'string' ? request : request.url;
          if (method !== 'GET' || !safeSameOrigin(target)) {
            prevented++;
            return Promise.reject(new Error('mad4b_blocked_non_get_filter_call'));
          }
          getCalls++;
          return originalFetch.apply(this, arguments);
        };
      }
      if (originalOpen) {
        w.XMLHttpRequest.prototype.open = function (method, target) {
          if (String(method).toUpperCase() !== 'GET' || !safeSameOrigin(target)) {
            prevented++;
            throw new Error('mad4b_blocked_non_get_filter_call');
          }
          getCalls++;
          return originalOpen.apply(this, arguments);
        };
      }
      input.click();
      await new Promise(resolve => window.setTimeout(resolve, 2400));
      const after = snapshot(frame);
      const resourceDelta = w.performance.getEntriesByType('resource').slice(beforeResources)
        .filter(e => ['fetch', 'xmlhttprequest'].includes(e.initiatorType)).length;
      const digestValue = await digest(after.ids);
      return { attempted: true, ajax: getCalls > 0 || resourceDelta > 0,
        changed: after.ids.join(',') !== before.ids.join(','), digest: digestValue,
        prevented, after };
    } finally {
      doc.removeEventListener('submit', submitGuard, true);
      if (typeof originalFetch === 'function') w.fetch = originalFetch;
      if (originalOpen) w.XMLHttpRequest.prototype.open = originalOpen;
    }
  }

  const onceKey = 'mad4b-native-hybrid-session-' + origin.host;
  button.addEventListener('click', async function () {
    if (busy) return;
    busy = true; button.disabled = true;
    try { window.sessionStorage.setItem(onceKey, 'started'); } catch (_) {}
    const begin = performance.now();
    const out = {
      route_count: 0, js_errors: 0, dom_ready: false, http_ok: false, same_origin: false,
      canonical_match: false, hreflang_count: 0, listing_count: 0,
      listing_unique: false, listing_digest: '', filter_controls: 0,
      filter_attempted: false, filter_ajax_observed: false, filter_dataset_changed: false,
      filter_dataset_digest: '', duration_ms: 0, blocked_reason: 'none'
    };
    const frames = [];
    try {
      report('Loading the same-origin Staging homepage...');
      const home = await loadFrame(origin.href, 12000); frames.push(home);
      let first = snapshot(home);
      out.route_count = 1;
      out.dom_ready = first.status;
      out.http_ok = navigationOK(home);
      out.same_origin = true;
      out.canonical_match = first.canonical;
      out.hreflang_count = first.hreflang;
      out.listing_count = first.listings;
      out.listing_unique = first.unique;
      out.listing_digest = await digest(first.ids);
      let targetFrame = home, target = first;
      const link = archiveLink(home);
      if (link) {
        report('Inspecting a same-origin listing/archive route...');
        const archive = await loadFrame(link, 12000);
        frames.push(archive);
        targetFrame = archive; target = snapshot(archive);
        out.route_count = 2;
        out.dom_ready = out.dom_ready && target.status;
        out.http_ok = out.http_ok && navigationOK(archive);
        out.canonical_match = out.canonical_match && target.canonical;
        out.hreflang_count = Math.max(out.hreflang_count, target.hreflang);
        out.listing_count = Math.max(out.listing_count, target.listings);
        out.listing_unique = out.listing_unique && target.unique;
        out.listing_digest = await digest(target.ids);
      }
      out.filter_controls = target.controls;
      if (filterOpt && filterOpt.checked) {
        report('Testing one filter checkbox with non-GET requests blocked...');
        const test = await inspectFilter(targetFrame, target);
        out.filter_attempted = test.attempted;
        out.filter_ajax_observed = test.ajax;
        out.filter_dataset_changed = test.changed;
        out.filter_dataset_digest = test.digest;
        if (!test.attempted) out.blocked_reason = 'filter_unavailable';
        else if (!test.ajax) out.blocked_reason = 'filter_no_ajax';
        // AJAX presence plus DOM changes is still NOT independent dataset parity.
      }
      out.js_errors = Math.min(20, frames.reduce((n, f) => n + (f.__mad4bLateErrors || 0), 0));
    } catch (e) {
      out.js_errors = Math.min(20, frames.reduce((n, f) => n + (f.__mad4bLateErrors || 0), 0));
      out.blocked_reason = ['iframe_blocked', 'navigation_timeout', 'unsafe_route']
        .includes(e.message) ? e.message : 'other';
    } finally {
      out.duration_ms = Math.min(30000, Math.max(0, Math.round(performance.now() - begin)));
      frames.forEach(f => f.remove());
    }
    try {
      const body = new URLSearchParams();
      body.set('action', 'mad4b_native_hybrid_observe');
      body.set('nonce', cfg.nonce);
      body.set('observation', JSON.stringify(out));
      const response = await fetch(cfg.endpoint, {
        method: 'POST', credentials: 'same-origin', redirect: 'error',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      });
      const payload = await response.json();
      if (!response.ok || !payload.success) throw new Error('receive_denied');
      report('Local observation: ' + payload.data.state +
        '. Filter AJAX: ' + (out.filter_ajax_observed ? 'observed, not independently verified' : 'unverified') +
        '. Browser release certification: BLOCKED.');
    } catch (_) {
      report('Local observation could not be recorded. No browser acceptance certificate was issued.');
    }
    busy = false; button.disabled = false;
  });

  // Automatically run a passive read-only DOM probe once in this admin tab
  // after the owner has explicitly saved Native Hybrid opt-in. Never click
  // a filter automatically; interactive checks require a separate checkbox.
  try {
    if (document.visibilityState === 'visible' && !window.sessionStorage.getItem(onceKey)) {
      window.setTimeout(() => {
        if (document.visibilityState === 'visible' && !busy && !filterOpt.checked) button.click();
      }, 1600);
    }
  } catch (_) { /* unavailable session storage only disables automatic run */ }
})();
