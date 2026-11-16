(function () {
  'use strict';
  const data = window.MAD4B_TOUR_STORY_GALLERY;
  if (!data || data.contract !== 'mad4b.tour-story-gallery.v1' || !Array.isArray(data.sections)) return;
  const lang = String(data.language || 'en').slice(0, 2).toLowerCase();
  const translation = {
    en:{title:'Explore your journey in photos',sub:'Photos grouped by the places and experiences in this itinerary.',day:'Day ',days:'Days ',photos:'photos',tour:'Tour gallery',close:'Close photo',previous:'Previous photo',next:'Next photo',view:'View photo',chapter:{'Tour Photos':'Tour Photos'}},
    es:{title:'Explora tu viaje en fotos',sub:'Fotos organizadas por los lugares y experiencias de este itinerario.',day:'Día ',days:'Días ',photos:'fotos',tour:'Fotos del viaje',close:'Cerrar foto',previous:'Foto anterior',next:'Foto siguiente',view:'Ver foto',chapter:{'Tour Photos':'Fotos del viaje','Cairo':'El Cairo','Giza':'Guiza','Luxor':'Lúxor','Aswan':'Asuán','Red Sea':'Mar Rojo'}},
    fr:{title:'Découvrez votre voyage en photos',sub:'Photos classées selon les lieux et les expériences de cet itinéraire.',day:'Jour ',days:'Jours ',photos:'photos',tour:'Photos du voyage',close:'Fermer la photo',previous:'Photo précédente',next:'Photo suivante',view:'Voir la photo',chapter:{'Tour Photos':'Photos du voyage','Cairo':'Le Caire','Giza':'Gizeh','Luxor':'Louxor','Aswan':'Assouan','Red Sea':'Mer Rouge'}},
    de:{title:'Entdecken Sie Ihre Reise in Bildern',sub:'Fotos nach Orten und Erlebnissen dieser Reiseroute.',day:'Tag ',days:'Tage ',photos:'Fotos',tour:'Reisefotos',close:'Foto schließen',previous:'Vorheriges Foto',next:'Nächstes Foto',view:'Foto ansehen',chapter:{'Tour Photos':'Reisefotos','Cairo':'Kairo','Giza':'Gizeh','Luxor':'Luxor','Aswan':'Assuan','Red Sea':'Rotes Meer'}},
    it:{title:'Scopri il tuo viaggio in foto',sub:'Foto suddivise per luoghi ed esperienze dell’itinerario.',day:'Giorno ',days:'Giorni ',photos:'foto',tour:'Foto del tour',close:'Chiudi foto',previous:'Foto precedente',next:'Foto successiva',view:'Visualizza foto',chapter:{'Tour Photos':'Foto del tour','Cairo':'Il Cairo','Giza':'Giza','Luxor':'Luxor','Aswan':'Assuan','Red Sea':'Mar Rosso'}},
    ar:{title:'اكتشف رحلتك بالصور',sub:'صور مرتبة حسب الأماكن والتجارب المذكورة في برنامج الرحلة.',day:'اليوم ',days:'الأيام ',photos:'صور',tour:'صور الرحلة',close:'إغلاق الصورة',previous:'الصورة السابقة',next:'الصورة التالية',view:'عرض صورة',chapter:{'Tour Photos':'صور الرحلة','Cairo':'القاهرة','Giza':'الجيزة','Luxor':'الأقصر','Aswan':'أسوان','Red Sea':'البحر الأحمر'}}
  }[lang] || null;
  const copy = translation || {title:'Explore your journey in photos',sub:'Photos grouped by the places and experiences in this itinerary.',day:'Day ',days:'Days ',photos:'photos',tour:'Tour gallery',close:'Close photo',previous:'Previous photo',next:'Next photo',view:'View photo',chapter:{}};
  const chapterName = name => copy.chapter?.[String(name)] || String(name);
  const chapters = data.sections.filter(item => Array.isArray(item.images) && item.images.length);
  if (!chapters.length) return;
  let overlay = null;
  let overlayImages = [];
  let current = 0;
  let previousFocus = null;
  const element = (tag, className, text) => {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (text !== undefined) item.textContent = String(text);
    return item;
  };
  const dayLabel = days => {
    if (!Array.isArray(days) || !days.length) return '';
    const numbers = Array.from(new Set(days.map(Number))).sort((a, b) => a - b);
    return (numbers.length === 1 ? copy.day : copy.days) + numbers.join(', ');
  };
  function hideViewer() {
    if (!overlay) return;
    const old = overlay;
    overlay = null;
    old.remove();
    document.body.classList.remove('mad4b-story-lightbox-open');
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    previousFocus = null;
  }
  function displayPhoto() {
    if (!overlay || !overlayImages.length) return;
    const target = overlayImages[current];
    const img = overlay.querySelector('.mad4b-story-lightbox-photo');
    const label = overlay.querySelector('.mad4b-story-lightbox-caption');
    const counter = overlay.querySelector('.mad4b-story-lightbox-counter');
    img.src = target.image.url;
    img.alt = target.image.alt || target.chapter;
    label.textContent = chapterName(target.chapter) + (target.image.context_label ? ' · ' + target.image.context_label : (target.image.alt ? ' · ' + target.image.alt : ''));
    counter.textContent = (current + 1) + ' / ' + overlayImages.length;
  }
  function openViewer(index) {
    if (overlay) hideViewer();
    previousFocus = document.activeElement;
    current = index;
    overlay = element('div', 'mad4b-story-lightbox');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Tour gallery photograph');
    const content = element('div', 'mad4b-story-lightbox-content');
    const close = element('button', 'mad4b-story-lightbox-close', '×');
    close.setAttribute('type', 'button');
    close.setAttribute('aria-label', copy.close);
    close.addEventListener('click', hideViewer);
    const prev = element('button', 'mad4b-story-lightbox-prev', '‹');
    prev.type = 'button';
    prev.setAttribute('aria-label', copy.previous);
    prev.addEventListener('click', () => { current = (current - 1 + overlayImages.length) % overlayImages.length; displayPhoto(); });
    const next = element('button', 'mad4b-story-lightbox-next', '›');
    next.type = 'button';
    next.setAttribute('aria-label', copy.next);
    next.addEventListener('click', () => { current = (current + 1) % overlayImages.length; displayPhoto(); });
    const image = element('img', 'mad4b-story-lightbox-photo');
    const info = element('div', 'mad4b-story-lightbox-info');
    const caption = element('p', 'mad4b-story-lightbox-caption');
    const counter = element('span', 'mad4b-story-lightbox-counter');
    info.append(caption, counter);
    content.append(close, prev, image, next, info);
    overlay.append(content);
    overlay.addEventListener('click', event => { if (event.target === overlay) hideViewer(); });
    document.body.append(overlay);
    document.body.classList.add('mad4b-story-lightbox-open');
    displayPhoto();
    close.focus();
  }
  document.addEventListener('keydown', function (event) {
    if (!overlay) return;
    if (event.key === 'Escape') { event.preventDefault(); hideViewer(); }
    else if (event.key === 'ArrowRight') { event.preventDefault(); current = (current + 1) % overlayImages.length; displayPhoto(); }
    else if (event.key === 'ArrowLeft') { event.preventDefault(); current = (current - 1 + overlayImages.length) % overlayImages.length; displayPhoto(); }
    else if (event.key === 'Tab') {
      const buttons = [...overlay.querySelectorAll('button')];
      const i = buttons.indexOf(document.activeElement);
      if (event.shiftKey && i === 0) { event.preventDefault(); buttons[buttons.length - 1].focus(); }
      if (!event.shiftKey && i === buttons.length - 1) { event.preventDefault(); buttons[0].focus(); }
    }
  });
  function mount(popup) {
    if (!popup || popup.querySelector('[data-mad4b-story-gallery]')) return;
    const original = popup.querySelector('.elementor-widget-uael-image-gallery');
    if (!original) return;
    const panel = element('section', 'mad4b-story-gallery');
    panel.setAttribute('data-mad4b-story-gallery', '1');
    panel.setAttribute('aria-label', copy.title);
    if (lang === 'ar') panel.dir = 'rtl';
    const heading = element('div', 'mad4b-story-gallery-heading');
    const title = element('h2', '', copy.title);
    const sub = element('p', '', copy.sub);
    heading.append(title, sub);
    const nav = element('nav', 'mad4b-story-gallery-navigation');
    nav.setAttribute('aria-label', 'Jump to photo section');
    const blocks = element('div', 'mad4b-story-gallery-sections');
    const images = [];
    chapters.forEach((chapter, chapterIndex) => {
      const sectionId = 'mad4b-tour-gallery-' + data.tour_id + '-' + chapterIndex;
      const group = element('section', 'mad4b-story-gallery-chapter');
      group.id = sectionId;
      const bar = element('div', 'mad4b-story-gallery-section-header');
      const words = element('div');
      words.append(element('h3', '', chapterName(chapter.title)));
      const days = dayLabel(chapter.days);
      if (days) words.append(element('p', 'mad4b-story-gallery-days', days));
      bar.append(words, element('span', 'mad4b-story-gallery-count', chapter.images.length + ' ' + copy.photos));
      const grid = element('div', 'mad4b-story-gallery-grid');
      chapter.images.forEach((photo) => {
        const index = images.length;
        images.push({ chapter: chapter.title, image: photo });
        const item = element('button', 'mad4b-story-gallery-tile');
        item.type = 'button';
        item.setAttribute('aria-label', copy.view + ' ' + (index + 1) + ' · ' + chapterName(chapter.title));
        const img = element('img');
        img.src = photo.thumbnail || photo.url;
        img.alt = photo.alt || chapter.title;
        img.loading = 'lazy';
        img.decoding = 'async';
        const figcaption = element('span', 'mad4b-story-gallery-photo-caption', photo.context_label || (photo.source === 'destination_gallery' ? chapterName(chapter.title) : copy.tour));
        item.append(img, figcaption);
        item.addEventListener('click', () => openViewer(index));
        grid.append(item);
      });
      group.append(bar, grid);
      blocks.append(group);
      const navButton = element('button', 'mad4b-story-gallery-tab', chapterName(chapter.title) + ' · ' + chapter.images.length);
      navButton.type = 'button';
      navButton.setAttribute('aria-controls', sectionId);
      navButton.addEventListener('click', () => group.scrollIntoView({ behavior: 'smooth', block: 'start' }));
      nav.append(navButton);
    });
    overlayImages = images;
    panel.append(heading, nav, blocks);
    // No gallery data or popup template is overwritten. The original UAEL
    // widget stays as a fail-safe until a full accessible section render works.
    original.parentNode.insertBefore(panel, original);
    original.classList.add('mad4b-story-gallery-superseded');
    popup.classList.add('mad4b-story-gallery-active');
  }
  function tryMount() {
    document.querySelectorAll('.elementor-popup-modal').forEach(mount);
  }
  function startWatching() {
    tryMount();
    if (document.querySelector('.elementor-popup-modal [data-mad4b-story-gallery]')) return;
    const observer = new MutationObserver(() => {
      tryMount();
      if (document.querySelector('.elementor-popup-modal [data-mad4b-story-gallery]')) observer.disconnect();
    });
    observer.observe(document.body, { childList: true, subtree: true });
    window.setTimeout(() => observer.disconnect(), 6000);
  }
  document.addEventListener('click', event => {
    const target = event.target.closest('#single_tour_gellery_button, [href*="popup%3Aopen"]');
    if (target) window.setTimeout(startWatching, 40);
  }, true);
  if (window.jQuery) window.jQuery(document).on('elementor/popup/show', () => window.setTimeout(startWatching, 50));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tryMount);
  else tryMount();
})();
