/* MAD4B Passport AJAX Search v1 — original Fluent Forms field is the authority.
 * No change to the saved value, FF validation, WPML translations or form schema.
 * Modal dialog prevents native mobile 195-option popup/keyboard overlap.
 */
(() => {
  'use strict';
  const cfg = window.MAD4BPassportSearchV1;
  if (!cfg || !/^https?:\/\//.test(cfg.endpoint || '')) return;
  const COPY = {
    en: ['Search passport country', 'Choose passport country', 'Search by country or nationality', 'No matching countries', 'Country search unavailable. Use the original list.', 'Use standard country list', 'Close', 'Clear choice'],
    ar: ['البحث عن دولة جواز السفر', 'اختر دولة جواز السفر', 'اكتب اسم الدولة أو الجنسية', 'لا توجد نتائج', 'البحث غير متاح. استخدم القائمة الأصلية.', 'استخدم القائمة الأصلية', 'إغلاق', 'مسح الاختيار'],
    es: ['Buscar país del pasaporte', 'Elegir país del pasaporte', 'Busca país o nacionalidad', 'No hay resultados', 'La búsqueda no está disponible.', 'Usar lista original', 'Cerrar', 'Borrar selección'],
    fr: ['Rechercher pays du passeport', 'Choisir pays du passeport', 'Rechercher pays ou nationalité', 'Aucun résultat', 'Recherche indisponible.', 'Utiliser la liste d’origine', 'Fermer', 'Effacer'],
    de: ['Passland suchen', 'Passland auswählen', 'Land oder Nationalität suchen', 'Keine Treffer', 'Suche nicht verfügbar.', 'Originalauswahl verwenden', 'Schließen', 'Auswahl löschen'],
    it: ['Cerca paese del passaporto', 'Scegli paese del passaporto', 'Cerca paese o nazionalità', 'Nessun risultato', 'Ricerca non disponibile.', 'Usa elenco originale', 'Chiudi', 'Cancella']
  };
  const text = COPY[cfg.lang] || COPY.en;
  let active = null;
  let seq = 0;
  const cache = new Map();
  const make = (name, cls, content) => {
    const e = document.createElement(name);
    if (cls) e.className = cls;
    if (content !== undefined) e.textContent = content;
    return e;
  };
  const safeResults = (field, response) => {
    if (!response || !Array.isArray(response.results) || response.results.length > 14) return [];
    return response.results.filter(item =>
      item && typeof item.value === 'string' && typeof item.label === 'string' &&
      item.value.length <= 80 && item.label.length <= 90 &&
      [...field.options].some(o => o.value === item.value)
    );
  };
  function bind(field) {
    if (field.dataset.mad4bPassportAjaxReady === '1' || field.options.length !== 196 ||
        !field.options[1]?.dataset.passportIso ||
        !field.closest('form.fluent_form_6')) return;
    if (typeof HTMLDialogElement === 'undefined' ||
        !('showModal' in HTMLDialogElement.prototype)) return; // native fallback
    const root = field.parentElement;
    if (!root) return;
    const trigger = make('button', 'mad4b-passport-trigger');
    trigger.type = 'button';
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-label', text[1]);
    const current = () => field.selectedOptions[0]?.textContent?.trim() || text[1];
    trigger.textContent = field.value ? current() : text[1];
    const dialog = make('dialog', 'mad4b-passport-search-dialog');
    dialog.setAttribute('aria-label', text[0]);
    const header = make('div', 'mad4b-passport-dialog-header');
    const title = make('strong', '', text[0]);
    const close = make('button', 'mad4b-passport-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', text[6]);
    header.append(title, close);
    const search = make('input', 'mad4b-passport-search-input');
    search.type = 'search';
    search.autocomplete = 'off';
    search.spellcheck = false;
    search.placeholder = text[2];
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-autocomplete', 'list');
    search.setAttribute('aria-expanded', 'true');
    const list = make('div', 'mad4b-passport-results');
    list.setAttribute('role', 'listbox');
    const listId = 'mad4b-passport-results-' + (field.id || Math.random().toString(36).slice(2));
    list.id = listId;
    search.setAttribute('aria-controls', listId);
    const status = make('p', 'mad4b-passport-status');
    status.setAttribute('aria-live', 'polite');
    const actions = make('div', 'mad4b-passport-actions');
    const useNative = make('button', 'mad4b-passport-fallback', text[5]);
    const clear = make('button', 'mad4b-passport-clear', text[7]);
    for (const button of [useNative, clear]) button.type = 'button';
    actions.append(useNative, clear);
    dialog.append(header, search, list, status, actions);
    root.append(trigger, dialog);
    field.dataset.mad4bPassportAjaxReady = '1';
    field.classList.add('mad4b-passport-native-hidden');
    let currentAbort = null;
    let timer = null;
    let hits = [];
    let focused = -1;
    const currentKey = () => search.value.trim().slice(0, 48).toLocaleLowerCase();
    function updateButton() { trigger.textContent = field.value ? current() : text[1]; }
    function pick(value) {
      const valid = [...field.options].find(o => o.value === value);
      if (!valid) return;
      field.value = value;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
      updateButton();
      dialog.close();
      trigger.focus({ preventScroll: true });
    }
    function activate(i) {
      const buttons = [...list.querySelectorAll('button')];
      if (!buttons.length) return;
      focused = (i + buttons.length) % buttons.length;
      buttons.forEach((button, index) => {
        button.setAttribute('aria-selected', String(index === focused));
      });
      search.setAttribute('aria-activedescendant', buttons[focused].id);
      buttons[focused].scrollIntoView({ block: 'nearest' });
    }
    function render(results, query, more) {
      hits = results;
      focused = -1;
      search.removeAttribute('aria-activedescendant');
      list.replaceChildren();
      results.forEach((result, i) => {
        const button = make('button', 'mad4b-passport-result');
        button.type = 'button';
        button.id = listId + '-r' + i;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', 'false');
        button.textContent = result.label;
        button.addEventListener('click', () => pick(result.value));
        list.append(button);
      });
      status.textContent = !results.length ? text[3] : (more ? '…' : '');
    }
    async function searchServer() {
      currentAbort?.abort();
      const request = ++seq;
      const key = currentKey();
      const cacheKey = cfg.lang + '|' + key;
      if (cache.has(cacheKey)) {
        const saved = cache.get(cacheKey);
        render(saved.results, key, saved.more);
        return;
      }
      const controller = new AbortController();
      currentAbort = controller;
      status.textContent = '…';
      const url = new URL(cfg.endpoint);
      url.searchParams.set('q', key);
      url.searchParams.set('lang', cfg.lang);
      try {
        const response = await fetch(url, {
          method: 'GET', credentials: 'same-origin',
          headers: { 'Accept': 'application/json' },
          cache: 'no-store', signal: controller.signal
        });
        if (!response.ok) throw new Error('search unavailable');
        const json = await response.json();
        if (request !== seq || !dialog.open) return;
        const results = safeResults(field, json);
        const payload = { results, more: Boolean(json.has_more) };
        cache.set(cacheKey, payload);
        if (cache.size > 50) cache.delete(cache.keys().next().value);
        render(results, key, payload.more);
      } catch (error) {
        if (error.name === 'AbortError' || request !== seq) return;
        list.replaceChildren();
        status.textContent = text[4];
        useNative.hidden = false;
      }
    }
    function show() {
      if (active && active !== dialog && active.open) active.close();
      active = dialog;
      search.value = '';
      dialog.showModal();
      searchServer();
      // Focusing after showModal keeps the OS keyboard inside the viewport.
      search.focus({ preventScroll: true });
    }
    trigger.addEventListener('click', show);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      currentAbort?.abort();
      clearTimeout(timer);
      updateButton();
      if (active === dialog) active = null;
    });
    search.addEventListener('input', () => {
      // Invalidate the old result synchronously, even during debounce.
      // An outdated in-flight response must never replace newer matches.
      ++seq;
      currentAbort?.abort();
      list.replaceChildren();
      focused = -1;
      search.removeAttribute('aria-activedescendant');
      status.textContent = '…';
      clearTimeout(timer);
      timer = setTimeout(searchServer, 175);
    });
    search.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault(); activate(focused + (event.key === 'ArrowDown' ? 1 : -1));
      } else if (event.key === 'Enter' && focused >= 0 && hits[focused]) {
        event.preventDefault(); pick(hits[focused].value);
      }
    });
    useNative.addEventListener('click', () => {
      dialog.close();
      field.classList.remove('mad4b-passport-native-hidden');
      trigger.hidden = true;
      field.focus();
    });
    clear.addEventListener('click', () => pick(''));
    field.addEventListener('change', updateButton);
    field.addEventListener('invalid', event => {
      if (field.value || !field.required) return;
      event.preventDefault();
      show();
    });
  }
  function mount() {
    document.querySelectorAll('form.fluent_form_6 select[name="dropdown_1"]').forEach(bind);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
  } else mount();
  // New Elementor widgets can mount forms after Ajax without duplicate wrappers.
  let pending = false;
  new MutationObserver(() => {
    if (pending) return;
    pending = true;
    queueMicrotask(() => { pending = false; mount(); });
  }).observe(document.body, { childList: true, subtree: true });
})();
