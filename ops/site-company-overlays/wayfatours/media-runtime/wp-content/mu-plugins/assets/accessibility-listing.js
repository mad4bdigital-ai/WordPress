/* Shared JetEngine listing semantics. Works for initial and AJAX-rendered cards. */
(() => {
  'use strict';
  const names = {
    en: ['Add to favourites', 'Remove from favourites'],
    ar: ['أضف إلى المفضلة', 'إزالة من المفضلة'],
    de: ['Zu Favoriten hinzufügen', 'Aus Favoriten entfernen'],
    fr: ['Ajouter aux favoris', 'Retirer des favoris'],
    it: ['Aggiungi ai preferiti', 'Rimuovi dai preferiti'],
    es: ['Añadir a favoritos', 'Quitar de favoritos']
  };
  const lang = (document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
  const copy = names[lang] || names.en;
  const galleryName = {en:'Open gallery photo',ar:'عرض صورة المعرض',de:'Galeriefoto öffnen',fr:'Ouvrir une photo de la galerie',it:'Apri foto della galleria',es:'Abrir foto de la galería'}[lang] || 'Open gallery photo';
  const observed = new WeakSet();
  const selector = 'a.jet-add-to-store, a.jet-remove-from-store, a.jet-listing-dynamic-link__link[aria-label], a.uael-grid-gallery-img, button.jet-ajax-search__submit, button.jet-search-suggestions__submit';

  function titleFor(link) {
    const card = link.closest('.jet-listing-grid__item');
    if (!card) return '';
    const heading = card.querySelector('h2, h3, h4, .jet-listing-dynamic-field__content');
    return (heading?.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 115);
  }
  function fixLink(link) {
    if (link.matches('button.jet-ajax-search__submit, button.jet-search-suggestions__submit')) {
      const visible = (link.textContent || '').trim().replace(/\s+/g, ' ');
      const existing = link.getAttribute('aria-label') || '';
      if (visible && existing && !existing.toLocaleLowerCase().includes(visible.toLocaleLowerCase())) {
        link.setAttribute('aria-label', visible + ' — ' + existing);
      }
      return;
    }
    if (link.matches('.jet-add-to-store, .jet-remove-from-store')) {
      const remove = link.classList.contains('jet-remove-from-store');
      const title = titleFor(link);
      const labelNode = link.querySelector('.jet-listing-dynamic-link__label');
      const visible = (labelNode?.textContent || link.textContent || '').trim().replace(/\s+/g,' ');
      const action = visible || copy[remove ? 1 : 0];
      const label = action + (title ? ' — ' + title : '');
      if (link.getAttribute('aria-label') !== label) link.setAttribute('aria-label', label);
      link.setAttribute('role', 'button');
      link.setAttribute('aria-pressed', remove ? 'true' : 'false');
      if (!observed.has(link)) {
        observed.add(link);
        new MutationObserver(() => fixLink(link)).observe(link, {attributes:true,attributeFilter:['class']});
      }
      return;
    }
    if (link.matches('.uael-grid-gallery-img')) {
      const alt = (link.querySelector('img')?.getAttribute('alt') || '').trim();
      if (!alt && !link.textContent.trim() && !link.getAttribute('aria-label')) link.setAttribute('aria-label', galleryName);
      return;
    }
    const visible = (link.textContent || '').trim().replace(/\s+/g, ' ');
    const original = link.getAttribute('aria-label') || '';
    if (visible && original && !original.toLocaleLowerCase().includes(visible.toLocaleLowerCase())) {
      const detail = original.replace(/^View\s*:\s*/i, '').trim();
      link.setAttribute('aria-label', visible + (detail ? ' — ' + detail : ''));
    }
  }
  function scan(node) {
    if (!(node instanceof Element)) return;
    if (node.matches(selector)) fixLink(node);
    node.querySelectorAll(selector).forEach(fixLink);
  }
  function initialize() {
    scan(document.body);
    new MutationObserver(mutations => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node.nodeType === 1) scan(node);
        }
      }
    }).observe(document.body, {childList:true,subtree:true});
    document.addEventListener('keydown', event => {
      if (event.key !== ' ' || !event.target.matches?.('a.jet-add-to-store, a.jet-remove-from-store')) return;
      event.preventDefault();
      event.target.click();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once:true});
  else initialize();
})();
