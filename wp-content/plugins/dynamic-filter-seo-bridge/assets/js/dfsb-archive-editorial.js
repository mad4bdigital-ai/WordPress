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
    var pending = false, error = false, noResults = false, scheduled = false, running = false;
    function update() {
      if (running) return;
      running = true;
      scheduled = false;
      var intro = panel.querySelector('.seo-readmore-content');
      var introText = bodyOf(intro);
      var populated = !!introText, previously = [];
      if (introText.length > 100) previously.push(introText);
      Array.prototype.forEach.call(panel.querySelectorAll('.elementor-widget-jet-listing-dynamic-field'), function (field) {
        var source = field.querySelector('.etg-filter-term-section > div') ||
          field.querySelector('.etg-dfsb-live-slot, [data-dfsb-slot]') || field;
        var text = bodyOf(source);
        var bodies = Array.prototype.slice.call(field.querySelectorAll(
          '.dfsb-term-detail > p, .dfsb-term-detail__body, .etg-filter-term-section__content'
        )).map(bodyOf).filter(function (body) { return body.length > 80; });
        var matchesPrior = function (value) {
          return previously.some(function (prior) {
            return prior.indexOf(value.slice(0, 110)) !== -1 ||
              value.indexOf(prior.slice(0, 110)) !== -1;
          });
        };
        var duplicate = bodies.length ? bodies.every(matchesPrior) :
          text.length > 100 && matchesPrior(text);
        field.hidden = !text || duplicate;
        if (text && !duplicate) {
          populated = true;
          if (text.length > 100) previously.push(text);
        }
      });
      var notice = error ? labels[3] : pending ? labels[0] : noResults ? labels[1] :
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
    document.addEventListener('dfsb/ajax-presentation-start', function () { pending = true; error = false; schedule(); });
    document.addEventListener('dfsb/ajax-presentation-updated', function (e) {
      var data = e && e.detail || {};
      pending = false; error = false;
      noResults = data.result_count_authoritative === true && data.result_count === 0;
      schedule();
    });
    document.addEventListener('dfsb/ajax-presentation-blocked', function () { pending = false; error = true; schedule(); });
    document.addEventListener('dfsb/ajax-presentation-reset', function () { pending = false; error = false; noResults = false; schedule(); });
    document.addEventListener('etg-dfsb/ajax-presentation-reset', function () { pending = false; error = false; noResults = false; schedule(); });
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
