(() => {
  'use strict';
  const names = {
    en: {heading:'Explore places connected to your tour',intro:'Browse related destinations and attractions. Visits depend on your itinerary.',attractions:'Attractions',zones:'Zones',cities:'Cities',regions:'Regions',countries:'Countries',view:'View layout',explorer:'Explorer',split:'Split',story:'Story',compact:'Compact',expand:'Details',collapse:'Close'},
    es: {heading:'Explora los lugares relacionados con tu viaje',intro:'Descubre destinos y atracciones relacionados. Las visitas dependen de tu itinerario.',attractions:'Atracciones',zones:'Zonas',cities:'Ciudades',regions:'Regiones',countries:'Países',view:'Cambiar diseño',explorer:'Explorar',split:'Dividido',story:'Historia',compact:'Compacto',expand:'Detalles',collapse:'Cerrar'},
    fr: {heading:'Explorez les lieux liés à votre voyage',intro:'Découvrez les destinations associées. Les visites dépendent du programme.',attractions:'Sites',zones:'Zones',cities:'Villes',regions:'Régions',countries:'Pays',view:'Affichage',explorer:'Explorer',split:'Deux colonnes',story:'Parcours',compact:'Compact',expand:'Détails',collapse:'Fermer'},
    de: {heading:'Entdecken Sie Orte rund um Ihre Reise',intro:'Entdecken Sie verknüpfte Reiseziele. Besuche hängen vom Reiseverlauf ab.',attractions:'Sehenswürdigkeiten',zones:'Gebiete',cities:'Städte',regions:'Regionen',countries:'Länder',view:'Ansicht',explorer:'Entdecken',split:'Geteilt',story:'Reiseverlauf',compact:'Kompakt',expand:'Details',collapse:'Schließen'},
    it: {heading:'Scopri i luoghi collegati al viaggio',intro:'Scopri le destinazioni associate. Le visite dipendono dal programma.',attractions:'Attrazioni',zones:'Zone',cities:'Città',regions:'Regioni',countries:'Paesi',view:'Layout',explorer:'Esplora',split:'Diviso',story:'Percorso',compact:'Compatto',expand:'Dettagli',collapse:'Chiudi'},
    ar: {heading:'اكتشف الأماكن المرتبطة برحلتك',intro:'تصفح الوجهات والمعالم المرتبطة. الزيارات الفعلية تعتمد على البرنامج.',attractions:'المعالم',zones:'المناطق',cities:'المدن',regions:'المحافظات',countries:'الدول',view:'نمط العرض',explorer:'استكشاف',split:'مقسم',story:'مسار',compact:'مختصر',expand:'التفاصيل',collapse:'إغلاق'}
  };
  const kinds=['attractions','zones','cities','regions','countries'];
  const patterns=['explorer','split','story','compact'];
  function textFor(){
    let lang=document.documentElement.lang?.toLowerCase().split('-')[0]||'en';
    return names[lang]||names.en;
  }
  function init(section){
    if(!section || section.dataset.tourJourneyInitialized==='1')return;
    const widget=section.querySelector('.tour-journey-related-editor, .elementor-element-6190bab');
    // Some Elementor dynamic fields deliberately emit no wrapper in places-only mode.
    if(!widget)return;
    const labels=textFor();
    // Read groups from the actual Elementor template. The class tags are the
    // primary contract; the DOM structure is a safe fallback for stale HTML caches.
    // This also allows additional JetEngine listing groups without a new JS release.
    const template=widget.querySelector('.elementor-template > .elementor[data-elementor-id]');
    const rootGroups=template
      ? Array.from(template.children).filter(n=>n.querySelector('.jet-listing-grid__items'))
      : Array.from(widget.querySelectorAll('.tour-place-group'));
    const groups=rootGroups.filter(g=>
      g.querySelectorAll('.jet-listing-grid__item').length>0 && getComputedStyle(g).display!=='none');
    if(!groups.length)return;
    const tourTitle=(document.querySelector('h1')?.textContent||'').trim();
    const available=groups.map((g,i)=>{
      let kind=kinds.find(k=>g.classList.contains('tour-place-'+k));
      if(!kind){
        // Fallback only for Elementor fragments saved before class tagging.
        // Preserve the original heading rather than guessing a destination type.
        kind='related-'+i;
        g.classList.add('tour-place-group','tour-place-related');
      }
      const raw=g.querySelector('.elementor-widget-heading .elementor-heading-title')?.textContent?.trim()||'';
      const derived=raw.startsWith(tourTitle)&&tourTitle ? raw.slice(tourTitle.length).trim() : raw;
      const label=labels[kind]||derived.slice(0,42)||labels.heading;
      return {node:g,kind,count:g.querySelectorAll('.jet-listing-grid__item').length,label};
    });
    if(!available.length)return;
    section.dataset.tourJourneyInitialized='1';
    section.classList.add('tour-journey-enhanced');

    const panel=document.createElement('div');
    panel.className='tour-journey-places-header';
    const intro=document.createElement('div');
    intro.className='tour-journey-places-intro';
    const heading=document.createElement('h3');
    heading.className='tour-journey-places-title';
    heading.textContent=labels.heading;
    const subtitle=document.createElement('p');
    subtitle.className='tour-journey-places-subtitle';
    subtitle.textContent=labels.intro;
    intro.append(heading,subtitle);

    const layout=document.createElement('label');
    layout.className='tour-journey-layout-label';
    const label=document.createElement('span');
    label.textContent=labels.view;
    const select=document.createElement('select');
    select.className='tour-journey-layout-select';
    select.setAttribute('aria-label',labels.view);
    patterns.forEach(p=>{
      const option=document.createElement('option');
      option.value=p;option.textContent=labels[p]||p;
      select.appendChild(option);
    });
    const fallback=section.dataset.tourJourneyMode;
    select.value=patterns.includes(fallback)?fallback:'explorer';
    layout.append(label,select);
    panel.append(intro,layout);

    const tabs=document.createElement('div');
    tabs.className='tour-journey-places-tabs';
    tabs.setAttribute('role','tablist');
    tabs.setAttribute('aria-label',labels.heading);
    const instance=section.getAttribute('data-id')||'tour';
    const buttons=[];
    available.forEach(({node,kind,count,label},i)=>{
      const btn=document.createElement('button');
      btn.type='button';btn.className='tour-journey-place-tab';
      btn.setAttribute('role','tab');btn.id='tour-place-tab-'+instance+'-'+kind;
      btn.setAttribute('aria-controls','tour-place-panel-'+instance+'-'+kind);
      btn.textContent=label;
      const badge=document.createElement('span');badge.className='tour-journey-place-count';badge.textContent=String(count);
      btn.appendChild(badge);
      node.id='tour-place-panel-'+instance+'-'+kind;
      node.setAttribute('role','tabpanel');
      node.setAttribute('aria-labelledby',btn.id);
      node.setAttribute('tabindex','0');
      buttons.push({btn,node,kind});
      tabs.appendChild(btn);
      btn.addEventListener('click',()=>activate(kind,true));
      btn.addEventListener('keydown',(evt)=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(evt.key))return;
        evt.preventDefault();
        const current=buttons.findIndex(b=>b.kind===kind);
        const target=evt.key==='Home'?0:evt.key==='End'?buttons.length-1:
          (current+(evt.key==='ArrowRight'?1:-1)+buttons.length)%buttons.length;
        buttons[target].btn.focus();activate(buttons[target].kind,false);
      });
    });
    function activate(kind,focus){
      buttons.forEach(({btn,node,kind:k})=>{
        const active=k===kind;
        btn.setAttribute('aria-selected',String(active));
        btn.tabIndex=active?0:-1;
        node.hidden=!active;
        node.dataset.tourActive=String(active);
        if(focus && active)btn.scrollIntoView({behavior:'smooth',block:'nearest',inline:'nearest'});
      });
    }
    // Only the existing Elementor template is moved; no listing is cloned or re-queried.
    const host=widget.querySelector('.elementor-widget-container')||widget;
    host.prepend(tabs);host.prepend(panel);
    widget.classList.add('tour-journey-related');
    activate(buttons[0].kind,false);

    // Compact mode is genuinely concise without discarding editorial text:
    // titles stay visible and each detailed description is independently expandable.
    section.querySelectorAll('.tour-hl-ui__card').forEach((card,i)=>{
      const title=card.querySelector('.tour-hl-ui__title');
      const desc=card.querySelector('.tour-hl-ui__description');
      const copy=card.querySelector('.tour-hl-ui__copy');
      if(!title || !desc || !copy || !desc.textContent.trim())return;
      const button=document.createElement('button');
      button.type='button';
      button.className='tour-hl-ui__expand';
      button.textContent=labels.expand||'Details';
      const descId='tour-hl-desc-'+instance+'-'+i;
      desc.id=descId;
      button.setAttribute('aria-controls',descId);
      button.setAttribute('aria-expanded','false');
      button.addEventListener('click',()=>{
        const expanded=card.classList.toggle('is-expanded');
        button.setAttribute('aria-expanded',String(expanded));
        button.textContent=expanded?(labels.collapse||'Close'):(labels.expand||'Details');
      });
      card.classList.add('is-collapsible');
      copy.appendChild(button);
    });

    select.addEventListener('change',()=>{
      if(!patterns.includes(select.value))return;
      patterns.forEach(mode=>section.classList.remove('tour-journey--'+mode));
      section.classList.add('tour-journey--'+select.value);
      section.dataset.tourJourneyMode=select.value;
    });
  }
  function scan(root=document){
    if(root.matches?.('.tour-journey-discovery'))init(root);
    root.querySelectorAll?.('.tour-journey-discovery').forEach(init);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>scan(),{once:true});
  else scan();
  window.addEventListener('elementor/frontend/init',()=>scan());
  document.addEventListener('jet-engine/listing-grid/after-load',()=>scan());
})();
