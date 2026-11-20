/* Source-aware accessibility contracts for Elementor archives + Jet Dropbar. */
(() => {
  'use strict';
  const phrases = {
    en: ['Available Tours & Activities', 'Open Share Menu'],
    ar: ['الرحلات والأنشطة المتاحة', 'فتح قائمة المشاركة'],
    de: ['Ausgewählte Touren und Aktivitäten', 'Teilen-Menü öffnen'],
    fr: ['Excursions et activités en vedette', 'Ouvrir le menu de partage'],
    it: ['Tour e attività in evidenza', 'Apri il menu di condivisione'],
    es: ['Tours y actividades destacados', 'Abrir menú para compartir']
  };
  const lang = (document.documentElement.lang || 'en').slice(0,2).toLowerCase();
  const [featuredLabel, shareLabel] = phrases[lang] || phrases.en;
  function fix() {
    for (const widget of document.querySelectorAll('.elementor-widget-jet-dropbar[aria-label]')) {
      const button = widget.querySelector('button.jet-dropbar__button');
      if (!button) continue;
      const label = widget.getAttribute('aria-label') || shareLabel;
      if (!button.hasAttribute('aria-label')) button.setAttribute('aria-label', label);
      widget.removeAttribute('aria-label');
    }
    for (const archive of document.querySelectorAll('.elementor-location-archive')) {
      if (!document.querySelector('main, [role="main"]')) archive.setAttribute('role', 'main');
      // This tour archive already has its real Elementor heading. Do not
      // inject an unrelated visible accessibility title above the filters.
      if (document.body.classList.contains('post-type-archive-tours-and-activities')) {
        archive.querySelectorAll('.a11y-archive-section-heading').forEach(el => el.remove());
        continue;
      }
      if (archive.querySelector('.a11y-archive-section-heading')) continue;
      const headingElements = [...archive.querySelectorAll('h1,h2,h3')];
      const visible = el => el.getBoundingClientRect().height > 0 && getComputedStyle(el).display !== 'none';
      const firstH3 = headingElements.find(el => el.tagName === 'H3' && visible(el));
      if (!firstH3) continue;
      const index = headingElements.indexOf(firstH3);
      if (!headingElements.slice(0,index).some(el => el.tagName === 'H1' && visible(el)) ||
          headingElements.slice(0,index).some(el => el.tagName === 'H2' && visible(el))) continue;
      archive.querySelectorAll('.a11y-featured-section-heading').forEach(el => el.remove());
      const section = firstH3.closest('.jet-listing-grid');
      const header = document.createElement('h2');
      header.className = 'a11y-archive-section-heading';
      header.textContent = featuredLabel;
      (section || firstH3).before(header);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fix, {once:true});
  else fix();
  window.addEventListener('load', fix, {once:true});
})();
