(() => {
  'use strict';
  /* One progressive enhancement of the EXISTING Journey UI and JetEngine lists.
     Split, Explorer and Story share one approved taxonomy / evidence registry.
     Modes differ in presentation, never in terms, permissions or queries. */
  const order=['country','state_governorate','city','zone_area','place_attraction'];
  const groupKey={country:'countries',state_governorate:'regions',city:'cities',
    zone_area:'zones',place_attraction:'attractions'};
  const typeLabels={
    en:{country:'Countries',state_governorate:'Governorates',city:'Cities',zone_area:'Areas',place_attraction:'Attractions'},
    es:{country:'Países',state_governorate:'Gobernaciones',city:'Ciudades',zone_area:'Zonas',place_attraction:'Atracciones'},
    fr:{country:'Pays',state_governorate:'Régions',city:'Villes',zone_area:'Zones',place_attraction:'Sites'},
    de:{country:'Länder',state_governorate:'Regionen',city:'Städte',zone_area:'Gebiete',place_attraction:'Sehenswürdigkeiten'},
    it:{country:'Paesi',state_governorate:'Regioni',city:'Città',zone_area:'Zone',place_attraction:'Attrazioni'},
    ar:{country:'الدول',state_governorate:'المحافظات',city:'المدن',zone_area:'المناطق',place_attraction:'المعالم'}
  };
  const strings={
    en:{heading:'Explore this highlight',context:'Tour destination context',levels:'Destination levels',
      path:'Geographic hierarchy',direct:'Mentioned in this highlight',associated:'Related to the tour',
      note:'Related places are not necessarily included visits.',open:'Explore linked places',places:'places'},
    es:{heading:'Explora esta experiencia',context:'Contexto del viaje',levels:'Niveles de destinos',
      path:'Jerarquía geográfica',direct:'Mencionado en el destacado',associated:'Relacionado con el viaje',
      note:'Los destinos relacionados no siempre están incluidos.',open:'Explorar lugares vinculados',places:'lugares'},
    fr:{heading:'Explorez cette étape',context:'Géographie du voyage',levels:'Niveaux des destinations',
      path:'Hiérarchie géographique',direct:'Mentionné dans ce temps fort',associated:'Associé au voyage',
      note:'Les lieux associés ne sont pas toujours inclus.',open:'Explorer les lieux',places:'lieux'},
    de:{heading:'Dieses Highlight erkunden',context:'Reisekontext',levels:'Zielkategorien',
      path:'Geografische Ebenen',direct:'Im Highlight erwähnt',associated:'Mit der Reise verbunden',
      note:'Verbundene Orte sind nicht automatisch enthalten.',open:'Orte entdecken',places:'Orte'},
    it:{heading:'Esplora questa tappa',context:'Contesto del viaggio',levels:'Livelli delle destinazioni',
      path:'Gerarchia geografica',direct:'Citato nel momento',associated:'Collegato al viaggio',
      note:'I luoghi collegati non sono sempre inclusi.',open:'Esplora i luoghi',places:'luoghi'},
    ar:{heading:'استكشف هذه المحطة',context:'الوجهات المرتبطة بالرحلة',levels:'مستويات الوجهات',
      path:'التدرج الجغرافي',direct:'مذكور في الهايلايت',associated:'مرتبط بالرحلة',
      note:'ليس بالضرورة أن تكون كل الوجهات المرتبطة ضمن الزيارات المشمولة.',open:'استكشف الأماكن',places:'أماكن'}
  };
  const modesCopy={
    en:{browse:'Explore all tour places',levels:'Explore by destination type',
      story:'Places behind this highlight',storyContext:'Tour destination context',
      linked:'Mentioned in highlight',associated:'Other tour-related places',
      storyButton:'Explore linked places',contextButton:'Browse tour places',
      tourContext:'Tour-level context, not a confirmed visit',all:'All connected destinations'},
    es:{browse:'Explorar todos los destinos',levels:'Explorar por tipo de destino',
      story:'Lugares de este momento',storyContext:'Contexto del viaje',
      linked:'Mencionados en el destacado',associated:'Otros lugares relacionados',
      storyButton:'Ver lugares vinculados',contextButton:'Ver lugares del viaje',
      tourContext:'Contexto del viaje; no es una visita confirmada',all:'Todos los destinos vinculados'},
    fr:{browse:'Découvrir tous les lieux',levels:'Explorer par type de destination',
      story:'Lieux de ce temps fort',storyContext:'Contexte du voyage',
      linked:'Mentionné dans ce temps fort',associated:'Autres lieux du voyage',
      storyButton:'Voir les lieux liés',contextButton:'Voir les lieux du voyage',
      tourContext:'Contexte du voyage, pas une visite confirmée',all:'Toutes les destinations'},
    de:{browse:'Alle Reiseziele entdecken',levels:'Nach Reisezieltyp entdecken',
      story:'Orte dieses Highlights',storyContext:'Kontext der Reise',
      linked:'Im Highlight erwähnt',associated:'Weitere Reiseorte',
      storyButton:'Verknüpfte Orte ansehen',contextButton:'Reiseorte ansehen',
      tourContext:'Reisekontext, kein bestätigter Besuch',all:'Alle verbundenen Reiseziele'},
    it:{browse:'Esplora tutte le destinazioni',levels:'Esplora per tipo di destinazione',
      story:'Luoghi di questo momento',storyContext:'Contesto del viaggio',
      linked:'Citato in questo momento',associated:'Altri luoghi del viaggio',
      storyButton:'Esplora luoghi collegati',contextButton:'Esplora i luoghi del viaggio',
      tourContext:'Contesto del viaggio, non una visita confermata',all:'Tutte le destinazioni'},
    ar:{browse:'استكشف جميع وجهات الرحلة',levels:'استكشف حسب نوع الوجهة',
      story:'أماكن مرتبطة بهذه المحطة',storyContext:'سياق وجهات الرحلة',
      linked:'مذكورة في هذا العنصر',associated:'وجهات أخرى مرتبطة بالرحلة',
      storyButton:'عرض الأماكن المرتبطة',contextButton:'استكشف وجهات الرحلة',
      tourContext:'سياق جغرافي للرحلة، وليس زيارة مؤكدة',all:'كل الوجهات المرتبطة'}
  };
  function normalized(url){
    try{const u=new URL(url,document.baseURI);return u.origin+u.pathname.replace(/\/+$/,'/');}catch{return '';}
  }
  function init(section){
    if(section.dataset.jdSplitReady==='1'||section.dataset.tourJourneyInitialized!=='2')return;
    const widget=section.querySelector('.tour-journey-related-editor,.elementor-element-6190bab');
    const header=widget?.querySelector('.tour-journey-places-header');
    const originalTabs=widget?.querySelector('.tour-journey-places-tabs');
    if(!widget||!header||!originalTabs)return;
    let terms=[];
    try{const parsed=JSON.parse(section.dataset.tourDestinations||'[]');if(Array.isArray(parsed))terms=parsed;}catch{}
    terms=terms.filter(t=>t&&order.includes(t.kind)&&Number.isInteger(Number(t.id))
      &&typeof t.url==='string'&&t.url&&typeof t.name==='string');
    if(!terms.length)return;
    const lang=document.documentElement.lang?.split('-')[0]?.toLowerCase()||'en';
    const L=strings[lang]||strings.en;
    const TL=typeLabels[lang]||typeLabels.en;
    const M=modesCopy[lang]||modesCopy.en;
    const termMap=new Map(terms.map(t=>[Number(t.id),t]));
    const entries=[];
    const presentGroups=new Map();
    [...widget.querySelectorAll('.tour-place-group')].forEach(group=>{
      const kind=Object.values(groupKey).find(k=>group.classList.contains('tour-place-'+k));
      if(!kind)return;
      presentGroups.set(kind,group);
      group.querySelectorAll('.jet-listing-grid__item').forEach(item=>{
        const anchor=item.querySelector('a[href^="http"]');
        if(!anchor)return;
        const value=normalized(anchor.href);
        const term=terms.find(t=>normalized(t.url)===value);
        if(term)entries.push({term,item,group,kind,href:value});
      });
    });
    if(!entries.length)return;
    const byId=new Map(entries.map(e=>[Number(e.term.id),e]));
    const cards=[...section.querySelectorAll('.tour-hl-ui__card')];
    if(!cards.length)return;
    section.dataset.jdSplitReady='1';
    section.classList.add('jd-split-v3','jd-modes-v3');
    section.dataset.jdModesVersion='3';
    const panel=document.createElement('section');panel.className='jd-split-v3__panel jd-modes-v3__panel';
    panel.hidden=true;
    const kicker=document.createElement('span');kicker.className='jd-split-v3__kicker';
    const h=document.createElement('h4');h.className='jd-split-v3__heading';
    const path=document.createElement('nav');path.className='jd-split-v3__path';
    path.setAttribute('aria-label',L.path);
    const levels=document.createElement('nav');levels.className='jd-split-v3__levels';
    levels.setAttribute('aria-label',L.levels);
    const summary=document.createElement('p');summary.className='jd-split-v3__summary';
    summary.setAttribute('aria-live','polite');
    const note=document.createElement('p');note.className='jd-split-v3__note';note.textContent=L.note;
    panel.append(kicker,h,path,levels,summary,note);
    header.after(panel);
    let selected=null,model=null,currentKind=null;
    const anc=term=>{
      const chain=[];let current=term;const seen=new Set();
      while(current&&!seen.has(Number(current.id))){
        seen.add(Number(current.id));chain.unshift(current);
        current=termMap.get(Number(current.parent))||null;
      }
      return chain;
    };
    function mapped(card){
      const direct=new Map();
      card.querySelectorAll('.jd-place-tile').forEach(tile=>{
        const href=normalized(tile.querySelector('.jd-place-tile__details')?.href||'');
        if(!href)return;
        const item=entries.find(e=>e.href===href);
        if(item)direct.set(Number(item.term.id),tile.dataset.tourEvidence||'mentioned');
      });
      const related=new Map();
      function add(entry,evidence){
        if(!entry)return;
        const key=Number(entry.term.id);
        if(!related.has(key))related.set(key,{...entry,evidence});
        else if(evidence==='mentioned')related.get(key).evidence='mentioned';
      }
      const referenced=entries.filter(e=>direct.has(Number(e.term.id)));
      if(!referenced.length){
        // Every Highlight can explore the Tour's geo-context.
        // Never mislabel these as places asserted by its text.
        entries.forEach(e=>add(e,'associated'));
      }else{
        referenced.forEach(e=>add(e,direct.get(Number(e.term.id))));
        const parents=new Set(referenced.filter(e=>e.term.kind==='place_attraction')
          .map(e=>Number(e.term.parent)).filter(Boolean));
        entries.filter(e=>e.term.kind==='place_attraction'&&parents.has(Number(e.term.parent)))
          .forEach(e=>add(e,direct.has(Number(e.term.id))?'mentioned':'associated'));
        [...related.values()].forEach(e=>anc(e.term).forEach(t=>add(byId.get(Number(t.id)),'associated')));
      }
      return {
        related:[...related.values()].sort((a,b)=>order.indexOf(a.term.kind)-order.indexOf(b.term.kind)),
        sourced:referenced.length>0
      };
    }
    const mentionedAcrossTour=new Set();
    cards.forEach(card=>card.querySelectorAll('.jd-place-tile__details[href]').forEach(link=>{
      const href=normalized(link.href);
      const entry=entries.find(e=>e.href===href);
      if(entry)mentionedAcrossTour.add(Number(entry.term.id));
    }));
    function exploreModel(){
      return {sourced:mentionedAcrossTour.size>0,related:entries.map(entry=>({
        ...entry,evidence:mentionedAcrossTour.has(Number(entry.term.id))?'mentioned':'associated'
      })).sort((a,b)=>order.indexOf(a.term.kind)-order.indexOf(b.term.kind))};
    }
    // The hierarchy remains read-only; category and evidence always originate
    // from server-approved Term + JetEngine listing data (no second query).
    function commonAncestors(related,kind){
      const chains=related.map(e=>anc(e.term));
      const first=chains[0]||[];
      const shared=[];
      for(let i=0;i<first.length;i++){
        if(chains.every(chain=>Number(chain[i]?.id)===Number(first[i].id)))shared.push(first[i]);
        else break;
      }
      return related.length===1&&kind!=='place_attraction'?shared:
        shared.filter(t=>t.kind!=='place_attraction');
    }
    const storyPointers=new Map();
    function ensureStoryPointers(){
      cards.forEach((card,i)=>{
        let pointer=storyPointers.get(card);
        if(pointer)return;
        pointer=document.createElement('div');
        pointer.className='jd-modes-v3__story-pointer';
        const flag=document.createElement('span');flag.className='jd-modes-v3__story-flag';
        flag.textContent=String(i+1).padStart(2,'0')+' · '+M.story;
        const meta=document.createElement('span');meta.className='jd-modes-v3__story-meta';
        const action=document.createElement('button');action.type='button';
        action.className='jd-modes-v3__story-action';
        action.addEventListener('click',()=>{
          card.click(); // existing Journey controller updates canonical selection first
          requestAnimationFrame(()=>requestAnimationFrame(()=>{
            header.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
            levels.querySelector('button.is-current')?.focus({preventScroll:true});
            const target=[...presentGroups.values()].find(g=>g.dataset.tourActive==='true');
            if(target?.id&&history.replaceState)
              history.replaceState(history.state,'',location.pathname+location.search+'#'+encodeURIComponent(target.id));
          }));
        });
        pointer.append(flag,meta,action);
        card.append(pointer);
        storyPointers.set(card,pointer);
      });
    }
    function updateStoryPointers(active){
      ensureStoryPointers();
      cards.forEach(card=>{
        const pointer=storyPointers.get(card);
        const data=mapped(card);
        const direct=data.related.filter(e=>e.evidence==='mentioned').length;
        const meta=pointer.querySelector('.jd-modes-v3__story-meta');
        const action=pointer.querySelector('button');
        // Never label broad tour geography as a visit asserted by a Highlight.
        meta.textContent=direct
          ?String(direct)+' '+M.linked+' · '+String(data.related.length-direct)+' '+M.associated
          :M.tourContext;
        action.textContent=direct?M.storyButton:M.contextButton;
        pointer.classList.toggle('is-current',card===active);
        pointer.classList.toggle('is-context-only',direct===0);
        pointer.hidden=section.dataset.tourJourneyMode!=='story';
      });
    }
    function activeOriginal(group){
      [...presentGroups.values()].forEach(g=>{
        const yes=g===group;
        g.hidden=!yes;g.dataset.tourActive=String(yes);
      });
      [...originalTabs.querySelectorAll('.tour-journey-place-tab')].forEach(btn=>{
        const yes=btn.getAttribute('aria-controls')===group.id;
        btn.setAttribute('aria-selected',String(yes));btn.tabIndex=yes?0:-1;
      });
    }
    function showKind(kind){
      const related=model.related.filter(e=>e.term.kind===kind);
      if(!related.length)return;
      const group=presentGroups.get(groupKey[kind]);
      if(!group)return;
      currentKind=kind;
      activeOriginal(group);
      const allowed=new Set(related.map(e=>e.item));
      entries.forEach(e=>{
        const visible=e.group===group&&allowed.has(e.item);
        e.item.hidden=!visible;
        e.item.classList.toggle('jd-split-v3__mentioned',visible&&related.some(x=>x.item===e.item&&x.evidence==='mentioned'));
        e.item.classList.toggle('jd-split-v3__associated',visible&&related.some(x=>x.item===e.item&&x.evidence!=='mentioned'));
      });
      levels.querySelectorAll('button').forEach(b=>{
        const on=b.dataset.kind===kind;b.classList.toggle('is-current',on);
        b.setAttribute('aria-pressed',String(on));
      });
      const direct=related.filter(e=>e.evidence==='mentioned').length;
      // Breadcrumb reflects the active taxonomy level, not a single arbitrary
      // attraction when multiple sibling attractions belong to one highlight.
      path.replaceChildren();
      const context=commonAncestors(related,kind);
      context.forEach((term,i)=>{
        if(i){
          const sep=document.createElement('span');sep.textContent='›';
          sep.setAttribute('aria-hidden','true');path.append(sep);
        }
        const inModel=model.related.some(e=>Number(e.term.id)===Number(term.id));
        const canDrill=inModel&&presentGroups.has(groupKey[term.kind])&&term.kind!==kind;
        const crumb=document.createElement(canDrill?'button':'span');
        crumb.className='jd-split-v3__crumb';
        crumb.textContent=term.name;
        if(canDrill){
          crumb.type='button';crumb.classList.add('jd-modes-v3__crumb-button');
          crumb.addEventListener('click',()=>showKind(term.kind));
        }
        path.append(crumb);
      });
      if(related.length>1||kind==='place_attraction'){
        if(path.children.length){
          const sep=document.createElement('span');sep.textContent='›';
          sep.setAttribute('aria-hidden','true');path.append(sep);
        }
        const crumb=document.createElement('span');
        crumb.className='jd-split-v3__crumb jd-split-v3__crumb--current';
        crumb.textContent=(TL[kind]||L.places)+' ('+related.length+')';
        path.append(crumb);
      }
      path.hidden=!path.children.length;
      const mode=section.dataset.tourJourneyMode;
      const matchedNames=related.filter(e=>e.evidence==='mentioned').map(e=>e.term.name);
      summary.textContent=String(related.length)+' '+(TL[kind]||L.places)
        +' · '+direct+' '+(mode==='split'?L.direct:M.linked)
        +' · '+String(related.length-direct)+' '+(mode==='split'?L.associated:M.associated)
        +(mode==='split'&&matchedNames.length?' · '+matchedNames.join(', '):'');
      panel.dataset.jdContextMode=mode;
      section.dataset.jdActiveDestinationLevel=kind;
      section.dataset.jdSplitLevel=kind;
    }
    function display(card,preferred=null,mode='split'){
      selected=card;
      model=mode==='explorer'?exploreModel():mapped(card);
      if(!model.related.length){panel.hidden=true;return;}
      panel.hidden=false;
      kicker.textContent=mode==='explorer'?M.levels:
        mode==='story'?(model.sourced?M.story:M.storyContext):(model.sourced?L.heading:L.context);
      h.textContent=mode==='explorer'?M.browse:
        (card.querySelector('.tour-hl-ui__title')?.textContent?.trim()||L.context);
      levels.replaceChildren();
      const available=order.filter(k=>model.related.some(e=>e.term.kind===k)
        &&presentGroups.has(groupKey[k]));
      available.forEach(k=>{
        const group=presentGroups.get(groupKey[k]);
        const n=model.related.filter(e=>e.term.kind===k).length;
        const button=document.createElement('button');button.type='button';
        button.dataset.kind=k;button.className='jd-split-v3__level';
        const text=document.createElement('span');text.textContent=TL[k]||k;
        const badge=document.createElement('strong');badge.textContent=String(n);
        button.append(text,badge);
        button.setAttribute('aria-pressed','false');
        button.addEventListener('click',()=>showKind(k));
        levels.append(button);
      });
      const next=available.includes(preferred)?preferred:
        available.includes('place_attraction')&&model.sourced?'place_attraction':
          available.includes('state_governorate')?'state_governorate':available[0];
      if(next)showKind(next);
      if(mode==='split'){
      let inline=card.querySelector('.jd-split-v3__inline');
      if(!inline){
        inline=document.createElement('div');inline.className='jd-split-v3__inline';
        const count=document.createElement('span');count.className='jd-split-v3__inline-count';
        const go=document.createElement('button');go.type='button';go.textContent=L.open;
        go.addEventListener('click',()=>{
          header.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
          levels.querySelector('button.is-current')?.focus({preventScroll:true});
        });
        inline.append(count,go);card.append(inline);
      }
      inline.querySelector('.jd-split-v3__inline-count').textContent=String(model.related.length)+' '+L.places;
      cards.forEach(c=>{if(c!==card)c.querySelector('.jd-split-v3__inline')?.remove();});
      }
      if(mode==='story')updateStoryPointers(card);
    }
    function cleanup(){
      panel.hidden=true;
      cards.forEach(c=>c.querySelector('.jd-split-v3__inline')?.remove());
      entries.forEach(e=>{
        e.item.hidden=false;
        e.item.classList.remove('jd-split-v3__mentioned','jd-split-v3__associated');
      });
      storyPointers.forEach(p=>{p.hidden=true;});
    }
    function refresh(){
      const mode=section.dataset.tourJourneyMode;
      if(!['split','explorer','story'].includes(mode)){cleanup();return;}
      const card=section.querySelector('.tour-hl-ui__card.jd-active-highlight')||cards[0];
      if(!card){cleanup();return;}
      const old=currentKind;
      if(mode!=='split'){
        cards.forEach(c=>c.querySelector('.jd-split-v3__inline')?.remove());
      }
      if(mode!=='story')storyPointers.forEach(p=>{p.hidden=true;});
      display(card,old,mode);
    }
    // The original Journey script processes clicks and mode changes first.
    // The Split-specific renderer reads the resulting active item on the next frame.
    let scheduled=false;
    const queue=()=>{
      if(scheduled)return;scheduled=true;
      requestAnimationFrame(()=>{scheduled=false;refresh();});
    };
    section.addEventListener('click',event=>{
      if(event.target.closest('.tour-hl-ui__card'))queue();
    });
    section.addEventListener('keydown',event=>{
      if((event.key==='Enter'||event.key===' ')&&event.target.closest('.tour-hl-ui__card'))queue();
    });
    widget.querySelector('.tour-journey-layout-select')?.addEventListener('change',queue);
    queue();
  }
  function scan(){
    document.querySelectorAll('.tour-journey-discovery').forEach(init);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan,{once:true});
  else scan();
  window.addEventListener('elementor/frontend/init',scan);
})();
