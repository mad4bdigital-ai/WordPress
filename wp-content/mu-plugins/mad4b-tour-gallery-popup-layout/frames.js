/**
 * MAD4B Tour gallery: native Elementor image-frame activation.
 * The thumbnail preview uses Elementor background-image containers, not IMG
 * links. Delegate activation to the already working Elementor popup button;
 * no second gallery, modal implementation, or hard-coded popup/template ID.
 */
(function () {
  'use strict';

  var BUTTON_ID = 'single_tour_gellery_button';
  var ACTIVE_CLASS = 'mad4b-gallery-frame-opener';

  function isInteractiveTarget(element, frame) {
    if (!element || !element.closest) return false;
    var child = element.closest('a, button, input, select, textarea, summary, [role="button"], [role="link"]');
    return !!child && child !== frame && frame.contains(child);
  }

  function nativeButton() {
    var button = document.getElementById(BUTTON_ID);
    if (!button || button.tagName !== 'A') return null;
    var href = button.getAttribute('href') || '';
    if (href.indexOf('#elementor-action') !== 0 ||
        href.indexOf('popup') === -1) return null;
    return button;
  }

  function openNativePopup(event, frame) {
    if (event.defaultPrevented || (event.type === 'keydown' &&
      event.key !== 'Enter' && event.key !== ' ')) return;
    if (event.target !== frame && isInteractiveTarget(event.target, frame)) return;
    var button = nativeButton();
    if (!button || !frame.isConnected) return;
    if (event.type === 'keydown') event.preventDefault();
    button.click();
  }

  function init() {
    var button = nativeButton();
    if (!button) return;
    var grid = button.closest('.e-grid');
    if (!grid || !grid.contains(button)) return;
    function photoFrame(frame) {
      if (!frame || !frame.classList || !frame.classList.contains('e-con')) return false;
      var background = window.getComputedStyle(frame).backgroundImage;
      // Never turn ordinary wrappers into controls. Only an actual background
      // image is a photo frame (Elementor may resolve it after page render).
      return !!(background && background !== 'none' && background.indexOf('url(') !== -1);
    }
    // Elementor has two visual layers: a standalone portrait container and
    // a nested four-image grid. Discover the portrait as a sibling of the
    // grid wrapper, without hardcoding widget IDs or binding unrelated images.
    var gallery = grid.parentElement && grid.parentElement.parentElement;
    var portrait = gallery && Array.prototype.slice.call(gallery.children)
      .filter(function (child) { return child !== grid.parentElement && photoFrame(child); });
    // Preserve the visual order: standalone portrait first, then grid cells.
    var candidates = (portrait || []).concat(Array.prototype.slice.call(grid.children));
    var frames = candidates.filter(photoFrame);
    if (frames.length < 2) return;
    var label = (button.innerText || button.textContent || '').trim() || 'View all photos';
    frames.forEach(function (frame, index) {
      if (frame.dataset.mad4bGalleryFrameOpen === '1') return;
      frame.dataset.mad4bGalleryFrameOpen = '1';
      frame.classList.add(ACTIVE_CLASS);
      // The final preview frame already contains a focusable All Photos
      // anchor. Do not nest a second ARIA button around that real link.
      if (!frame.contains(button)) {
        frame.setAttribute('role', 'button');
        frame.setAttribute('tabindex', '0');
        frame.setAttribute('aria-haspopup', 'dialog');
        frame.setAttribute('aria-label', label + ' (' + (index + 1) + ')');
      }
      frame.addEventListener('click', function (event) {
        openNativePopup(event, frame);
      });
      frame.addEventListener('keydown', function (event) {
        openNativePopup(event, frame);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once: true});
  } else {
    init();
  }
  // Elementor may render a dynamic gallery after document ready.
  if (window.jQuery) window.jQuery(window).on('elementor/frontend/init', init);
})();
