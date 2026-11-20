/**
 * Unite the existing Accommodation and Transportation panels under the
 * original MAD4B JetEngine sticky pricing navigation.
 *
 * Presentation only. No form/input clone, AJAX rebinding, native save
 * override, transport pricing mutation, or public CPT route change.
 */
(() => {
  'use strict';
  const root = document.querySelector('.jet-engine-options-page-wrap');
  if (!root || !window.MAD4BPricingWorkspaceUnified) return;

  const definitions = [
    {
      id: 'accommodation',
      selector: '.mad4b-stay-panel',
      label: 'Accommodation & Units',
      symbol: '⌂',
      note: 'Select a Property or Unit, inspect source pricing and readiness. This panel uses its own protected previews.',
      count: 'Stay'
    },
    {
      id: 'transportation',
      selector: '#mad4b-transport-self-controls',
      label: 'Transportation',
      symbol: '⇆',
      note: 'Choose a transfer, review source pricing, and confirm route / Tour Component readiness. Transport policy has its own Save action.',
      count: 'Self'
    }
  ];
  const mounted = new Map();
  let nav, content, guide, heading, hint, observer;
  let originalGuide = '';
  const $ = selector => root.querySelector(selector);
  const elem = (name, cls, text) => {
    const node = document.createElement(name);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = String(text);
    return node;
  };
  const isCore = node => node?.matches?.('.mad4b-ws-nav-btn:not([data-pricing-external])');
  const nativeButtons = () => [...nav.querySelectorAll('.mad4b-ws-nav-btn:not([data-pricing-external])')];

  function focusContent(){
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches)
      content.scrollIntoView({block: 'start', behavior: 'auto'});
    else content.scrollIntoView({block: 'start', behavior: 'smooth'});
  }

  function resetPane() {
    const active = root.dataset.mad4bPricingPane;
    if (!active) return;
    delete root.dataset.mad4bPricingPane;
    for (const entry of mounted.values()){
      entry.host.hidden = true;
      entry.button.classList.remove('is-active');
      entry.button.setAttribute('aria-current', 'false');
    }
    if (guide) guide.textContent = originalGuide;
  }

  function selectPane(id) {
    const selected = mounted.get(id);
    if (!selected || !root.classList.contains('mad4b-ws-visual')) return;
    root.dataset.mad4bPricingPane = id;
    for (const original of nativeButtons()){
      original.classList.remove('is-active');
      original.setAttribute('aria-current', 'false');
    }
    for (const [key, value] of mounted){
      const active = key === id;
      value.host.hidden = !active;
      value.button.classList.toggle('is-active', active);
      value.button.setAttribute('aria-current', active ? 'page' : 'false');
    }
    heading.textContent = selected.definition.label;
    hint.textContent = selected.definition.note;
    guide.textContent = id === 'transportation'
      ? 'Transport policy saves separately inside the Transportation panel. Native JetEngine Save affects only core settings.'
      : 'Accommodation source previews are read-only. Edit native Property, Unit or Rate records to persist their data.';
    // A previous user may have collapsed the host's introductory details;
    // displaying the module must not show an apparently empty section.
    const collapsible = selected.panel.querySelector(':scope > details');
    if (collapsible) collapsible.open = true;
    focusContent();
  }

  function attachNativeRecovery() {
    // Capture-phase cleanup happens before JetEngine's own native navigation
    // render callback. That callback keeps ownership of all original fields.
    nav.addEventListener('click', event => {
      const button = event.target.closest('.mad4b-ws-nav-btn');
      if (button && isCore(button)) resetPane();
    }, true);

    const search = $('.mad4b-ws-search');
    search?.addEventListener('input', () => resetPane(), true);

    const allModes = [...root.querySelectorAll('.mad4b-ws-actions button')];
    const nativeMode = allModes.find(x => x.textContent.trim() === 'All JetEngine fields');
    const visualMode = allModes.find(x => x.textContent.trim() === 'Grouped JetEngine view');
    nativeMode?.addEventListener('click', resetPane);
    visualMode?.addEventListener('click', () => {
      resetPane();
      const first = nativeButtons()[0];
      if (first) first.click();
    });
  }

  function attach(definition, panel) {
    if (mounted.has(definition.id)) return;
    const button = elem('button', 'mad4b-ws-nav-btn mad4b-ws-service-nav');
    button.type = 'button';
    button.dataset.pricingExternal = definition.id;
    button.setAttribute('aria-controls', 'mad4b-ws-external-' + definition.id);
    button.setAttribute('aria-current', 'false');

    const title = elem('span');
    title.append(elem('span', 'mad4b-ws-nav-icon', definition.symbol),
      document.createTextNode(definition.label));
    button.append(title, elem('small', '', definition.count));
    button.addEventListener('click', () => selectPane(definition.id));

    const host = elem('section','mad4b-ws-external-section');
    host.id = 'mad4b-ws-external-' + definition.id;
    host.dataset.pricingModule = definition.id;
    host.setAttribute('aria-label', definition.label);
    host.hidden = true;
    // Move the actual single panel node, not its markup or its form values.
    // Existing event listeners, REST/AJAX requests, and save actions survive.
    host.append(panel);
    content.append(host);
    nav.append(button);
    mounted.set(definition.id, {button, host, panel, definition});
  }

  function reconcile() {
    if (!nav || !content) {
      nav = $('.mad4b-ws-nav');
      content = $('.mad4b-ws-content');
      guide = $('.mad4b-ws-tip');
      heading = $('.mad4b-ws-section-heading h3');
      hint = $('.mad4b-ws-section-heading p');
      if (!nav || !content || !guide || !heading || !hint ||
        !root.classList.contains('mad4b-ws-visual')) return;
      originalGuide = guide.textContent;
      root.classList.add('mad4b-pricing-composed');
      attachNativeRecovery();
    }
    for (const definition of definitions) {
      const panel = $(definition.selector);
      if (panel && !mounted.has(definition.id)) attach(definition, panel);
    }
    if (mounted.size === definitions.length) observer?.disconnect();
  }

  // WooCommerce/JetEngine and the two source panels can initialize in any
  // footer-script order; observe only until their original DOM nodes exist.
  observer = new MutationObserver(reconcile);
  observer.observe(root,{childList:true,subtree:true});
  reconcile();
  document.addEventListener('DOMContentLoaded', reconcile, {once:true});
  window.setTimeout(() => observer?.disconnect(), 15000);
})();
