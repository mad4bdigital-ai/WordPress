/* Accessible tour estimate disclaimer, translating at render time through WPML. */
(() => {
  'use strict';
  const mount = () => {
    const copy = window.MAD4BTourUiSourceParityV1?.caption || 'Tour estimate · subject to confirmation';
    document.querySelectorAll('body.single-tours-and-activities .shared-fluent-server-quote').forEach(quote => {
      if (quote.querySelector('.mad4b-tour-estimate-caption')) return;
      const heading=quote.querySelector('.shared-price-title');
      if (!heading) return;
      const hint=document.createElement('small');
      hint.className='mad4b-tour-estimate-caption';
      hint.textContent=copy;
      heading.insertAdjacentElement('afterend',hint);
    });
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
  window.addEventListener('load',mount,{once:true});
  document.addEventListener('shared-presentation-selection-changed',mount);
  document.addEventListener('shared-presentation-rehydration-requested',mount);
})();
