/* Restore JetEngine's signed listing settings before its Elementor handlers run.
   No signatures are generated or verified on the client. */
(() => {
  const prepare = () => {
    document.querySelectorAll('header.elementor-location-header .elementor-widget-jet-listing-grid .jet-listing-grid--lazy-load[data-lazy-load]').forEach(grid => {
      const widget = grid.closest('.elementor-widget-jet-listing-grid');
      if (!widget || !widget.classList.contains('navigation-editor-grid')) return;
      let options;
      try { options = JSON.parse(grid.getAttribute('data-lazy-load') || '{}'); }
      catch (_) { return; }
      const settings = options.signed_widget_settings;
      if (!settings || typeof settings !== 'object' || !settings._jet_engine_settings_signature ||
          settings._jet_engine_settings_signature !== options.settings_signature) return;
      widget.setAttribute('data-widget-settings', JSON.stringify(settings));
      if (window.jQuery) window.jQuery(widget).data('widget-settings', settings);
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', prepare, { once: true });
  else prepare();
})();
