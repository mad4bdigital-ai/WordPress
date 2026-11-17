/**
 * MAD4B Rate UI Lifecycle v2 — no global MutationObserver / no proprietary AJAX.
 * Rate selection remains owned by SharedPresentationSelectionV1.
 * Native JetEngine, Elementor and JetSmartFilters render the listing contents.
 */
(()=>{'use strict';
 if(window.MAD4BRateActionLifecycleV2)return;
 const rateRegionSelector='#tour_rates_accordion, #tour_rates';
 const actionSelector='.rate-select-actions[data-rate-actions]';
 const captionsByLanguage={
  en:['Select Rate','Selected · Remove'],
  ar:['اختر السعر','محدد · إلغاء'],
  fr:['Choisir le tarif','Sélectionné · Retirer'],
  de:['Tarif wählen','Ausgewählt · Entfernen'],
  es:['Elegir tarifa','Seleccionado · Quitar'],
  it:['Scegli tariffa','Selezionato · Rimuovi']
 };
 const lang=String(document.documentElement.lang||'en').split(/[-_]/)[0];
 const captions=captionsByLanguage[lang]||captionsByLanguage.en;
 let pending=false;
 let processed=0;
 let accordionRepairs=0;
 let nativeRetry=null;

 // JetAccordion registers click.jetAccordion on first Elementor widget-ready.
 // JetEngine can inject Rate listing widgets AFTER Elementor's initial ready
 // and before its own asynchronous assets/handler pass has finished.
 // Restore the native JetTabs handler, never replace accordion behavior.
 function hasNativeAccordionHandler(control){
  const jq=window.jQuery;
  const registry=jq?._data?.(control,'events');
  if(!registry || !Array.isArray(registry.click))return false;
  return registry.click.some(listener=>String(listener.namespace||'').split('.').includes('jetAccordion'));
 }
 function restoreNativeAccordions(root){
  const jq=window.jQuery;
  const hooks=window.elementorFrontend?.hooks;
  if(!jq?._data || !hooks?.doAction)return;
  for(const widget of root.querySelectorAll('.elementor-widget-jet-accordion')){
   const control=widget.querySelector('.jet-accordion__inner > .jet-toggle > .jet-toggle__control');
   if(!control || hasNativeAccordionHandler(control))continue;
   hooks.doAction('frontend/element_ready/jet-accordion.default',jq(widget),jq);
   if(hasNativeAccordionHandler(control))accordionRepairs++;
  }
 }
 // Follow JetEngine lazy and JetSmartFilters lifecycle: bounded retries
 // allow vendor asset promises to finish without page-wide DOM observers.
 function retryNativeAccordions(){
  if(nativeRetry!==null)clearTimeout(nativeRetry);
  [80,400].forEach((ms,i)=>setTimeout(()=>{
   const root=scope();if(root)restoreNativeAccordions(root);
  },ms));
  nativeRetry=setTimeout(()=>{
   nativeRetry=null;
   const root=scope();if(root)restoreNativeAccordions(root);
  },1100);
 }

 const scope=()=>{
  // Match the semantic Prices section across translated Elementor template IDs.
  const heading=document.getElementById('prices');
  const section=heading?.closest('.e-con, section');
  if(section?.querySelector(rateRegionSelector))return section;
  const listing=document.querySelector(rateRegionSelector);
  return listing?.closest('.elementor-widget-template, .elementor-location-single')||null;
 };
 const selectedId=()=>String(window.SharedPresentationSelectionV1?.getSnapshot?.()?.selectedRateId||'');
 const eligibleRateIds=()=>new Set(
  (Array.isArray(window.SharedPresentationFluentConfigV1?.rateChoices)
   ? window.SharedPresentationFluentConfigV1.rateChoices : [])
   .map(item=>String(item?.id||'')).filter(Boolean)
 );
 function positionRateActions(root) {
  root.querySelectorAll('.tour-native-package-tier').forEach(card=>{
   const dates=card.querySelector('.elementor-element-f4afb2e');
   const actions=card.querySelector('.tour-rate-native-actions');
   if(!dates||!actions||!dates.parentElement)return;
   // Preserve the same source-authored node; never clone a booking action.
   if(actions.previousElementSibling!==dates)dates.insertAdjacentElement('afterend',actions);
   actions.classList.add('rate-positioned-by-date');
  });
 }
 function mountHeaderActions(root) {
  const eligible=eligibleRateIds();
  if(!eligible.size)return;
  root.querySelectorAll(actionSelector).forEach(group=>{
   const id=String(group.querySelector('[data-rate-booking-rate-id]')?.dataset.rateBookingRateId||'');
   if(!eligible.has(id))return;
   const toggle=group.closest('.jet-toggle');
   const header=toggle?.querySelector(':scope > .jet-toggle__control');
   if(!toggle||!header)return;
   let button=toggle.querySelector(':scope > .mad4b-rate-header-select');
   if(button && button.dataset.rateId!==id){
    // Stale AJAX item: remove the wrong control before recreating it.
    button.remove();
    button=null;
   }
   if(!button){
    button=document.createElement('button');
    button.type='button';
    button.className='mad4b-rate-header-select';
    button.dataset.rateId=id;
    button.dataset.rateTier=group.dataset.rateTier||'';
    button.textContent=captions[0];
    header.insertAdjacentElement('afterend',button);
   }
   toggle.classList.add('mad4b-rate-header-action-enabled');
   // The original action remains the fallback if this enhancement cannot mount.
   group.style.setProperty('display','none','important');
  });
 }
 function syncSelection(root) {
  const chosen=selectedId();
  root.querySelectorAll(actionSelector).forEach(group=>{
   const isSelected=Boolean(chosen) && chosen===group.dataset.rateActions;
   group.dataset.selected=String(isSelected);
   const choose=group.querySelector('[data-rate-booking-rate-id]');
   const remove=group.querySelector('[data-rate-remove-rate-id]');
   if(choose){choose.hidden=isSelected;choose.setAttribute('aria-pressed',String(isSelected));}
   if(remove)remove.hidden=!isSelected;
  });
  root.querySelectorAll('.mad4b-rate-header-select').forEach(button=>{
   const active=Boolean(chosen) && chosen===button.dataset.rateId;
   button.setAttribute('aria-pressed',String(active));
   button.textContent=active?captions[1]:captions[0];
  });
 }
 function hydrate() {
  const root=scope();
  if(!root)return;
  restoreNativeAccordions(root);
  positionRateActions(root);
  mountHeaderActions(root);
  syncSelection(root);
  processed++;
 }
 const schedule=()=>{
  if(pending)return;
  pending=true;
  requestAnimationFrame(()=>{pending=false;hydrate();});
 };
 document.addEventListener('click',event=>{
  const button=event.target?.closest?.('.mad4b-rate-header-select');
  const root=scope();
  if(!button || !root || !root.contains(button))return;
  event.preventDefault();
  event.stopPropagation();
  const id=Number(button.dataset.rateId);
  const tourId=Number(window.SharedPresentationSelectionConfigV1?.tourId);
  if(!Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(tourId)||tourId<1)return;
  document.dispatchEvent(new CustomEvent('rate-selection-request',{
   detail:{tourId,rateId:id,remove:selectedId()===String(id)}
  }));
 });
 document.addEventListener('shared-presentation-selection-changed',()=>{
  const root=scope();if(root)syncSelection(root);
 });
 [
  'jet-filter-content-rendered',
  'shared-presentation-rehydration-requested',
  'jet-engine/listing-grid/after-lazy-load',
  'jet-engine/listing/ajax-get-listing/done'
 ].forEach(event=>document.addEventListener(event,schedule));
 // JetEngine and JetSmartFilters dispatch listing events through jQuery.
 if(window.jQuery)window.jQuery(document).on(
  'jet-filter-content-rendered.mad4bRateUI jet-engine/listing-grid/after-lazy-load.mad4bRateUI jet-engine/listing/ajax-get-listing/done.mad4bRateUI',
  ()=>{
   schedule();
   retryNativeAccordions();
  }
 );
 document.addEventListener('DOMContentLoaded',schedule,{once:true});
 window.addEventListener('load',schedule,{once:true});
 window.MAD4BRateActionLifecycleV2={version:'2.1.0',refresh:schedule,
  inspect:()=>({hydrations:processed,accordionRepairs,rateActions:scope()?.querySelectorAll(actionSelector).length||0})};
 schedule();
})();
