(() => {
  'use strict';
  const cfg = window.MAD4BJetModePage;
  const root = document.querySelector('.jet-engine-options-page-wrap.mad4b-ws-visual');
  const top = root?.querySelector('#settings_top');
  if (!cfg?.fields || !top || top.querySelector('.mad4b-repeater-overview')) return;

  const create = (tag, className, value) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== undefined) node.textContent = String(value);
    return node;
  };
  const sections = [];
  const fieldDefs = cfg.fields.filter(f => f?.type === 'repeater' && Array.isArray(f.repeater_fields));
  const toText = (value) => String(value ?? '').slice(0, 160).trim();

  const collectRow = (row, fields) => {
    const values = {};
    for (const def of fields) {
      const container = Array.from(row.querySelectorAll('[data-repeater-control-name]'))
        .find(el => el.getAttribute('data-repeater-control-name') === def.name &&
          el.closest('.cx-ui-repeater-item') === row);
      if (!container) continue;
      const checkbox = container.querySelector('input[type="checkbox"]');
      const select = container.querySelector('select');
      const regular = container.querySelector('input:not([type=hidden]):not([type=checkbox]),textarea');
      if (checkbox) values[def.name] = checkbox.checked ? 'Yes' : 'No';
      else if (select) {
        values[def.name] = select.multiple
          ? Array.from(select.selectedOptions).map(x => x.textContent.trim()).join(', ')
          : (select.selectedOptions?.[0]?.textContent?.trim() ?? select.value);
      } else if (regular) values[def.name] = regular.value;
      else {
        const hidden = container.querySelector('input[type=hidden]');
        if (hidden) values[def.name] = hidden.value;
      }
      if (values[def.name] !== undefined) values[def.name] = toText(values[def.name]);
    }
    return values;
  };
  const rowsOf = (s) => Array.from(s.list.children)
    .filter(row => row.classList.contains('cx-ui-repeater-item'));
  const localPreview = (values, fields) => {
    const parts = [];
    for (const def of fields) {
      const v = toText(values[def.name]);
      if (v === '') continue;
      parts.push(def.label + ': ' + v);
      if (parts.length === 8) break;
    }
    return parts.length ? parts.join(' · ') : 'No values entered';
  };
  const rowSummaryNode = (row) => {
    // This wide source-policy repeater already renders a read-only overview
    // above the native editor. Never inject a large second summary into the
    // tiny native drag/copy/delete header: it squeezes the editor to a sliver.
    if (row.closest('[data-control-name="shared_price_source_mode_rows"]')) return null;
    const actionBox = row.querySelector('.cx-ui-repeater-actions-box');
    if (!actionBox) return null;
    let result = actionBox.querySelector('.mad4b-repeater-row-preview');
    if (!result) {
      result = create('span', 'mad4b-repeater-row-preview', 'Preview available');
      result.setAttribute('aria-label', 'Current row overview');
      // Never attach interactive buttons or change JetEngine's native row toggle.
      actionBox.append(result);
    }
    return result;
  };

  for (const def of fieldDefs) {
    // Native price-source repeater has a large CX row editor. Injecting a
    // second sibling overview between native info and content distorted
    // its flex/grid layout and left a blank panel above real inputs.
    // The canonical source values already live in JetEngine rows; keep them
    // together and allow the original row tools to handle collapse/expand.
    if (def.name === 'shared_price_source_mode_rows') continue;
    const control = Array.from(top.querySelectorAll('.mad4b-ws-control[data-control-name]'))
      .find(el => el.dataset.controlName === def.name);
    const list = control?.querySelector('.cx-ui-repeater-list');
    if (!control || !list) continue;
    const panel = create('div', 'mad4b-repeater-overview');
    const header = create('div', 'mad4b-repeater-overview__head');
    const name = create('strong', '', 'Live overview');
    const state = create('span', 'mad4b-repeater-overview__state', 'Current inputs · not saved');
    header.append(name, state);
    const content = create('ol', 'mad4b-repeater-overview__list');
    panel.append(header, content);
    const nativeInfo = control.querySelector(':scope > .cx-control__info');
    // Put the compact read-only overview under the native label, in the
    // otherwise unused left information column. Never insert a third sibling
    // into the original JetEngine inline info/content flex row.
    if (nativeInfo) nativeInfo.append(panel);
    else {
      // Vendor markup mismatch: skip rather than create a competing flex item.
      continue;
    }
    sections.push({def, control, list, panel, state, content});
  }

  // Price Source Mode rows deliberately do not get the large AJAX overview.
  // Provide instead a compact *read-only, per-row* value summary in the
  // original JetEngine toolbar. It stays visible in collapsed state.
  // The six native inputs remain their sole source of truth.
  const sourceControl = top.querySelector(
    '[data-control-name="shared_price_source_mode_rows"]');
  const sourceList = sourceControl?.querySelector('.cx-ui-repeater-list');
  if (sourceList) {
    const keys = [
      ['mode', 'Tour mode'], ['direct_weight', 'Direct'], ['rate_weight', 'Rate'],
      ['tie_break', 'Tie break'], ['fallback', 'Fallback'], ['visibility', 'Visibility']
    ];
    const modeRows = () => Array.from(sourceList.children)
      .filter(node => node.classList.contains('cx-ui-repeater-item'));
    const read = (row, key) => {
      const control = Array.from(row.querySelectorAll('[data-repeater-control-name]'))
        .find(el => el.dataset.repeaterControlName === key &&
          el.closest('.cx-ui-repeater-item') === row);
      if (!control) return '';
      const selected = control.querySelector('select');
      if (selected) return selected.selectedOptions?.[0]?.textContent?.trim() || selected.value || '';
      const input = control.querySelector('input:not([type="hidden"]),textarea');
      return input?.value?.trim() || '';
    };
    const summarize = (row) => {
      const bits = keys.map(([key, label]) => {
        const value = read(row, key).slice(0, 55);
        return value ? label + ': ' + value : '';
      }).filter(Boolean);
      return bits.join(' · ') || 'No rule values entered';
    };
    const syncModeRows = () => {
      modeRows().forEach(row => {
        const actions = row.querySelector(':scope > .cx-ui-repeater-actions-box');
        if (!actions) return;
        let summary = actions.querySelector('.mad4b-mode-rule-summary');
        if (!summary) {
          summary = create('span', 'mad4b-mode-rule-summary');
          summary.setAttribute('aria-label', 'Current source rule values');
          actions.append(summary);
        }
        const text = summarize(row);
        if (summary.textContent !== text) summary.textContent = text;
      });
    };
    syncModeRows();
    sourceControl.addEventListener('input', event => {
      if (event.target.closest('.cx-ui-repeater-item')) syncModeRows();
    });
    sourceControl.addEventListener('change', event => {
      if (event.target.closest('.cx-ui-repeater-item')) syncModeRows();
    });
    // JetEngine add, duplicate, and delete all mutate the native list.
    new MutationObserver(syncModeRows).observe(sourceList, {childList:true});
  }

  if (!sections.length) return;

  const draw = (s, previews, status) => {
    s.content.replaceChildren();
    const rows = rowsOf(s);
    if (!rows.length) {
      s.content.append(create('li', 'mad4b-repeater-overview__empty', 'No rows configured'));
    } else {
      rows.forEach((row, i) => {
        const text = String(previews?.[i] || localPreview(
          collectRow(row, s.def.repeater_fields), s.def.repeater_fields)).slice(0, 850);
        const label = create('li', '', text);
        s.content.append(label);
        const title = rowSummaryNode(row);
        if (title) title.textContent = text.slice(0, 300);
      });
    }
    s.state.textContent = status || 'Current inputs · not saved';
  };
  const snapshot = () => sections.map(s => ({
    field:s.def.name,
    rows:rowsOf(s).slice(0, 50).map(row => collectRow(row, s.def.repeater_fields)),
  }));
  const localRefresh = () => {
    for (const s of sections) draw(s, null, 'Current inputs · not saved');
  };
  localRefresh();

  // A single AJAX call may cover all visible and collapsed repeater rows.
  // Never sends a JetEngine save action; never depends on opening the row.
  const service = cfg.overview || {};
  let debounce = null;
  let lastSnapshot = '';
  let activeController = null;
  let requestSerial = 0;
  const send = async () => {
    if (!service.ajax_url || !service.nonce || !service.action || typeof fetch !== 'function') return;
    const data = snapshot();
    const serialized = JSON.stringify(data);
    if (serialized === lastSnapshot) return;
    if (serialized.length > 45000) {
      for (const s of sections) s.state.textContent = 'Local preview · request size limit';
      return;
    }
    lastSnapshot = serialized;
    if (activeController) activeController.abort();
    const controller = new AbortController();
    activeController = controller;
    const serial = ++requestSerial;
    const params = new URLSearchParams({
      action:service.action,
      nonce:service.nonce,
      page_slug:String(cfg.slug || ''),
      items:serialized,
    });
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(service.ajax_url, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body:params.toString(),
        signal:controller.signal,
      });
      if (!response.ok) throw new Error('Overview response ' + response.status);
      const result = await response.json();
      // A response for an older set of unsaved values must never override a newer one.
      if (serial !== requestSerial || JSON.stringify(snapshot()) !== serialized) return;
      if (result?.success !== true || !Array.isArray(result.data?.previews)) throw new Error('Invalid overview payload');
      const received = new Map(result.data.previews.map(x => [x.field, x]));
      for (const s of sections) {
        const serverRows = received.get(s.def.name)?.rows;
        draw(s, Array.isArray(serverRows) ? serverRows.map(x => x.summary) : null,
          'AJAX overview · preview only · not saved');
      }
    } catch (err) {
      if (serial === requestSerial && !controller.signal.aborted) {
        for (const s of sections) s.state.textContent = 'Local overview · AJAX unavailable';
      }
    } finally {
      clearTimeout(timeout);
      if (activeController === controller) activeController = null;
    }
  };
  const queue = (changed = true) => {
    if (changed) {
      localRefresh();
      lastSnapshot = '';
    }
    if (debounce !== null) clearTimeout(debounce);
    debounce = setTimeout(send, changed ? 800 : 1200);
  };
  top.addEventListener('input', event => {
    if (event.target.closest('.cx-ui-repeater-item')) queue();
  });
  top.addEventListener('change', event => {
    if (event.target.closest('.cx-ui-repeater-item')) queue();
  });
  for (const s of sections) {
    new MutationObserver(() => queue()).observe(s.list, {childList:true});
  }
  // One low-priority batched read-only preview. No polling, no page-blocking AJAX.
  if (typeof requestIdleCallback === 'function') requestIdleCallback(() => queue(false), {timeout:2200});
  else queue(false);
})();
