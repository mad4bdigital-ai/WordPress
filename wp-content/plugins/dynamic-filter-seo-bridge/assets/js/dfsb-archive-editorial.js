/* Native editorial presentation: source- and language-aware, never mutates JetSmartFilters.
 * Preserve local.6 empty-slot handling, local.1 duplicate suppression, and AJAX state.
 */
(function () {
  'use strict';
  var strings = {
    en: ['Updating travel information…', 'No tours match the selected filters.', 'Select a tour filter to explore relevant travel information.', 'Tour details could not refresh. Please adjust your filters.'],
    es: ['Actualizando la información de viajes…', 'No hay excursiones que coincidan con los filtros.', 'Selecciona un filtro para explorar viajes relacionados.', 'No se pudo actualizar la información. Ajusta los filtros.'],
    ar: ['جارٍ تحديث معلومات الرحلات…', 'لا توجد رحلات تطابق الفلاتر المحددة.', 'اختر فلترًا لاستكشاف الرحلات ذات الصلة.', 'تعذر تحديث المعلومات. يرجى تعديل الفلاتر.'],
    de: ['Reiseinformationen werden aktualisiert…', 'Keine Touren entsprechen den ausgewählten Filtern.', 'Wählen Sie einen Filter, um passende Reisen zu entdecken.', 'Die Reiseinformationen konnten nicht aktualisiert werden.'],
    fr: ['Mise à jour des informations de voyage…', 'Aucun voyage ne correspond aux filtres.', 'Sélectionnez un filtre pour découvrir les voyages associés.', 'Impossible de mettre à jour les informations. Modifiez les filtres.'],
    it: ['Aggiornamento delle informazioni di viaggio…', 'Nessun tour corrisponde ai filtri selezionati.', 'Seleziona un filtro per esplorare i tour pertinenti.', 'Impossibile aggiornare le informazioni. Modifica i filtri.']
  };
  var lang = (document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
  var labels = strings[lang] || strings.en;
  function normalize(text) { return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase(); }
  function bodyOf(el) { return normalize(el && el.innerText); }
  function bind(panel) {
    if (!panel || panel.dataset.dfsbEditorialBound === '1') return;
    panel.dataset.dfsbEditorialBound = '1';
    var status = document.createElement('p');
    status.className = 'dfsb-editorial-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.hidden = true;
    panel.appendChild(status);
    var states = Object.create(null), autoGroup = '', globalError = false, scheduled = false, running = false;
    function declaredGroup(el) {
      var own = el.getAttribute && (el.getAttribute('data-etg-dfsb-group') || el.getAttribute('data-dfsb-group'));
      if (own) return own.trim() || 'auto';
      var parent = el.closest && el.closest('[data-etg-dfsb-group],[data-dfsb-group]');
      return parent && parent !== el ? declaredGroup(parent) : 'auto';
    }
    function relevantGroups() {
      var nodes = panel.querySelectorAll('[data-etg-dfsb-token],[data-dfsb-token],[data-etg-dfsb-slot],[data-dfsb-slot],[data-etg-dfsb-media-slot],[data-dfsb-media-slot]');
      var groups = Array.prototype.map.call(nodes.length ? nodes : [panel], declaredGroup);
      return groups.map(function (key) { return key === 'auto' ? autoGroup : key; }).filter(function (key, i, all) { return key && all.indexOf(key) === i; });
    }
    function transition(kind, e) {
      var data = e && e.detail || {};
      var key = data.group || (data.provider && data.query_id ? data.provider + '/' + data.query_id : '');
      if (typeof data.editorial_auto_group === 'string') autoGroup = data.editorial_auto_group;
      else if (!autoGroup && key) autoGroup = key; // legacy event without resolved scope
      // Unscoped historical notifications are used only for a single known group.
      var groups = relevantGroups();
      if (!key && kind !== 'blocked' && groups.length === 1) key = groups[0];
      if (!key && kind === 'start' && groups.length === 0) return;
      if (key) {
        if (groups.indexOf(key) !== -1) globalError = false;
        if (kind === 'reset') delete states[key];
        else states[key] = { pending: kind === 'start', error: kind === 'blocked', zero: kind === 'updated' && data.result_count_authoritative === true && data.result_count === 0 };
      } else if (kind === 'blocked') {
        if (data.reason === 'jsf_runtime_contract_unavailable') { states = Object.create(null); globalError = true; }
        else if (!groups.length) globalError = true;
      } else if (kind === 'reset' && !groups.length) globalError = false;
      schedule();
    }
    // Only hide exact native card duplicates within a resolved group and role.
    // Text prefixes alone cannot establish identity of a different term.
    var hiddenCards = new WeakSet();
    function suppressNativeCards(seen) {
      Array.prototype.forEach.call(panel.querySelectorAll('.dfsb-term-detail[data-term-id]'), function (card) {
        if (hiddenCards.has(card)) { card.hidden = false; hiddenCards.delete(card); }
        if (card.hidden) return;
        var roleNode = card.closest('[data-dfsb-role]');
        var role = roleNode && roleNode.getAttribute('data-dfsb-role');
        var id = card.getAttribute('data-term-id');
        var group = declaredGroup(card);
        if (group === 'auto') group = autoGroup;
        if (!group || !role || !id) return;
        var signature = JSON.stringify([group, role, id, normalize(card.textContent)]);
        if (seen.has(signature)) { card.hidden = true; hiddenCards.add(card); }
        else seen.add(signature);
      });
    }
    function update() {
      if (running) return;
      running = true;
      scheduled = false;
      var intro = panel.querySelector('.seo-readmore-content');
      var introText = bodyOf(intro);
      var populated = !!introText, previously = Object.create(null);
      var introGroup = intro ? declaredGroup(intro) : 'auto';
      if (introGroup === 'auto') introGroup = autoGroup;
      if (introGroup && introText.length > 100) previously[introGroup] = [introText];
      suppressNativeCards(new Set());
      Array.prototype.forEach.call(panel.querySelectorAll('.elementor-widget-jet-listing-dynamic-field'), function (field) {
        var source = field.querySelector('.etg-filter-term-section > div') ||
          field.querySelector('.etg-dfsb-live-slot, [data-dfsb-slot]') || field;
        var cards = field.querySelectorAll('.dfsb-term-detail[data-term-id]');
        if (cards.length) {
          // Preserve custom surrounding copy and closed <details> bodies.
          // Whole-field hiding is safe only when all content is duplicate cards.
          var visible = Array.prototype.some.call(cards, function (card) { return !card.hidden; });
          var cardText = Array.prototype.map.call(cards, function (card) { return normalize(card.textContent); }).join('').replace(/\s/g, '');
          var onlyCards = normalize(source.textContent).replace(/\s/g, '') === cardText;
          field.hidden = !visible && onlyCards;
          if (!field.hidden) populated = true;
          return;
        }
        var group = declaredGroup(source);
        if (group === 'auto') group = autoGroup;
        var prior = group ? (previously[group] || (previously[group] = [])) : [];
        var text = bodyOf(source);
        var bodies = Array.prototype.slice.call(field.querySelectorAll(
          '.dfsb-term-detail > p, .dfsb-term-detail__body, .etg-filter-term-section__content'
        )).map(bodyOf).filter(function (body) { return body.length > 80; });
        var matchesPrior = function (value) {
          return prior.indexOf(value) !== -1;
        };
        var duplicate = bodies.length ? bodies.every(matchesPrior) :
          text.length > 100 && matchesPrior(text);
        field.hidden = !text || duplicate;
        if (text && !duplicate) {
          populated = true;
          if (text.length > 100 && group) prior.push(text);
        }
      });
      var relevant = relevantGroups().map(function (key) { return states[key] || {}; });
      var pending = relevant.some(function (state) { return state.pending; });
      var error = globalError || relevant.some(function (state) { return state.error; });
      var noResults = relevant.length > 0 && relevant.every(function (state) { return state.zero; });
      var notice = pending ? labels[0] : error ? labels[3] : noResults ? labels[1] :
        !populated ? labels[2] : '';
      if (status.textContent !== notice) status.textContent = notice;
      status.hidden = !notice;
      panel.hidden = !populated && !notice;
      panel.classList.toggle('dfsb-editorial-loading', pending);
      panel.setAttribute('aria-busy', pending ? 'true' : 'false');
      running = false;
    }
    function schedule() {
      if (scheduled || running) return;
      scheduled = true;
      window.requestAnimationFrame(update);
    }
    ['start','updated','blocked','reset'].forEach(function (kind) {
      document.addEventListener('dfsb/ajax-presentation-' + kind, function (e) { transition(kind, e); });
    });
    document.addEventListener('etg-dfsb/ajax-presentation-reset', function (e) { transition('reset', e); });
    document.addEventListener('dfsb/presentation-updated', schedule);
    // AJAX and Elementor emit explicit events. Observing our own text/hidden
    // mutations causes extra work and visual re-layout during hydration.
    update();
  }
  function init() { document.querySelectorAll('.dfsb-archive-editorial').forEach(bind); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  document.addEventListener('elementor/frontend/init', init);
}());
