/* MAD4B native Elementor first-paint frame cache, v1.0.0.
 * Cache only within one animation frame; Elementor remains the device authority.
 * The existing Shared First-Paint owner embeds this asset inline, feature-off
 * by default and bound to each tenant's audited SHA-256 admission.
 */
(function () {
  'use strict';
  if (window.__mad4bElementorDeviceModeFrameCacheV1) return;
  window.__mad4bElementorDeviceModeFrameCacheV1 = { installed: false, version: '1.0.0' };
  window.addEventListener('elementor/frontend/init', function () {
    var api = window.elementorFrontend;
    if (!api || typeof api.getCurrentDeviceMode !== 'function' ||
        (typeof api.isEditMode === 'function' && api.isEditMode()) ||
        api.__mad4bOriginalDeviceModeMethod) return;
    var original = api.getCurrentDeviceMode;
    var valid = false, cached = '', width = 0, height = 0, pixelRatio = 0, frame = 0;
    var invalidate = function () {
      valid = false;
      if (frame) { window.cancelAnimationFrame(frame); frame = 0; }
    };
    window.addEventListener('resize', invalidate, { passive: true });
    window.addEventListener('orientationchange', invalidate, { passive: true });
    window.addEventListener('pageshow', invalidate, { passive: true });
    window.addEventListener('pagehide', invalidate, { passive: true });
    document.addEventListener('visibilitychange', invalidate);
    api.getCurrentDeviceMode = function () {
      var w = window.innerWidth, h = window.innerHeight, ratio = window.devicePixelRatio;
      if (valid && document.visibilityState === 'visible' &&
          w === width && h === height && ratio === pixelRatio) return cached;
      // All truth and breakpoint changes originate from Elementor's native API.
      var mode = original.apply(this, arguments);
      if (typeof mode !== 'string' || !mode) { invalidate(); return mode; }
      cached = mode; width = w; height = h; pixelRatio = ratio;
      valid = document.visibilityState === 'visible';
      if (valid && !frame) frame = window.requestAnimationFrame(function () {
        valid = false; frame = 0;
      });
      return mode;
    };
    Object.defineProperty(api, '__mad4bOriginalDeviceModeMethod', { value: original, configurable: true });
    window.__mad4bElementorDeviceModeFrameCacheV1.installed = true;
  }, { once: true });
})();
