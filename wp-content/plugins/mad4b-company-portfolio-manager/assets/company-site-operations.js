/* Progressive enhancement: no browser request can execute a company operation. */
(function () {
  'use strict';
  const root = document.getElementById('mad4b-company-site-operations');
  if (!root) return;
  const form = document.getElementById('mad4b-so-target-form');
  const company = document.getElementById('mad4b-so-company');
  const environment = document.getElementById('mad4b-so-environment');
  const refresh = document.getElementById('mad4b-so-refresh');
  const message = document.getElementById('mad4b-so-refresh-status');
  const currentCompany = root.getAttribute('data-company');
  const currentEnvironment = root.getAttribute('data-environment');
  let busy = false;
  let polling = null;
  const originalState = {};
  root.querySelectorAll('[data-operation]').forEach(function (node) {
    originalState[node.getAttribute('data-operation')] = {label: node.querySelector('.mad4b-so-current-state')?.textContent?.trim() || '', request_id: node.getAttribute('data-last-request-id') || ''};
  });
  function updateEnvironments() {
    const selected = company.options[company.selectedIndex];
    const allowed = (selected?.getAttribute('data-environments') || '').split(',');
    [...environment.options].forEach(function (option) {
      option.disabled = !allowed.includes(option.value);
    });
    if (!allowed.includes(environment.value)) environment.value = allowed[0] || '';
  }
  company?.addEventListener('change', function () { updateEnvironments(); form.requestSubmit(); });
  environment?.addEventListener('change', function () { form.requestSubmit(); });
  function setMessage(value) { if (message) message.textContent = value; }
  function updateStatus(data) {
    const latest = data.latest || {};
    let changed = false;
    root.querySelectorAll('[data-operation]').forEach(function (card) {
      const operation = card.getAttribute('data-operation');
      const entry = latest[operation];
      const previous = originalState[operation] || {};
      const next = entry?.state_label || 'Not checked';
      if (previous.label !== next || previous.request_id !== (entry?.request_id || '')) changed = true;
    });
    const queue = Number(data.counts?.queued || 0);
    if (changed) {
      setMessage('A new receipt is available. Updating…');
      window.location.reload();
      return;
    }
    if (queue > 0) {
      setMessage(queue + ' request(s) queued · checking every 20 seconds');
      if (!polling) polling = window.setInterval(check, 20000);
    } else {
      setMessage('Up to date · no active requests');
      if (polling) { clearInterval(polling); polling = null; }
    }
  }
  async function check() {
    if (busy || document.hidden) return;
    busy = true;
    refresh && (refresh.disabled = true);
    setMessage('Checking receipts…');
    try {
      const target = new URL(root.getAttribute('data-api'), window.location.href);
      target.searchParams.set('company', currentCompany);
      target.searchParams.set('environment', currentEnvironment);
      const controller = new AbortController();
      const timeout = window.setTimeout(function () { controller.abort(); }, 12000);
      let response;
      try {
        response = await fetch(target.toString(), { method: 'GET', credentials: 'same-origin',
          headers: { 'X-WP-Nonce': root.getAttribute('data-nonce') }, signal: controller.signal,
          cache: 'no-store' });
      } finally { clearTimeout(timeout); }
      if (!response.ok) throw new Error('Status unavailable (HTTP ' + response.status + ')');
      const result = await response.json();
      if (result.contract !== 'mad4b.company.site-operations.dashboard.v1' ||
          result.company !== currentCompany || result.environment !== currentEnvironment ||
          result.target_verified !== true) throw new Error('Target status changed');
      updateStatus(result);
    } catch (error) {
      setMessage(error.name === 'AbortError' ? 'Status check timed out. Retry.' :
        'Status check unavailable. Use Reload history.');
    } finally { busy = false; if (refresh) refresh.disabled = false; }
  }
  const attention = document.getElementById('mad4b-so-history-attention');
  attention?.addEventListener('change', function () {
    const rows = root.querySelectorAll('tr[data-receipt-state]');
    let visible = 0;
    rows.forEach(function (row) {
      const state = row.getAttribute('data-receipt-state');
      const show = !attention.checked || ['BLOCKED','NOT_LOCALLY_BOUND','FAILED','EXPIRED'].includes(state);
      row.hidden = !show;
      if (show) visible++;
    });
    const body = document.getElementById('mad4b-so-receipts');
    body?.querySelector('#mad4b-so-filter-empty')?.remove();
    if (attention.checked && !visible && rows.length && body) {
      const empty = document.createElement('tr');
      empty.id = 'mad4b-so-filter-empty';
      const td = document.createElement('td');
      td.colSpan = 5;
      td.textContent = 'No results need attention in the recent history.';
      empty.appendChild(td);
      body.appendChild(empty);
    }
    setMessage(attention.checked ? visible + ' results need attention' : 'Showing all recent results');
  });
  root.addEventListener('click', async function (event) {
    const control = event.target.closest('[data-mad4b-copy]');
    if (!control) return;
    const value = control.getAttribute('data-mad4b-copy');
    if (!value) return;
    try {
      if (!navigator.clipboard?.writeText) throw new Error('Unavailable');
      await navigator.clipboard.writeText(value);
      setMessage('Copied to clipboard');
    } catch (_) { setMessage('Clipboard unavailable. Select the value to copy.'); }
  });
  root.querySelectorAll('form.mad4b-so-run').forEach(function (actionForm) {
    actionForm.addEventListener('submit', function () {
      const button = actionForm.querySelector('button[type="submit"]');
      if (!button || button.disabled) return;
      button.disabled = true;
      button.textContent = 'Submitting request…';
      setMessage('Submitting an exact-target request to Company Management…');
    });
  });
  refresh?.addEventListener('click', check);
  if ([...root.querySelectorAll('.mad4b-so-current-state')].some(function (node) { return node.textContent.includes('Queued'); })) {
    polling = window.setInterval(check, 20000);
  }
  window.addEventListener('pagehide', function () { if (polling) clearInterval(polling); });
})();
