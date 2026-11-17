(function(window,document){
 'use strict';
 const cfg=window.MAD4B_UAEL_SERVER_DEFER;
 if(!cfg||!cfg.url||!cfg.action)return;
 const pending=new Map();
 const getPopup=()=>[...document.querySelectorAll('.elementor-popup-modal')]
  .find(p=>getComputedStyle(p).display!=='none'&&p.querySelector('[data-mad4b-uael-server-slot="1"],[data-mad4b-uael-tour="1"]'));
 const getSlot=()=>getPopup()?.querySelector('[data-mad4b-uael-server-slot="1"]');
 const copy=()=>{
   const locale=(document.documentElement.lang||'en').slice(0,2).toLowerCase();
   return {
    ar:{choose:'اختر برنامج الرحلة البحرية',none:'اختر البرنامج',note:'معاينة خط السير فقط؛ السعر والتوافر يتحددان من الحجز',loading:'جارٍ تحميل البرنامج…',error:'تعذر عرض البرنامج المختار'},
    es:{choose:'Seleccionar programa del crucero',none:'Elige un programa',note:'Vista previa del itinerario. Precio y disponibilidad se verifican al reservar',loading:'Cargando programa…',error:'No se pudo cargar el programa'},
    fr:{choose:'Choisir le programme de croisière',none:'Choisir un programme',note:'Aperçu uniquement. Prix et disponibilités à confirmer à la réservation',loading:'Chargement du programme…',error:'Impossible de charger ce programme'},
    de:{choose:'Kreuzfahrtprogramm auswählen',none:'Programm auswählen',note:'Nur Reiseverlauf-Vorschau. Preise und Verfügbarkeit werden bei Buchung bestätigt',loading:'Programm wird geladen…',error:'Programm konnte nicht geladen werden'},
    it:{choose:'Scegli il programma della crociera',none:'Seleziona programma',note:'Solo anteprima itinerario. Prezzo e disponibilità da confermare',loading:'Caricamento programma…',error:'Impossibile caricare il programma'},
    en:{choose:'Choose Cruise Program',none:'Select a program',note:'Itinerary preview only. Price and availability are confirmed separately',loading:'Loading program…',error:'Could not load the selected program'}
   }[locale]||{choose:'Choose Cruise Program',none:'Select a program',
     note:'Itinerary preview only. Price and availability are confirmed separately',
     loading:'Loading program…',error:'Could not load the selected program'};
 };
 const fetchHTML=(tour,nonce,program)=>{
   const key=tour+'|'+String(program);
   let request=pending.get(key);
   if(request)return request;
   const data=new URLSearchParams({action:cfg.action,tour_id:tour,_ajax_nonce:nonce,program_id:String(program)});
   request=fetch(cfg.url,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:data.toString()}).then(async response=>{
        if(!response.ok)throw Error('gallery_http_'+response.status);
        const obj=await response.json();
        if(!obj?.success||typeof obj.data?.html!=='string')throw Error('gallery_invalid');
        if(Number(obj.data.program_id||0)!==Number(program))throw Error('gallery_program_mismatch');
        return obj.data;
      }).finally(()=>pending.delete(key));
   pending.set(key,request);
   return request;
 };
 const widgetFromHTML=(html)=>{
   const t=document.createElement('template');t.innerHTML=html;
   const node=t.content.querySelector('.elementor-widget-uael-image-gallery');
   if(!node||!node.querySelector('[data-mad4b-uael-tour="1"]'))throw Error('gallery_widget_invalid');
   return node;
 };
 const notify=()=>window.dispatchEvent(new Event('mad4b:uael-server-hydrated'));
 const mountChoice=(node,data,tour,nonce)=>{
   const popup=getPopup()||node.closest('.elementor-popup-modal');
   if(!popup)return;
   let panel=popup.querySelector('.mad4b-uael-program-choice');
   if(!Array.isArray(data.programs)||data.programs.length<2){
     if(panel)panel.remove();
     return;
   }
   const words=copy();
   if(!panel){
     panel=document.createElement('div');panel.className='mad4b-uael-program-choice';
     panel.dataset.mad4bProgramSwitcher='1';
     panel.setAttribute('role','group');
     const label=document.createElement('label');
     label.className='mad4b-uael-program-label';label.textContent=words.choose;
     const select=document.createElement('select');select.className='mad4b-uael-program-select';
     select.id='mad4b-uael-program-'+tour;
     select.setAttribute('aria-label',words.choose);
     label.htmlFor=select.id;
     const note=document.createElement('p');note.className='mad4b-uael-program-note';note.textContent=words.note;
     const status=document.createElement('p');status.className='mad4b-uael-program-status';
     status.setAttribute('role','status');status.setAttribute('aria-live','polite');
     panel.append(label,select,note,status);
   }
   const select=panel.querySelector('select');
   const oldOptions=data.programs.filter(x=>Number.isInteger(Number(x.id))&&Number(x.id)>0&&typeof x.label==='string');
   select.replaceChildren();
   const empty=document.createElement('option');empty.value='0';empty.textContent=words.none;select.append(empty);
   for(const option of oldOptions){
     const entry=document.createElement('option');entry.value=String(option.id);entry.textContent=option.label;
     select.append(entry);
   }
   select.value=String(data.program_id||0);
   select.dataset.activeProgram=select.value;
   panel.querySelector('.mad4b-uael-program-status').textContent='';
   if(!panel.isConnected)node.before(panel);
   select.onchange=async()=>{
     const next=Number(select.value),previous=select.dataset.activeProgram||'0';
     const status=panel.querySelector('.mad4b-uael-program-status');
     if(!Number.isSafeInteger(next)||next<0||
       (next!==0&&!oldOptions.some(x=>Number(x.id)===next))){
       select.value=previous;return;
     }
     // "Select a program" is a meaningful reset for multi-program cruises:
     // the original photo gallery remains, with no invented itinerary days.
     select.disabled=true;status.textContent=words.loading;
     try{
       const nextData=await fetchHTML(tour,nonce,next);
       if(!panel.isConnected)return;
       const current=popup.querySelector('.elementor-widget-uael-image-gallery:has([data-mad4b-uael-tour="1"])');
       if(!current)throw Error('gallery_widget_missing');
       const newNode=widgetFromHTML(nextData.html);
       current.replaceWith(newNode);
       // Re-use the exact ORIGINAL UAEL widget for every Program. No cloned
       // photo engines, fabricated rate selections or external media.
       mountChoice(newNode,nextData,tour,nonce);
       notify();
       window.dispatchEvent(new CustomEvent('mad4b:cruise-gallery-program-preview',{
         detail:{tourId:Number(tour),programId:next,previewOnly:true}
       }));
     }catch(e){
       select.value=previous;
       status.textContent=words.error;
     }finally{
       if(select.isConnected)select.disabled=false;
     }
   };
 };
 const hydrate=async()=>{
   const slot=getSlot();if(!slot)return;
   if(slot.dataset.mad4bLoading==='1'||slot.dataset.mad4bLoaded==='1')return;
   const tour=slot.dataset.mad4bTourId,nonce=slot.dataset.mad4bNonce;
   if(!/^\d+$/.test(tour||'')||!nonce)return;
   slot.dataset.mad4bLoading='1';slot.setAttribute('aria-busy','true');slot.textContent='';
   try{
     const data=await fetchHTML(tour,nonce,0);
     if(!slot.isConnected)return;
     const wrapper=slot.closest('.elementor-widget-html');
     if(!wrapper)throw Error('gallery_placeholder_missing');
     const node=widgetFromHTML(data.html);
     wrapper.replaceWith(node);
     mountChoice(node,data,tour,nonce);
     notify();
   }catch(e){
     if(slot.isConnected){
       slot.dataset.mad4bLoading='0';slot.setAttribute('aria-busy','false');
       const lang=(document.documentElement.lang||'en').slice(0,2).toLowerCase();
       const message={
         ar:['تعذر تحميل صور الرحلة','إعادة المحاولة'],
         es:['No se pudieron cargar las fotos','Reintentar'],
         fr:['Impossible de charger les photos','Réessayer'],
         de:['Die Fotos konnten nicht geladen werden','Erneut versuchen'],
         it:['Impossibile caricare le foto','Riprova'],
         en:['Could not load tour photos','Try again']
       }[lang]||['Could not load tour photos','Try again'];
       const p=document.createElement('p');p.setAttribute('role','status');p.textContent=message[0];
       const button=document.createElement('button');button.type='button';button.textContent=message[1];
       button.className='mad4b-uael-retry-button';
       button.addEventListener('click',hydrate,{once:true});
       slot.replaceChildren(p,button);
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
