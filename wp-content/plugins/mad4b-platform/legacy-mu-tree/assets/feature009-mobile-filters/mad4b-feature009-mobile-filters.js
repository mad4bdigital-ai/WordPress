/* Feature 009: use Elementor's native Filter button and template widget. */
(function () {
  'use strict';
  function init() {
    if (!document.body || !document.body.classList.contains('post-type-archive-tours-and-activities')) return;
    var sidebar = document.querySelector('.elementor-element-7d67ce7');
    if (!sidebar) return;
    var panel = sidebar.querySelector('.elementor-element-702cfb6c');
    var trigger = sidebar.querySelector('.elementor-element-697ba59f a.elementor-button');
    if (!panel || !trigger || !panel.querySelector('.jet-filter') || trigger.dataset.mad4bFilterBound === '1') return;
    var text = trigger.querySelector('.elementor-button-text');
    var initialLabel = text ? text.textContent.trim() : trigger.textContent.trim();
    var labels = {
      en: 'Hide filters', fr: 'Masquer les filtres', de: 'Filter ausblenden',
      es: 'Ocultar filtros', it: 'Nascondi filtri', ar: 'إخفاء الفلاتر'
    };
    var language = String(document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
    var openLabel = labels[language] || labels.en;
    var onMobile = function () { return window.matchMedia('(max-width: 767px)').matches; };
    trigger.dataset.mad4bFilterBound = '1';
    panel.id = 'mad4b-feature009-mobile-filter-panel';
    trigger.setAttribute('aria-controls', panel.id);
    function setOpen(open) {
      panel.classList.toggle('mad4b-feature009-mobile-filter-open', !!open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (text) text.textContent = open ? openLabel : initialLabel;
    }
    trigger.addEventListener('click', function (event) {
      if (!onMobile()) return; // Preserve native tablet behavior.
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation(); // Block Elementor popup action on mobile.
      setOpen(trigger.getAttribute('aria-expanded') !== 'true');
    }, true);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && onMobile() && trigger.getAttribute('aria-expanded') === 'true') {
        setOpen(false);
        trigger.focus();
      }
    });
    setOpen(false);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
