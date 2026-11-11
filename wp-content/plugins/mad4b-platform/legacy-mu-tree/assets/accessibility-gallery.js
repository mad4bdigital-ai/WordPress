/* UAEL/Slick keyboard safety. Observe only carousels, not the entire document. */
(() => {
  'use strict';
  const selector = '.uael-img-gallery-wrap.uael-img-carousel-wrap';
  const focusable = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]';
  const states = new WeakMap();

  function synchronize(root) {
    const slides = root.querySelectorAll('.slick-slide');
    if (!slides.length) return;
    for (const slide of slides) {
      const isHidden = slide.getAttribute('aria-hidden') === 'true' || !slide.classList.contains('slick-active');
      if (isHidden && slide.contains(document.activeElement)) {
        const accessible = root.querySelector('.slick-slide.slick-active[aria-hidden="false"] .uael-grid-img');
        if (accessible) accessible.focus({ preventScroll: true });
      }
      if (slide.inert !== isHidden) slide.inert = isHidden;
      if (slide.getAttribute('tabindex') !== (isHidden ? '-1' : '0')) {
        slide.setAttribute('tabindex', isHidden ? '-1' : '0');
      }
      for (const child of slide.querySelectorAll(focusable)) {
        if (child === slide) continue;
        const intended = isHidden ? '-1' : '0';
        if (child.getAttribute('tabindex') !== intended) child.setAttribute('tabindex', intended);
      }
    }
  }

  function register(root) {
    if (states.has(root)) return;
    let scheduled = false;
    const queue = () => {
      if (scheduled) return;
      scheduled = true;
      requestAnimationFrame(() => { scheduled = false; synchronize(root); });
    };
    const observer = new MutationObserver(queue);
    observer.observe(root, {subtree:true, childList:true, attributes:true, attributeFilter:['class','aria-hidden','tabindex']});
    states.set(root, {observer, queue});
    queue();
  }

  function mount() {
    document.querySelectorAll(selector).forEach(register);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once:true});
  else mount();
  window.addEventListener('load', mount, {once:true});
  if (window.jQuery) {
    window.jQuery(document).on('init reInit afterChange breakpoint', selector, function () {
      register(this);
      states.get(this)?.queue();
    });
  }
})();
