/* MAD4B Tour Form 6 — two explicit submission journeys.
 * The server decides whether an advance checkout is available.
 * The browser ONLY changes text and mirrors no payment amount.
 */
(() => {
  'use strict';
  const cfg = window.MAD4BTourForm6ModeV1;
  if (!cfg || !Number.isInteger(Number(cfg.tourId)) || !cfg.endpoint) return;
  const defaults = {
    quote_button:'Request a quote',
    quote_note:'You won’t be charged when you submit this request. We’ll confirm availability, the final price and any required deposit before you decide to proceed. This is not a confirmed booking.',
    advance_button:'Send request — advance option follows',
    advance_note:'Your enquiry is saved first. A secure payment option follows in the thank-you message if the server validates the estimate. Payment is optional at submission.'
  };
  // Start with the language of the actual rendered Tour. The readiness call
  // may be delayed or fail; never flash a default-language CTA on ES/AR/etc.
  const initialCopy = cfg.labels && typeof cfg.labels === 'object' ? cfg.labels : {};
  const fallbackLabels = { ...defaults, ...initialCopy };
  let serverMode = 'quote_only';
  let labels = { ...fallbackLabels };
  const connectedForms = new WeakSet();
  const attached = new Set();
  function selectionEligible(form) {
    if (serverMode !== 'advance_checkout') return false;
    const rate = form.querySelector('[name="mad4b_route_rate_id"]')?.value || '';
    const occupancy = form.querySelector('[name="mad4b_route_occupancy"]')?.value || '';
    const stored = form.querySelector('[name="shared_tour_selection_v2"]')?.value || '';
    let intent;
    try { intent = JSON.parse(stored); } catch (_) { return false; }
    if (!intent || Number(intent.tour_id)!==Number(cfg.tourId))return false;
    const adults = Number(form.querySelector('[name="no_of_adults"]')?.value || 0);
    const isRate = intent.source==='rate' &&
      /^[1-9][0-9]*$/.test(rate) &&
      Number(intent.rate_id)===Number(rate) &&
      ['single','double','triple'].includes(occupancy) &&
      intent.occupancy===occupancy;
    const isDirect = intent.source==='direct' && (rate===''||rate==='0') &&
      (intent.rate_id===null||intent.rate_id===0) &&
      (occupancy===''||occupancy==='single') &&
      ['single','double','triple'].includes(intent.occupancy);
    // This UI changes the SUBMISSION LABEL only, never authorizes payment.
    // Require a fresh server-returned price and reject unpriced components.
    const serverPriced=!!form.querySelector(
      '.shared-fluent-server-quote .shared-price-amount') &&
      form.querySelector('.shared-fluent-server-quote')
        ?.dataset.priceScope==='nonbinding_tour_estimate';
    if (form.dataset.mad4bDatePending==='1'||
        form.dataset.mad4bDateDraftInvalid==='1'||!serverPriced)
      return false;
    return (isRate||isDirect) &&
      typeof intent.travel_date==='string' && /^\d{4}-\d{2}-\d{2}$/.test(intent.travel_date) &&
      Array.isArray(intent.extras)&&intent.extras.length===0 &&
      Array.isArray(intent.stay_stages)&&intent.stay_stages.length===0 &&
      Number(intent.adults)===adults && adults>0 &&
      Number(intent.children)===0 && Number(intent.infants)===0 &&
      Number(form.querySelector('[name="no_of_children"]')?.value || 0)===0 &&
      Number(form.querySelector('[name="no_of_infants"]')?.value || 0)===0;
  }
  function changeButtonText(button, value) {
    // Preserve any Fluent Forms spinner/icons introduced after initial render.
    const firstText = [...button.childNodes].find(n => n.nodeType === Node.TEXT_NODE && n.textContent.trim());
    if (firstText) firstText.textContent = value;
    else if (!button.childElementCount) button.textContent = value;
    else {
      let title = button.querySelector('.mad4b-form6-mode-button-label');
      if (!title) {
        title = document.createElement('span');
        title.className = 'mad4b-form6-mode-button-label';
        button.prepend(title);
      }
      title.textContent = value;
    }
    button.setAttribute('aria-label', value);
  }
  function refresh(form) {
    if (!form.isConnected) { attached.delete(form); return; }
    const button = form.querySelector('button[type="submit"],input[type="submit"]');
    if (!button) return;
    // The customer may REQUEST an advance even when Extras, stay stages or
    // minor pricing require Reservations review. Only an eligible signed,
    // server-verified reference can CREATE the Woo payment link after save.
    const paymentReady = selectionEligible(form);
    const serverPriced = !!form.querySelector('.shared-fluent-server-quote .shared-price-amount') &&
      form.querySelector('.shared-fluent-server-quote')?.dataset.priceScope === 'nonbinding_tour_estimate';
    const advance = serverMode === 'advance_checkout' && serverPriced &&
      form.dataset.mad4bDatePending !== '1' &&
      form.dataset.mad4bDateDraftInvalid !== '1';
    const mode = advance ? 'advance_checkout' : 'quote_only';
    form.dataset.mad4bSubmissionMode = mode;
    const label = advance ? labels.advance_button : labels.quote_button;
    const note = advance ? (paymentReady ? labels.advance_note : (labels.advance_pending_note || labels.advance_note)) : labels.quote_note;
    if (button.dataset.mad4bLastModeLabel !== label) {
      if (button.tagName.toLowerCase()==='input') button.value=label;
      else changeButtonText(button,label);
      button.dataset.mad4bLastModeLabel = label;
    }
    const explanation=form.querySelector('.mad4b-form6-mode-explanation');
    if (explanation && explanation.textContent !== note) explanation.textContent = note;
    // The quote card already carries its own estimate caption.
    // One submission notice only: below the button, not twice in the form.
    const priceNote=form.querySelector('.shared-fluent-server-quote .shared-price-note');
    if (priceNote) priceNote.remove();
  }
  function attach(form) {
    if (connectedForms.has(form)) return;
    const submit=form.querySelector('button[type="submit"],input[type="submit"]');
    if (!submit) return;
    connectedForms.add(form);
    attached.add(form);
    const hint=document.createElement('p');
    hint.className='mad4b-form6-mode-explanation';
    hint.setAttribute('aria-live','polite');
    const wrapper=submit.closest('.ff_submit_btn_wrapper');
    if (wrapper) wrapper.insertAdjacentElement('afterend',hint);
    else form.append(hint);
    let timer;
    const schedule=()=>{
      clearTimeout(timer);
      timer=setTimeout(()=>refresh(form),370);
    };
    form.addEventListener('change',schedule);
    form.addEventListener('input',schedule);
    form.addEventListener('submit',()=>refresh(form),true);
    refresh(form);
  }
  function mount() {
    document.querySelectorAll('form.fluent_form_6').forEach(form => {
      if (form.closest('.elementor-sticky__spacer')) return;
      attach(form);
    });
  }
  async function updateReadiness() {
    const u=new URL(cfg.endpoint);
    u.searchParams.set('tour_id',String(cfg.tourId));
    u.searchParams.set('lang',String(cfg.lang||'en'));
    try {
      const response=await fetch(u,{
        credentials:'same-origin',method:'GET',cache:'no-store',
        headers:{Accept:'application/json'}
      });
      if(!response.ok)throw new Error('readiness_unavailable');
      const data=await response.json();
      if (!data || !['quote_only','advance_checkout'].includes(data.mode) ||
          data.booking_confirmed!==false || typeof data.labels!=='object')
        throw new Error('invalid_readiness_contract');
      serverMode=data.mode;
      labels={...defaults,...data.labels};
    }catch(_){
      // Fail closed: never advertise checkout without a current server proof.
      serverMode='quote_only';
      labels={...fallbackLabels};
    }
    for(const form of attached)refresh(form);
  }
  if (document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
  let pending=false;
  new MutationObserver(()=>{
    if(pending)return;
    pending=true;
    queueMicrotask(()=>{pending=false;mount();});
  }).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('shared-presentation-selection-changed',()=>{
    for (const form of attached) if(form.isConnected) setTimeout(()=>refresh(form),410);
  });
  // Only a completed original server price preview may promote the CTA.
  // Avoid reading every body DOM mutation, which can starve page rendering.
  document.addEventListener('mad4b-form6-price-verified',event=>{
    if(Number(event.detail?.tourId)!==Number(cfg.tourId))return;
    for(const form of attached)if(form.isConnected)refresh(form);
  });
  document.addEventListener('visibilitychange',()=>{
    if(document.visibilityState==='visible')updateReadiness();
  });
  window.addEventListener('pageshow',()=>updateReadiness());
  updateReadiness();
})();

/* Thank-you transition. No browser may create an advance amount or pay URL.
 * Countdown is an opt-in, server-rendered action AFTER Fluent has saved.
 */
(() => {
  'use strict';
  const attached = new WeakSet();
  const setup = (panel) => {
    if (attached.has(panel)) return;
    attached.add(panel);
    if (!panel.closest('.mad4b-request-confirmation')) return;
    const seconds = Number(panel.dataset.mad4bRedirectSeconds);
    if (!Number.isInteger(seconds) || seconds < 1 || seconds > 15) return;
    const section = panel.closest('.mad4b-request-payment-panel');
    const link = section?.querySelector('a.mad4b-request-pay-link[href]');
    const display = panel.querySelector('.mad4b-form6-countdown-number');
    const cancel = panel.querySelector('button.mad4b-form6-countdown-cancel');
    const cancelled = panel.querySelector('.mad4b-form6-countdown-cancelled');
    if (!link || !display || !cancel || !cancelled) return;
    let url;
    try { url = new URL(link.getAttribute('href'), window.location.href); } catch (_) { return; }
    // Refuse cross-origin, insecure, or non-Woo destination even if markup
    // was altered by another plugin. The server already validated the order.
    const orderPay = url.pathname.split('/').includes('order-pay') || url.searchParams.has('order-pay');
    if (url.origin !== window.location.origin || url.protocol !== 'https:' ||
        !orderPay || !url.searchParams.has('key')) return;
    let remaining = seconds;
    let stopped = false;
    let interval;
    const stop = () => {
      stopped = true;
      window.clearInterval(interval);
      cancel.hidden = true;
      cancelled.hidden = false;
    };
    cancel.addEventListener('click', stop, { once: true });
    interval = window.setInterval(() => {
      if (stopped || !panel.isConnected) { window.clearInterval(interval); return; }
      // Do not whisk customers away while they are viewing another tab.
      if (document.visibilityState === 'hidden') return;
      remaining -= 1;
      display.textContent = String(Math.max(0, remaining));
      if (remaining <= 0) {
        window.clearInterval(interval);
        if (!stopped) window.location.assign(url.href);
      }
    }, 1000);
  };
  const selector = '.mad4b-request-confirmation .mad4b-form6-payment-countdown';
  const inspect = (node) => {
    if (!node || node.nodeType !== 1) return;
    // Check only inserted subtrees; never rescan all ~4k tour-page elements
    // every time Elementor/Fluent dynamically updates another component.
    if (node.matches?.(selector)) setup(node);
    node.querySelectorAll?.(selector).forEach(setup);
  };
  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', () => inspect(document.body), { once: true });
  else inspect(document.body);
  // Fluent inserts the confirmation after Ajax success; no duplicate timer.
  new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) inspect(node);
    }
  }).observe(document.body, { childList: true, subtree: true });
})();
