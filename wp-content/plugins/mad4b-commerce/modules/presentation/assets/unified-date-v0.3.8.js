/* MAD4B Unified Date V1: Flatpickr, JetSearch/JetSmartFilters and Fluent Forms bridges. */
(() => {
  'use strict';
  const cfg = window.MAD4BUnifiedDateV1;
  if (!cfg || typeof window.flatpickr !== 'function') return;
  const current = cfg.request?.status === 'valid' ? cfg.request : {};
  const state = {mode: current.mode === 'fixed' ? 'fixed' : 'flex', from: current.from || '', to: current.to || ''};
  const instances = [];
  const cloneIntent=x=>({mode:x.mode==='fixed'?'fixed':'flex',from:x.from||'',to:x.mode==='flex'?(x.to||''):''});
  const sameIntent=(a,b)=>a.mode===b.mode&&a.from===b.from&&a.to===b.to;
  let stagedRateIntent=null;
  let fluentSyncDepth=0,fluentReverseTimer=0,formReverseApplying=false;
  // Elementor clones archive filter templates into the popup. DOM datasets
  // are copied, but event listeners are not. Track real initialized nodes.
  const initializedRoots = new WeakSet();
  const addDays = (day, count) => {
    const date = new Date(day + 'T12:00:00Z');
    date.setUTCDate(date.getUTCDate() + count);
    return date.toISOString().slice(0, 10);
  };
  const minDate = addDays(cfg.today, cfg.policy.min_lead_days || 0);
  const maxDate = addDays(cfg.today, cfg.policy.max_advance_days || 730);
  const iso = d => [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
  const dateLabel = s => {
    if (!s) return '';
    const [y,m,d] = s.split('-').map(Number);
    return new Intl.DateTimeFormat(document.documentElement.lang || 'en', {day:'numeric',month:'short',year:'numeric'}).format(new Date(y,m-1,d));
  };
  const validIntent = value => Boolean(value.from) && value.from>=minDate && value.from<=maxDate &&
    (value.mode === 'fixed' ||
    (Boolean(value.to) && value.to>=minDate && value.to<=maxDate &&
      value.to >= addDays(value.from,cfg.policy.min_flex_gap_days || 0) &&
      (new Date(value.to)-new Date(value.from))/86400000 <= cfg.policy.max_flex_days));
  const valid = () => validIntent(state);
  function params(url,intent=state) {
    ['mad4b_date_mode','mad4b_date_from','mad4b_date_to','travel_date',
      '_meta_query_advanced_date__any__tour_rate_start_date__tour_rate_end_date|date']
      .forEach(k=>url.searchParams.delete(k));
    if (validIntent(intent)) {
      url.searchParams.set('mad4b_date_mode',intent.mode);
      url.searchParams.set('mad4b_date_from',intent.from);
      if (intent.mode === 'flex') url.searchParams.set('mad4b_date_to',intent.to);
      // Only a fixed day can be inherited as a booking/Last Minute date.
      else url.searchParams.set('travel_date',intent.from);
    }
    return url;
  }
  // The URL is the durable Date Intent across reloads, currencies and WPML.
  // JetSmartFilters receives the same contract inside its native AJAX query.
  const intentKeys=['mad4b_date_mode','mad4b_date_from','mad4b_date_to'];
  const currencyHandoffKey='mad4b:date:currency-handoff';
  const messages={
    en:{apply:'Apply',applying:'Updating rates…',use:'Use Dates'},
    es:{apply:'Aplicar',applying:'Actualizando tarifas…',use:'Usar fechas'},
    fr:{apply:'Appliquer',applying:'Mise à jour des tarifs…',use:'Choisir les dates'},
    de:{apply:'Anwenden',applying:'Tarife werden aktualisiert…',use:'Daten verwenden'},
    it:{apply:'Applica',applying:'Aggiornamento tariffe…',use:'Usa le date'},
    ar:{apply:'تطبيق',applying:'جارٍ تحديث الأسعار…',use:'اعتماد التواريخ'}
  };
  const lang=String(document.documentElement.lang||'en').slice(0,2);
  const words=messages[lang]||messages.en;
  let ajaxPending=false;
  // User-initiated revisions survive slow AJAX. Only the latest revision
  // may write back to the inquiry form.
  let formRevision=0;
  function markFormDatePending(pending){
    document.querySelectorAll('form.fluent_form_6').forEach(form=>{
      if(pending)form.dataset.mad4bDatePending='1';
      else delete form.dataset.mad4bDatePending;
    });
  }
  const sync = () => instances.forEach(x => {if(x.root.isConnected)x.render();});
  const copyToState=intent=>{Object.assign(state,cloneIntent(intent));};
  function rateDraftLabel(intent){
    if(!intent||sameIntent(intent,state))return '';
    return intent.from?(intent.mode==='flex'&&intent.to
      ?' · '+dateLabel(intent.from)+' – '+dateLabel(intent.to)
      :' · '+dateLabel(intent.from)):'';
  }
  const dateNotices={
    en:{pending:'Dates pending. Select Apply to update available Rates and your inquiry form.',
      invalid:'Select a valid end date to apply your travel period.',
      from:'Select an arrival date before submitting.',
      to:'Select a valid end date for Flex Date before submitting.',
      range:'The selected travel date is outside the allowed booking window.',
      submit:'Complete and apply valid travel dates before submitting your inquiry.'},
    es:{pending:'Fechas pendientes. Pulsa Aplicar para actualizar las tarifas y el formulario.',
      invalid:'Selecciona una fecha final válida para aplicar el período.',
      from:'Selecciona la fecha de llegada antes de enviar.',
      to:'Selecciona una fecha final válida para las fechas flexibles.',
      range:'Las fechas están fuera del período permitido.',
      submit:'Completa y aplica las fechas válidas antes de enviar.'},
    fr:{pending:'Dates en attente. Cliquez sur Appliquer pour mettre à jour les tarifs et le formulaire.',
      invalid:'Sélectionnez une date de fin valide pour appliquer la période.',
      from:"Sélectionnez une date d'arrivée avant l'envoi.",
      to:'Sélectionnez une date de fin valide pour les dates flexibles.',
      range:'Les dates dépassent la période autorisée.',
      submit:"Complétez et appliquez les dates avant l'envoi."},
    de:{pending:'Daten vorgemerkt. Anwenden aktualisiert Tarife und Anfrageformular.',
      invalid:'Bitte ein gültiges Enddatum wählen.',
      from:'Bitte vor dem Absenden ein Anreisedatum wählen.',
      to:'Bitte ein gültiges Enddatum für flexible Daten wählen.',
      range:'Das Datum liegt außerhalb des zulässigen Zeitraums.',
      submit:'Vor dem Absenden gültige Reisedaten anwenden.'},
    it:{pending:'Date in sospeso. Premi Applica per aggiornare tariffe e modulo.',
      invalid:'Seleziona una data finale valida.',
      from:"Seleziona una data d'arrivo prima di inviare.",
      to:'Seleziona una data finale valida per le date flessibili.',
      range:'Le date sono fuori dal periodo consentito.',
      submit:'Completa e applica le date prima di inviare.'},
    ar:{pending:'التواريخ معلقة. اضغط تطبيق لتحديث الأسعار ونموذج الاستفسار.',
      invalid:'اختر تاريخ نهاية صحيحًا لتطبيق فترة السفر.',
      from:'اختر تاريخ الوصول قبل إرسال الاستفسار.',
      to:'اختر تاريخ نهاية صحيحًا للفترة المرنة.',
      range:'تاريخ السفر خارج الفترة المسموح بها.',
      submit:'أكمل تواريخ السفر الصحيحة وطبقها قبل الإرسال.'}
  };
  const noticeWords=dateNotices[lang]||dateNotices.en;
  function rateDraftNote(){
    const note=document.querySelector('.mad4b-ud-rate-note');
    const apply=document.querySelector('.mad4b-ud-rate-apply');
    if(note){
      let message;
      if(stagedRateIntent && !validIntent(stagedRateIntent) && stagedRateIntent.from)
        message=noticeWords.invalid;
      else if(stagedRateIntent&&!sameIntent(stagedRateIntent,state))
        message=noticeWords.pending;
      else
        message=valid()
          ?(note.dataset.mad4bValidMessage||'Rate periods only · Departure and availability are not confirmed')
          :(note.dataset.mad4bEmptyMessage||'Select travel dates to narrow the available rate periods');
      // Avoid an endless MutationObserver -> mount() -> text replacement loop.
      if(note.textContent!==message)note.textContent=message;
    }
    if(apply){
      const disabled=ajaxPending||Boolean(stagedRateIntent&&stagedRateIntent.from&&!validIntent(stagedRateIntent));
      if(apply.disabled!==disabled)apply.disabled=disabled;
    }
  }

  function rewriteContextLinks(){
    const selectedCurrency=new URL(location.href).searchParams.get('wcmlc')
      ||[...document.querySelectorAll('select.pricing-currency-select')]
        .find(el=>!!el.getClientRects().length)?.value;
    document.querySelectorAll('a.wpml-ls-link, a.wpml-ls-item-toggle').forEach(anchor=>{
      const href=anchor.getAttribute('href');
      if(!href||href.startsWith('#'))return;
      try{
        const u=new URL(href,location.href);
        if(u.origin!==location.origin||!u.pathname.includes('/tours-and-activities/'))return;
        const updatedUrl=params(u);
        if(/^(USD|EUR|EGP|GBP)$/.test(String(selectedCurrency||'')))
          updatedUrl.searchParams.set('wcmlc',selectedCurrency);
        const updated=updatedUrl.toString();
        if(anchor.href!==updated)anchor.href=updated;
      }catch(e){}
    });
  }
  function rememberCurrencyHandoff(){
    try{
      sessionStorage.setItem(currencyHandoffKey,JSON.stringify({
        path:location.pathname,at:Date.now(),
        mode:valid()?state.mode:null,from:valid()?state.from:null,
        to:valid()&&state.mode==='flex'?state.to:null
      }));
    }catch(e){}
  }
  function restoreCurrencyHandoff(){
    if(new URL(location.href).searchParams.has('mad4b_date_mode'))return false;
    try{
      const raw=sessionStorage.getItem(currencyHandoffKey);
      if(!raw)return false;
      sessionStorage.removeItem(currencyHandoffKey);
      const saved=JSON.parse(raw);
      if(saved.path!==location.pathname||Date.now()-saved.at>120000||
        !saved.mode||!saved.from)return false;
      const u=new URL(location.href);
      u.searchParams.set('mad4b_date_mode',saved.mode);
      u.searchParams.set('mad4b_date_from',saved.from);
      if(saved.mode==='flex'&&saved.to)u.searchParams.set('mad4b_date_to',saved.to);
      location.replace(u.toString());
      return true;
    }catch(e){return false;}
  }
  document.addEventListener('change',e=>{
    if(e.target.closest('select.pricing-currency-select,select[name="currency"],select[name="wcml_currency_switcher"]'))
      rememberCurrencyHandoff();
  },true);
  document.addEventListener('click',e=>{
    const anchor=e.target.closest('a');
    if(!anchor)return;
    if(anchor.matches('.wpml-ls-link,.wpml-ls-item-toggle'))rewriteContextLinks();
    if(/currency|wcml/i.test(String(anchor.className)))rememberCurrencyHandoff();
  },true);
  function nativeAjaxPrefilter(){
    const jq=window.jQuery;
    if(!jq?.ajaxPrefilter)return;
    jq.ajaxPrefilter(opts=>{
      if(typeof opts.data!=='string'||!String(opts.url||'').includes('admin-ajax.php'))return;
      const payload=new URLSearchParams(opts.data);
      if(payload.get('action')!=='jet_smart_filters')return;
      if(!['jet-engine/tour_rates_accordion','jet-engine/tours_query_archive'].includes(payload.get('provider')))return;
      for(const key of [...intentKeys,'mad4b_date_clear'])payload.delete('query['+key+']');
      const intent=ajaxPending&&pendingAjaxIntent?pendingAjaxIntent:state;
      if(validIntent(intent)){
        payload.set('query[mad4b_date_mode]',intent.mode);
        payload.set('query[mad4b_date_from]',intent.from);
        if(intent.mode==='flex')payload.set('query[mad4b_date_to]',intent.to);
      }else payload.set('query[mad4b_date_clear]','1');
      const select=document.querySelector('.elementor-location-header .pricing-currency-select')
        ||document.querySelector('select.pricing-currency-select');
      const currency=select?.value||new URL(location.href).searchParams.get('wcmlc');
      if(/^(USD|EUR|EGP|GBP)$/.test(String(currency||'')))
        payload.set('query[mad4b_currency]',currency);
      opts.data=payload.toString();
    });
  }
  let pendingAjaxIntent=null;
  function applyDateIntent(context,intentCandidate=null){
    if(ajaxPending)return;
    const candidate=cloneIntent(intentCandidate||state);
    if(candidate.from&&!validIntent(candidate))return;
    const next=params(new URL(location.href),candidate);
    const provider=context==='rates'?'jet-engine/tour_rates_accordion':'jet-engine/tours_query_archive';
    const group=window.JetSmartFilters?.filterGroups?.[provider];
    if(!group||!window.jQuery){
      location.assign(next.toString());return;
    }
    ajaxPending=true;
    pendingAjaxIntent=candidate;
    const revisionAtStart=formRevision;
    if(context==='rates')markFormDatePending(true);
    rateDraftNote();
    const buttons=[...document.querySelectorAll('.mad4b-ud-rate-apply')];
    buttons.forEach(b=>{b.disabled=true;b.classList.add('is-loading');b.textContent=words.applying;});
    let finished=false;
    let timeout;
    const complete=()=>{
      if(finished)return;
      finished=true;
      clearTimeout(timeout);
      ajaxPending=false;
      pendingAjaxIntent=null;
      copyToState(candidate);
      const changedDuringRequest=context==='rates'&&formRevision!==revisionAtStart;
      if(context==='rates'&&!changedDuringRequest)stagedRateIntent=null;
      cfg.request=valid()?{status:'valid',mode:state.mode,from:state.from,to:state.to}:{status:'absent'};
      buttons.forEach(b=>{b.disabled=false;b.classList.remove('is-loading');b.textContent=words.apply;});
      sync();
      syncLegacy('apply');
      if(context==='rates'){
        if(!changedDuringRequest){
          fluent(true);
          markFormDatePending(false);
          document.querySelectorAll('form.fluent_form_6').forEach(form=>setFluentDateReview(form,''));
        }else{
          // The form has newer values; never overwrite them from stale readback.
          const form=document.querySelector('form.fluent_form_6');
          if(form)setTimeout(()=>onFluentDateChange(form),0);
        }
        [80,300].forEach(ms=>setTimeout(()=>{
          syncRateActiveFilters();
          reconcileRatePresentationCount();
          clearInvalidRateSelection();
        },ms));
      }
      rateDraftNote();
      rewriteContextLinks();
      if(context==='archive')preserveTourLinks();
    };
    const fallback=()=>{
      if(finished)return;
      // URL contains the requested intent; a fresh page may serve it natively.
      pendingAjaxIntent=null;
      ajaxPending=false;
      location.assign(next.toString());
    };
    timeout=setTimeout(fallback,40000);
    try{
      history.replaceState(history.state,'',next.toString());
      group.baseUrlParams=next.search;
      if(context==='rates'){
        const native=group.filters.find(f=>Number(f.filterId)===46259);
        if(!native?.$dateRangeInput?.length){fallback();return;}
        const dateValue=isoDate=>isoDate.replaceAll('-','.');
        const encoded=validIntent(candidate)
          ?dateValue(candidate.from)+'-'+dateValue(candidate.mode==='flex'?candidate.to:candidate.from):'';
        native.$dateRangeInput.val(encoded);
        // Subscribe before dispatch: quick cached JSF responses can complete synchronously.
        const jq=window.jQuery;
        const listener=(_event,xhr,settings)=>{
          const body=typeof settings.data==='string'
            ?new URLSearchParams(settings.data):null;
          if(body?.get('action')!=='jet_smart_filters'||body.get('provider')!==provider)return;
          // Only the intended Date Intent may complete this transaction.
          const from=body.get('query[mad4b_date_from]')||'';
          const to=body.get('query[mad4b_date_to]')||'';
          if(from!==candidate.from||(candidate.mode==='flex'&&to!==candidate.to))return;
          jq(document).off('ajaxComplete',listener);
          if(xhr.status>=200&&xhr.status<300)complete();else fallback();
        };
        jq(document).on('ajaxComplete',listener);
        // Native Date Range does not dispatch when cleared to empty.
        // Explicitly request a refresh with the same JSF provider and facets.
        if(!validIntent(candidate) && typeof group.ajaxRequest==='function'){
          group.$provider=group.getProvider();
          group.ajaxRequest(response=>{
            try{group.ajaxRequestCompleted(response,{})}
            finally{complete();}
          });
        }else group.applyFiltersHandler('ajax');
      }else{
        group.$provider=group.getProvider();
        if(!group.$provider?.length||typeof group.ajaxRequest!=='function'){
          fallback();return;
        }
        // Archive has no second date facet. Call its native JSF AJAX channel
        // without inventing a provider or a parallel rendering engine.
        group.ajaxRequest(response=>{
          try{group.ajaxRequestCompleted(response,{})}
          finally{complete();}
        });
      }
      // Rate readback is verified by ajaxComplete and selection reconciliation.
    }catch(e){fallback();}
  }

  const legacyKey='mad4b:travel-date:v1';
  // Old travel_date was a *single chosen travel day*. Never turn a flexible
  // window boundary into a bookable date or Last Minute pricing input.
  const legacySelected = () => state.mode==='fixed' && valid() ? state.from : null;
  const syncLegacy = origin => {
    const day=legacySelected();
    try {
      if(day) sessionStorage.setItem(legacyKey,JSON.stringify({date:day,at:Date.now(),origin:'unified'}));
      else sessionStorage.removeItem(legacyKey);
    } catch(e){}
    document.dispatchEvent(new CustomEvent('mad4b-travel-date-changed',{
      detail:{travel_date:day,origin:origin||'unified'}
    }));
  };
  if(!window.MAD4BTravelDate)window.MAD4BTravelDate=Object.freeze({
    version:'2.0.0-compat',
    get:()=>legacySelected(),
    set:day=>{
      if(typeof day!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(day))return false;
      const d=new Date(day+'T12:00:00Z');
      if(Number.isNaN(d.getTime())||d.toISOString().slice(0,10)!==day ||
         day<minDate||day>maxDate)return false;
      state.mode='fixed';state.from=day;state.to='';sync();syncLegacy('api');
      return true;
    },
    clear:()=>{state.from='';state.to='';sync();syncLegacy('clear');}
  });
  function connectNativeJetSearch(){
    const jq=window.jQuery;
    if(cfg.context!=='home'||!jq?.ajaxPrefilter)return;
    jq.ajaxPrefilter((options)=>{
      if(!valid()||!/(?:\/jet-search\/v1\/search-posts)(?:\?|$)/.test(options.url||''))return;
      const extra=new URLSearchParams({
        mad4b_date_mode:state.mode,
        mad4b_date_from:state.from
      });
      if(state.mode==='flex')extra.set('mad4b_date_to',state.to);
      options.data=(options.data?String(options.data)+'&':'')+extra.toString();
    });
  }

  function picker(root,context) {
    if (!root || initializedRoots.has(root)) return;
    initializedRoots.add(root);
    // Avoid retaining detached cloned popup roots across reopen cycles.
    for(let i=instances.length-1;i>=0;i--){
      if(!instances[i].root.isConnected){instances[i].close();instances.splice(i,1);}
    }
    root.dataset.mad4bDateReady='1';
    root.classList.add('mad4b-ud','mad4b-ud--'+context);
    root.innerHTML = [
      '<button type="button" class="mad4b-ud__trigger" aria-haspopup="dialog" aria-expanded="false">',
      '<span class="mad4b-ud__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg></span>',
      '<span class="mad4b-ud__summary">Travel Dates</span><span class="mad4b-ud__chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span></button>',
      '<div class="mad4b-ud__panel" role="dialog" aria-label="Select travel dates" hidden>',
      '<div class="mad4b-ud__heading"><strong>Select Travel Dates</strong><button type="button" class="mad4b-ud__close" aria-label="Close">×</button></div>',
      '<div class="mad4b-ud__modes" role="group" aria-label="Date flexibility">',
      '<button type="button" data-mode="flex">Flex Date</button><button type="button" data-mode="fixed">Fixed Date</button></div>',
      '<div class="mad4b-ud__selection" aria-live="polite">',
      '<div><span>From Date</span><strong class="mad4b-ud__from">Select date</strong></div>',
      '<div class="mad4b-ud__to-wrap"><span>To Date</span><strong class="mad4b-ud__to">Select date</strong></div></div>',
      '<div class="mad4b-ud__calendar-host"></div>',
      '<div class="mad4b-ud__footer"><button type="button" class="mad4b-ud__clear">Clear</button>',
      '<button type="button" class="mad4b-ud__apply">Apply Dates</button></div></div>'
    ].join('');
    const panel=root.querySelector('.mad4b-ud__panel');
    const q=selector=>root.querySelector(selector)||panel.querySelector(selector);
    const pickerLabels={
      en:['Select Travel Dates','Flex Date','Fixed Date','From Date','To Date','Clear','Apply Dates','Travel Dates','Select date','Close'],
      ar:['اختر تواريخ السفر','تاريخ مرن','تاريخ محدد','من تاريخ','إلى تاريخ','مسح','تطبيق التواريخ','تواريخ السفر','اختر تاريخًا','إغلاق'],
      fr:['Choisir les dates','Dates flexibles','Date fixe','Du','Au','Effacer','Appliquer','Dates de voyage','Choisir une date','Fermer'],
      es:['Elegir fechas','Fecha flexible','Fecha fija','Desde','Hasta','Borrar','Aplicar','Fechas de viaje','Elegir fecha','Cerrar'],
      de:['Reisedaten wählen','Flexible Daten','Festes Datum','Von','Bis','Löschen','Anwenden','Reisedaten','Datum wählen','Schließen'],
      it:['Scegli le date','Date flessibili','Data fissa','Dal','Al','Cancella','Applica','Date di viaggio','Scegli data','Chiudi']
    };
    const lbl=pickerLabels[lang]||pickerLabels.en;
    panel.setAttribute('aria-label',lbl[0]);
    panel.querySelector('.mad4b-ud__heading strong').textContent=lbl[0];
    panel.querySelector('.mad4b-ud__close').setAttribute('aria-label',lbl[9]);
    panel.querySelector('[data-mode="flex"]').textContent=lbl[1];
    panel.querySelector('[data-mode="fixed"]').textContent=lbl[2];
    panel.querySelector('.mad4b-ud__selection > div:first-child span').textContent=lbl[3];
    panel.querySelector('.mad4b-ud__to-wrap span').textContent=lbl[4];
    panel.querySelector('.mad4b-ud__clear').textContent=lbl[5];
    panel.querySelector('.mad4b-ud__apply').textContent=lbl[6];
    const trigger=q('.mad4b-ud__trigger'),host=q('.mad4b-ud__calendar-host');
    // Self-healing critical styles for Rate filter: the prior CSS URL had
    // a seven-day cache life with its query-string version stripped.
    if(context==='rates'){
      const important=(el,entries)=>Object.entries(entries).forEach(([key,value])=>
        el.style.setProperty(key,value,'important'));
      important(trigger,{
        display:'flex',width:'100%',minHeight:'44px',height:'44px',padding:'0 12px',
        backgroundColor:'#fff',color:'#173e4a',
        border:'1px solid #d8e2e5',borderRadius:'10px',
        boxShadow:'none',transform:'none',textTransform:'none',letterSpacing:'normal',
        fontFamily:'inherit',fontSize:'13px',fontWeight:'400',
        lineHeight:'1.35',whiteSpace:'nowrap'
      });
      important(q('.mad4b-ud__summary'),{
        flex:'1 1 auto',minWidth:'0',overflow:'hidden',textOverflow:'ellipsis',
        whiteSpace:'nowrap',fontSize:'13px',fontWeight:'400',textAlign:'start'
      });
      important(root,{
        width:window.matchMedia('(max-width:767px)').matches?'100%':'min(290px,100%)',
        maxWidth:'100%',margin:'0'
      });
    }
    let calendar=null, opened=false;
    let draft=cloneIntent(state);
    const backdrop=document.createElement('div');
    backdrop.className='mad4b-ud__backdrop';
    backdrop.setAttribute('aria-hidden','true');
    function close(){
      if(!opened)return;
      opened=false;panel.hidden=true;trigger.setAttribute('aria-expanded','false');
      backdrop.remove();
      if(root.isConnected)root.appendChild(panel);else panel.remove();
      document.body.classList.remove('mad4b-ud-dialog-open');
      draft=cloneIntent(context==='rates'&&stagedRateIntent?stagedRateIntent:state);
      render();
      if(root.isConnected)trigger.focus({preventScroll:true});
    }
    function setPortalGeometry(){
      const mobile=window.matchMedia('(max-width:767px)').matches;
      const set=(el,entries)=>Object.entries(entries).forEach(([key,value])=>
        el.style.setProperty(key,value,'important'));
      set(backdrop,{
        position:'fixed',top:'0',right:'0',bottom:'0',left:'0',
        zIndex:'2147483000',background:'rgba(11,36,48,.30)'
      });
      set(panel,{
        position:'fixed',zIndex:'2147483001',
        backgroundColor:'#fff',boxSizing:'border-box',
        overflowY:'auto',overflowX:'hidden',
        maxWidth:'100vw',margin:'0',
        boxShadow:'0 24px 70px rgba(9,37,48,.28)'
      });
      if(mobile){
        set(panel,{
          top:'auto',left:'0',right:'0',bottom:'0',transform:'none',
          width:'100%',maxHeight:'90dvh',borderRadius:'20px 20px 0 0'
        });
      }else{
        set(panel,{
          top:'50%',left:'50%',right:'auto',bottom:'auto',
          transform:'translate(-50%,-50%)',
          width:'min(390px,calc(100vw - 32px))',
          maxHeight:'min(88dvh,670px)',borderRadius:'18px'
        });
      }
    }
    backdrop.addEventListener('click',close);
    window.addEventListener('resize',()=>{if(opened)setPortalGeometry();},{passive:true});
    function render() {
      const displayed=context==='rates'&&stagedRateIntent?stagedRateIntent:state;
      let summary=displayed.from
        ? dateLabel(displayed.from)+(displayed.mode==='flex'&&displayed.to?' – '+dateLabel(displayed.to):''):lbl[7];
      if(context==='rates'){
        const compact=day=>new Intl.DateTimeFormat(document.documentElement.lang||'en',{
          day:'numeric',month:'short',year:'numeric'
        }).format(new Date(day+'T12:00:00'));
        if(displayed.from&&displayed.mode==='flex'&&displayed.to){
          const [fy,fm]=displayed.from.split('-'),[ty,tm,td]=displayed.to.split('-');
          const fromD=Number(displayed.from.split('-')[2]);
          summary=(fy===ty&&fm===tm
            ? fromD+'–'+Number(td)+' '+new Intl.DateTimeFormat(document.documentElement.lang||'en',{month:'short'}).format(new Date(displayed.from+'T12:00:00'))+' '+ty
            :compact(displayed.from)+' – '+compact(displayed.to));
          summary=lbl[1]+' · '+summary;
        }else summary=displayed.from?(displayed.mode==='fixed'?lbl[2]+' · ':lbl[1]+' · ')+compact(displayed.from):lbl[7];
        if(stagedRateIntent&&!sameIntent(stagedRateIntent,state))summary+=' · '+(lang==='ar'?'معلّق':lang==='fr'?'En attente':lang==='es'?'Pendiente':lang==='de'?'Ausstehend':lang==='it'?'In attesa':'Pending');
      }
      q('.mad4b-ud__summary').textContent=summary;
      q('.mad4b-ud__trigger').title=summary;
      q('.mad4b-ud__from').textContent=dateLabel(draft.from)||lbl[8];
      q('.mad4b-ud__to').textContent=dateLabel(draft.to)||lbl[8];
      q('.mad4b-ud__to-wrap').hidden=draft.mode!=='flex';
      panel.classList.toggle('mad4b-ud__panel--fixed',draft.mode==='fixed');
      q('.mad4b-ud__apply').disabled=!validIntent(draft);
      panel.querySelectorAll('[data-mode]').forEach(b=>{
        b.classList.toggle('is-active',b.dataset.mode===draft.mode);
        b.setAttribute('aria-pressed',b.dataset.mode===draft.mode?'true':'false');
      });
    }
    function initCalendar() {
      if(calendar) calendar.destroy();
      host.replaceChildren();
      const input=document.createElement('input');input.type='text';
      input.className='mad4b-ud__calendar-input';input.setAttribute('aria-label','Date calendar');
      host.appendChild(input);
      const dates=draft.from?(draft.mode==='flex'&&draft.to?[draft.from,draft.to]:[draft.from]):[];
      calendar=window.flatpickr(input,{
        inline:true,appendTo:host,mode:draft.mode==='flex'?'range':'single',
        dateFormat:'Y-m-d',defaultDate:dates,minDate:minDate,maxDate:maxDate,
        disableMobile:true,allowInput:false,
        onChange:selected=>{
          draft.from=selected[0]?iso(selected[0]):'';
          draft.to=draft.mode==='flex'&&selected[1]?iso(selected[1]):'';
          render();
        }
      });
    }
    function open() {
      if(opened){close();return;}
      instances.forEach(x=>{if(x.root!==root)x.close();});
      draft=cloneIntent(context==='rates'&&stagedRateIntent?stagedRateIntent:state);
      opened=true;panel.hidden=false;trigger.setAttribute('aria-expanded','true');
      panel.setAttribute('aria-modal','true');
      setPortalGeometry();
      document.body.append(backdrop,panel);
      document.body.classList.add('mad4b-ud-dialog-open');
      initCalendar();render();
      q('.mad4b-ud__close').focus({preventScroll:true});
    }
    if(context==='rates')q('.mad4b-ud__apply').textContent=words.use;
    trigger.addEventListener('click',open);
    q('.mad4b-ud__close').addEventListener('click',close);
    panel.querySelectorAll('[data-mode]').forEach(b=>b.addEventListener('click',()=>{
      if(b.dataset.mode===draft.mode)return;
      draft.mode=b.dataset.mode;draft.to='';initCalendar();render();
    }));
    q('.mad4b-ud__clear').addEventListener('click',()=>{
      draft.from='';draft.to='';
      if(context==='rates'){
        stagedRateIntent=cloneIntent(draft);
        close();sync();rateDraftNote();
      }else if(context==='archive'){
        applyDateIntent('archive',draft);
        close();
      }else{
        copyToState(draft);syncLegacy('clear');close();sync();
      }
    });
    q('.mad4b-ud__apply').addEventListener('click',()=>{
      if(!validIntent(draft))return;
      if(context==='rates'){
        stagedRateIntent=sameIntent(draft,state)?null:cloneIntent(draft);
        close();sync();rateDraftNote();
      }else if(context==='archive'){
        applyDateIntent('archive',draft);
        close();
      }else{
        copyToState(draft);syncLegacy('apply');close();sync();
      }
    });
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&opened)close();});
    document.addEventListener('click',e=>{
      if(opened&&!root.contains(e.target)&&!panel.contains(e.target))close();
    });
    instances.push({root,render,close});render();
  }

  function home() {
    const form=document.querySelector('.elementor-element-7631225e .jet-ajax-search__form');
    if(!form||form.dataset.mad4bDateReady)return;
    const submit=form.querySelector('.jet-ajax-search__submit');
    if(!submit)return;
    const holder=form.querySelector('.jet-ajax-search__fields-holder');
    if(!holder)return;
    const categories=holder.querySelector('.jet-ajax-search__categories');
    const root=document.createElement('div');
    holder.insertBefore(root,categories || null);
    form.dataset.mad4bDateReady='1';form.classList.add('mad4b-ud-search-form');
    picker(root,'home');
    // Preserve the JetSearch native flow when no date is selected.
    const redirectWithDate = e=>{
      if(!valid())return;
      e.preventDefault();e.stopImmediatePropagation();
      const url=new URL(cfg.archiveUrl,window.location.href);
      new FormData(form).forEach((value,key)=>{
        if(typeof value==='string'&&value.length<=4000&&!key.startsWith('mad4b_'))
          url.searchParams.append(key,value);
      });
      window.location.assign(params(url).toString());
    };
    form.addEventListener('submit',redirectWithDate,true);
    form.addEventListener('click',e=>{
      if(e.target.closest('.jet-ajax-search__submit'))redirectWithDate(e);
    },true);
  }
  function archive() {
    document.querySelectorAll('.mad4b-ud-root[data-mad4b-date-context="archive"]').forEach(el=>picker(el,'archive'));
    if(document.querySelector('.mad4b-ud-root[data-mad4b-date-context="archive"]'))return;
    const first=document.querySelector('.elementor-widget-jet-smart-filters-range');
    if(first&&!document.querySelector('.mad4b-ud--archive')){
      const root=document.createElement('div');first.parentNode.insertBefore(root,first);picker(root,'archive');
    }
  }
  function preserveTourLinks() {
    if(!valid())return;
    const prefix=new URL(cfg.archiveUrl,window.location.origin).pathname;
    document.querySelectorAll('#tours_query_archive a[href]').forEach(a=>{
      try{
        const destination=new URL(a.href,window.location.href);
        if(destination.origin!==window.location.origin ||
          destination.pathname===prefix || !destination.pathname.startsWith(prefix))return;
        a.href=params(destination).toString();
      }catch(e){/* Ignore malformed third-party links. */}
    });
  }
  // Keep native Package/Season active chips, and replace malformed
  // Date Range infinity with a single Date Intent summary chip.
  function syncRateActiveFilters(){
    if(cfg.context!=='single')return;
    const parents=[...document.querySelectorAll(
      '.jet-smart-filters-active[data-query-id="tour_rates_accordion"]')];
    if(!parents.length)return;
    parents.forEach(parent=>{
      parent.querySelectorAll('.jet-active-filter:not(.mad4b-ud-active-date)').forEach(item=>{
        const text=String(item.querySelector('.jet-active-filter__label')?.textContent||'')
          .toLowerCase().replace(/\s+/g,' ').trim();
        if(['active date range','travel dates','date range'].some(label=>text.startsWith(label)))
          item.remove();
      });
    });
    const chips=parents.flatMap(parent=>[...parent.querySelectorAll('.mad4b-ud-active-date')]);
    if(!valid()){
      chips.forEach(item=>item.remove());
      return;
    }
    const fmt=date=>new Intl.DateTimeFormat(document.documentElement.lang||'en',{
      day:'numeric',month:'short',year:'numeric'
    }).format(new Date(date+'T12:00:00'));
    const value=state.mode==='flex'&&state.to
      ?fmt(state.from)+' – '+fmt(state.to):fmt(state.from);
    const chipLabels={
      en:['Flex Date','Fixed Date','Clear travel dates'],
      ar:['تاريخ مرن','تاريخ محدد','مسح تواريخ السفر'],
      fr:['Dates flexibles','Date fixe','Effacer les dates'],
      es:['Fechas flexibles','Fecha fija','Borrar las fechas'],
      de:['Flexible Daten','Festes Datum','Reisedaten löschen'],
      it:['Date flessibili','Data fissa','Cancella le date']
    };
    const chipsI18n=chipLabels[lang]||chipLabels.en;
    const label=state.mode==='flex'?chipsI18n[0]:chipsI18n[1];
    let chip=chips[0];
    chips.slice(1).forEach(item=>item.remove());
    if(!chip){
      const parent=parents.find(el=>el.getClientRects().length) || parents[0];
      let list=parent.querySelector('.jet-active-filters__list');
      if(!list){
        list=document.createElement('div');
        list.className='jet-active-filters__list';
        parent.appendChild(list);
      }
      chip=document.createElement('div');
      chip.className='jet-active-filter mad4b-ud-active-date';
      chip.setAttribute('role','group');
      const title=document.createElement('div');
      title.className='jet-active-filter__label';
      const text=document.createElement('div');
      text.className='jet-active-filter__val';
      const remove=document.createElement('button');
      remove.type='button';
      remove.className='mad4b-ud-active-remove';
      remove.textContent='×';
      remove.addEventListener('click',event=>{
        event.preventDefault();
        event.stopImmediatePropagation();
        const cleared={mode:state.mode,from:'',to:''};
        stagedRateIntent=null;
        applyDateIntent('rates',cleared);
      });
      chip.append(title,text,remove);
      list.appendChild(chip);
    }
    if(chip.children[0].textContent!==label)chip.children[0].textContent=label;
    if(chip.children[1].textContent!==value)chip.children[1].textContent=value;
    const remove=chip.children[2];
    if(remove.getAttribute('aria-label')!==chipsI18n[2])
      remove.setAttribute('aria-label',chipsI18n[2]);
  }
  function clearInvalidRateSelection(){
    if(cfg.context!=='single')return;
    const store=window.SharedPresentationSelectionV1;
    const selected=store?.getSnapshot?.().selectedRateId;
    if(!selected)return;
    const region=document.querySelector('#tour_rates_accordion');
    if(!region)return;
    const available=new Set([...region.querySelectorAll('[data-rate-actions]')]
      .map(el=>String(el.dataset.rateActions||'')));
    if(available.has(String(selected)))return;
    document.dispatchEvent(new CustomEvent('rate-selection-request',{detail:{
      tourId:Number(store.getSnapshot().tourId),rateId:Number(selected),remove:true,
      source:'mad4b-date-query-readback'
    }}));
    if(store.getSnapshot().selectedRateId===selected){
      document.dispatchEvent(new CustomEvent('mad4b-date-rate-selection-stale',{
        detail:{tourId:Number(store.getSnapshot().tourId),rateId:Number(selected)}
      }));
    }
  }
  // Count comes from the native JetSmartFilters pagination found_posts.
  function syncQueryCount(queryId,found){
    if(!Number.isSafeInteger(found)||found<0)return;
    document.querySelectorAll('.jet-engine-query-count[data-query="'+queryId+'"]')
      .forEach(el=>{if(el.textContent!==String(found))el.textContent=String(found);});
  }
  // Query 310's server total can be stale relative to its actual accordion.
  // Never display a positive count if the rendered list is explicitly empty.
  function reconcileRatePresentationCount(serverFound=null){
    if(cfg.context!=='single')return;
    const region=document.querySelector('#tour_rates_accordion');
    if(!region)return;
    const ids=new Set([...region.querySelectorAll('[data-rate-actions]')]
      .map(el=>el.dataset.rateActions).filter(v=>/^[1-9][0-9]*$/.test(v)));
    const noData=!!region.querySelector('.jet-listing-not-found,.jet-listing-grid__no-results')
      ||(ids.size===0 && /^\s*(?:No data|No data was found|No results)\s*$/i.test(region.textContent||''));
    if(ids.size>0) syncQueryCount(310,ids.size);
    else if(noData) syncQueryCount(310,0);
    if(Number.isSafeInteger(serverFound) && serverFound!==ids.size && (ids.size>0||noData)){
      document.dispatchEvent(new CustomEvent('mad4b-date-rate-readback-mismatch',{
        detail:{query_id:310,server_found:serverFound,rendered:ids.size}
      }));
    }
  }
  function rateAjaxReadback(){
    if(!window.jQuery?.fn)return;
    window.jQuery(document).on('ajaxSuccess.mad4bDateCount',(event,xhr,settings,data)=>{
      const params=new URLSearchParams(typeof settings.data==='string'?settings.data:'');
      if(params.get('action')!=='jet_smart_filters')return;
      if(!['jet-engine/tour_rates_accordion','jet-engine/tours_query_archive']
        .includes(params.get('provider')))return;
      let result=data;
      if(typeof result==='string'){
        try{result=JSON.parse(result);}catch(e){return;}
      }
      const pagination=result?.pagination;
      if(pagination && Number.isSafeInteger(Number(pagination.query_id))
        && Number.isSafeInteger(Number(pagination.found_posts))){
        const queryId=Number(pagination.query_id),found=Number(pagination.found_posts);
        syncQueryCount(queryId,found);
        if(queryId===310) {
          // After native JetSmartFilters replaces the accordion, verify DOM readback.
          [80,350].forEach(delay=>setTimeout(()=>{
            reconcileRatePresentationCount(found);
            clearInvalidRateSelection();
          },delay));
        }else setTimeout(()=>syncQueryCount(queryId,found),20);
      }
      if(cfg.context==='single')setTimeout(syncRateActiveFilters,50);
    });
  }

  // The original JSF #46259 widget remains mounted for query/provider
  // compatibility. Only its *visible controls* are replaced; dates are
  // carried in the verified Date Intent URL and filtered server-side.
  const rateDateInitialized=new WeakSet();
  function quarantineLegacyRateDatepicker(widget) {
    if (!widget || !widget.classList.contains('mad4b-ud-rate-filter-ready'))return;
    const jq=window.jQuery;
    widget.querySelectorAll('.jet-date-range__from,.jet-date-range__to').forEach(input=>{
      input.tabIndex=-1;
      input.setAttribute('aria-hidden','true');
      input.readOnly=true;
      if(jq?.fn?.datepicker && jq(input).hasClass('hasDatepicker')) {
        try {
          if(jq.datepicker?._curInst?.input?.[0]===input)
            jq.datepicker._hideDatepicker();
          jq(input).datepicker('destroy');
        }catch(e){}
      }
    });
  }
  function rateDateFields() {
    if(cfg.context!=='single')return;
    const native=document.querySelector(
      '.jet-date-range[data-filter-id="46259"][data-query-id="tour_rates_accordion"],'+
      '.jet-date-range[data-filter-id="46259"][data-query-id="tour_rates"]'
    );
    if(!native)return;
    const widget=native.closest('.elementor-widget-jet-smart-filters-date-range');
    if(!widget)return;
    quarantineLegacyRateDatepicker(widget);
    if(rateDateInitialized.has(widget))return;
    const host=widget.querySelector('.elementor-widget-container');
    if(!host)return;
    const root=document.createElement('div');
    root.className='mad4b-ud-root mad4b-ud-rate-select';
    root.dataset.mad4bDateContext='rates';
    const note=document.createElement('p');
    note.className='mad4b-ud-rate-note';
    const lang=String(document.documentElement.lang||'en').slice(0,2);
    const message={
      en:['Rate periods only · Departure and availability are not confirmed',
          'Select travel dates to narrow the available rate periods'],
      ar:['فترات أسعار فقط · موعد المغادرة والتوافر غير مؤكدين',
          'اختر تاريخ السفر لعرض فترات الأسعار المناسبة'],
      es:['Períodos de tarifa · Salidas y disponibilidad sin confirmar',
          'Selecciona fechas para filtrar los períodos de tarifa'],
      fr:['Périodes tarifaires · Départs et disponibilité non confirmés',
          'Choisissez des dates pour filtrer les tarifs'],
      de:['Tarifzeiträume · Abfahrt und Verfügbarkeit nicht bestätigt',
          'Reisedaten auswählen, um Tarifzeiträume zu filtern'],
      it:['Periodi tariffari · Partenze e disponibilità non confermate',
          'Scegli le date per filtrare i periodi tariffari']
    };
    note.dataset.mad4bValidMessage=(message[lang]||message.en)[0];
    note.dataset.mad4bEmptyMessage=(message[lang]||message.en)[1];
    note.textContent=(message[lang]||message.en)[valid()?0:1];
    host.insertBefore(root,host.firstChild);
    host.appendChild(note);
    picker(root,'rates');
    widget.classList.add('mad4b-ud-rate-filter-ready');
    quarantineLegacyRateDatepicker(widget);
    // JetSmartFilters can initialize its native controls after Elementor.
    // Recheck only this widget; never destroy unrelated datepickers.
    [0,200,800].forEach(delay=>setTimeout(()=>quarantineLegacyRateDatepicker(widget),delay));
    rateDateInitialized.add(widget);
    const group=widget.parentElement;
    const selects=group?[...group.children].filter(node=>
      node.matches?.('.elementor-widget-jet-smart-filters-select')):[];
    if(group&&selects.length>=2&&!group.querySelector('.mad4b-rate-filter-row')){
      const toolbar=document.createElement('div');
      toolbar.className='mad4b-rate-filter-row';
      toolbar.setAttribute('role','group');
      toolbar.setAttribute('aria-label','Rate filters');
      const apply=document.createElement('button');
      apply.type='button';
      apply.className='mad4b-ud-rate-apply';
      apply.textContent=words.apply;
      apply.addEventListener('click',()=>{
        if(stagedRateIntent&&stagedRateIntent.from&&!validIntent(stagedRateIntent))return;
        applyDateIntent('rates',stagedRateIntent||state);
      });
      group.insertBefore(toolbar,widget);
      toolbar.append(widget,selects[0],selects[1],apply);
      note.classList.add('mad4b-rate-filter-help');
      group.insertBefore(note,toolbar.nextSibling);
      group.classList.add('mad4b-unified-rate-toolbar-host');
    }
    document.querySelectorAll('.mad4b-ud-rate-context').forEach(el=>el.remove());
    rateDraftNote();
  }
  // Date inheritance in the native Rate Accordion: visibility only, never
  // a price adjustment or booking/Last Minute eligibility receipt.
  function rates() {
    if(cfg.context!=='single'||cfg.request?.status!=='valid')return;
    // The read-only date note is already inside the unified Rates field.
    // Avoid an additional banner (and MutationObserver replaceChildren loops).
    if(document.querySelector('.mad4b-ud-rate-filter-ready')){
      document.querySelectorAll('.mad4b-ud-rate-context').forEach(el=>el.remove());
      return;
    }
    const listing=document.querySelector('#tour_rates_accordion,#tour_rates');
    const priceHeading=document.getElementById('prices');
    if(!listing&&!priceHeading)return;
    let banner=document.querySelector('.mad4b-ud-rate-context');
    if(!banner) {
      banner=document.createElement('div');
      banner.className='mad4b-ud-rate-context';
      banner.setAttribute('role','status');
      if(listing?.parentElement)listing.insertAdjacentElement('beforebegin',banner);
      else priceHeading.insertAdjacentElement('afterend',banner);
    }
    const safeIds=Array.isArray(cfg.rateEligibleIds)
      ?cfg.rateEligibleIds.map(x=>String(x)).filter(x=>/^\d+$/.test(x)):[];
    const qualified=new Set(safeIds);
    const msg={
      en:['Travel dates','Rates shown for the selected rate period; departure and inventory are not confirmed','No eligible Rate periods cover these travel dates. You can still request a custom itinerary'],
      es:['Fechas de viaje','Tarifas correspondientes al período; salidas y plazas sin confirmar','No hay tarifas para las fechas seleccionadas. Solicita un itinerario personalizado'],
      fr:['Dates de voyage','Périodes tarifaires correspondantes, départs non confirmés','Aucun tarif correspondant. Demandez un itinéraire personnalisé'],
      de:['Reisedaten','Tarifzeiträume; Abfahrt und Plätze nicht bestätigt','Kein passender Tarifzeitraum. Bitte individuell anfragen'],
      it:['Date del viaggio','Periodi tariffari; partenze non confermate','Nessuna tariffa corrispondente. Richiedi un itinerario'],
      ar:['تواريخ السفر','فترات الأسعار المتوافقة؛ المغادرة والتوافر غير مؤكدين','لا توجد أسعار متوافقة مع الفترة. يمكنك إرسال استفسار']
    };
    const lang=String(document.documentElement.lang||'en').slice(0,2);
    const [label,detail,empty]=msg[lang]||msg.en;
    const period=cfg.request.mode==='flex' && cfg.request.to
      ?dateLabel(cfg.request.from)+' – '+dateLabel(cfg.request.to)
      :dateLabel(cfg.request.from);
    banner.replaceChildren();
    const heading=document.createElement('strong');
    heading.textContent=label+': '+period;
    const info=document.createElement('span');
    info.textContent=safeIds.length?detail:empty;
    banner.append(heading,info);
    if(!listing)return;
    for(const action of listing.querySelectorAll('[data-rate-actions]')){
      const id=String(action.dataset.rateActions||'');
      if(!/^\d+$/.test(id))continue;
      const card=action.closest('.jet-toggle,.jet-listing-grid__item');
      if(!card)continue;
      card.classList.toggle('mad4b-ud-rate-out-of-window',!qualified.has(id));
    }
  }
  function fluentFormat(isoDate) {
    const [y,m,d]=isoDate.split('-');
    return d+'-'+['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][Number(m)-1]+'-'+y.slice(-2);
  }
  function fluent(force=false) {
    if(!force&&cfg.request?.status!=='valid')return;
    fluentSyncDepth++;
    try{
    document.querySelectorAll('form.fluent_form_6').forEach(form=>{
      if(state.mode==='flex')form.dataset.mad4bDateInheritedFlex='1';
      else delete form.dataset.mad4bDateInheritedFlex;
      const wanted=state.mode==='flex'?'true':'false';
      const radio=Array.from(form.querySelectorAll('input[name="tour_date_flexibility"]')).find(el=>el.value===wanted);
      if(radio&&(force||!radio.dataset.mad4bDatePrefilled)){
        radio.checked=true;radio.dataset.mad4bDatePrefilled='1';
        radio.dispatchEvent(new Event('change',{bubbles:true}));
      }
      [['datetime',state.from],['datetime_2',state.mode==='flex'?state.to:'']].forEach(([field,value])=>{
        const input=form.querySelector('input[name="'+field+'"]');
        if(!input||(!force&&input.dataset.mad4bDatePrefilled))return;
        if(input._flatpickr)input._flatpickr.setDate(value||[],Boolean(value),'Y-m-d');
        else{
          input.value=value?fluentFormat(value):'';
          input.dispatchEvent(new Event('input',{bubbles:true}));
          input.dispatchEvent(new Event('change',{bubbles:true}));
        }
        input.dataset.mad4bDatePrefilled='1';
      });
    });
    }finally{fluentSyncDepth--;}
  }
  // Native Fluent Forms date/radio edits feed the same applied date contract.
  // A partial or inverted flexible range is never applied or submitted.
  const reverseReady=new WeakSet();
  function parseFluentDate(input){
    if(!input)return '';
    if(input._flatpickr?.selectedDates?.length)
      return iso(input._flatpickr.selectedDates[0]);
    const v=String(input.value||'').trim();
    if(/^\d{4}-\d{2}-\d{2}$/.test(v))return v;
    const m=/^(\d{1,2})-([a-z]{3})-(\d{2,4})$/i.exec(v);
    if(!m)return '';
    const months=['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    const month=months.indexOf(m[2].toLowerCase())+1;
    if(!month)return '';
    const yy=Number(m[3]),year=yy<100?2000+yy:yy;
    const date=year+'-'+String(month).padStart(2,'0')+'-'+String(m[1]).padStart(2,'0');
    const day=new Date(date+'T12:00:00Z');
    return Number.isNaN(day.getTime())||day.toISOString().slice(0,10)!==date?'':date;
  }
  function setFluentDateReview(form,message){
    let notice=form.querySelector('.mad4b-date-form-review');
    if(!notice){
      notice=document.createElement('div');
      notice.className='mad4b-date-form-review';
      notice.setAttribute('role','status');
      notice.setAttribute('aria-live','polite');
      const field=form.querySelector('input[name="datetime_2"]')?.closest('.ff-el-group')
        ||form.querySelector('input[name="datetime"]')?.closest('.ff-el-group');
      if(field)field.insertAdjacentElement('afterend',notice);
      else form.prepend(notice);
    }
    if(notice.textContent!==(message||''))notice.textContent=message||'';
    notice.hidden=!message;
    if(message)form.dataset.mad4bDateDraftInvalid='1';
    else delete form.dataset.mad4bDateDraftInvalid;
  }
  function onFluentDateChange(form){
    if(fluentSyncDepth||cfg.context!=='single')return;
    const fromInput=form.querySelector('input[name="datetime"]');
    const toInput=form.querySelector('input[name="datetime_2"]');
    const isFlex=!!form.querySelector('input[name="tour_date_flexibility"][value="true"]:checked');
    const candidate={mode:isFlex?'flex':'fixed',from:parseFluentDate(fromInput),
      to:isFlex?parseFluentDate(toInput):''};
    if(ajaxPending)return; // completion replays the latest revision

    if(isFlex&&candidate.from&&candidate.to&&candidate.to<candidate.from){
      fluentSyncDepth++;
      try{
        if(toInput?._flatpickr)toInput._flatpickr.setDate([],false);
        else if(toInput)toInput.value='';
      }finally{fluentSyncDepth--;}
      candidate.to='';
    }
    // Incomplete dates stay draft-only; stale rates are never represented as
    // prices for a future interval. Submission remains guarded until complete.
    if(!candidate.from||!validIntent(candidate)){
      stagedRateIntent=cloneIntent(candidate);
      sync();rateDraftNote();
      const message=!candidate.from?noticeWords.from
        :isFlex&&!candidate.to?noticeWords.to
        :noticeWords.range;
      setFluentDateReview(form,message);
      return;
    }
    setFluentDateReview(form,'');
    if(sameIntent(candidate,state)){
      stagedRateIntent=null;
      markFormDatePending(false);
      sync();rateDraftNote();return;
    }
    stagedRateIntent=cloneIntent(candidate);
    sync();rateDraftNote();
    applyDateIntent('rates',candidate);
  }
  function connectFluentReverse(){
    if(cfg.context!=='single')return;
    document.querySelectorAll('form.fluent_form_6').forEach(form=>{
      if(reverseReady.has(form))return;
      reverseReady.add(form);
      form.addEventListener('change',event=>{
        if(fluentSyncDepth)return;
        if(!event.target?.matches?.('input[name="datetime"],input[name="datetime_2"],input[name="tour_date_flexibility"]'))
          return;
        formRevision++;
        markFormDatePending(true);
        clearTimeout(fluentReverseTimer);
        fluentReverseTimer=setTimeout(()=>onFluentDateChange(form),110);
      });
      // Fluent Forms has read-only flatpickr inputs, so invalid intervals need
      // an explicit capture-phase guard rather than relying on HTML validity.
      form.addEventListener('submit',event=>{
        if(form.dataset.mad4bDateDraftInvalid==='1'||
           form.dataset.mad4bDatePending==='1'||ajaxPending){
          event.preventDefault();
          event.stopImmediatePropagation();
          setFluentDateReview(form,noticeWords.submit);
        }
      },true);
    });
  }
  function mount() {
    document.querySelectorAll('.mad4b-ud-root[data-mad4b-date-context="home"]').forEach(el=>picker(el,'home'));
    if(cfg.context==='home')home();
    if(cfg.context==='archive'){archive();preserveTourLinks();}
    if(cfg.context==='single'){rateDateFields();fluent();connectFluentReverse();rates();}
    rewriteContextLinks();
    if(cfg.context==='single'){
      syncRateActiveFilters();
      // Initial PHP count and real accordion must agree, including after reload.
      reconcileRatePresentationCount();
    }
  }
  connectNativeJetSearch();
  nativeAjaxPrefilter();
  rateAjaxReadback();
  document.addEventListener('jet-filter-content-rendered',()=>{
    if(cfg.context!=='single')return;
    const widget=document.querySelector('.mad4b-ud-rate-filter-ready');
    quarantineLegacyRateDatepicker(widget);
    setTimeout(()=>{
      reconcileRatePresentationCount();
      clearInvalidRateSelection();
    },100);
  });
  if(restoreCurrencyHandoff())return;
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
  let pending=false;
  const mountTargets='.mad4b-ud-root,form.fluent_form_6,.mad4b-rate-filter-row,'+
    '.jet-ajax-search__form,.jet-smart-filters-active';
  new MutationObserver(changes=>{
    if(pending)return;
    if(!changes.some(c=>[...c.addedNodes].some(node=>
      node.nodeType===1&&(node.matches?.(mountTargets)||node.querySelector?.(mountTargets)))))return;
    pending=true;
    setTimeout(()=>{pending=false;mount();},120);
  }).observe(document.documentElement,{childList:true,subtree:true});
})();
