/*
 * Destination mobile contents: reuse the proven tour drawer from Elementor anchor links.
 * No Elementor data changes, no headings removed, no content/anchors rewritten.
 */
(function () {
  'use strict';

  var MOBILE_QUERY = '(max-width: 767px)';
  var WIDGET_SELECTOR = 'body.tax-destinations .elementor-45774 .elementor-element-f10db4f';
  var LABELS = {
    en: ['Destination contents', 'Overview & highlights', 'Things to do', 'Places to explore', 'Travel planning'],
    ar: ['محتويات الوجهة', 'نظرة عامة وأهم المميزات', 'أنشطة وتجارب', 'أماكن للاستكشاف', 'تخطيط الرحلة'],
    fr: ['Sommaire de la destination', 'Présentation', 'Activités', 'Lieux à explorer', 'Préparer le voyage'],
    de: ['Reiseziel-Inhalte', 'Überblick', 'Aktivitäten', 'Orte entdecken', 'Reiseplanung'],
    it: ['Contenuti della destinazione', 'Panoramica', 'Attività', 'Luoghi da scoprire', 'Organizzare il viaggio'],
    es: ['Contenido del destino', 'Resumen y puntos destacados', 'Qué hacer', 'Lugares para explorar', 'Planifica tu viaje']
  };
  var RULES = [
    /\b(about|overview|introduction|history|general|highlights|destination info|geography|about us)\b|عن|نبذة|مقدمة|نظرة عامة|présentation|aperçu|geschichte|überblick|panoramica|resumen|historia/i,
    /\b(activities|excursions|attractions|things to do|tours|diving|experiences|inspiration|adventure|spots)\b|الأنشطة|التجارب|الجولات|المعالم|activit[eé]s|excursions|attivit[aà]|actividades|atracciones|ausfl[uü]ge/i,
    /\b(nearby|destinations|zones|areas|cities|government|states|regions|hierarchy|places|explore|must visit|landmarks)\b|مدن|المناطق|الأماكن|وجهات|استكشف|مجاورة|villes|r[eé]gions|orte|st[aä]dte|luoghi|citt[aà]|lugares|ciudades|regiones/i,
    /\b(plan|ready|booking|inquiry|contact|travel tips|when to visit|like this|next trip|your trip|journey)\b|خطط|الحجز|استعد|التخطيط|السفر|r[eé]servation|voyage|planung|buchung|viaggio|prenotazione|reserva|viaje|planifica/i
  ];

  function languageFor(doc) {
    var lang = (doc.documentElement.getAttribute('lang') || 'en').slice(0, 2).toLowerCase();
    return LABELS[lang] ? lang : 'en';
  }

  function classify(title, index) {
    // More-specific "FAQ" and "Important Links" take precedence over generic
    // mention of tours or company in long, generated post titles.
    if (RULES[3].test(title)) return 3;
    if (RULES[2].test(title)) return 2;
    if (RULES[1].test(title)) return 1;
    if (RULES[0].test(title)) return 0;
    if (index < 3) return 0;
    if (index < 8) return 1;
    if (index < 13) return 2;
    return 3;
  }

  function allowedHash(href) {
    return typeof href === 'string' &&
      /^#[a-zA-Z][a-zA-Z0-9_:-]{0,120}$/.test(href);
  }

  function element(doc, tag, className, label) {
    var e = doc.createElement(tag);
    if (className) e.className = className;
    if (typeof label === 'string') e.textContent = label;
    return e;
  }

  function build(widget, doc) {
    if (widget.classList.contains('mad4b-toc-ready')) return true;
    var source = widget.querySelectorAll(
      '.elementor-toc__body a.elementor-toc__list-item-text[href^="#"]'
    );
    if (!source.length) return false;

    var items = [];
    Array.prototype.forEach.call(source, function (item) {
      var label = (item.textContent || '').trim().replace(/\s+/g, ' ');
      var href = item.getAttribute('href');
      if (label && allowedHash(href) && doc.getElementById(href.slice(1))) {
        items.push({ text: label, href: href });
      }
    });
    if (!items.length) return false;

    var container = widget.querySelector('.elementor-widget-container');
    if (!container) return false;
    var labels = LABELS[languageFor(doc)];
    var nav = element(doc, 'nav', 'mad4b-destination-toc');
    nav.setAttribute('aria-label', labels[0]);
    var drawer = element(doc, 'details', 'mad4b-destination-toc__drawer');
    var summary = element(doc, 'summary', '');
    summary.appendChild(element(doc, 'span', 'mad4b-destination-toc__heading', labels[0]));
    summary.appendChild(element(doc, 'span', 'mad4b-destination-toc__count', String(items.length)));
    var chevron = element(doc, 'span', 'mad4b-destination-toc__chevron');
    chevron.setAttribute('aria-hidden', 'true');
    summary.appendChild(chevron);
    drawer.appendChild(summary);
    var panels = element(doc, 'div', 'mad4b-destination-toc__panels');
    var lists = [[], [], [], []];
    items.forEach(function (item, index) {
      lists[classify(item.text, index)].push(item);
    });

    lists.forEach(function (group, index) {
      if (!group.length) return;
      var details = element(doc, 'details', 'mad4b-destination-toc__group');
      var heading = element(doc, 'summary', '', labels[index + 1]);
      details.appendChild(heading);
      var ul = element(doc, 'ul', '');
      group.forEach(function (item) {
        var li = element(doc, 'li', '');
        var anchor = element(doc, 'a', '', item.text);
        anchor.setAttribute('href', item.href);
        anchor.addEventListener('click', function () { drawer.open = false; });
        li.appendChild(anchor);
        ul.appendChild(li);
      });
      details.appendChild(ul);
      panels.appendChild(details);
    });

    drawer.appendChild(panels);
    nav.appendChild(drawer);
    container.insertBefore(nav, container.firstChild);
    widget.classList.add('mad4b-toc-ready');
    return true;
  }

  function init() {
    if (!doc.body.classList.contains('tax-destinations')) return;
    var widget = doc.querySelector(WIDGET_SELECTOR);
    if (!widget) return;
    var media = window.matchMedia(MOBILE_QUERY);
    var observer = null;

    function activate() {
      if (!media.matches || widget.classList.contains('mad4b-toc-ready')) return;
      if (build(widget, doc)) {
        if (observer) observer.disconnect();
      } else if (!observer && typeof MutationObserver !== 'undefined') {
        observer = new MutationObserver(function () {
          if (build(widget, doc)) observer.disconnect();
        });
        observer.observe(widget, { childList: true, subtree: true });
        setTimeout(function () {
          if (observer) observer.disconnect();
          observer = null;
        }, 15000);
      }
    }

    activate();
    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', activate);
    } else if (typeof media.addListener === 'function') {
      media.addListener(activate);
    }
  }

  var doc = typeof document !== 'undefined' ? document : null;
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { classify: classify, allowedHash: allowedHash, languageFor: languageFor };
  }
  if (!doc) return;
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();
}());
