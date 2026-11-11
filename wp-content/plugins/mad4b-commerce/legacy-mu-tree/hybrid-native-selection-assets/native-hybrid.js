/** Add selection to ORIGINAL Elementor widgets; do not replace listing, filters or Fluent Form. */
(() => {
 'use strict';
 const config=window.BookingHybridQA;
 if(!config)return;
 const root=document.querySelector('[data-booking-hybrid-qa]') || document;
 const styleRoot=root===document?document.body:root;
 const within=(s)=>root.querySelector(s);
 const rates=new Set(config.rateIds.map(Number));
 const rateCards=()=>(config.nativeRateWidgetIds||[]).flatMap(
   id=>[...root.querySelectorAll('.elementor-element-'+id+' .jet-listing-grid__item')]
 ).filter(card=>rates.has(resolveRateId(card)));
 const selectedCard=id=>rateCards().find(card=>resolveRateId(card)===Number(id));
 const nativeStay=()=>selectedCard(selectedRate)?.querySelector('.booking-tour-stay-options')
   || within('[data-hybrid-stay-slot] .booking-tour-stay-options');
 const nativeRate=()=>nativeStay()?.querySelector('[data-tour-stay-rate]');
 const rateCopy=()=>within('[data-hybrid-summary-rate]');
 const stayCopy=()=>within('[data-hybrid-summary-stay]');
 let selectedRate=config.defaultRate;
 function labelFromRate(id){
   const card=selectedCard(id);
   const local=card?.querySelector('[data-tour-stay-rate]');
   const found=[...(local?.options||[])].find(x=>Number(x.value)===Number(id));
   return found?.textContent?.trim() || ('Tour Rate #'+id);
 }
 function selectRate(id){
   if(!rates.has(Number(id)))return;
   const card=selectedCard(id);
   if(!card)return; // A filtered-out or unrendered Rate is never selected.
   selectedRate=Number(id);
   const select=card.querySelector('[data-tour-stay-rate]');
   if(select && [...select.options].some(x=>Number(x.value)===selectedRate)
     && Number(select.value)!==selectedRate){
     select.value=String(selectedRate);
     select.dispatchEvent(new Event('change',{bubbles:true}));
   }
   updateLabels();
   card.scrollIntoView({block:'nearest',behavior:'smooth'});
 }
 function updateLabels(){
   const label=labelFromRate(selectedRate);
   const copy=rateCopy();
   if(copy)copy.textContent='Selected for request: '+label;
   for(const pill of root.querySelectorAll('[data-hybrid-rate-pill]')){
     const active=Number(pill.dataset.hybridRatePill)===selectedRate;
     pill.setAttribute('aria-checked',String(active));
     pill.classList.toggle('is-selected',active);
   }
   for(const card of rateCards()){
     card.classList.toggle('booking-hybrid-selected',resolveRateId(card)===selectedRate);
   }
   styleRoot?.classList.add('booking-hybrid-qa-ready');
   for(const btn of root.querySelectorAll('[data-hybrid-select-rate]')){
     const active=Number(btn.dataset.hybridSelectRate)===selectedRate;
     btn.setAttribute('aria-pressed',String(active));
     const nextText=active?'Selected ✓':'Select this rate';
     if(btn.textContent!==nextText)btn.textContent=nextText;
     btn.closest('.jet-listing-grid__item')?.classList.toggle('booking-hybrid-selected',active);
   }
 }
 function resolveRateId(card){
   // Only from explicit native post identity; never from a title, index or price.
   for(const attr of ['data-post-id','data-post_id','data-id','data-item-id']){
     const found=Number(card.getAttribute(attr));
     if(rates.has(found))return found;
   }
   const names=card.className+' '+(card.querySelector('[class*=jet-listing-dynamic-post-]')?.className||'');
   const match=names.match(/(?:jet-listing-dynamic-post-|post-)(\d+)\b/);
   if(match&&rates.has(Number(match[1])))return Number(match[1]);
   return 0;
 }
 function enhanceListings(){
   const widgets=config.nativeRateWidgetIds||[];
   for(const widgetId of widgets){
     const selectors='.elementor-element-'+widgetId+' .jet-listing-grid__item';
     for(const card of root.querySelectorAll(selectors)){
       if(card.querySelector('[data-hybrid-select-rate]'))continue;
       const rateId=resolveRateId(card);
       if(!rateId)continue; // Never guess hidden identity from displayed price.
       const button=document.createElement('button');
       button.type='button';button.className='booking-hybrid-select';
       button.dataset.hybridSelectRate=String(rateId);
       button.textContent='Select this rate';
       button.setAttribute('aria-label','Select '+labelFromRate(rateId));
       button.addEventListener('click',event=>{
         event.preventDefault();event.stopPropagation();selectRate(rateId);
       });
       card.append(button);
     }
   }
   updateLabels();
 }
 function attachNativeStay(){
   const select=nativeRate();
   if(select&&!select.dataset.hybridAttached){
     select.dataset.hybridAttached='1';
     // The actual rate selection comes from the EXISTING Jet Listing cards.
     // Keep the native per-Rate select and its original handlers intact.
     select.addEventListener('change',()=>{
       const id=Number(select.value);
       if(rates.has(id)){selectedRate=id;updateLabels();}
     });
   }
 }
 function attachForm(){
   // Elementor currently renders separate desktop/mobile instances of Form #6
   // on the original Tour single template; QA must guard *every* instance.
   for(const form of root.querySelectorAll('form.fluent_form_'+config.formId)){
   if(form.dataset.hybridReadOnlyAttached)continue;
   form.dataset.hybridReadOnlyAttached='1';
   // Original form stays in original sidebar and retains normal discovery fields.
   // QA case only: block submissions until persisted binding and consent are verified.
   const block=(event)=>{
     event.preventDefault();event.stopImmediatePropagation();
     const copy=stayCopy();
     if(copy)copy.textContent='QA only: submit is disabled until booking-data persistence is verified.';
   };
   form.addEventListener('submit',block,true);
   form.addEventListener('click',event=>{
     if(event.target.closest('button[type="submit"],input[type="submit"]'))block(event);
   },true);
   }
 }
 root.addEventListener('booking-context:selection',event=>{
   const copy=stayCopy(),detail=event.detail||{};
   if(copy && detail.slot){
     copy.textContent='Selected stay option for '+detail.slot+' · availability not confirmed.';
   }
 });
 root.addEventListener('booking:tour-stay-quote-ready',event=>{
   const copy=stayCopy();
   if(!copy)return;
   const q=event.detail?.quote;
   const entries=q?.additional_by_currency && typeof q.additional_by_currency==='object'
     ? Object.entries(q.additional_by_currency) : [];
   const amount=entries.filter(([c,n])=>/^[A-Z]{3}$/.test(c) && Number.isFinite(Number(n)))
     .map(([c,n])=>c+' '+Number(n).toFixed(2)).join(' · ');
   copy.textContent=amount
     ?'Additional stay reference: '+amount+'. Base Tour Rate remains separate. Final total and availability are unconfirmed.'
     :'Stay options updated; additional prices and availability require confirmation.';
 });
 function start(){
   attachNativeStay();attachForm();enhanceListings();
   const target=root.querySelector('.elementor-element-1985c71')||root;
   // JetSmartFilters reload ListingGrid items with AJAX.
   let queued=false;
   const observer=new MutationObserver(()=>{
     if(queued)return;queued=true;
     requestAnimationFrame(()=>{queued=false;attachNativeStay();attachForm();enhanceListings();});
   });
   observer.observe(target,{subtree:true,childList:true});
   const fluentNode=root.querySelector('.elementor-element-ebdaa9c');
   if(fluentNode&&fluentNode!==target)
     observer.observe(fluentNode,{subtree:true,childList:true});
   updateLabels();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
 else start();
})();
