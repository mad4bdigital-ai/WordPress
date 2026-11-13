/**
 * Journey Destination Quick Preview.
 * One native dialog + lazy same-origin Elementor taxonomy page, matching the
 * Extra Options preview interaction without importing its commerce actions.
 */
(() => {
  'use strict';
  // The native Extras Preview already owns its iframe dialog; do not nest one.
  if(window!==window.top)return;
  if(window.MAD4BDestinationPreviewV1)return;
  window.MAD4BDestinationPreviewV1=true;
  const words={
    en:['Destination preview','Explore this place without leaving your tour','Full destination page','Close preview','Loading destination…','Preview unavailable. You can open the full page.'],
    es:['Vista previa del destino','Explora este lugar sin salir de tu viaje','Página del destino','Cerrar','Cargando destino…','Vista previa no disponible. Abre la página completa.'],
    fr:['Aperçu de la destination','Découvrez ce lieu sans quitter votre voyage','Page complète','Fermer','Chargement de la destination…','Aperçu indisponible. Ouvrez la page complète.'],
    de:['Vorschau des Reiseziels','Entdecken Sie diesen Ort ohne Ihre Reise zu verlassen','Gesamte Zielseite','Schließen','Reiseziel wird geladen…','Vorschau nicht verfügbar. Öffnen Sie die vollständige Seite.'],
    it:['Anteprima della destinazione','Esplora il luogo senza lasciare il viaggio','Pagina completa','Chiudi','Caricamento…','Anteprima non disponibile. Apri la pagina completa.'],
    ar:['معاينة الوجهة','استكشف المكان دون مغادرة الرحلة','صفحة الوجهة الكاملة','إغلاق','جارٍ تحميل الوجهة…','المعاينة غير متاحة. يمكنك فتح صفحة الوجهة الكاملة.']
  };
  const lang=()=>words[(document.documentElement.lang||'en').split('-')[0].toLowerCase()]||words.en;
  const canonical=(raw)=>{
    try{
      const u=new URL(raw,document.baseURI);
      if(u.origin!==location.origin||u.protocol!==location.protocol||u.username||u.password)return null;
      return u.pathname.replace(/\/+$/,'/')||'/';
    }catch{return null;}
  };
  let active=null;

  function applyFrame(frame,dialog,state) {
    const doc=frame.contentDocument;
    if(!doc?.body||!doc.head)throw Error('frame-unavailable');
    const actual=new URL(frame.contentWindow.location.href);
    if(actual.origin!==location.origin||actual.pathname!==state.pathname)throw Error('unexpected-frame-route');
    doc.documentElement.classList.add('mad4b-destination-preview-frame');
    doc.body.classList.add('mad4b-destination-preview-frame');
    const styles=document.querySelector('#tour-destination-preview-inline-css')?.textContent;
    if(!styles)throw Error('preview-styles-unavailable');
    const style=doc.createElement('style');
    style.id='mad4b-destination-preview-frame-styles';style.textContent=styles;doc.head.append(style);

    // Resolve destination Hero + About from the real, already-rendered
    // Elementor module tree. Companies differ in wrapper class names and
    // document IDs; neither is a capability or content contract.
    const shells=[...doc.querySelectorAll('.elementor[data-elementor-id]')];
    const directModules=node=>shells.filter(n=>n!==node&&
      n.parentElement?.closest('.elementor[data-elementor-id]')===node);
    const rootCandidates=shells.filter(n=>
      !n.parentElement?.closest('.elementor[data-elementor-id]') &&
      n.querySelector('h1') && directModules(n).length>0);
    let resolved=null;
    for(const rootCandidate of rootCandidates){
      const modules=directModules(rootCandidate);
      const hero=modules.find(m=>m.querySelector('h1') &&
        (m.querySelector('img,picture,video') || m.querySelector('[data-widget_type="jet-listing-dynamic-field.default"]')))
        || modules.find(m=>m.querySelector('h1'));
      if(!hero)continue;
      const editorial=[];
      for(const module of modules){
        if(module===hero)continue;
        const headings=[...module.querySelectorAll('[data-widget_type="heading.default"]')]
          .filter(w=>!w.closest('.jet-listing-grid__item,.destination-native-insights')
            && w.textContent.trim().length>0);
        const fields=[...module.querySelectorAll('[data-widget_type="jet-listing-dynamic-field.default"]')]
          .filter(w=>!w.closest('.jet-listing-grid__item,.destination-native-insights'));
        for(const field of fields){
          const source=field.querySelector('.jet-listing-dynamic-field__content')||field;
          const length=source.textContent.trim().length;
          const title=headings.filter(w=>
            !!(w.compareDocumentPosition(field)&doc.defaultView.Node.DOCUMENT_POSITION_FOLLOWING)).at(-1);
          if(title && length>=65)editorial.push({module,title,source,length});
        }
      }
      editorial.sort((a,b)=>b.length-a.length);
      resolved={root:rootCandidate,modules,hero,about:editorial[0]||null};
      break;
    }
    if(!resolved)throw Error('destination-content-contract-unavailable');
    const {root,modules,hero,about}=resolved;
    root.classList.add('mad4b-destination-preview-root');
    modules.forEach(module=>{
      if(module===hero)return;
      module.hidden=true;
      module.style.display='none';
    });
    // Preserve exactly one original native hero. Recompose the explanatory
    // panel only from existing JetEngine field markup; don't query or infer.
    if(about){
      const compact=doc.createElement('section');
      compact.className='mad4b-destination-preview-about';
      const title=doc.createElement('h2');
      title.textContent=about.title.textContent.trim();
      const copy=doc.createElement('div');
      copy.className='mad4b-destination-preview-about-copy';
      copy.append(about.source.cloneNode(true));
      compact.append(title,copy);
      // Keep original Elementor/JetEngine enrichment exactly as rendered by
      // the Destination Term Archive Content template. Moving the native
      // element preserves its accordion state and listeners; never request
      // or reconstruct the same term fields a second time.
      const nativeInsights=about.module.querySelector('.destination-native-insights');
      if(nativeInsights&&nativeInsights.querySelector('.destination-native-field')){
        const fullLinks=nativeInsights.querySelector('.destination-native-internal-links');
        if(fullLinks){fullLinks.hidden=true;fullLinks.style.display='none';}
        compact.append(nativeInsights);
        dialog.dataset.previewModules='hero-about-insights';
      }else{
        dialog.dataset.previewModules='hero-about';
      }
      hero.after(compact);
    }else{
      dialog.dataset.previewModules='hero-only';
    }
    doc.querySelectorAll('form').forEach(el=>{el.inert=true;el.hidden=true;});
    // Suppress native inquiry CTAs within this information-only preview.
    // Discover the actual Elementor action from its native inquiry button,
    // rather than embedding a popup/template ID or modifying the source.
    const nativeInquiry=doc.querySelector('a[id^="tours_inquery_"][id$="_popup_button"]');
    if(nativeInquiry){
      const action=nativeInquiry.getAttribute('href');
      if(action){
        doc.querySelectorAll('a[href]').forEach(link=>{
          if(link.getAttribute('href')!==action)return;
          const widget=link.closest('.elementor-widget-button');
          if(widget)widget.hidden=true;
        });
      }
    }

    // Avoid secondary browsing inside the preview. The toolbar exposes
    // a deliberate full-page link. Preserve inline anchor/gallery controls.
    // Escape pressed while focus is INSIDE the same-origin iframe does not
    // reach the parent document's native dialog. Forward it explicitly,
    // unless Elementor is displaying its own nested gallery overlay.
    doc.addEventListener('keydown',event=>{
      if(event.key!=='Escape'||dialog.classList.contains('mad4b-destination-preview-gallery-open'))return;
      event.preventDefault();event.stopPropagation();dialog.close();
    },true);
    doc.addEventListener('click',event=>{
      if(event.defaultPrevented||event.button!==0||event.altKey||event.metaKey||
        event.ctrlKey||event.shiftKey)return;
      const a=event.target.closest?.('a[href]');
      if(!a||a.closest('.elementor-popup-modal,[data-elementor-open-lightbox]')||
        a.hasAttribute('data-elementor-open-lightbox'))return;
      const href=a.getAttribute('href')||'';
      if(!href||href.startsWith('#')||href.startsWith('javascript:'))return;
      let next;try{next=new URL(href,actual.href);}catch{return;}
      if(next.pathname===actual.pathname&&next.hash)return;
      event.preventDefault();event.stopImmediatePropagation();
    },true);

    let last=false,scheduled=false;
    const gallery=()=>{
      scheduled=false;
      if(!dialog.isConnected||!doc.body)return;
      const open=[...doc.querySelectorAll('.elementor-popup-modal')].some(n=>
        n.isConnected&&n.getAttribute('aria-hidden')!=='true'&&
        doc.defaultView.getComputedStyle(n).display!=='none');
      if(open!==last){last=open;dialog.classList.toggle('mad4b-destination-preview-gallery-open',open);}
    };
    const schedule=()=>{
      if(scheduled)return;scheduled=true;
      doc.defaultView.requestAnimationFrame(gallery);
    };
    const observer=new MutationObserver(schedule);
    observer.observe(doc.body,{childList:true,subtree:true,attributes:true,attributeFilter:['style','class','aria-hidden']});
    state.observer=observer;schedule();
  }

  function release(state){
    if(state.closed)return;
    state.closed=true;state.observer?.disconnect();
    state.frame?.removeAttribute('src');
    state.dialog.remove();
    document.documentElement.classList.remove('mad4b-destination-preview-active');
    if(active===state)active=null;
    try{state.trigger?.focus({preventScroll:true});}catch{}
  }

  function open(term,trigger){
    if(active){const previous=active;previous.dialog.close();release(previous);}
    const t=lang();
    const dialog=document.createElement('dialog');
    dialog.className='mad4b-destination-quick-preview-dialog';
    dialog.setAttribute('aria-label',t[0]+': '+term.name);
    const shell=document.createElement('div');shell.className='mad4b-destination-quick-preview-shell';
    const header=document.createElement('header');header.className='mad4b-destination-quick-preview-toolbar';
    const info=document.createElement('div');info.className='mad4b-destination-quick-preview-heading';
    const label=document.createElement('span');label.textContent=t[0];
    const title=document.createElement('strong');title.textContent=term.name;
    const caption=document.createElement('small');caption.textContent=t[1];
    info.append(label,title,caption);
    const full=document.createElement('a');full.className='mad4b-destination-quick-preview-full';
    full.href=term.url;full.target='_blank';full.rel='noopener noreferrer';full.textContent=t[2];
    full.setAttribute('aria-label',t[2]+': '+term.name);
    const exit=document.createElement('button');exit.type='button';
    exit.className='mad4b-destination-quick-preview-close';exit.textContent=t[3];
    header.append(info,full,exit);
    const content=document.createElement('div');content.className='mad4b-destination-quick-preview-content';
    const status=document.createElement('p');status.className='mad4b-destination-quick-preview-status';
    status.setAttribute('role','status');status.setAttribute('aria-live','polite');status.textContent=t[4];
    const frame=document.createElement('iframe');frame.title=t[0]+': '+term.name;
    frame.className='mad4b-destination-quick-preview-frame';frame.loading='eager';frame.referrerPolicy='same-origin';
    // Same-origin Elementor content. No form submission, top navigation or popups.
    frame.setAttribute('sandbox','allow-scripts allow-same-origin');
    frame.setAttribute('aria-busy','true');
    frame.style.visibility='hidden';
    content.append(status,frame);
    shell.append(header,content);dialog.append(shell);
    const state={dialog,frame,trigger,pathname:canonical(term.url),closed:false,observer:null};
    active=state;
    exit.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
    dialog.addEventListener('close',()=>release(state),{once:true});
    frame.addEventListener('load',()=>{
      if(state.closed)return;
      try{
        applyFrame(frame,dialog,state);
        status.hidden=true;frame.style.visibility='visible';
        frame.removeAttribute('aria-busy');dialog.dataset.status='ready';
      }catch{
        status.hidden=false;status.textContent=t[5];dialog.dataset.status='unavailable';
      }
    });
    document.body.append(dialog);
    document.documentElement.classList.add('mad4b-destination-preview-active');
    dialog.showModal();frame.src=term.url;exit.focus({preventScroll:true});
  }

  function find(event){
    if(event.defaultPrevented||event.button!==0||event.ctrlKey||event.altKey||
       event.metaKey||event.shiftKey)return null;
    const section=event.target.closest?.('.tour-journey-discovery');
    if(!section)return null;
    const item=event.target.closest('.tour-place-group .jd-native-place');
    const details=event.target.closest('.jd-place-tile__details');
    if(!item&&!details)return null;
    const link=event.target.closest('a[href]');
    if(event.target.closest('button,input,select,textarea')||
      link?.hasAttribute('download'))return null;
    let terms;try{terms=JSON.parse(section.dataset.tourDestinations||'[]');}catch{return null;}
    if(!Array.isArray(terms)||terms.length>500)return null;
    let url=link?.href;
    if(!url&&item){
      url=[...item.querySelectorAll('a[href]')].map(a=>a.href).find(h=>{
        const key=canonical(h);return key&&terms.some(t=>canonical(t.url)===key);
      });
    }
    const key=canonical(url);if(!key)return null;
    const term=terms.find(t=>t&&typeof t.url==='string'&&typeof t.name==='string'&&
       Number.isSafeInteger(Number(t.id))&&Number(t.id)>0&&canonical(t.url)===key);
    return term?{term,trigger:link||item}:null;
  }
  document.addEventListener('click',event=>{
    const hit=find(event);if(!hit)return;
    event.preventDefault();event.stopPropagation();
    open(hit.term,hit.trigger);
  },true);
})();
