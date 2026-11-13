/** Scoped, keyboard-accessible native Tour-Single quick preview; never a card-selection event. */
(()=>{'use strict';
 if(window.__mad4bExtraQuickPreviewV1)return;
 window.__mad4bExtraQuickPreviewV1=true;
 let dialog=null,trigger=null,frame=null,currentExtra=0,currentParent=0,committing=false;
 function close(){
   if(dialog?.open)dialog.close();
 }
 function clear(){
   if(!dialog)return;
   frame?.removeAttribute('src');
   dialog.remove();dialog=null;frame=null;
   try{trigger?.focus({preventScroll:true});}catch(_){/* focus target may have been re-rendered */}
   trigger=null;currentExtra=0;currentParent=0;committing=false;
 }
 function create(){
   const d=document.createElement('dialog');d.className='mad4b-extra-quick-preview-dialog';
   d.setAttribute('aria-label','Optional experience quick preview');
   const wrap=document.createElement('div');wrap.className='mad4b-extra-quick-preview-shell';
   const header=document.createElement('header');header.className='mad4b-extra-quick-preview-toolbar';
   const title=document.createElement('strong');title.textContent='Optional experience';
   const back=document.createElement('small');back.textContent='Explore details without leaving your tour';
   const exit=document.createElement('button');exit.type='button';exit.className='mad4b-extra-quick-preview-close';exit.textContent='Close';exit.setAttribute('aria-label','Close quick preview');
   header.append(title,back,exit);
   const iframe=document.createElement('iframe');iframe.title='Optional experience details (original Tour Single template)';
   iframe.className='mad4b-extra-quick-preview-frame';iframe.loading='eager';iframe.referrerPolicy='same-origin';
   // No allow-forms: a preview cannot submit the embedded quote/payment form.
   iframe.setAttribute('sandbox','allow-scripts allow-same-origin');
   wrap.append(header,iframe);d.append(wrap);
   exit.addEventListener('click',close);
   d.addEventListener('close',clear);
   d.addEventListener('click',e=>{if(e.target===d)close();});
   document.body.append(d);
   dialog=d;frame=iframe;return {d,title,iframe};
 }
 // A frame request is never treated as a booking command. The parent
 // clicks the original, source-owned Select extra control, then verifies
 // that the existing Fluent Form 6 projection actually contains the ID.
 const requestType='mad4b-extra-preview-select-request';
 const resultType='mad4b-extra-preview-select-result';
 // Keep the ORIGINAL Elementor/UAEL popup inside this authorised iframe,
 // but present it as one full Quick Preview layer. Never move the popup
 // across documents or create a replacement gallery renderer.
 const galleryStateType='mad4b-extra-preview-gallery-state';
 function confirmInForm(cfg,id){
   const forms=[...document.querySelectorAll('form.fluent_form_6,form#fluentform_6')];
   return forms.some(form=>{
     const input=[...form.querySelectorAll('input[name]')]
       .find(el=>el.name===cfg.field);
     if(!input)return false;
     try{
       const obj=JSON.parse(input.value);
       return Number(obj.tour_id)===cfg.tourId &&
         Array.isArray(obj.extra_ids) && obj.extra_ids.includes(id);
     }catch(_){return false;}
   });
 }
 function respond(ok){
   frame?.contentWindow?.postMessage({
     type:resultType,ok,extraTourId:currentExtra,parentTourId:currentParent
   },location.origin);
 }
 window.addEventListener('message',async event=>{
   if(!dialog?.open||!frame||event.source!==frame.contentWindow||
      event.origin!==location.origin)return;
   if(event.data?.type===galleryStateType){
     dialog.classList.toggle('mad4b-extra-quick-preview-gallery-active',
       event.data.open===true);
     return;
   }
   if(event.data?.type!==requestType||committing)return;
   if(event.data.extraTourId!==currentExtra ||
      event.data.parentTourId!==currentParent)return;
   committing=true;
   try{
     const cfg=window.MAD4BExtraForm6ConfigV1;
     if(!cfg||!window.__mad4bExtraForm6Bound||cfg.formId!==6||
        cfg.tourId!==currentParent||!Array.isArray(cfg.allowed)||
        !cfg.allowed.some(item=>Number(item.id)===currentExtra)||
        !document.querySelector('form.fluent_form_6,form#fluentform_6')){
       respond(false);return;
     }
     const groups=[...document.querySelectorAll(
       '.shared-extra-feature-carousel [data-extra-actions][data-parent-tour]')];
     const group=groups.find(g=>g.dataset.extraActions===String(currentExtra)&&
       g.dataset.parentTour===String(currentParent));
     const select=group?.querySelector('[data-extra-select]');
     if(!select){respond(false);return;}
     if(!confirmInForm(cfg,currentExtra)&&!select.hidden)select.click();
     await Promise.resolve();
     if(dialog?.open && confirmInForm(cfg,currentExtra)){close();return;}
     respond(false);
   }catch(_){respond(false);}
   finally{committing=false;}
 });
 document.addEventListener('click',event=>{
   let button=event.target.closest?.('.shared-extra-feature-carousel [data-mad4b-extra-preview-url]');
   let focusSource=button;
   // The original card's image/title links open the same preview. Only
   // hijack the extra tour permalink; never destination/filter links or
   // modified/new-tab clicks, and never the Select/Remove controls.
   if(!button && !event.defaultPrevented && event.button===0 &&
      !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey){
     const link=event.target.closest?.('.shared-extra-feature-carousel .jet-listing-grid__item a[href]');
     const card=link?.closest('.jet-listing-grid__item');
     const candidate=card?.querySelector('button.mad4b-extra-preview-open[data-mad4b-extra-preview-url]');
     if(link && candidate && link.getAttribute('target')!=='_blank'){
       try {
         const dest=new URL(link.getAttribute('href'),location.href);
         const extra=new URL(candidate.getAttribute('data-mad4b-extra-preview-url'),location.href);
         if(dest.origin===location.origin && dest.pathname===extra.pathname){
           button=candidate;focusSource=link;
         }
       } catch(_){/* invalid link: keep native navigation */}
     }
   }
   if(!button || button.tagName!=='BUTTON' || button.disabled)return;
   const raw=button.getAttribute('data-mad4b-extra-preview-url');if(!raw)return;
   let url;try{url=new URL(raw,location.href);}catch(_){return;}
   if(url.origin!==location.origin||url.protocol!==location.protocol||url.searchParams.get('mad4b_extra_quickview')!=='1')return;
   event.preventDefault();event.stopPropagation();
   if(dialog)close();
   trigger=focusSource;
   const active=create();
   const card=button.closest('.jet-listing-grid__item');
   const native=card?.querySelector('[data-extra-actions][data-parent-tour]');
   currentExtra=Number(button.getAttribute('data-mad4b-extra-preview')||0);
   currentParent=Number(native?.dataset.parentTour||0);
   // Source-owned translated Tour name, not the first arbitrary dynamic
   // field (for example the card's 'Featured' badge).
   const name=button.getAttribute('data-mad4b-extra-preview-title');
   if(name?.trim())active.title.textContent=name.trim().slice(0,130);
   active.iframe.src=url.href;
   active.d.showModal();
 },true);
})();
