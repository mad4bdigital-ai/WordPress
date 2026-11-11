/**
 * Position the existing Package Level <details> panel in the browser's
 * top layer while keeping the original Swiper viewport clipped.
 *
 * Position from the trigger CENTER, constrained by the visible intersection
 * of the matching Rate card, its Slider, and the viewport. Falls back to
 * original inline <details> when the Popover API is unavailable.
 */
(() => {
  'use strict';
  const DETAILS = 'details.tour-rate-term-details';
  const PANEL = '.tour-rate-term-details__content';
  const GAP = 8;
  const MAX_WIDTH = 285;
  let scheduled = false;

  const openPanels = () => Array.from(document.querySelectorAll(DETAILS))
    .filter(details => details.open)
    .map(details => ({ details, panel: details.querySelector(PANEL) }))
    .filter(({ panel }) => panel && panel.matches(':popover-open'));

  function relocate(details, panel) {
    if (!details.isConnected || !panel.isConnected) return false;

    const trigger = details.querySelector('summary');
    const card = details.closest('.jet-listing-grid__item');
    const slider = details.closest('.jet-listing-grid__slider');
    if (!trigger || !card) return false;

    const anchor = trigger.getBoundingClientRect();
    const cardRect = card.getBoundingClientRect();
    const sliderRect = slider?.getBoundingClientRect();
    const viewportW = document.documentElement.clientWidth;
    const viewportH = document.documentElement.clientHeight;

    // A clipping Slider stays clipped. The top-layer panel uses this rect
    // only as a POSITIONING bound, never as a reason to change overflow.
    const leftBound = Math.max(GAP, cardRect.left + GAP, sliderRect ? sliderRect.left + GAP : GAP);
    const rightBound = Math.min(viewportW - GAP, cardRect.right - GAP,
      sliderRect ? sliderRect.right - GAP : viewportW - GAP);
    const available = rightBound - leftBound;

    // Hide a panel whose trigger was swiped outside the visible Slider.
    if (available < 100 || anchor.right <= leftBound - GAP ||
        anchor.left >= rightBound + GAP || anchor.bottom < 0 ||
        anchor.top > viewportH) return false;

    const width = Math.max(100, Math.min(MAX_WIDTH, available));
    // Preferred alignment = trigger CENTER; clamp inside the visible card.
    const anchorCenter = anchor.left + anchor.width / 2;
    const x = Math.max(leftBound, Math.min(anchorCenter - width / 2, rightBound - width));

    panel.style.width = width + 'px';
    panel.style.maxWidth = width + 'px';
    panel.style.left = Math.round(x) + 'px';
    const maxPanelHeight = Math.max(110, viewportH - GAP * 2);
    panel.style.maxHeight = maxPanelHeight + 'px';

    // Prefer below the trigger; flip above if the viewport bottom is near.
    const panelHeight = Math.min(panel.getBoundingClientRect().height, maxPanelHeight);
    const below = viewportH - GAP - (anchor.bottom + GAP);
    const above = anchor.top - GAP * 2;
    const preferredTop = below >= panelHeight || below >= above
      ? anchor.bottom + GAP
      : anchor.top - panelHeight - GAP;
    const y = Math.max(GAP, Math.min(preferredTop, viewportH - GAP - panelHeight));
    panel.style.top = Math.round(y) + 'px';
    return true;
  }

  function close(details, panel) {
    if (panel?.matches(':popover-open')) panel.hidePopover();
    if (details.open) details.open = false;
  }

  function positionOpenPanels() {
    scheduled = false;
    for (const { details, panel } of openPanels()) {
      if (!relocate(details, panel)) close(details, panel);
    }
  }

  function schedulePosition() {
    if (!scheduled) {
      scheduled = true;
      requestAnimationFrame(positionOpenPanels);
    }
  }

  // toggle does not bubble, therefore use event capture to work with
  // JetSmartFilters AJAX-inserted cards without per-card listeners.
  document.addEventListener('toggle', event => {
    const element = event.target;
    if (element.matches?.(DETAILS)) {
      const panel = element.querySelector(PANEL);
      if (!panel || typeof panel.showPopover !== 'function') return;
      if (!element.open) {
        if (panel.matches(':popover-open')) panel.hidePopover();
        return;
      }
      panel.setAttribute('popover', 'auto');
      panel.style.visibility = 'hidden';
      try {
        panel.showPopover();
        if (!relocate(element, panel)) {
          close(element, panel);
        }
      } catch (_) {
        // Progressive-enhancement fallback to the original <details>.
        panel.removeAttribute('popover');
      } finally {
        panel.style.visibility = '';
      }
    } else if (element.matches?.(PANEL) && element.hasAttribute('popover')) {
      // Native light dismiss/Escape closes the popover, so keep the summary
      // aria/native <details> state in sync.
      const details = element.closest(DETAILS);
      if (!element.matches(':popover-open') && details?.open) details.open = false;
    }
  }, true);

  window.addEventListener('resize', schedulePosition, { passive: true });
  window.addEventListener('scroll', schedulePosition, { passive: true, capture: true });
  // Swiper may animate slides without any scroll/resize event.
  document.addEventListener('transitionend', event => {
    if (event.target?.closest?.('.jet-listing-grid__slider')) schedulePosition();
  }, true);
  document.addEventListener('touchmove', event => {
    if (event.target?.closest?.('.jet-listing-grid__slider')) schedulePosition();
  }, { passive: true, capture: true });
})();
