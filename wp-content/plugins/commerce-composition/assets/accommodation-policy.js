/* Consume committed native selection state, never an unaccepted selection request. */
(() => {
 'use strict';
 const requests = new WeakMap(), rendered = new WeakMap();
 const config = window.mad4bAccommodationPolicy;
 if (!config) return;
 async function refresh(node, snapshot) {
  if (!snapshot || Number(node.dataset.tour) !== Number(snapshot.tourId)) return;
  const rate = Number(snapshot.selectedRateId) || 0;
  if (rendered.get(node) === rate) return;
  rendered.set(node, rate);
  requests.get(node)?.abort();
  const controller = new AbortController(); requests.set(node, controller);
  node.setAttribute('aria-busy','true');
  // Remove stale content immediately, including after a failed or removed selection.
  node.replaceChildren();
  if (!rate) { node.textContent = config.select || ''; node.removeAttribute('aria-busy'); return; }
  const url = new URL(config.url);
  Object.entries({tour_id:snapshot.tourId,rate_id:rate,view:node.dataset.view,lang:config.lang || ''}).forEach(([k,v])=>url.searchParams.set(k,v));
  try {
   const response = await fetch(url,{signal:controller.signal,credentials:'same-origin',cache:'no-store'});
   if (!response.ok) throw new Error('catalog_unavailable');
   const data = await response.json();
   if (requests.get(node)===controller && !controller.signal.aborted) { node.innerHTML = data.html; node.dataset.rate = String(rate); }
  } catch (error) {
   if (requests.get(node)===controller && error.name !== 'AbortError') { node.textContent = config.unavailable || ''; rendered.delete(node); }
  } finally { if (requests.get(node)===controller) node.removeAttribute('aria-busy'); }
 }
 function update(snapshot) { document.querySelectorAll('.mad4b-accommodation-policy').forEach(node=>refresh(node,snapshot)); }
 function hydrate() { update(window.SharedPresentationSelectionV1?.getSnapshot()); }
 document.addEventListener('shared-presentation-selection-changed',event=>update(event.detail?.snapshot));
 document.addEventListener('shared-presentation-rehydration-requested',event=>update(event.detail?.snapshot));
 document.addEventListener('jet-filter-content-rendered',hydrate);
 document.addEventListener('DOMContentLoaded',hydrate);
 hydrate();
})();
