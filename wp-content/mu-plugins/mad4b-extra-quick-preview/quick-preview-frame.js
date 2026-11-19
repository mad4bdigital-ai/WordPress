/** Authorised Extras iframe CTA. The parent owns the native selection and Form 6. */
(()=>{
 'use strict';
 if(window.__mad4bExtraPreviewFrameBound)return;
 window.__mad4bExtraPreviewFrameBound=true;
 const TYPE='mad4b-extra-preview-select-request';
 const RESULT='mad4b-extra-preview-select-result';
 const GALLERY='mad4b-extra-preview-gallery-state';
 // Elementor owns the real gallery popup and UAEL owns its filters. Observe
 // only an authorised Quick Preview iframe, then tell the existing parent
 // dialog which chrome should be visible; no second modal is built here.
 let galleryOpened=null,scheduled=false;
 const galleryOpen=()=>[...document.querySelectorAll('.elementor-popup-modal')]
   .some(p=>p.isConnected && p.querySelector('[data-mad4b-uael-tour="1"]') &&
     p.getAttribute('aria-hidden')!=='true' &&
     getComputedStyle(p).display!=='none');
 const syncGallery=()=>{
   scheduled=false;
   if(!document.body?.classList.contains('mad4b-extra-quickview-frame')||
      window.parent===window)return;
   const open=galleryOpen();
   if(open===galleryOpened)return;
   galleryOpened=open;
   document.documentElement.classList.toggle('mad4b-extra-quickview-gallery-active',open);
   document.body.classList.toggle('mad4b-extra-quickview-gallery-active',open);
   window.parent.postMessage({type:GALLERY,open},location.origin);
 };
 const scheduleGallery=()=>{
   if(scheduled)return;
   scheduled=true;
   window.requestAnimationFrame(syncGallery);
 };
 const observeGallery=()=>{
   if(!document.body)return;
   const observer=new MutationObserver(scheduleGallery);
   observer.observe(document.body,{subtree:true,childList:true,
     attributes:true,attributeFilter:['class','style','aria-hidden']});
   if(window.jQuery)window.jQuery(document).on(
      'elementor/popup/show elementor/popup/hide',scheduleGallery);
   scheduleGallery();
 };
 if(document.readyState==='loading')
   document.addEventListener('DOMContentLoaded',observeGallery,{once:true});
 else observeGallery();
 const valid=n=>Number.isSafeInteger(n)&&n>0;
 document.addEventListener('click',e=>{
   const button=e.target.closest?.('[data-mad4b-preview-select][data-mad4b-preview-parent]');
   if(!button||!document.body.classList.contains('mad4b-extra-quickview-frame'))return;
   const extra=Number(button.dataset.mad4bPreviewSelect);
   const parent=Number(button.dataset.mad4bPreviewParent);
   if(!valid(extra)||!valid(parent)||window.parent===window)return;
   e.preventDefault();
   if(button.disabled)return;
   button.disabled=true;button.setAttribute('aria-busy','true');
   const status=button.parentElement?.querySelector('.mad4b-extra-preview-cta-message');
   if(status)status.textContent='';
   window.parent.postMessage({type:TYPE,extraTourId:extra,parentTourId:parent},location.origin);
 });
 window.addEventListener('message',e=>{
   if(e.origin!==location.origin||e.source!==window.parent||e.data?.type!==RESULT)return;
   const extra=Number(e.data.extraTourId),parent=Number(e.data.parentTourId);
   const button=document.querySelector('[data-mad4b-preview-select][data-mad4b-preview-parent]');
   if(!button||Number(button.dataset.mad4bPreviewSelect)!==extra||
      Number(button.dataset.mad4bPreviewParent)!==parent)return;
   if(e.data.ok)return;
   button.disabled=false;button.removeAttribute('aria-busy');
   const status=button.parentElement?.querySelector('.mad4b-extra-preview-cta-message');
   if(status)status.textContent='Could not add this experience to your trip form. Please try again.';
 });
})();
