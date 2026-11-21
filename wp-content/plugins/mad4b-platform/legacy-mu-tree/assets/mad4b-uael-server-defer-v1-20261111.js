(function(window,document){
 'use strict';
 const cfg=window.MAD4B_UAEL_SERVER_DEFER;
 if(!cfg||!cfg.url||!cfg.action)return;
 const pending=new Map();
 const getSlot=()=>document.querySelector('.elementor-popup-modal [data-mad4b-uael-server-slot="1"]');
 const hydrate=async()=>{
  const slot=getSlot();if(!slot)return;
  if(slot.dataset.mad4bLoading==='1'||slot.dataset.mad4bLoaded==='1')return;
  const tour=slot.dataset.mad4bTourId,nonce=slot.dataset.mad4bNonce;
  if(!/^\d+$/.test(tour||'')||!nonce)return;
  slot.dataset.mad4bLoading='1';
  slot.setAttribute('aria-busy','true');
  slot.textContent='';
  const payload=new URLSearchParams({action:cfg.action,tour_id:tour,_ajax_nonce:nonce});
  let request=pending.get(tour);
  if(!request){
    request=fetch(cfg.url,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:payload.toString()}).then(async response=>{
        if(!response.ok)throw Error('gallery_http_'+response.status);
        const obj=await response.json();
        if(!obj?.success||typeof obj.data?.html!=='string')throw Error('gallery_invalid');
        return obj.data.html;
      }).finally(()=>pending.delete(tour));
    pending.set(tour,request);
  }
  try{
   const html=await request;
   if(!slot.isConnected)return;
   const wrapper=slot.closest('.elementor-widget-html');
   if(!wrapper)throw Error('gallery_placeholder_missing');
   const t=document.createElement('template');t.innerHTML=html;
   const node=t.content.querySelector('.elementor-widget-uael-image-gallery');
   if(!node||!node.querySelector('[data-mad4b-uael-tour="1"]'))throw Error('gallery_widget_invalid');
   wrapper.replaceWith(node);
   // The established UAEL addon owns its original Masonry, Fancybox,
   // itinerary tabs and native event handling, not this transport script.
   window.dispatchEvent(new Event('mad4b:uael-server-hydrated'));
  }catch(e){
   if(slot.isConnected){
     slot.dataset.mad4bLoading='0';
     slot.setAttribute('aria-busy','false');
     slot.textContent='Gallery could not be loaded. Please try again.';
   }
  }
 };
 const schedule=()=>{[0,100,300,900].forEach(ms=>window.setTimeout(hydrate,ms));};
 if(window.jQuery)window.jQuery(document).on('elementor/popup/show',schedule);
 document.addEventListener('click',e=>{
  const target=e.target.closest('#single_tour_gellery_button,[href*="popup%3Aopen"]');
  if(target)schedule();
 },true);
})(window,document);
