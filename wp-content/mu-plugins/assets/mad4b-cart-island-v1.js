/* MAD4B Cart Island v1 — public static shell, private on-demand Woo Store API.
 * No cart/order/token state in page HTML, storage, analytics or shared cache.
 */
(function (win, doc) {
  'use strict';
  var ROOT = '[data-mad4b-cart-island="v1"]';
  var EVENTS = ['wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart',
    'wc_fragment_refresh', 'added_to_cart', 'removed_from_cart',
    'updated_wc_div', 'mad4b:cart-changed'];
  var islands = Array.prototype.slice.call(doc.querySelectorAll(ROOT));
  if (!islands.length) return;
  var refreshInFlight = null;
  var pendingRefresh = false;
  var version = 0;
  var hydrated = false;
  var timer = null;

  function hasCartHint() {
    // Woo's non-HttpOnly presence signals. No Woo session hash is read.
    return /(?:^|;\s*)(?:woocommerce_items_in_cart|woocommerce_cart_hash)=/.test(doc.cookie || '');
  }
  function hide(node) {
    if (node) { node.textContent = ''; node.hidden = true; }
  }
  function safeNumber(value) {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : 0;
  }
  function money(totals) {
    if (!totals || typeof totals !== 'object') return '';
    var code = String(totals.currency_code || '');
    if (!/^[A-Z]{3}$/.test(code)) return '';
    var minor = safeNumber(Number(totals.currency_minor_unit));
    if (minor > 6) return '';
    var raw = String(totals.total_price || '');
    if (!/^\d{1,13}$/.test(raw)) return '';
    var amount = Number(raw) / Math.pow(10, minor);
    try {
      return new Intl.NumberFormat(doc.documentElement.lang || 'en', {
        style: 'currency', currency: code
      }).format(amount);
    } catch (_) { return ''; }
  }
  function display(root, payload) {
    var count = root.querySelector('[data-cart-count]');
    var items = root.querySelector('[data-cart-items]');
    var total = root.querySelector('[data-cart-total]');
    var status = root.querySelector('[data-cart-status]');
    var rawItems = Array.isArray(payload && payload.items) ? payload.items.slice(0, 50) : [];
    var quantity = safeNumber(Number(payload && payload.items_count));
    if (count) count.textContent = String(Math.min(Math.floor(quantity), 9999));
    if (items) {
      items.replaceChildren();
      rawItems.forEach(function (entry) {
        if (!entry || typeof entry !== 'object') return;
        var row = doc.createElement('li');
        var title = String(entry.name || '').slice(0, 180);
        var qty = Math.min(9999, Math.floor(safeNumber(Number(entry.quantity))));
        row.textContent = title + ' × ' + qty;
        items.appendChild(row);
      });
    }
    if (total) total.textContent = money(payload && payload.totals);
    if (status) status.textContent = quantity ? '' : 'Your cart is empty.';
  }
  function clear(root, reason) {
    var count = root.querySelector('[data-cart-count]');
    if (count) count.textContent = '–';
    hide(root.querySelector('[data-cart-items]'));
    hide(root.querySelector('[data-cart-total]'));
    var status = root.querySelector('[data-cart-status]');
    if (status) status.textContent = reason || 'Cart unavailable.';
  }
  function validateEndpoint(raw) {
    try {
      var url = new URL(raw, win.location.href);
      if (url.origin !== win.location.origin ||
          !/\/wp-json\/wc\/store\/v1\/cart\/?$/.test(url.pathname) ||
          url.search || url.hash) return '';
      return url.href;
    } catch (_) { return ''; }
  }
  function readCart() {
    if (refreshInFlight) {
      // Cart updates may happen while an earlier GET is in progress.
      // Never leave a stale count behind: fetch the newest state afterwards.
      pendingRefresh = true;
      return refreshInFlight;
    }
    var endpoint = validateEndpoint(islands[0].getAttribute('data-cart-endpoint'));
    if (!endpoint) return Promise.reject(new Error('Invalid cart endpoint'));
    var active = ++version;
    refreshInFlight = win.fetch(endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      mode: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      headers: { Accept: 'application/json' }
    }).then(function (response) {
      var cc = response.headers.get('Cache-Control') || '';
      if (!response.ok || !/\bprivate\b/i.test(cc) || !/\bno-store\b/i.test(cc) ||
          !/application\/json/i.test(response.headers.get('Content-Type') || '')) {
        throw new Error('Private cart response not verified');
      }
      return response.json();
    }).then(function (data) {
      if (active !== version || !data || !Array.isArray(data.items)) return;
      hydrated = true;
      islands.forEach(function (root) {
        root.querySelector('[data-cart-items]').hidden = false;
        root.querySelector('[data-cart-total]').hidden = false;
        display(root, data);
      });
    }).catch(function () {
      if (active === version) {
        hydrated = false;
        islands.forEach(function (root) { clear(root); });
      }
    }).finally(function () {
      refreshInFlight = null;
      if (pendingRefresh) {
        pendingRefresh = false;
        schedule();
      }
    });
    return refreshInFlight;
  }
  function schedule() {
    if (timer !== null) win.clearTimeout(timer);
    timer = win.setTimeout(function () {
      timer = null;
      readCart();
    }, 75);
  }
  islands.forEach(function (root) {
    var trigger = root.querySelector('[data-cart-open]');
    var panel = root.querySelector('[data-cart-panel]');
    if (!trigger || !panel) return;
    trigger.addEventListener('click', function () {
      var open = panel.hidden;
      panel.hidden = !open;
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) readCart();
    });
  });
  EVENTS.forEach(function (name) {
    doc.body.addEventListener(name, schedule, { passive: true });
  });
  // Original jQuery Woo hooks are kept for legacy add-to-cart integrations.
  if (win.jQuery && win.jQuery.fn) {
    win.jQuery(doc.body).on('added_to_cart removed_from_cart wc_fragment_refresh updated_wc_div', schedule);
  }
  doc.addEventListener('visibilitychange', function () {
    if (!doc.hidden && (hydrated || hasCartHint())) schedule();
  });
  win.addEventListener('pageshow', function (event) {
    if ((event.persisted || hasCartHint()) && islands.length) schedule();
  });
  // Skip a WooCommerce Store API request for every first-time browsing guest.
  // Once a Woo cart exists, hydrate in the island only, never in the full page.
  if (hasCartHint()) schedule();
})(window, document);
