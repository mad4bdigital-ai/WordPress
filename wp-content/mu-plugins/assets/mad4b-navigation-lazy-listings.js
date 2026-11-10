/* MAD4B Navigation: signed JetEngine lazy listings, activated only by native Elementor branch opening.
 * Does not synthesize signatures, create menu markup or change JetEngine Query Builder.
 */
(() => {
  'use strict';
  const SELECTOR = '.elementor-widget-jet-listing-grid.navigation-editor-grid';
  const waiting = new Map();
  const loaded = new WeakSet();
  const pending = new WeakSet();
  const MAX_ACTIVE = 2;
  let active = 0;
  let installed = false;
  let nativeTabsPatched = false;
  let nativeTabsPatching = false;
  let attempts = 0;

  function prepare(root = document) {
    root.querySelectorAll(`${SELECTOR} .jet-listing-grid--lazy-load[data-lazy-load]`).forEach(grid => {
      const widget = grid.closest(SELECTOR);
      if (!widget || !grid.closest('header.elementor-location-header')) return;
      let options;
      try { options = JSON.parse(grid.getAttribute('data-lazy-load') || '{}'); } catch (_) { return; }
      const settings = options.signed_widget_settings;
      if (!settings || typeof settings !== 'object' || !settings._jet_engine_settings_signature ||
        settings._jet_engine_settings_signature !== options.settings_signature) return;
      widget.setAttribute('data-widget-settings', JSON.stringify(settings));
      if (window.jQuery) window.jQuery(widget).data('widget-settings', settings);
    });
  }

  function isBranchWidget(widget) {
    return !!(widget && widget.matches && widget.matches(SELECTOR) &&
      widget.closest('header.elementor-location-header .native-destination-deep-tabs'));
  }

  function branchOwner(widget) {
    const tabs = widget.closest('.native-destination-deep-tabs');
    if (!tabs) return null;
    let node = widget;
    while (node && node !== tabs) {
      if (node.parentElement && node.parentElement.classList.contains('e-n-tabs-content'))
        return { tabs, panel: node };
      node = node.parentElement;
    }
    return { tabs, panel: null };
  }

  function activePanel(tabs) {
    const content = tabs.querySelector('.e-n-tabs-content');
    const heading = tabs.querySelector('.e-n-tabs-heading');
    if (!content || !heading) return null;
    const buttons = [...heading.querySelectorAll('[role="tab"],.e-n-tab-title')];
    const selected = buttons.find(b => b.getAttribute('aria-selected') === 'true' ||
      b.getAttribute('aria-expanded') === 'true' || b.classList.contains('e-active'));
    if (!selected) return null;
    const id = selected.getAttribute('aria-controls');
    if (id) {
      const direct = [...content.children].find(p => p.id === id);
      if (direct) return direct;
    }
    const index = buttons.indexOf(selected);
    return index >= 0 ? content.children[index] || null : null;
  }

  function visibleBranch(widget) {
    const owner = branchOwner(widget);
    if (!owner || !owner.panel || activePanel(owner.tabs) !== owner.panel) return false;
    // Every outer Nested Tabs level must also be selected.
    let outer = owner.tabs.closest('.native-destination-deep-tabs .e-n-tabs-content');
    while (outer) {
      const parentTabs = outer.closest('.native-destination-deep-tabs');
      if (!parentTabs) break;
      let panel = owner.tabs;
      while (panel && panel.parentElement !== outer) panel = panel.parentElement;
      if (!panel || activePanel(parentTabs) !== panel) return false;
      outer = parentTabs.parentElement?.closest('.native-destination-deep-tabs .e-n-tabs-content');
    }
    return widget.getClientRects().length > 0;
  }

  function setRetry(widget, retry) {
    const grid = widget.querySelector('.jet-listing-grid--lazy-load');
    if (!grid || grid.querySelector('.mad4b-navigation-retry')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'mad4b-navigation-retry';
    button.textContent = 'Retry loading';
    button.addEventListener('click', () => { button.remove(); retry(); });
    grid.appendChild(button);
  }

  function kick(widget) {
    if (loaded.has(widget) || pending.has(widget) || !visibleBranch(widget)) return;
    const item = waiting.get(widget);
    if (!item || active >= MAX_ACTIVE) return;
    waiting.delete(widget);
    pending.add(widget);
    active++;
    let finished = false;
    const done = ok => {
      if (finished) return;
      finished = true;
      pending.delete(widget);
      active = Math.max(0, active - 1);
      if (ok) loaded.add(widget);
      else {
        waiting.set(widget, item);
        setRetry(widget, () => kick(widget));
      }
      drain();
    };
    // JetEngine manages the actual signed AJAX, Elementor reinitialization and its DOM.
    // Observe only the canonical completion class; never replace the grid HTML.
    const watch = new MutationObserver(() => {
      if (!widget.isConnected) { watch.disconnect(); done(false); return; }
      if (widget.querySelector('.jet-listing-grid--lazy-load-completed')) {
        watch.disconnect(); done(true);
      }
    });
    watch.observe(widget, {subtree:true, attributes:true, attributeFilter:['class'], childList:true});
    try { item.original.call(item.owner, item.args); }
    catch (_) { watch.disconnect(); done(false); return; }
    window.setTimeout(() => {
      if (finished) return;
      watch.disconnect();
      if (widget.querySelector('.jet-listing-grid--lazy-load-completed')) done(true);
      else done(false);
    }, 18000);
  }

  function drain() {
    if (active >= MAX_ACTIVE) return;
    for (const widget of waiting.keys()) {
      if (active >= MAX_ACTIVE) break;
      if (!widget.isConnected) { waiting.delete(widget); continue; }
      kick(widget);
    }
  }

  // Elementor's own onInit() invokes activateDefaultTab() on every
  // reinitialization, wiping a visitor-selected branch after JetEngine AJAX.
  // Preserve the native widget's initialized state; no simulated clicks and
  // no replacement rendering. Only header destination Nested Tabs are scoped.
  function preserveNativeTabs() {
    if (nativeTabsPatched || nativeTabsPatching) return;
    const registry = window.elementorFrontend?.elementsHandler;
    if (!registry?.elementsHandlers?.['nested-tabs.default']) return;
    nativeTabsPatching = true;
    Promise.resolve(registry.getHandler('nested-tabs.default')).then(Handler => {
      const proto = Handler?.prototype;
      if (!proto || typeof proto.activateDefaultTab !== 'function') return;
      if (!proto.__mad4bNavigationPreserveSelection) {
        const original = proto.activateDefaultTab;
        proto.activateDefaultTab = function(...args) {
          const node = this.$element && this.$element[0];
          if (node && node.classList.contains('native-destination-deep-tabs') &&
              node.closest('header.elementor-location-header') &&
              this.elements?.$widgetContainer?.hasClass('e-activated') &&
              this.elements?.$tabTitles?.filter('[aria-selected="true"]').length) {
            return; // Already initialized by Elementor: retain visitor selection.
          }
          return original.apply(this, args);
        };
        proto.__mad4bNavigationPreserveSelection = true;
      }
      nativeTabsPatched = true;
    }).catch(() => {}).finally(() => { nativeTabsPatching = false; });
  }

  function install() {
    preserveNativeTabs();
    const engine = window.JetEngine;
    if (!engine || typeof engine.lazyLoadListing !== 'function') return false;
    if (engine.lazyLoadListing.__mad4bNavigationOnDemand) return true;
    const original = engine.lazyLoadListing;
    engine.lazyLoadListing = function(args) {
      const container = args && args.container && args.container[0];
      const widget = container && container.closest && container.closest(SELECTOR);
      if (!isBranchWidget(widget)) return original.apply(this, arguments);
      if (!waiting.has(widget) && !loaded.has(widget))
        waiting.set(widget, { original, owner: this, args });
      // Do NOT let JetEngine's zero-offset IntersectionObserver see hidden tabs.
      // Its original method is invoked precisely when the selected tab opens.
      drain();
    };
    engine.lazyLoadListing.__mad4bNavigationOnDemand = true;
    installed = true;
    return true;
  }

  function tabEvent(event) {
    const tab = event.target.closest && event.target.closest('[role="tab"],.e-n-tab-title');
    if (!tab || !tab.closest('header.elementor-location-header .native-destination-deep-tabs')) return;
    // Elementor updates aria-selected and active panels in the same event tick.
    window.setTimeout(drain, 0);
  }
  document.addEventListener('click', tabEvent, true);
  document.addEventListener('keyup', event => {
    if (event.key === 'Enter' || event.key === ' ') tabEvent(event);
  }, true);
  document.addEventListener('pointerover', event => {
    const menu = event.target.closest && event.target.closest('header.elementor-location-header .e-n-menu');
    if (menu) drain();
  }, { passive: true });

  // Install before Elementor calls widgetListingGrid. DOMContentLoaded alone
  // is too late on pages where JetEngine initializes within the same tick.
  const early = window.setInterval(() => {
    if (install() || attempts++ >= 250) window.clearInterval(early);
  }, 10);
  const tabsPatchPoll = window.setInterval(() => {
    preserveNativeTabs();
    if (nativeTabsPatched || ++attempts > 400) window.clearInterval(tabsPatchPoll);
  }, 20);
  if (window.jQuery) window.jQuery(window).on('elementor/frontend/init', () => { prepare(); install(); preserveNativeTabs(); });
  const ready = () => {
    prepare();
    if (!install() && attempts++ < 80) window.setTimeout(ready, 100);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, { once: true });
  else ready();
  window.MAD4BNavigationLazyDiagnostics = () => ({ installed, nativeTabsPatched, active, queued: waiting.size });
})();
