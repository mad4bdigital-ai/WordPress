/* MAD4B tour sections visibility recovery v1.1 (native Elementor motion inheritance).
 * Normal Elementor animations are authoritative; only missed view callbacks recover.
 * Scoped to native tour section anchors (never galleries, quotes, filters or booking).
 */
(function (win, doc) {
  'use strict';
  if (!doc.body || !doc.body.classList.contains('single-tours-and-activities')) {
    // defer may execute before a late body only on malformed documents.
    if (doc.readyState === 'loading') {
      doc.addEventListener('DOMContentLoaded', start, { once: true });
    }
    return;
  }
  start();
  function start() {
    if (!doc.body || !doc.body.classList.contains('single-tours-and-activities')) return;
    var root = doc.querySelector('.mad4b-tour-content-sorted');
    if (!root || root.dataset.mad4bVisibilityRecovery === 'v1') return;
    root.dataset.mad4bVisibilityRecovery = 'v1';
    var margin = 300, graceMs = 850;
    var since = new WeakMap(), watched = new Set(), done = new WeakSet(), pending = new WeakSet();
    var scheduled = false, observer = null, stopped = false;
    var stats = { recovered: 0, natural: 0, reconciled: 0, checked: 0 };
    win.MAD4B_TOUR_VISIBILITY_RECOVERY = stats;
    function sections() {
      return Array.from(root.children).filter(function (e) {
        return e.classList.contains('mad4b-tour-section-anchor');
      });
    }
    function hidden(e) {
      return e.classList.contains('elementor-invisible') &&
        e.hasAttribute('data-settings') &&
        e.getAttribute('data-settings').indexOf('"animation"') !== -1 &&
        win.getComputedStyle(e).visibility === 'hidden';
    }
    function rect(e) { return e.getBoundingClientRect(); }
    function near(r) { return r.bottom > -margin && r.top < win.innerHeight + margin; }
    function actual(r) { return r.bottom > 0 && r.top < win.innerHeight; }
    function nativeMotion(e) {
      // Same responsive animation source and delay as Elementor GlobalHandler.
      var settings;
      try { settings = JSON.parse(e.getAttribute('data-settings') || '{}'); }
      catch (_) { return null; }
      var frontend = win.elementorFrontend;
      function setting(k) {
        if (frontend && typeof frontend.getCurrentDeviceSetting === 'function') {
          try { return frontend.getCurrentDeviceSetting(settings, k); } catch (_) {}
        }
        return settings[k];
      }
      var name = setting('animation') || setting('_animation');
      if (typeof name !== 'string' || !/^[A-Za-z][A-Za-z0-9_-]{0,79}$/.test(name)) return null;
      var rawDelay = settings._animation_delay || settings.animation_delay || 0;
      var delay = Number(rawDelay);
      if (!Number.isFinite(delay) || delay < 0 || delay > 30000) delay = 0;
      return { name: name, delay: delay };
    }
    function recover(e, reason) {
      if (!hidden(e) || pending.has(e)) return;
      var motion = nativeMotion(e);
      if (!motion) return; // Do not change unknown Elementor animation settings.
      pending.add(e);
      // Mirror Elementor's native .animate(), without CSS overrides.
      e.classList.remove(motion.name);
      if (done.has(e)) e.classList.remove('animated');
      win.setTimeout(function () {
        pending.delete(e);
        if (!hidden(e)) return; // Native IntersectionObserver won the race.
        e.classList.remove('elementor-invisible');
        if (motion.name !== 'none') e.classList.add('animated', motion.name);
        e.classList.add('mad4b-animation-inherited');
        e.dataset.mad4bAnimationRecovered = reason;
        done.add(e);
        since.delete(e);
        stats.recovered++;
        if (reason === 'order') stats.reconciled++;
        if (observer) observer.unobserve(e);
        schedule();
      }, motion.delay);
    }
    function finished() {
      var count = 0;
      sections().forEach(function (e) {
        if (e.classList.contains('elementor-invisible') && hidden(e)) count++;
      });
      return count === 0;
    }
    function stop() {
      if (stopped) return;
      stopped = true;
      if (observer) observer.disconnect();
      win.removeEventListener('scroll', schedule);
      win.removeEventListener('resize', schedule);
      win.removeEventListener('pageshow', schedule);
      win.removeEventListener('hashchange', schedule);
      doc.removeEventListener('visibilitychange', schedule);
      if (mo) mo.disconnect();
    }
    function sweep() {
      if (stopped || doc.hidden) return;
      stats.checked++;
      var all = sections();
      all.sort(function (a, b) {
        var x = parseInt(win.getComputedStyle(a).order, 10) || 0;
        var y = parseInt(win.getComputedStyle(b).order, 10) || 0;
        return x - y;
      });
      var laterVisible = false;
      for (var i = all.length - 1; i >= 0; i--) {
        var e = all[i], r = rect(e);
        if (!hidden(e)) {
          if (watched.has(e) && !done.has(e)) { stats.natural++; done.add(e); }
          if (actual(r) || r.bottom < 0) laterVisible = true;
          since.delete(e);
          continue;
        }
        if (done.has(e)) { recover(e, 'rehidden'); continue; }
        if (laterVisible && r.bottom <= 0) { recover(e, 'order'); continue; }
        if (!near(r) || !actual(r)) { since.delete(e); continue; }
        if (!since.has(e)) {
          since.set(e, win.performance.now());
          // One-shot safety check instead of perpetual polling.
          win.setTimeout(schedule, graceMs + 60);
        } else if (win.performance.now() - since.get(e) >= graceMs) {
          recover(e, 'missed-viewport');
        }
      }
      if (finished()) stop();
    }
    function schedule() {
      if (stopped || scheduled) return;
      scheduled = true;
      win.requestAnimationFrame(function () { scheduled = false; sweep(); });
    }
    var mo = null;
    if ('IntersectionObserver' in win) {
      observer = new IntersectionObserver(schedule, { rootMargin: '300px 0px 300px 0px', threshold: 0 });
      sections().forEach(function (e) { if (hidden(e)) { observer.observe(e); watched.add(e); } });
    }
    if ('MutationObserver' in win) {
      mo = new MutationObserver(function (records) {
        var added = false;
        records.forEach(function (m) { if (m.type === 'childList') added = true; });
        if (added && observer) sections().forEach(function (e) {
          if (hidden(e) && !watched.has(e)) { watched.add(e); observer.observe(e); }
        });
        schedule();
      });
      // Elementor class changes also trigger a quick reconciliation pass.
      mo.observe(root, { childList: true, subtree: false });
      sections().forEach(function (e) { mo.observe(e, { attributes: true, attributeFilter: ['class'] }); });
    }
    win.addEventListener('scroll', schedule, { passive: true });
    win.addEventListener('resize', schedule, { passive: true });
    win.addEventListener('pageshow', schedule);
    win.addEventListener('hashchange', schedule);
    doc.addEventListener('visibilitychange', schedule);
    schedule();
  }
})(window, document);
