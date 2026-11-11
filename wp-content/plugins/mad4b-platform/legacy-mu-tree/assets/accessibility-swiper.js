/* JetEngine Swiper accessibility without synchronous geometry reads.
 * IntersectionObserver is the layout-owned visibility authority. */
(() => {
  'use strict';
  const selector = '.jet-listing-grid__slider.swiper-initialized';
  const focusable = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex]';
  const contexts = new WeakMap();
  const originalTabs = new WeakMap();

  function tabState(element, hidden) {
    if (!originalTabs.has(element)) originalTabs.set(element, element.getAttribute('tabindex'));
    const intended = hidden ? '-1' : originalTabs.get(element);
    if (intended === null) {
      if (element.hasAttribute('tabindex')) element.removeAttribute('tabindex');
    } else if (element.getAttribute('tabindex') !== intended) {
      element.setAttribute('tabindex', intended);
    }
  }
  function apply(slide, hidden, slider) {
    // The active slide must never become keyboard-inaccessible just because
    // IntersectionObserver sampled an offscreen slider root.
    if (slide.classList.contains('swiper-slide-active')) hidden = false;
    if (slide.inert === hidden && slide.getAttribute('aria-hidden') === String(hidden)) return;
    if (hidden && slide.contains(document.activeElement)) {
      const candidate = slider.querySelector('.swiper-slide-active a[href],.swiper-slide-active button:not([disabled])');
      if (candidate && !slide.contains(candidate)) candidate.focus({preventScroll:true});
    }
    if (slide.inert !== hidden) slide.inert = hidden;
    if (slide.getAttribute('aria-hidden') !== String(hidden)) slide.setAttribute('aria-hidden', String(hidden));
    slide.querySelectorAll(focusable).forEach(child => tabState(child, hidden));
  }
  function register(slider) {
    if (contexts.has(slider) || !('IntersectionObserver' in window)) return;
    const known = new WeakSet();
    const observer = new IntersectionObserver(entries => {
      // Chrome computes these intersections after layout, so Elementor and
      // Swiper never pay for forced getBoundingClientRect/getComputedStyle here.
      for (const e of entries) {
        const visible = e.target.classList.contains('swiper-slide-active') ||
          (e.isIntersecting && e.intersectionRect.width > 4 && e.intersectionRect.height > 4);
        apply(e.target, !visible, slider);
      }
    }, {root:slider, threshold:[0,0.01,0.25]});
    const watch = node => {
      if (!(node instanceof Element)) return;
      if (node.matches('.swiper-slide') && !known.has(node)) {
        known.add(node);
        observer.observe(node);
      }
      node.querySelectorAll('.swiper-slide').forEach(child => {
        if (known.has(child)) return;
        known.add(child);
        observer.observe(child);
      });
    };
    watch(slider);
    const changes = new MutationObserver(records => {
      records.forEach(record => {
        if (record.type === 'attributes') {
          // Swiper may change its active class without crossing an IO threshold.
          if (record.target.classList.contains('swiper-slide-active'))
            apply(record.target, false, slider);
          return;
        }
        record.addedNodes.forEach(watch);
      });
    });
    changes.observe(slider, {childList:true,subtree:true,attributes:true,attributeFilter:['class']});
    contexts.set(slider,{observer,changes});
  }
  function mount() { document.querySelectorAll(selector).forEach(register); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once:true});
  else mount();
  window.addEventListener('load', mount, {once:true});
  // Initial Swiper mount can occur after DOMContentLoaded.
  window.setTimeout(mount,300);
  window.setTimeout(mount,1500);
  document.addEventListener('jet-filter-content-rendered',mount);
})();
