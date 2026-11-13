(() => {
  'use strict';
  const labelsByLang = {
    en: {heading:'Places connected to your journey', intro:'Explore destinations connected to this experience. Included visits depend on your itinerary.',attractions:'Attractions',zones:'Areas',cities:'Cities',regions:'Regions',countries:'Countries',view:'Explore this journey',explorer:'Explorer',split:'Split',story:'Story',linked:'LINKED PLACE',show:'Show place in journey',details:'View destination details',all:'All connected places',selected:'Place linked to your highlight',more:'Browse every place',step:'Highlight',jump:'Explore related places',noMatch:'Explore the related destinations'},
    es: {heading:'Lugares relacionados con tu viaje',intro:'Descubre los destinos vinculados. Las visitas dependen del itinerario.',attractions:'Atracciones',zones:'Zonas',cities:'Ciudades',regions:'Regiones',countries:'Países',view:'Explorar el viaje',explorer:'Explorar',split:'Dividido',story:'Historia',linked:'LUGAR VINCULADO',show:'Mostrar lugar relacionado',details:'Ver destino',all:'Todos los lugares',selected:'Lugar vinculado al momento destacado',more:'Ver todos los lugares',step:'Momento',jump:'Explorar lugares relacionados',noMatch:'Explorar destinos relacionados'},
    fr: {heading:'Lieux liés à votre voyage',intro:'Découvrez les destinations associées selon votre itinéraire.',attractions:'Sites',zones:'Zones',cities:'Villes',regions:'Régions',countries:'Pays',view:'Explorer le voyage',explorer:'Explorer',split:'Deux volets',story:'Récit',linked:'LIEU ASSOCIÉ',show:'Afficher le lieu',details:'Voir la destination',all:'Tous les lieux',selected:'Lieu lié à cette étape',more:'Tous les lieux',step:'Étape',jump:'Explorer les lieux',noMatch:'Découvrir les lieux associés'},
    de: {heading:'Orte Ihrer Reise',intro:'Entdecken Sie verknüpfte Ziele gemäß Ihrem Reiseverlauf.',attractions:'Sehenswürdigkeiten',zones:'Gebiete',cities:'Städte',regions:'Regionen',countries:'Länder',view:'Reise entdecken',explorer:'Entdecken',split:'Geteilt',story:'Geschichte',linked:'VERKNÜPFTER ORT',show:'Ort in der Reise anzeigen',details:'Ziel öffnen',all:'Alle Orte',selected:'Ort zu diesem Highlight',more:'Alle Orte ansehen',step:'Highlight',jump:'Orte entdecken',noMatch:'Verbundene Ziele entdecken'},
    it: {heading:'Luoghi collegati al viaggio',intro:'Scopri le destinazioni associate al tuo itinerario.',attractions:'Attrazioni',zones:'Zone',cities:'Città',regions:'Regioni',countries:'Paesi',view:'Esplora il viaggio',explorer:'Esplora',split:'Diviso',story:'Racconto',linked:'LUOGO COLLEGATO',show:'Mostra il luogo',details:'Apri la destinazione',all:'Tutti i luoghi',selected:'Luogo associato a questa tappa',more:'Tutti i luoghi',step:'Tappa',jump:'Esplora luoghi collegati',noMatch:'Esplora destinazioni collegate'},
    ar: {heading:'أماكن مرتبطة برحلتك',intro:'استكشف الوجهات المرتبطة بالتجربة. الزيارات الفعلية حسب البرنامج.',attractions:'المعالم',zones:'المناطق',cities:'المدن',regions:'المحافظات',countries:'الدول',view:'استكشف الرحلة',explorer:'استكشاف',split:'مقسم',story:'قصة الرحلة',linked:'مَعْلَم مُرْتَبِط',show:'عرض المعلم في الوجهات',details:'تفاصيل المعلم',all:'كل الأماكن المرتبطة',selected:'المعلم المرتبط بهذه الميزة',more:'استعراض كل الأماكن',step:'محطة',jump:'استكشف الأماكن المرتبطة',noMatch:'استكشف الوجهات المرتبطة'}
  };
  const modes=['explorer','split','story'];
  const kinds=['attractions','zones','cities','regions','countries'];
  const selectedText = (selector,root) => root.querySelector(selector)?.textContent?.trim() || '';
  function path(url){
    try {const u=new URL(url,document.baseURI);return u.origin+u.pathname.replace(/\/+$/,'/');}
    catch {return '';}
  }
  function safeImage(node){
    const inner=node?.querySelector(':scope > .elementor > .e-con');
    // JetEngine often injects a scoped <style> with per-term background URLs,
    // rather than an inline style. Read that already-rendered source without
    // querying the server or inventing a photo.
    const nativeStyle=[...node.querySelectorAll('style')].map(e=>e.textContent||'').join(' ');
    const candidates=[inner?.style.backgroundImage,inner && getComputedStyle(inner).backgroundImage,nativeStyle];
    for(const value of candidates){
      if(!value || value==='none')continue;
      const match=String(value).match(/url\(\s*["']?([^"'()]+)["']?\s*\)/i);
      if(!match)continue;
      try {const u=new URL(match[1],location.href);if(u.origin===location.origin && /^https?:$/.test(u.protocol))return u.href;}catch{}
    }
    return '';
  }
  function findNativeGroups(widget,tourTitle,L){
    const template=widget.querySelector('.elementor-template > .elementor[data-elementor-id]');
    const roots=template ? [...template.children].filter(n=>n.querySelector('.jet-listing-grid__items')) : [...widget.querySelectorAll('.tour-place-group')];
    return roots.filter(el=>el.querySelector('.jet-listing-grid__item') && getComputedStyle(el).display!=='none')
      .map((node,i)=>{
        let kind=kinds.find(k=>node.classList.contains('tour-place-'+k));
        if(!kind){kind='related-'+i;node.classList.add('tour-place-group','tour-place-related');}
        const raw=selectedText('.elementor-widget-heading .elementor-heading-title',node);
        const name=raw.startsWith(tourTitle)?raw.slice(tourTitle.length).trim():raw;
        const places=[...node.querySelectorAll('.jet-listing-grid__item')].map((item,j)=>{
          const anchor=item.querySelector('a[href^="http"]');
          if(!anchor)return null;
          const href=path(anchor.href);
          if(!href)return null;
          const termId=item.dataset.postId||item.dataset.termId||String(j);
          const id='tour-journey-place-'+(node.dataset.id||kind)+'-'+termId;
          item.id=id;
          item.classList.add('jd-native-place');
          return {item,id,href,name:anchor.textContent.trim(),photo:safeImage(item),kind};
        }).filter(Boolean);
        return {node,kind,name:L[kind]||name||L.all,places};
      }).filter(g=>g.places.length>0);
  }
  function init(section){
    if(!section || section.dataset.tourJourneyInitialized==='2')return;
    const widget=section.querySelector('.tour-journey-related-editor, .elementor-element-6190bab');
    if(!widget)return;
    const code=document.documentElement.lang?.split('-')[0]?.toLowerCase()||'en';
    const L=labelsByLang[code]||labelsByLang.en;
    const title=selectedText('h1',document);
    const groups=findNativeGroups(widget,title,L);
    if(!groups.length)return;
    const instance=section.dataset.id||'tour';
    section.dataset.tourJourneyInitialized='2';
    section.classList.add('tour-journey-enhanced','jd-v2');
    section.dataset.tourJourneyMode=section.dataset.tourJourneyMode==='compact'?'explorer':section.dataset.tourJourneyMode;
    section.classList.remove('tour-journey--compact');
    if(!modes.includes(section.dataset.tourJourneyMode))section.dataset.tourJourneyMode='explorer';
    modes.forEach(m=>section.classList.toggle('tour-journey--'+m,section.dataset.tourJourneyMode===m));

    const related=widget.querySelector('.elementor-widget-container')||widget;
    const head=document.createElement('div');head.className='tour-journey-places-header';
    const intro=document.createElement('div');intro.className='tour-journey-places-intro';
    const h=document.createElement('h3');h.className='tour-journey-places-title';h.textContent=L.heading;
    const desc=document.createElement('p');desc.className='tour-journey-places-subtitle';desc.textContent=L.intro;
    intro.append(h,desc);
    const switcher=document.createElement('label');switcher.className='tour-journey-layout-label';
    const switchTitle=document.createElement('span');switchTitle.textContent=L.view;
    const select=document.createElement('select');select.className='tour-journey-layout-select';select.setAttribute('aria-label',L.view);
    modes.forEach(m=>{const option=document.createElement('option');option.value=m;option.textContent=L[m];select.append(option);});
    select.value=section.dataset.tourJourneyMode;
    switcher.append(switchTitle,select);head.append(intro,switcher);
    const tabs=document.createElement('div');tabs.className='tour-journey-places-tabs';
    tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label',L.all);
    const status=document.createElement('p');status.className='jd-filter-status';status.setAttribute('aria-live','polite');
    status.textContent=L.more;
    const native=new Map();
    groups.forEach((g,i)=>{
      const tab=document.createElement('button');tab.type='button';tab.className='tour-journey-place-tab';
      tab.id='tour-place-tab-'+instance+'-'+g.kind;
      tab.setAttribute('role','tab');tab.setAttribute('aria-controls','tour-place-panel-'+instance+'-'+g.kind);
      tab.append(document.createTextNode(g.name+' '));
      const count=document.createElement('span');count.className='tour-journey-place-count';count.textContent=String(g.places.length);tab.append(count);
      g.tab=tab;tabs.append(tab);
      g.node.id='tour-place-panel-'+instance+'-'+g.kind;
      g.node.setAttribute('role','tabpanel');g.node.setAttribute('aria-labelledby',tab.id);g.node.tabIndex=0;
      g.places.forEach(p=>native.set(p.href,p));
      tab.addEventListener('click',()=>{setActiveTab(g.kind);clearSelection();});
      tab.addEventListener('keydown',e=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
        e.preventDefault();const dir=document.documentElement.dir==='rtl'?-1:1;
        const dest=e.key==='Home'?0:e.key==='End'?groups.length-1:(i+(e.key==='ArrowRight'?dir:-dir)+groups.length)%groups.length;
        groups[dest].tab.focus();setActiveTab(groups[dest].kind);clearSelection();
      });
    });
    const cards=[...section.querySelectorAll('.tour-hl-ui__card')];
    const activeMap=new Map();
    const statusText=(card)=>{
      const t=card?.querySelector('.tour-hl-ui__title')?.textContent?.trim()||'';
      return t ? L.selected+': '+t : L.more;
    };
    function setActiveTab(kind){
      groups.forEach(g=>{
        const on=g.kind===kind;
        g.node.hidden=!on;
        g.node.dataset.tourActive=String(on);
        g.tab.setAttribute('aria-selected',String(on));g.tab.tabIndex=on?0:-1;
      });
    }
    function clearFilter(){
      groups.forEach(g=>g.places.forEach(p=>{p.item.hidden=false;p.item.classList.remove('jd-place-highlight','jd-place-dimmed');}));
    }
    function clearSelection(){
      cards.forEach(c=>{c.classList.remove('jd-active-highlight');c.removeAttribute('aria-current');});
      clearFilter();
      status.textContent=L.more;
    }
    function selectHighlight(card,{navigate=false,place=null}={}){
      clearSelection();
      card.classList.add('jd-active-highlight');
      card.setAttribute('aria-current','step');
      const list=activeMap.get(card)||[];
      status.textContent=list.length ? statusText(card) : L.noMatch;
      const target=place||list[0]||null;
      if(target){
        setActiveTab(target.kind);
        if(section.dataset.tourJourneyMode==='split'){
          groups.forEach(g=>g.places.forEach(p=>{p.item.hidden=(g.kind===target.kind&&!list.some(t=>t.id===p.id));}));
        } else{
          list.forEach(p=>p.item.classList.add('jd-place-highlight'));
        }
      }
      if(navigate&&target){
        requestAnimationFrame(()=>{
          if(!target.item.isConnected)return;
          target.item.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'center',inline:'nearest'});
          target.item.tabIndex=-1;target.item.focus({preventScroll:true});
          const suffix='#'+encodeURIComponent(target.id);
          if(history.replaceState)history.replaceState(history.state,'',location.pathname+location.search+suffix);
        });
      }
    }
    cards.forEach((card,i)=>{
      card.dataset.jdStep=String(i+1);
      const links=[...card.querySelectorAll('.tour-hl-ui__related a[href]')];
      const found=[];
      links.forEach(link=>{
        const original=link.href,matched=native.get(path(original));
        const name=link.textContent.trim().replace(/^⌖\\s*/,'').trim();
        // A source-verified destination may not be present in this exact listing.
        // In that case it remains an ordinary destination link, never a fake anchor.
        if(!matched)return;
        const tile=document.createElement('div');tile.className='jd-place-tile';
        tile.dataset.placeId=matched.id;
        // Retain approved editorial evidence when the native term link becomes a visual tile.
        tile.dataset.tourEvidence=link.dataset.tourEvidence||'mentioned';
        const jump=document.createElement('a');jump.className='jd-place-tile__jump';
        jump.href='#'+matched.id;
        jump.setAttribute('aria-label',L.show+': '+matched.name);
        const media=document.createElement('span');media.className='jd-place-tile__media';
        if(matched.photo){
          const img=document.createElement('img');img.loading='lazy';img.decoding='async';img.src=matched.photo;img.alt='';
          media.append(img);
        }else{media.classList.add('jd-place-tile__media--empty');media.textContent='⌖';}
        const copy=document.createElement('span');copy.className='jd-place-tile__copy';
        const eyebrow=document.createElement('span');eyebrow.className='jd-place-tile__eyebrow';eyebrow.textContent=L.linked;
        const nameSpan=document.createElement('strong');nameSpan.className='jd-place-tile__name';nameSpan.textContent=name||matched.name;
        copy.append(eyebrow,nameSpan);
        const arrow=document.createElement('span');arrow.className='jd-place-tile__arrow';arrow.setAttribute('aria-hidden','true');arrow.textContent='↘';
        jump.append(media,copy,arrow);
        jump.addEventListener('click',e=>{if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();selectHighlight(card,{navigate:true,place:matched});});
        const details=document.createElement('a');details.className='jd-place-tile__details';details.href=original;
        details.title=L.details+': '+matched.name;details.setAttribute('aria-label',L.details+': '+matched.name);
        details.textContent='↗';
        tile.append(jump,details);
        link.replaceWith(tile);
        found.push(matched);
      });
      activeMap.set(card,found);
      if(found.length)card.classList.add('jd-has-place');
      // Selecting a highlight also updates the relationship panel in Split.
      card.tabIndex=0;
      card.addEventListener('click',e=>{if(e.target.closest('a,button,select,input'))return;selectHighlight(card);});
      card.addEventListener('keydown',e=>{
        if((e.key==='Enter'||e.key===' ')&&e.target===card){e.preventDefault();selectHighlight(card);}
      });
    });
    setActiveTab(groups[0].kind);
    related.prepend(status);related.prepend(tabs);related.prepend(head);
    widget.classList.add('tour-journey-related');

    const bridge=document.createElement('a');bridge.className='jd-bridge';
    bridge.href='#'+groups[0].node.id;
    bridge.innerHTML='<span class="jd-bridge__line" aria-hidden="true"></span><span class="jd-bridge__icon" aria-hidden="true">⌖</span><span class="jd-bridge__text"></span>';
    bridge.querySelector('.jd-bridge__text').textContent=L.jump;
    bridge.addEventListener('click',e=>{
      e.preventDefault();
      const target=groups.find(g=>g.node.dataset.tourActive==='true')||groups[0];
      head.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'start'});
      target.tab.focus({preventScroll:true});
    });
    widget.before(bridge);
    function applyMode(mode){
      if(!modes.includes(mode))mode='explorer';
      modes.forEach(m=>section.classList.toggle('tour-journey--'+m,m===mode));
      section.dataset.tourJourneyMode=mode;
      select.value=mode;
      clearFilter();
      const selected=section.querySelector('.tour-hl-ui__card.jd-active-highlight');
      if(selected)selectHighlight(selected);
      else if(mode==='split'){
        const firstLinked=cards.find(c=>(activeMap.get(c)||[]).length>0);
        if(firstLinked)selectHighlight(firstLinked);
      }
      if(!section.querySelector('.tour-hl-ui__card.jd-active-highlight'))status.textContent=L.more;
    }
    select.addEventListener('change',()=>applyMode(select.value));
    applyMode(select.value);
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
