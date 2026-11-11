/*
 * One compact nested mobile index sourced from Elementor's own TOC links.
 * No Elementor data changes, no headings removed, no content/anchors rewritten.
 */
(function () {
  'use strict';

  var MOBILE_QUERY = '(max-width: 767px)';
  var WIDGET_SELECTOR = 'body.single-tours-and-activities .elementor-24784 .elementor-element-b29a19b';
  var LABELS = {
    en: ['Tour contents', 'Overview', 'Experience & itinerary', 'Booking essentials', 'Explore more'],
    ar: ['محتويات الرحلة', 'نظرة عامة', 'تفاصيل الرحلة والبرنامج', 'معلومات الحجز', 'استكشف المزيد'],
    fr: ['Sommaire du voyage', 'Aperçu', 'Expérience et itinéraire', 'Réservation', 'À découvrir'],
    de: ['Reiseübersicht', 'Überblick', 'Erlebnis und Reiseverlauf', 'Buchungsinfos', 'Mehr entdecken'],
    it: ['Contenuti del tour', 'Panoramica', 'Esperienze e itinerario', 'Prenotazione', 'Scopri di più'],
    es: ['Contenido del tour', 'Resumen', 'Experiencia e itinerario', 'Reservas', 'Descubre más']
  };
  var RULES = [
    /\b(duration|destination|tour type|tour categor|schedule days?|days? of|overview)\b|المدة|الوجهات|التصنيف|مدة الرحلة|durée|destinations|dauer|reiseziel|durata|destinazioni|duraci[oó]n|destinos/i,
    /\b(about|highlights?|included|excluded|itinerary|program|amenities)\b|البرنامج|عن الرحلة|المميزات|المشمول|المستثنى|itin[eé]raire|surligne|leistungen|reiseverlauf|itinerario|inclusi|itinerario|incluido/i,
    /\b(important links|booking|pricing|cancel|children|accommodation|tipping|refund|price|payment)\b|الحجز|الأسعار|الإلغاء|الأطفال|الإقامة|paiement|r[eé]servation|annulation|buchung|preis|stornierung|prenotazione|prezzi|cancellazione|reserva|precio|cancelaci[oó]n/i,
    /\b(faq|why travel|explore|inspiration|ready to|travel ideas|designed journeys)\b|الأسئلة|لماذا|استكشف|إلهام|inspiration|d[eé]couvrir|entdecken|domande|ispirazione|explorar|inspiraci[oó]n/i
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
    if (index < 5) return 0;
    if (index < 10) return 1;
    if (index < 14) return 2;
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
      if (label && allowedHash(href)) {
        items.push({ text: label, href: href });
      }
    });
    if (!items.length) return false;

    var container = widget.querySelector('.elementor-widget-container');
    if (!container) return false;
    var labels = LABELS[languageFor(doc)];
    var nav = element(doc, 'nav', 'mad4b-tour-toc');
    nav.setAttribute('aria-label', labels[0]);
    var drawer = element(doc, 'details', 'mad4b-tour-toc__drawer');
    var summary = element(doc, 'summary', '');
    summary.appendChild(element(doc, 'span', 'mad4b-tour-toc__heading', labels[0]));
    summary.appendChild(element(doc, 'span', 'mad4b-tour-toc__count', String(items.length)));
    var chevron = element(doc, 'span', 'mad4b-tour-toc__chevron');
    chevron.setAttribute('aria-hidden', 'true');
    summary.appendChild(chevron);
    drawer.appendChild(summary);
    var panels = element(doc, 'div', 'mad4b-tour-toc__panels');
    var lists = [[], [], [], []];
    items.forEach(function (item, index) {
      lists[classify(item.text, index)].push(item);
    });

    lists.forEach(function (group, index) {
      if (!group.length) return;
      var details = element(doc, 'details', 'mad4b-tour-toc__group');
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
    if (!doc.body.classList.contains('single-tours-and-activities')) return;
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
