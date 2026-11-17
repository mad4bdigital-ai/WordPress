/* Scope to known related-content blocks, not all Elementor widgets. A
 * pending/lazy-loading grid remains visible. Empty may change after AJAX. */
(() => {
  'use strict';
  const groups = [
    '.elementor-48366 .elementor-element-3da417e', // Tour Governorates
    '.elementor-48366 .elementor-element-c73392f', // Tour Countries
    '.elementor-45774 .elementor-element-0e795e8'  // Destination recommended tours
  ];
  const done = g => {
    if (g.classList.contains('jet-listing-grid-loading') ||
        g.classList.contains('jet-listing-grid--lazy-load')) return false;
    // The completed marker is authoritative for AJAX lazy grids; otherwise a
    // server-rendered grid without a lazy loader is already settled.
    return g.classList.contains('jet-listing-grid--lazy-load-completed') ||
           !g.hasAttribute('data-lazy-load');
  };
  const apply = root => {
    const grids = Array.from(root.querySelectorAll('.jet-listing-grid'));
    if (!grids.length) return;
    const settled = grids.every(done);
    const hasItems = grids.some(g => g.querySelector('.jet-listing-grid__item'));
    const shouldHide = settled && !hasItems;
    if (root.classList.contains('mad4b-dynamic-empty') !== shouldHide)
      root.classList.toggle('mad4b-dynamic-empty', shouldHide);
  };
  const init = () => {
    const roots = groups.flatMap(selector => [...document.querySelectorAll(selector)]);
    roots.forEach(root => {
      if (root.dataset.mad4bDynamicEmptyWatch === '1') return;
      root.dataset.mad4bDynamicEmptyWatch = '1';
      let queued = false;
      const update = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {queued = false; apply(root);});
      };
      const observer = new MutationObserver(update);
      observer.observe(root, {childList:true, subtree:true, attributes:true, attributeFilter:['class']});
      update();
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
  document.addEventListener('mad4b:shell:ready', init);
})();
