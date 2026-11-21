/** MAD4B public price presentation — mirrors the *already published* Rate From label.
 * No pricing calculations, POSTs, booking changes, availability claims, or persistence.
 * The native server shortcode remains the authority when no rate is selected.
 */
(() => {
  'use strict';
  const targets = '.elementor-24784 .elementor-element-fbe7ba3 .etg-tour-price,'+
    '.elementor-24784 .elementor-element-482f13f .etg-tour-price';
  const originals = new WeakMap();
  const digit = v => v.replace(/[\u0660-\u0669\u06f0-\u06f9]/g, c => {
    const n = c.charCodeAt(0);
    return String(n >= 0x06f0 ? n - 0x06f0 : n - 0x0660);
  });
  const amountFromLabel = (label, currency) => {
    if (!/^[A-Z]{3}$/.test(currency)) return null;
    const m = label.match(/([0-9\u0660-\u0669\u06f0-\u06f9][0-9\u0660-\u0669\u06f0-\u06f9.,\s\u00a0]*)\s*([A-Z]{3})\s*$/i);
    if (!m || m[2].toUpperCase() !== currency) return null;
    const raw = digit(m[1]).replace(/[\s\u00a0]/g, '');
    if (!/^[0-9][0-9.,]*$/.test(raw)) return null;
    const decimal = /[.,][0-9]{2}$/.test(raw) ? raw.slice(-2) : null;
    const whole = (decimal === null ? raw : raw.slice(0, -3)).replace(/[.,]/g, '');
    if (!/^\d{1,9}$/.test(whole)) return null;
    const n = Number(whole) + (decimal === null ? 0 : Number(decimal) / 100);
    return Number.isFinite(n) && n > 0 && n <= 999999999 ? n : null;
  };
  const original = node => {
    if (!originals.has(node)) originals.set(node, {
      amount: node.querySelector('.etg-price-number')?.textContent ?? '',
      prefix: node.querySelector('.etg-price-prefix')?.textContent ?? '',
      currency: node.querySelector('.etg-price-code')?.textContent?.trim().toUpperCase() ?? '',
    });
    return originals.get(node);
  };
  const selectedRate = () => {
    const id = window.SharedPresentationSelectionV1?.getSnapshot?.()?.selectedRateId;
    if (!Number.isSafeInteger(id) || id < 1) return null;
    const choices = window.SharedPresentationFluentConfigV1?.rateChoices;
    if (!Array.isArray(choices) || !choices.some(v => Number(v?.id) === id)) return null;
    return id;
  };
  const publicLabelFor = id => {
    if (id === null) return '';
    const button = [...document.querySelectorAll('.mad4b-rate-header-select[data-rate-id]')]
      .find(v => Number(v.dataset.rateId) === id);
    return button?.closest('.jet-accordion__item')?.querySelector('.tk-tour-rate-label')?.textContent?.trim() || '';
  };
  // Grid Listing has no accordion summary. Read its already rendered
  // per-person published amounts only, never derive a financial quote.
  const publishedGridLabelFor = (id, currency) => {
    if (!id || !/^[A-Z]{3}$/.test(currency)) return '';
    const listing = document.querySelector('.elementor-48317 .jet-listing-dynamic-post-' + id + ' .elementor-46193');
    if (!listing || !/Per person in (?:Single|Double|Triple)/i.test(listing.textContent || '')) return '';
    const heads = [...listing.querySelectorAll('.elementor-heading-title')];
    const prices = [];
    for (let i = 0; i < heads.length - 1; i++) {
      const amountText = (heads[i].textContent || '').trim();
      const nextText = (heads[i+1].textContent || '').trim().toUpperCase();
      if (!/^[0-9]{1,3}(?:,[0-9]{3})*\.[0-9]{2}$/.test(amountText) || nextText !== currency) continue;
      const value = amountFromLabel(amountText + ' ' + currency, currency);
      if (value !== null) prices.push(value);
    }
    return prices.length ? Math.min(...prices).toFixed(2) + ' ' + currency : '';
  };
  const paint = () => {
    if (!document.body?.classList.contains('single-tours-and-activities')) return;
    const id = selectedRate();
    const label = publicLabelFor(id);
    document.querySelectorAll(targets).forEach(node => {
      const backup = original(node);
      const numberNode = node.querySelector('.etg-price-number');
      const prefix = node.querySelector('.etg-price-prefix');
      if (!numberNode || !prefix) return;
      const amount = id === null ? null : amountFromLabel(label || publishedGridLabelFor(id, backup.currency), backup.currency);
      if (amount === null) {
        numberNode.textContent = backup.amount;
        prefix.textContent = backup.prefix;
        node.removeAttribute('data-mad4b-selected-display-rate');
        return;
      }
      try {
        numberNode.textContent = new Intl.NumberFormat(document.documentElement.lang || 'en', {
          minimumFractionDigits: 2, maximumFractionDigits: 2
        }).format(amount);
      } catch (_) { return; }
      // Keep the native "From"/"Upcoming from" label, source and eligibility untouched.
      prefix.textContent = backup.prefix;
      node.setAttribute('data-mad4b-selected-display-rate', String(id));
    });
  };
  document.addEventListener('shared-presentation-selection-changed', paint);
  document.addEventListener('shared-presentation-rehydration-requested', paint);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', paint, {once:true});
  else paint();
})();
