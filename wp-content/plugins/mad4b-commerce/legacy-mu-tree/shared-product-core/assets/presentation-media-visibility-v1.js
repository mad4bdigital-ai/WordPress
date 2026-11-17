/**
 * Shared Presentation Media Visibility 1.1
 * JetEngine/Swiper + LiteSpeed image placeholder compatibility.
 * An IntersectionObserver on the IMAGE (not an untransformed Swiper gallery
 * container) activates real image sources only at viewport proximity.
 */
(() => {
  'use strict';
  if (window.SharedPresentationMediaVisibilityV1) return;
  const match = 'img.jet-engine-gallery-slider__item-img[data-src]';
  const known = new WeakSet();
  const current = new WeakSet();
  const attempts = new WeakMap();
  const metrics = { observed: 0, activated: 0, restored: 0, rejected: 0 };
  let pendingScan = false;

  function source(img) {
    const raw = img.getAttribute('data-src');
    if (!raw) return null;
    try {
      const u = new URL(raw, location.href);
      if (!/^https?:$/.test(u.protocol) || u.origin !== location.origin ||
          !u.pathname.startsWith('/wp-content/uploads/')) return null;
      return u.href;
    } catch (_) { return null; }
  }
  function activate(img) {
    if (!img.isConnected) return;
    const url = source(img);
    if (!url) { metrics.rejected += 1; return; }
    if (img.src === url) return;
    const previous = attempts.get(img) || 0;
    if (previous >= 4) return; // Avoid endless fights with a vendor optimizer.
    attempts.set(img, previous + 1);
    img.loading = 'lazy';
    img.decoding = 'async';
    img.setAttribute('src', url);
    if (previous) metrics.restored += 1;
    else metrics.activated += 1;
  }
  function nearViewport(img) {
    const r = img.getBoundingClientRect();
    return r.width > 0 && r.height > 0 &&
      r.bottom >= -300 && r.top <= innerHeight + 300 &&
      r.right >= -120 && r.left <= innerWidth + 120;
  }
  const watcher = typeof IntersectionObserver !== 'undefined'
    ? new IntersectionObserver(entries => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            current.add(entry.target);
            activate(entry.target);
          } else current.delete(entry.target);
        }
      }, { rootMargin: '300px 120px', threshold: 0 })
    : null;

  function attach(img) {
    if (known.has(img)) return;
    known.add(img);
    metrics.observed++;
    img.loading = 'lazy';
    img.decoding = 'async';
    if (watcher) watcher.observe(img);
    else if (nearViewport(img)) activate(img);
  }
  function discover(node) {
    if (node.nodeType !== 1) return;
    if (node.matches(match)) attach(node);
    node.querySelectorAll(match).forEach(attach);
  }
  function scan() {
    pendingScan = false;
    document.querySelectorAll(match).forEach(attach);
  }
  function rescan() {
    if (pendingScan) return;
    pendingScan = true;
    requestAnimationFrame(scan);
  }
  function initialize() {
    scan();
    const changes = new MutationObserver(records => {
      for (const change of records) {
        if (change.type === 'childList') {
          for (const added of change.addedNodes) discover(added);
        } else if (change.target.matches?.(match)) {
          const img = change.target;
          if (change.attributeName === 'data-src') attach(img);
          if (current.has(img) && nearViewport(img)) activate(img);
        }
      }
    });
    changes.observe(document.body, {
      childList: true, subtree: true, attributes: true,
      attributeFilter: ['data-src','src']
    });
    document.addEventListener('jet-filter-content-rendered', rescan);
    document.addEventListener('jet-engine/listing-grid/after-load-more', rescan);
    // Visual movement of Swiper slides is handled by IntersectionObserver.
    window.addEventListener('resize', rescan, {passive:true});
    if (!watcher) {
      // Fallback without IntersectionObserver: browser loading=lazy alone
      // cannot resolve JetEngine/LiteSpeed data-src placeholders.
      const proximity = () => {
        document.querySelectorAll(match).forEach(img => {
          if (nearViewport(img)) activate(img);
        });
      };
      window.addEventListener('scroll', proximity, {passive:true});
      window.addEventListener('resize', proximity, {passive:true});
    }
  }
  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', initialize, {once:true});
  else initialize();

  window.SharedPresentationMediaVisibilityV1 = Object.freeze({
    version:'1.1.0',
    getMetrics:()=>({...metrics}),
    rescan
  });
})();
