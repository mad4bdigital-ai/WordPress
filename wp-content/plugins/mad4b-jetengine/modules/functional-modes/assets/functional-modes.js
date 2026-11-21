(() => {
  'use strict';
  const cfg = window.MAD4BJetModePage;
  const help=window.MAD4BPricingHelp;
  const root = document.querySelector('.jet-engine-options-page-wrap');
  if (!cfg || !root || !Array.isArray(cfg.fields) || root.querySelector('.mad4b-workspace')) return;

  // Only re-style existing JetEngine controls. Do not clone, move, rename, or
  // submit field values through another API: JetEngine remains the form owner.
  const form = Array.from(root.querySelectorAll('form')).find(f =>
    f.querySelector('#settings_top') && f.querySelector('button[type="submit"]'));
  const top = form?.querySelector('#settings_top');
  const nativeSubmit = form?.querySelector('button[type="submit"]');
  if (!form || !top || !nativeSubmit) return;

  const make = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = String(text);
    return node;
  };
  const isPricing=cfg.slug==='etg-dynamic-pricing-controls';
  const pageLabel=String(cfg.pageLabel||'JetEngine Options').trim();
  // Pricing-specific terminology is NOT applied to every JetEngine page.
  // Keep native field keys/types and the saved JetEngine schema unchanged.
  const SECTION = isPricing?[
    {id:'overview',label:'General & availability',icon:'⚙',hint:'Booking and engine preferences. JetEngine Save changes owns persistence.'},
    {id:'sources',label:'Price sources',icon:'⇄',hint:'Weighting, fallback, source preferences and tour modes'},
    {id:'policies',label:'Pricing policies',icon:'▤',hint:'Group tiers, package levels and financial rules'},
    {id:'payments',label:'Payments & promotions',icon:'%',hint:'Gateway availability, promotions and method fees'},
    {id:'dates',label:'Dates & booking',icon:'▦',hint:'Travel windows, booking dates and lead time'},
    {id:'advanced',label:'Advanced settings',icon:'⌘',hint:'Formulas, reference fields and specialist options'}
  ]:[
    {id:'general',label:'General settings',icon:'⚙',hint:'Existing JetEngine settings and preferences'},
    {id:'content',label:'Content & labels',icon:'▤',hint:'Text, content and language settings'},
    {id:'design',label:'Design & media',icon:'◧',hint:'Appearance, assets, layout and presentation'},
    {id:'integration',label:'Integrations & communication',icon:'⇄',hint:'Connections, forms and communication controls'},
    {id:'operations',label:'Operations & policies',icon:'▦',hint:'Activation, availability and operational rules'},
    {id:'advanced',label:'Other settings',icon:'⌘',hint:'Other native JetEngine fields'}
  ];
  // Canonical COMPANY field-owner registry. A real JetEngine field gets
  // exactly one category; Product/Supplier panels link to company policy
  // rather than mirroring its original inputs. Keep future fields in
  // Advanced (visible, not silently dropped) until explicitly classified.
  const PRICING_FIELD_GROUPS={
    overview:[
      'pricing_engine_enabled','booking_enabled',
      'require_availability','allow_manual_overrides','stop_sale_global',
      'booking_fulfillment_mode'
    ],
    sources:[
      'shared_price_source_direct_weight','shared_price_source_rate_weight',
      'shared_price_source_tie_break','shared_price_source_fallback',
      'shared_price_source_visibility','shared_price_source_mode_rows',
      'mad4b_provider_catalog_rows','mad4b_provider_source_policy_rows'
    ],
    policies:[
      'tax_percent','service_fee_percent','shared_mode_policy_rows',
      'shared_group_tier_pricing_rows',
      'shared_package_pricing_rows'
    ],
    payments:['shared_gateway_availability_rows','shared_promotion_rows',
      'shared_payment_fee_rows'],
    dates:[
      'mad4b_booking_date_min_lead_days',
      'mad4b_booking_date_min_flex_gap_days','mad4b_booking_date_max_flex_days',
      'mad4b_booking_date_max_advance_days','mad4b_booking_lead_mode_tour',
      'mad4b_booking_lead_mode_cruise_tour','mad4b_booking_lead_mode_activity',
      'mad4b_booking_lead_mode_extra_trip','mad4b_booking_lead_mode_day_tour',
      'mad4b_booking_lead_mode_half_day','mad4b_booking_lead_mode_other',
      'quote_ttl_minutes','shared_price_card_date_mode',
      'mad4b_booking_operating_checkout_mode_v1'
    ],
    advanced:[
      'price_formula_version','tour_formula','property_formula',
      'transport_formula','shared_mode_policies_json',
      'shared_financial_rules_json','minimum_booking_lead_hours',
      'default_currency','shared_visa_policy_notes'
    ]
  };
  const PRICING_FIELD_OWNER=new Map();
  if(isPricing)for(const [section,keys] of Object.entries(PRICING_FIELD_GROUPS))
    for(const key of keys){
      if(PRICING_FIELD_OWNER.has(key))
        throw Error('MAD4B pricing field assigned to two sections: '+key);
      PRICING_FIELD_OWNER.set(key,section);
    }
  const classify = f => {
    const n=String(f.name||'').toLowerCase();
    const label=String(f.label||'').toLowerCase();
    const text=n+' '+label;
    if(!isPricing){
      if(/(logo|icon|image|photo|media|gallery|background|color|colour|font|typograph|hero|layout|style|design|border|radius|header|footer|css|favicon|video)/.test(text))return 'design';
      if(/(email|phone|contact|social|whatsapp|telegram|smtp|form|webhook|integration|api|notify|notification|message|communication)/.test(text))return 'integration';
      if(/(content|title|subtitle|description|tagline|label|translation|language|text|heading|address)/.test(text))return 'content';
      if(/(activate|enabled|disable|booking|availability|status|workflow|policy|schedule|review|approval|inventory|provider|supplier|pricing|offer|promotion|rate|discount|deposit|payment|operation)/.test(text))return 'operations';
      if(f.mode==='rules'||f.type==='repeater')return 'advanced';
      return 'general';
    }
    return PRICING_FIELD_OWNER.get(n)||'advanced';
  };
  const aliases = new Map(cfg.fields.map(f => [String(f.name), f]));
  // Native JetEngine owns field width, inline/stacked layout and input styling.
  // MAD4B only groups and filters the existing DOM without recalculating widths.
  const controls = [];
  const seen = new Set();
  // JetEngine's stable data-control-name is safer than depending on nested
  // repeater input[name] attributes or user-facing labels.
  for (const el of top.querySelectorAll('.cx-control[data-control-name]')) {
    const key=el.getAttribute('data-control-name');
    if (!key || seen.has(key) || !aliases.has(key)) continue;
    seen.add(key);
    const f=aliases.get(key);
    const type=String(f.type||'');
    const entry={el,key,mode:String(f.mode||'standard'),type,label:String(f.label||key),group:classify(f)};
    el.classList.add('mad4b-ws-control');
    el.dataset.mad4bGroup=entry.group;
    el.dataset.mad4bMode=entry.mode;
    controls.push(entry);
  }
  // Abort if vendor markup changed enough to invalidate the field mapping.
  if (!controls.length || controls.length < Math.min(1, cfg.fields.length)) return;
  const foundKeys=new Set(controls.map(c=>c.key));
  // JetEngine layout-only tabs do not render as editable controls.
  const missing=cfg.fields.filter(f=>!foundKeys.has(f.name));
  // Additional native fields can be registered through JetEngine raw-field
  // filters after MAD4B's stored schema discovery (notably Look & Feel).
  // Always keep the original vendor inputs; classify their visible DOM
  // metadata instead of hiding every field in an opaque Other settings tab.
  // Read labels and field identities only, never input values or secrets.
  const inferNativeField=el=>{
    const name=String(el.getAttribute('data-control-name')||'').slice(0,110);
    const label=el.querySelector(':scope > .cx-control__info .cx-control__title')
      ?.textContent?.trim()||name||'Additional JetEngine field';
    const known=['repeater','checkbox','switcher','select','radio',
      'media','gallery','colorpicker','number','textarea','text','html'];
    const type=known.find(t=>el.classList.contains('cx-control-'+t))||
      (el.querySelector('input[type=number]')?'number':'text');
    return {name,label,type,mode:'field-card'};
  };
  const other=Array.from(top.querySelectorAll('.cx-control[data-control-name]'))
    .filter(el=>!el.classList.contains('mad4b-ws-control') &&
      !el.closest('.mad4b-ws-control'));
  for (const el of other) {
    const f=inferNativeField(el);
    const group=classify(f);
    el.classList.add('mad4b-ws-control');
    el.dataset.mad4bGroup=group;
    el.dataset.mad4bMode=f.mode;
    controls.push({el,key:f.name,mode:f.mode,type:f.type,label:f.label,group});
  }

  // JetEngine's visually rendered native titles are not always associated
  // with the actual input/select/textarea, especially steppers and switchers.
  // Link the EXISTING input to its original title in place; do not add a
  // duplicate field/label, alter saved input names, or override real labels.
  const labelOriginalNativeFields=()=>{
    const taken=new Set();
    for(const c of controls){
      if(c.type==='repeater')continue;
      const info=c.el.querySelector(':scope > .cx-control__info');
      const title=info?.querySelector('.cx-control__title');
      const body=c.el.querySelector(':scope > .cx-control__content');
      if(!title||!body)continue;
      const targets=[...body.querySelectorAll('input:not([type=hidden]),select,textarea')]
        .filter(el=>{
          const owner=el.closest('.cx-control[data-control-name]');
          return owner===c.el;
        });
      if(!targets.length)continue;
      const safe=c.key.replace(/[^a-z0-9_-]/gi,'-');
      const prefix='mad4b-native-label-'+safe;
      let labelId=title.id||prefix;
      let suffix=1;
      while(!title.id &&
        (taken.has(labelId)||(document.getElementById(labelId)&&
           document.getElementById(labelId)!==title)))
        labelId=prefix+'-'+(suffix++);
      if(!title.id)title.id=labelId;
      taken.add(labelId);
      for(const target of targets){
        if(target.hasAttribute('aria-label')||
          target.hasAttribute('aria-labelledby')||
          target.closest('label')||
          (target.id && document.querySelector('label[for="'+
            CSS.escape(target.id)+'"]')))continue;
        if(target.matches('.cx-input-switcher-true,.cx-input-switcher-false')){
          // Switchers have two original radios, not two different settings.
          target.setAttribute('aria-label',String(c.label)+' — '+
            (target.classList.contains('cx-input-switcher-true')?'On':'Off'));
        }else target.setAttribute('aria-labelledby',labelId);
      }
    }
  };
  labelOriginalNativeFields();
  // Repeaters insert their rows after the initial JetEngine render. Only
  // annotate anonymous original On/Off radios, including later new rows.
  // No Vue input, option value, native field or checkbox is cloned/disabled.
  const labelRepeaterSwitchers=()=>{
    for(const field of top.querySelectorAll(
      '.cx-control-repeater .cx-input-switcher-true, '+
      '.cx-control-repeater .cx-input-switcher-false')){
      if((field.hasAttribute('aria-label') &&
            field.dataset.mad4bGeneratedLabel!=='1')||
         field.hasAttribute('aria-labelledby')||field.closest('label'))continue;
      const repeater=field.closest('.cx-control-repeater[data-control-name]');
      const row=field.closest('.cx-ui-repeater-item');
      const nested=field.name?.match(/\[([^\]]+)\]$/);
      const fieldName=(nested?.[1]||'Setting')
        .replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
      const title=repeater?.querySelector(
        ':scope > .cx-control__info .cx-control__title')?.textContent?.trim()
        ||'Pricing policy';
      const index=row && row.parentElement
        ?Array.from(row.parentElement.children).filter(el=>
          el.classList?.contains('cx-ui-repeater-item')).indexOf(row)+1:0;
      field.setAttribute('aria-label',
        title+' · '+(index>0?'Row '+index+' · ':'')+fieldName+' — '+
        (field.classList.contains('cx-input-switcher-true')?'On':'Off'));
      field.dataset.mad4bGeneratedLabel='1';
    }
  };
  labelRepeaterSwitchers();
  const switcherObserver=new MutationObserver(changes=>{
    if(changes.some(change=>[...change.addedNodes].some(node=>
      node.nodeType===1&&(node.matches?.('.cx-ui-repeater-item,.cx-input-switcher-true,.cx-input-switcher-false')||
        node.querySelector?.('.cx-ui-repeater-item,.cx-input-switcher-true,.cx-input-switcher-false')))))
      labelRepeaterSwitchers();
  });
  switcherObserver.observe(top,{childList:true,subtree:true});
  const workspace=make('section','mad4b-workspace');
  workspace.setAttribute('aria-label','MAD4B JetEngine functional editor');
  const banner=make('div','mad4b-ws-banner');
  const heading=make('div','mad4b-ws-title');
  heading.append(make('span','mad4b-ws-eyebrow','MAD4B / JETENGINE'));
  heading.append(make('h2','',isPricing?'Pricing Controls':pageLabel));
  heading.append(make('p','',isPricing
    ? 'Edit the actual settings and policies. All changes use JetEngine’s native save process.'
    : 'Edit existing JetEngine fields with grouped navigation. JetEngine remains the only save and validation owner.'));
  const actions=make('div','mad4b-ws-actions');
  const nativeSaveConfirmed=new URLSearchParams(window.location.search).has('dialog-saved');
  const state=make('span','mad4b-ws-status',
    nativeSaveConfirmed?'JetEngine confirmed save':'Configuration loaded · no changes yet');
  state.setAttribute('role','status');
  // Modernise the ORIGINAL JetEngine submit rather than adding another Save.
  // It stays within its original vendor form and submits through JetEngine.
  nativeSubmit.textContent='Save changes';
  nativeSubmit.classList.add('mad4b-ws-native-save-button');
  const helpMode=make('button','mad4b-ws-secondary mad4b-ws-help-mode',cfg.i18n?.helpMode||'Help mode');
  helpMode.type='button';
  helpMode.setAttribute('aria-pressed','false');
  const native=make('button','mad4b-ws-secondary','All JetEngine fields');
  native.type='button';
  const visual=make('button','mad4b-ws-secondary','Grouped JetEngine view');
  visual.type='button';
  actions.append(state,helpMode,native,visual);
  // Mode configuration is a global JetEngine engineering setting;
  // don't show its admin-only link to delegated pricing operators.
  if(cfg.editor){
    const link=make('a','mad4b-ws-link','Configure modes');
    link.href=cfg.editor;
    actions.append(link);
  }
  banner.append(heading,actions);
  if(isPricing)help?.attach(heading,'editor',{
    title:'Pricing Controls',text:help.message('editor')
  });
  workspace.append(banner);

  const summary=make('div','mad4b-ws-summary');
  const metrics=[
    [String(controls.length),'Editable fields'],
    [String(controls.filter(c=>c.type==='repeater').length),'Policy collections'],
    [String(SECTION.length),'Functional groups']
  ];
  for (const [number,label] of metrics) {
    const item=make('div','mad4b-ws-metric');
    item.append(make('strong','',number),make('span','',label));
    summary.append(item);
  }
  workspace.append(summary);

  const layout=make('div','mad4b-ws-layout');
  const sidebar=make('nav','mad4b-ws-sidebar');
  sidebar.setAttribute('aria-label','Settings sections');
  const search=make('input','mad4b-ws-search');
  search.type='search';
  search.placeholder='Search settings…';
  search.setAttribute('aria-label','Find a setting');
  sidebar.append(search);
  const navList=make('div','mad4b-ws-nav');
  const buttons=new Map();
  const countFor=id=>controls.filter(c=>c.group===id).length;
  for(const section of SECTION){
    const count=countFor(section.id);
    if(!count)continue;
    const button=make('button','mad4b-ws-nav-btn');
    button.type='button';
    const name=make('span','');
    name.append(make('span','mad4b-ws-nav-icon',section.icon),document.createTextNode(section.label));
    button.append(name,make('small','',count));
    button.dataset.section=section.id;
    buttons.set(section.id,button);
    navList.append(button);
  }
  sidebar.append(navList);
  const guide=make('p','mad4b-ws-tip','One Save action · original JetEngine form · changes are not live until saved.');
  sidebar.append(guide);
  const sectionHeading=make('div','mad4b-ws-section-heading');
  const sectionLabel=make('h3','','General & availability');
  const sectionHint=make('p','','');
  sectionHeading.append(sectionLabel,sectionHint);
  const content=make('div','mad4b-ws-content');
  content.append(sectionHeading);
  // Company-specific notes live in MAD4B's own section; native JetEngine
  // descriptions, labels and inputs remain untouched and always visible.
  const companyGuidance=make('aside','mad4b-ws-company-guidance');
  companyGuidance.hidden=true;
  companyGuidance.setAttribute('aria-label','Supplementary company field guidance');
  content.append(companyGuidance);
  const listing=make('p','mad4b-ws-listing','');
  content.append(listing);
  // Source ownership instead of another editable currency selector. The
  // optional JetEngine currency override remains in Advanced; Woo/WCML
  // retains the original store, FX and market currency settings.
  const currencyOwner=make('p','mad4b-ws-source-owner-note');
  if(isPricing){
    currencyOwner.append(document.createTextNode(
      'Currency & market catalog is owned by '));
    const link=make('a','',
      'Currencies & Markets');
    link.href='admin.php?page=mad4b-pricing-currencies';
    currencyOwner.append(link,document.createTextNode(
      '. Optional pricing-policy overrides remain in Advanced settings.'));
    content.insertBefore(currencyOwner,listing);
  }

  // Company pricing dimension preferences. The original JetEngine form owns
  // the ONLY Save action; these controls become signed native form fields.
  // Catalog is fetched lazily to avoid adding PHP/SQL to the page TTFB.
  const dimCfg=cfg.dimensions;
  let dimensionOpen=false;
  let dimensionLoaded=false;
  const dimLauncher=make('div','mad4b-ws-dimension-launcher');
  const dimIntro=make('div','mad4b-ws-dimension-intro');
  dimIntro.append(make('strong','','Step 2 · Choose active pricing dimensions'),
    make('p','','Enable the JetEngine audience, schedule and destination filters available for Pricing Lab strategies.'));
  const dimButton=make('button','mad4b-ws-dimension-open','Configure dimensions');
  dimButton.type='button';
  dimButton.setAttribute('aria-expanded','false');
  const dimBadge=make('span','mad4b-ws-dimension-count','Load available dimensions');
  dimLauncher.append(dimIntro,dimButton,dimBadge);
  const dimPanel=make('div','mad4b-ws-dimension-panel');
  dimPanel.hidden=true;
  dimPanel.id='mad4b-pricing-dimensions-panel';
  dimButton.setAttribute('aria-controls',dimPanel.id);
  content.insertBefore(dimLauncher,listing);
  content.insertBefore(dimPanel,listing);
  // Provider source policies live directly in the Price Sources section
  // but save in their own revisioned inactive Draft, not the JetEngine form.
  const providerPanel=make('details','mad4b-ws-provider-panel');
  providerPanel.hidden=true;
  const providerHeading=make('summary','mad4b-ws-provider-heading',
    'Provider source rules · company, suppliers, Tour groups & Rates');
  providerPanel.append(providerHeading);
  content.insertBefore(providerPanel,listing);
  providerPanel.addEventListener('toggle',()=>{
    if(providerPanel.open&&!providerPanel.hidden&&window.MAD4BProviderPriceSources)
      window.MAD4BProviderPriceSources.mount(providerPanel);
  });
  if(!dimCfg)dimLauncher.hidden=true;

  const dimHidden=(name,value)=>{
    const hidden=make('input');
    hidden.type='hidden';hidden.name=name;hidden.value=value;
    form.append(hidden);return hidden;
  };
  // Never submit a default selection if the operator did not open/load this
  // controller. Otherwise an unrelated native Save could reset a previously
  // customized dimension policy to 'all'.
  let dimInput=null;
  const ensureDimInputs=()=>{
    if(!dimInput&&dimCfg)dimInput={
      mode:dimHidden('mad4b_pricing_dimensions_mode','all'),
      selected:dimHidden('mad4b_pricing_dimensions_selected','[]'),
      nonce:dimHidden('mad4b_pricing_dimensions_nonce',dimCfg.save_nonce||''),
      languageMode:dimHidden('mad4b_pricing_language_mode','inherit_default'),
      authoringLanguage:dimHidden('mad4b_pricing_authoring_language','en')
    };
  };
  const dimLabels=Object.freeze({
    audience:'Audience & interests',booking_model:'Tour / private / group models',
    season:'Travel season',schedule:'Days & times',
    destination:'Destinations',style:'Tour styles & types',
    package:'Package levels',duration:'Duration',
    group_size:'Group sizes',other:'Additional fields'
  });
  const recommendedIDs=new Set([
    'taxonomy:good-for','taxonomy:destinations','taxonomy:season',
    'taxonomy:package_levels','taxonomy:tour-types',
    'taxonomy:day-times','taxonomy:schudle-days-of-weak',
    'taxonomy:tour-styles','taxonomy:tour_categories',
    'meta:tour_mode','taxonomy:duration-options'
  ]);
  const loadDimensions=async()=>{
    if(!dimCfg||dimensionLoaded)return;
    dimButton.disabled=true;
    dimBadge.textContent='Loading JetEngine fields…';
    try{
      const post=new URLSearchParams();
      post.set('action',dimCfg.action);
      post.set('nonce',dimCfg.nonce);
      const result=await fetch(dimCfg.ajax_url,{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body:post.toString()
      });
      const json=await result.json();
      if(!result.ok||!json?.success||!Array.isArray(json.data?.catalog))
        throw new Error(json?.data?.message||'Could not load company dimensions');
      renderDimensionOptions(json.data);
      dimensionLoaded=true;
    }catch(error){
      dimBadge.textContent='Catalog unavailable';
      dimPanel.replaceChildren(make('p','mad4b-ws-dimension-error',
        'Cannot load JetEngine fields right now. No preference will be changed. Refresh or try again.'));
      if(dimInput){for(const el of Object.values(dimInput))el.remove();dimInput=null;}
    }finally{dimButton.disabled=false;}
  };
  // Hierarchical policy editor: group > JetEngine dimension > conditions >
  // native pricing template > AJAX dry-run > optional inactive Lab Draft.
  // Data is returned by MAD4B Strategy Schema Bridge, never by page HTML.
  const policyRequest=async(op,fields={})=>{
    const post=new URLSearchParams({
      action:dimCfg.action,nonce:dimCfg.nonce,operation:op
    });
    for(const [key,value] of Object.entries(fields))
      post.set(key,typeof value==='string'?value:JSON.stringify(value));
    const response=await fetch(dimCfg.ajax_url,{
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:post.toString(),
    });
    const json=await response.json();
    if(!response.ok||!json?.success)
      throw new Error(String(json?.data?.message||'The policy request failed'));
    return json.data;
  };
  const policyRow=(item,data,selectedSet,languageScope)=>{
    const detail=make('details','mad4b-ws-nested-policy');
    const summary=make('summary');
    summary.textContent='Configure targeting & pricing policy';
    help?.attach(summary,'editor',{
      title:'Policy editor',text:help.message('editor')
    });
    detail.append(summary);
    const inner=make('div','mad4b-ws-nested-policy-content');
    const notice=make('p','mad4b-ws-nested-policy-notice',
      'Define a targeting condition, select a reusable native pricing template, then preview its real published-Rate impact. No live prices change here.');
    const formUI=make('div','mad4b-ws-policy-fields');
    const info=make('p','mad4b-ws-policy-info','Open to load native JetEngine choices.');
    const result=make('div','mad4b-ws-policy-result');
    result.setAttribute('aria-live','polite');
    inner.append(notice,formUI,info,result);detail.append(inner);
    let ready=false;
    let busy=false;
    let cached=null;
    const control=(label,kind='text')=>{
      const l=make('label','mad4b-ws-policy-control');
      l.append(make('span','',label));
      const input=make('input');input.type=kind;
      l.append(input);formUI.append(l);return input;
    };
    const select=(label,entries)=>{
      const l=make('label','mad4b-ws-policy-control');
      l.append(make('span','',label));
      const input=make('select');
      for(const [id,text] of entries){
        const option=document.createElement('option');
        option.value=String(id);option.textContent=String(text);
        input.append(option);
      }
      l.append(input);formUI.append(l);return input;
    };
    const renderResult=(response)=>{
      result.replaceChildren();
      result.append(make('strong','',
        'Dry-run · '+response.scope_count+' matching tours · '+
        response.examined_rates+' rates checked'));
      result.append(make('p','',
        response.eligible_rates.length+' rates eligible for staged draft · '+
        response.conflicts.length+' require review · no live prices changed'));
      if(response.truncated)
        result.append(make('p','mad4b-ws-policy-warning',
          'Result was truncated. Staging is unavailable until you narrow the targeting.'));
      const rows=make('div','mad4b-ws-policy-preview-rows');
      for(const x of (response.rows||[]).slice(0,6)){
        const sample=make('div','mad4b-ws-policy-preview-row');
        const title=make('strong','',x.tour||'Tour '+x.tour_id);
        const rate=make('small','','Rate #'+x.rate_id+' · '+x.status);
        const pricing=make('span','',
          x.before!==null&&x.illustrative_after!==null
          ?String(x.before)+' → '+String(x.illustrative_after)+' '+(x.currency||'')
          :'No eligible published rate quote');
        sample.append(title,rate,pricing);
        if(x.reference&&x.reference.source==='published_tour_rate'){
          const src=x.reference;
          const prefix=src.effective===null?'Source Rate quote unavailable':
            'Source: '+(src.amount_role==='active_rate_sale'
              ?'active Rate sale':'regular Rate price');
          const amount=src.effective===null?'not available for this context':
            String(src.effective)+' '+(src.currency||'');
          sample.append(make('small','mad4b-ws-policy-source-evidence',
            prefix+(src.effective===null?'':' ('+String(src.meta_field||'Rate field')+')')+
            ' · basis '+amount+' · '+String(src.audience||'general')+' / '+
            String(src.occupancy||'double')));
          if(src.amount_role==='active_rate_sale')
            sample.append(make('small','mad4b-ws-policy-warning',
              'Existing sale detected — discount stacking needs review.'));
        }
        if(x.reason)sample.append(make('small','mad4b-ws-policy-warning',x.reason));
        if(Number.isSafeInteger(Number(x.rate_id))&&Number(x.rate_id)>0){
          const details=make('details','mad4b-ws-policy-inheritance');
          const trigger=make('summary','',
            'Inspect Rate #'+x.rate_id+' inheritance');
          const evidence=make('div','mad4b-ws-policy-inheritance-body');
          details.append(trigger,evidence);
          let cachedInheritance=false;
          let fetchingInheritance=false;
          details.addEventListener('toggle',async()=>{
            if(!details.open||cachedInheritance||fetchingInheritance)return;
            fetchingInheritance=true;
            evidence.replaceChildren(make('p','','Reading current Rate → Tour → Taxonomy → Global bindings…'));
            try{
              const inspected=await policyRequest('inheritance',{
                rate_id:String(x.rate_id)
              });
              cachedInheritance=true;
              evidence.replaceChildren(make('strong','',
                'Effective source: '+String(inspected.effective_source||'Existing pricing default')));
              const applied=inspected.effective_policy;
              if(applied)
                evidence.append(make('p','',
                  'Policy Draft: '+String(applied.title||applied.id)+
                  ' · revision '+String(applied.revision??'—')));
              else evidence.append(make('p','',
                'No active Draft reference resolved. Native Rate pricing remains authoritative.'));
              const conflicts=inspected.conflicts||[];
              evidence.append(make('p',conflicts.length?'Review required · '+
                conflicts.length+' inheritance conflict(s).':
                'No policy-binding conflicts found.'));
              const list=make('ol','');
              for(const step of inspected.inheritance||[]){
                const label=String(step.source||'inherit').replaceAll('_',' ');
                list.append(make('li','',
                  label+' · '+(step.policies||[]).length+' linked Draft(s)'));
              }
              evidence.append(list,make('small','',
                'Read-only reference priority; no monetary quote, Rate or checkout is modified.'));
            }catch(error){
              evidence.replaceChildren(make('p','mad4b-ws-policy-warning',
                'Inheritance unavailable: '+String(error.message)));
            }finally{fetchingInheritance=false;}
          });
          sample.append(details);
        }
        rows.append(sample);
      }
      result.append(rows);
    };
    const load=async()=>{
      if(ready||busy)return;
      busy=true;
      info.textContent='Loading '+item.label+' conditions from JetEngine…';
      try{
        const scope=languageScope();
        if(scope.changed)throw Error('Save the company-wide language policy first, then reopen this dimension.');
        const native=await policyRequest('dimension',{
          dimension_id:item.id,
          languages:JSON.stringify([scope.authoring_language])
        });
        cached=native.dimension;
        if(!cached||cached.id!==item.id)throw Error('JetEngine dimension mismatch');
        cached.help=native.help||{};
        formUI.replaceChildren();
        const title=control('Draft strategy name');
        title.value=item.label+' · targeting draft';
        title.maxLength=105;
        const audience=select('Price audience',[['general','International / General'],
          ['egyptians','Egyptians']]);
        const occupancy=select('Rate occupancy',[['double','Double'],
          ['single','Single'],['triple','Triple']]);
        const valuesArea=make('fieldset','mad4b-ws-policy-terms');
        const legend=make('legend','','Match selected '+item.label+' values');
        valuesArea.append(legend);
        formUI.append(valuesArea);
        const match=select('How should selected values match?',[
          ['any','Match ANY (OR)'],['all','Match ALL (AND)']
        ]);
        help?.attachControl(match,'match',{
          title:'OR / AND matching',text:help.message('match')
        });
        help?.choiceHint(match,'match',{
          any:{text:help?.message('match.any')},
          all:{text:help?.message('match.all')}
        });
        const template=select('Native pricing template',
          (data.native_templates||[]).map(x=>[x.id,x.label]));
        help?.attachControl(template,'templates',{
          title:'Pricing template',text:help.message('templates')
        });
        const templateHint=help?.choiceHint(template,'templates',Object.fromEntries(
          (data.native_templates||[]).map(t=>[t.id,{
            text:help?.message('template.'+String(t.strategy||'unchanged'))
          }])));
        if((data.native_templates||[]).some(x=>x.id==='base-percent'))
          template.value='base-percent';
        // Programmatic initial value assignment does not fire change.
        // Synchronize the help after applying the actual selected template.
        templateHint?.refresh();
        const numeric=control('Template value', 'number');
        numeric.step='0.0001';
        numeric.value='100';
        help?.attachControl(numeric,'multiplier',{
          title:'Price multiplier',text:help.message('multiplier')
        });
        // Reuse the registered MAD4B / JetEngine "slider" field mode:
        // this is a visual control bound to the existing numeric draft
        // value, never a second pricing input or another saved field.
        const sliderWrap=make('div','mad4b-ws-policy-price-slider');
        sliderWrap.dataset.mad4bUiMode='slider';
        const sliderLabel=make('label','mad4b-ws-policy-price-slider-label');
        sliderLabel.append(make('span','','Adjust percentage with slider'));
        const slider=make('input');
        slider.type='range';slider.min='50';slider.max='150';
        slider.step='0.5';slider.value='100';
        slider.setAttribute('aria-label','Price multiplier (%) slider');
        const sliderReadout=make('output','mad4b-ws-policy-price-slider-value','100% · source price');
        sliderLabel.append(slider);
        const sliderScale=make('div','mad4b-ws-policy-price-slider-scale');
        const sliderMin=make('span','','50%');
        const sliderBase=make('span','','100% · original price');
        const sliderMax=make('span','','150%');
        sliderScale.append(sliderMin,sliderBase,sliderMax);
        const sliderHelp=make('small','mad4b-ws-policy-price-slider-help',
          'Move in 0.5% steps, or type a precise value above (up to 4 decimals). No published rate changes.');
        sliderWrap.append(sliderLabel,sliderReadout,sliderScale,sliderHelp);
        help?.attach(sliderWrap,'slider',{
          title:'Slider precision',text:help.message('slider')
        });
        const formatPercent=n=>String(Number(n.toFixed(4)));
        const syncSlider=()=>{
          const entered=Number(numeric.value);
          if(numeric.value===''||!Number.isFinite(entered)){
            slider.disabled=true;
            sliderReadout.textContent='Enter a percentage above';
            slider.setAttribute('aria-valuetext','Enter a valid percentage');
            return;
          }
          // Do not clamp typed rates to the visual range. Expand only
          // the range used by the slider; keep the source numeric intact.
          const min=Math.min(50,Math.floor(entered/25)*25);
          const max=Math.max(150,Math.ceil(entered/25)*25);
          slider.min=String(min);slider.max=String(max);
          sliderMin.textContent=formatPercent(min)+'%';
          sliderMax.textContent=formatPercent(max)+'%';
          slider.value=String(Math.round(entered*2)/2);
          slider.disabled=numeric.disabled;
          const change=entered-100;
          const description=Math.abs(change)<0.0000001?'source price'
            :change<0?formatPercent(-change)+'% discount'
            :formatPercent(change)+'% increase';
          sliderReadout.textContent=formatPercent(entered)+'% · '+description;
          slider.setAttribute('aria-valuetext',
            formatPercent(entered)+'% of source rate · '+description);
        };
        slider.addEventListener('input',()=>{
          if(numeric.disabled)return;
          numeric.value=slider.value;
          numeric.dispatchEvent(new Event('input',{bubbles:true}));
        });
        numeric.addEventListener('input',syncSlider);
        numeric.addEventListener('change',syncSlider);
        const currency=select('Amount currency (JetEngine Glossary)',[
          ['','Choose currency'],...Object.entries(data.currency_choices||{})
        ]);
        currency.value='';
        help?.attachControl(currency,'currency',{title:'Currency',text:help.message('currency')});
        help?.choiceHint(currency,'currency');
        help?.attachControl(audience,'audience',{title:'Rate audience',text:help.message('audience')});
        help?.attachControl(occupancy,'occupancy',{title:'Rate occupancy',text:help.message('occupancy')});
        // Optional target groups: blank = inherited across every WPML
        // translation. Each explicit group limits its own dimensions,
        // and several groups form a union, not a copy of the whole policy.
        const groupArea=make('fieldset','mad4b-ws-policy-groups');
        groupArea.append(make('legend','','Optional display-language / market groups'));
        help?.attach(groupArea,'groups',{
          title:'Explicit language and market groups',text:help.message('groups')
        });
        const scopeTip=make('p','mad4b-ws-policy-explanation',
          'No group = default WPML inheritance. Adding a group stops unrestricted inheritance for this Draft. Groups are combined with OR.');
        const addGroup=make('button','button','Add targeting group');
        addGroup.type='button';
        groupArea.append(scopeTip,addGroup);
        formUI.append(groupArea);
        const groupRows=[];
        const groupChoices=(caption,choices)=>{
          const label=make('label','mad4b-ws-policy-control');
          label.append(make('span','',caption));
          const field=make('select');
          field.multiple=true;field.size=Math.min(5,Math.max(2,choices.length));
          for(const [id,name] of choices){
            const option=document.createElement('option');
            option.value=id;option.textContent=name;field.append(option);
          }
          label.append(field);return [label,field];
        };
        const addTargetGroup=()=>{
          if(groupRows.length>=6)return;
          const row=make('div','mad4b-ws-policy-group');
          const titleLabel=make('label','mad4b-ws-policy-control');
          titleLabel.append(make('span','','Group name'));
          const title=make('input');title.type='text';title.maxLength=90;
          title.value='Target group '+(groupRows.length+1);
          titleLabel.append(title);
          const displayToggle=make('label','mad4b-ws-policy-group-toggle');
          const useLanguages=make('input');useLanguages.type='checkbox';
          displayToggle.append(useLanguages,make('span','','Target display languages'));
          const marketToggle=make('label','mad4b-ws-policy-group-toggle');
          const useMarkets=make('input');useMarkets.type='checkbox';
          marketToggle.append(useMarkets,make('span','','Target markets (content preview)'));
          const [localeWrap,localeSelect]=groupChoices('Display languages',
            Object.entries(data.languages||{en:'English'}).map(([id,label])=>[id,String(label)]));
          const [marketWrap,marketSelect]=groupChoices('Market countries',
            (data.market_countries||[]).map(x=>[String(x.id),String(x.name||x.id)]));
          const remove=make('button','button','Remove group');remove.type='button';
          const refresh=()=>{
            localeWrap.hidden=!useLanguages.checked;
            marketWrap.hidden=!useMarkets.checked;
          };
          useLanguages.addEventListener('change',refresh);
          useMarkets.addEventListener('change',refresh);
          row.append(titleLabel,displayToggle,localeWrap,marketToggle,
            marketWrap,remove);
          groupArea.insertBefore(row,addGroup);
          const instance={row,title,useLanguages,useMarkets,localeSelect,marketSelect};
          groupRows.push(instance);
          remove.addEventListener('click',()=>{
            row.remove();groupRows.splice(groupRows.indexOf(instance),1);
            formUI.dispatchEvent(new Event('change',{bubbles:true}));
          });
          refresh();formUI.dispatchEvent(new Event('change',{bubbles:true}));
        };
        addGroup.addEventListener('click',addTargetGroup);
        const readTargetGroups=()=>groupRows.map((g,i)=>{
          if(!g.useLanguages.checked&&!g.useMarkets.checked)
            throw Error('Group '+(i+1)+': enable display languages or markets, or remove the group to inherit globally.');
          const display=g.useLanguages.checked
            ?[...g.localeSelect.selectedOptions].map(x=>x.value):[];
          const markets=g.useMarkets.checked
            ?[...g.marketSelect.selectedOptions].map(x=>x.value):[];
          if(g.useLanguages.checked&&!display.length)
            throw Error('Group '+(i+1)+': select at least one display language.');
          if(g.useMarkets.checked&&!markets.length)
            throw Error('Group '+(i+1)+': select at least one market.');
          return {title:g.title.value.trim(),display_languages:display,markets};
        });
        const explanation=make('p','mad4b-ws-policy-explanation',
          '100% = source price. Example: 90% = a 10% reduction; this is only a preview until a separate commercial approval exists.');
        formUI.append(explanation);
        const currentTemplate=()=>data.native_templates.find(x=>x.id===template.value);
        const syncTemplate=()=>{
          const t=currentTemplate();
          numeric.disabled=t?.strategy==='unchanged';
          currency.disabled=['unchanged','percent','delta'].includes(t?.strategy);
          currency.parentElement.hidden=currency.disabled;
          const valueLabel=numeric.parentElement.querySelector('span');
          if(valueLabel)valueLabel.textContent=t?.strategy==='percent'
            ?'Price multiplier (% of source rate)':t?.strategy==='fixed'
            ?'Fixed price amount':t?.strategy==='delta'
            ?'Amount adjustment':t?.strategy==='floor'
            ?'Price floor':t?.strategy==='cap'?'Price cap':'Template value';
          if(t?.strategy==='unchanged')numeric.value='';
          else if(numeric.value==='' && t?.value!=='')numeric.value=String(t?.value??100);
          sliderWrap.hidden=t?.strategy!=='percent';
          syncSlider();
          if(t?.strategy==='percent')
            explanation.textContent='100% = published price · 90% = 10% discount. Native rules and existing overrides still apply.';
          else explanation.textContent='This is a Pricing Lab simulation. No automatic campaign, coupon or checkout rule will be enabled.';
        };
        template.addEventListener('change',()=>{
          const t=currentTemplate();
          numeric.value=String(t?.value??'');
          currency.value=String(t?.currency||'');
          syncTemplate();
          updateSummary();
          result.replaceChildren();
        });
        const chooseValues=()=>{
          valuesArea.replaceChildren(legend);
          if(cached.kind==='range'){
            const min=control('Minimum '+item.label,'number');
            const max=control('Maximum '+item.label,'number');
            // Move inside the native fieldset, preserving accessible labels.
            min.parentElement.remove(); max.parentElement.remove();
            valuesArea.append(min.parentElement,max.parentElement);
            min.value=String(cached.min??0); max.value=String(cached.max??0);
            return ()=>({id:item.id,min:min.value,max:max.value});
          }
          const list=make('div','mad4b-ws-policy-terms-list');
          const checks=[];
          for(const option of (cached.options||[]).slice(0,100)){
            const label=make('label','mad4b-ws-policy-term');
            const input=make('input');
            input.type='checkbox';input.value=String(option.id);
            label.append(input,make('span','',String(option.label)));
            const itemWrap=make('div','mad4b-ws-policy-term-item');
            itemWrap.append(label);
            help?.attach(itemWrap,'term',
              cached.help?.options?.[String(option.id)]||{
                title:String(option.label),
                text:help?.message('term')||'Select tours with this term.'
              });
            list.append(itemWrap);checks.push(input);
          }
          valuesArea.append(list);
          if(!checks.length)valuesArea.append(make('p','','No published values for the company authoring language.'));
          return ()=>({id:item.id,values:checks.filter(x=>x.checked).map(x=>x.value),
            match:match.value});
        };
        let readRule=chooseValues();

        // Four progressive stages use the same native JetEngine dimension
        // options and exactly the existing Pricing Lab AJAX contract.
        // Moving already-created controls retains their values/handlers:
        // no cloned forms, new persist APIs or duplicate pricing engines.
        const policySummary=make('div','mad4b-ws-policy-summary');
        policySummary.setAttribute('role','status');
        policySummary.setAttribute('aria-live','polite');
        policySummary.append(
          make('strong','','Policy summary'),
          make('p','','Choose a JetEngine condition to describe this Draft.')
        );
        formUI.prepend(policySummary);
        const step=(number,title,hint)=>{
          const section=make('section','mad4b-ws-policy-step');
          section.dataset.step=String(number);
          const heading=make('div','mad4b-ws-policy-step-heading');
          const index=make('span','mad4b-ws-policy-step-number',String(number));
          const text=make('div','mad4b-ws-policy-step-text');
          text.append(make('h4','',title),make('p','',hint));
          heading.append(index,text);
          const key=number===1?'targeting':
            number===2?'pricing':number===3?'scope':'impact';
          help?.attach(heading,key,{title,text:help.message(key)});
          const body=make('div','mad4b-ws-policy-step-body');
          section.append(heading,body);
          formUI.append(section);
          return {section,body};
        };
        const targetingStep=step(1,'Targeting conditions',
          'Choose the canonical JetEngine terms this policy must match.');
        targetingStep.body.append(title.parentElement,valuesArea);
        if(cached.kind!=='range')targetingStep.body.append(match.parentElement);
        else match.parentElement.remove();

        const pricingStep=step(2,'Pricing rule',
          'Use the existing Pricing Lab template; monetary calculation stays in Shared Product Core.');
        const priceBasis=make('div','mad4b-ws-policy-price-basis');
        priceBasis.setAttribute('role','note');
        const priceBasisHeading=make('div','mad4b-ws-policy-price-basis-header');
        priceBasisHeading.append(make('strong','','Reference price for this Draft'));
        help?.attach(priceBasisHeading,'price_basis',{
          title:'What price is the percentage applied to?',
          text:help.message('price_basis')
        });
        const priceBasisDetail=make('p','',
          'Published Tour Rate → current effective price · International / General · Double');
        const priceBasisCaveat=make('small','',
          'If an active sale exists, the Rate sale is used and stacking needs review. Validity uses today’s server time, not a chosen travel date. Direct Tour starting price is not used.');
        priceBasis.append(priceBasisHeading,priceBasisDetail,priceBasisCaveat);
        const advanced=make('details','mad4b-ws-policy-advanced');
        const advancedHeading=make('summary','','Change Rate audience & occupancy');
        const advancedBody=make('div','mad4b-ws-policy-advanced-body');
        advancedBody.append(audience.parentElement,occupancy.parentElement);
        advanced.append(advancedHeading,advancedBody);
        pricingStep.body.append(template.parentElement,numeric.parentElement,
          priceBasis,sliderWrap,currency.parentElement,advanced,explanation);

        const scopeStep=step(3,'Policy scope',
          'Canonical language inheritance by default; restrict it only when a group is added.');
        const scopeStatus=make('p','mad4b-ws-policy-scope-status');
        const groupDetails=make('details','mad4b-ws-policy-scope-details');
        groupDetails.append(make('summary','','Customize languages & markets'),groupArea);
        scopeStep.body.append(scopeStatus,groupDetails);

        const reviewStep=step(4,'Impact & validation',
          'Preview actual Tour Rate matches and conflicts before saving an inactive Draft.');
        const updateSummary=()=>{
          priceBasisDetail.textContent='Published Tour Rate → '+
            'current effective '+(currentTemplate()?.strategy==='percent'?'price':'Rate reference price')+
            ' · '+audience.selectedOptions[0]?.textContent+
            ' · '+occupancy.selectedOptions[0]?.textContent+
            ' · currency from Rate';
          const rule=readRule();
          const terms=cached.kind==='range'
            ?[String(rule.min||'0')+'–'+String(rule.max||'0')]
            :(cached.options||[])
              .filter(option=>(rule.values||[]).includes(String(option.id)))
              .map(option=>String(option.label));
          const condition=terms.length
            ?terms.join(rule.match==='all'?' AND ':' OR ')
            :'Select at least one '+item.label+' value';
          const t=currentTemplate();
          const amount=Number(numeric.value);
          let adjustment='Original price (unchanged)';
          if(t?.strategy==='percent'){
            if(numeric.value!==''&&Number.isFinite(amount)){
              const diff=100-amount;
              adjustment=Math.abs(diff)<0.00001?'No price adjustment'
                :diff>0?String(Number(diff.toFixed(4)))+'% decrease'
                :String(Number((-diff).toFixed(4)))+'% increase';
            }else adjustment='Enter a price multiplier';
          }else if(t?.strategy==='fixed')
            adjustment='Fixed '+(numeric.value||'—')+' '+(currency.value||'(currency required)');
          else adjustment=(t?.label||'Native template')+' · '+(numeric.value||'—');
          const scoped=groupRows.length>0;
          scopeStatus.textContent=scoped
            ?groupRows.length+' explicit target group'+(groupRows.length===1?'':'s')+
              ' · display-language/market scope replaces unrestricted inheritance · OR between groups.'
            :'Default: inherit from '+String(languageScope().authoring_language).toUpperCase()+
              ' to published WPML translations · no market restriction.';
          const summary=policySummary.querySelector('p');
          summary.textContent=item.label+': '+condition+' · '+adjustment+
            ' · '+audience.selectedOptions[0]?.textContent+
            ' · '+occupancy.selectedOptions[0]?.textContent+
            ' · '+(scoped?groupRows.length+' explicit scope group(s)':'all translated Tours');
        };
        formUI.addEventListener('input',updateSummary);
        formUI.addEventListener('change',updateSummary);
        updateSummary();
        syncTemplate();
        updateSummary();

        const actions=make('div','mad4b-ws-policy-actions');
        const preview=make('button','button button-primary','Evaluate impact · AJAX');
        const save=make('button','button','Save inactive Draft');
        preview.type=save.type='button';
        let lastPreviewConfig=null;
        save.disabled=true;
        const invalidate=()=>{
          lastPreviewConfig=null;save.disabled=true;
          // Any field change invalidates the previously certified dry-run.
          // Keep the last result visually distinguishable as outdated.
          if(result.childElementCount)result.dataset.stale='true';
        };
        formUI.addEventListener('input',invalidate);
        formUI.addEventListener('change',invalidate);
        actions.append(preview,save);
        reviewStep.body.append(actions,info,result);
        const build=()=>{
          if(!selectedSet.has(item.id))
            throw Error('Enable this dimension and save company preferences first.');
          const condition=readRule();
          if(cached.kind!=='range' && !condition.values?.length)
            throw Error('Choose at least one JetEngine value for the condition.');
          const t=currentTemplate();
          if(!t)throw Error('Select a native pricing template.');
          if(title.value.trim().length<3)throw Error('Name the strategy draft.');
          if(['fixed','floor','cap'].includes(t?.strategy)&&!currency.value)
            throw Error('Choose a supported amount currency from the JetEngine Glossary.');
          const selectedScope=languageScope();
          if(selectedScope.changed)
            throw Error('Save the company language switch first, then reopen this policy.');
          const groups=readTargetGroups();
          return {title:title.value.trim(),note:'Created from native Pricing Controls · '+item.label,
            template_id:t.id,value:numeric.value,currency:currency.value,
            audience:audience.value,occupancy:occupancy.value,
            language_scope:groups.length?'target_groups':'inherit_default',
            authoring_language:selectedScope.authoring_language,
            target_groups:groups,
            languages:[selectedScope.authoring_language],markets:[],
            logic:'and',rules:[condition]};
        };
        const run=async(op)=>{
          if(busy)return;
          busy=true;
          preview.disabled=save.disabled=true;
          try{
            const config=build();
            const signature=JSON.stringify(config);
            if(op==='save_draft' && signature!==lastPreviewConfig)
              throw Error('First run a fresh impact preview for this exact policy.');
            info.textContent=op==='preview'?'Running source-backed dry-run…':
              'Saving versioned inactive Pricing Lab Draft…';
            const payload={config:JSON.stringify(config)};
            if(op==='save_draft')payload.confirm='save_inactive_preset_draft';
            const response=await policyRequest(op,payload);
            if(op==='preview'){
              renderResult(response);
              delete result.dataset.stale;
              lastPreviewConfig=signature;
              info.textContent='Preview only · review conflicts before saving any Draft.';
            }else{
              result.replaceChildren(make('strong','',
                'Inactive Draft saved · revision '+response.revision));
              result.append(make('p','',
                'Draft ID: '+response.id+' · no live prices, bookings, coupons or campaigns changed.'));
              const link=make('a','','Review in Pricing Lab');
              link.href=dimCfg.pricing_lab;
              result.append(link);
              info.textContent='Saved separately from native JetEngine settings.';
            }
          }catch(e){
            info.textContent=String(e.message);
          }finally{busy=false;preview.disabled=false;
            save.disabled=!lastPreviewConfig;
          }
        };
        preview.addEventListener('click',()=>run('preview'));
        save.addEventListener('click',()=>run('save_draft'));
        info.textContent='Ready · choose terms and test. Saved Rate prices remain unchanged.';
        ready=true;
      }catch(e){info.textContent='Could not load this dimension: '+String(e.message);}
      finally{busy=false;}
    };
    detail.addEventListener('mad4b-language-scope-reset',()=>{
      ready=false;cached=null;formUI.replaceChildren();result.replaceChildren();
      info.textContent='Company language policy changed. Save the native settings, then reopen to load the matching JetEngine values.';
    });
    detail.addEventListener('toggle',()=>{if(detail.open)load();});
    return detail;
  };

  const renderDimensionOptions=(data)=>{
    ensureDimInputs();
    dimPanel.replaceChildren();
    const all=data.catalog;
    const selected=new Set(data.enabled_ids);
    let mode=data.mode==='custom'?'custom':'all';
    // Canonical WPML policy is inherited by default. Language and market
    // conditions are activated ONLY on an individual targeting group.
    const languagePolicy=data.pricing_language||{default_language:'en'};
    const languageScope=()=>({
      mode:'inherit_default',
      authoring_language:languagePolicy.default_language||'en',
      changed:false
    });
    const scopePanel=make('p','mad4b-ws-pricing-language-scope',
      'Default: author pricing once in '+String(languagePolicy.default_language||'en').toUpperCase()+
      ' and inherit across display languages. Add an explicit display-language or market group inside a policy to limit its reach.');
    const updateLanguageScope=()=>{
      dimInput.languageMode.value='inherit_default';
      dimInput.authoringLanguage.value=languagePolicy.default_language||'en';
    };
    const helper=make('p','mad4b-ws-dimension-helper',
      'Selections control which JetEngine conditions Pricing Lab can use for new or revalidated strategy drafts. They do not alter published prices or bypass Rate overrides.');
    // Each action needs a visible outcome and accessible explanation.
    // The old label "Inherit existing" was misleading: mode=all actually
    // auto-includes newly discovered JetEngine dimensions in the future.
    const toolbar=make('div','mad4b-ws-dimension-tools');
    toolbar.setAttribute('role','group');
    toolbar.setAttribute('aria-label','Choose how Pricing Lab dimensions are available');
    const actionHelp=[
      ['recommended','Recommended',
        'Use MAD4B’s predefined core dimensions, such as destination, season and schedule. Not a personalized recommendation.'],
      ['all','Select current',
        'Select every dimension discovered right now as a fixed custom list. New fields are not added automatically.'],
      ['none','Select none',
        'Remove all dimension choices for new or revalidated Pricing Lab drafts. Existing Rate prices and saved policies are not deleted.'],
      ['inherit','Auto-include all',
        'Use all current dimensions and automatically include new JetEngine dimensions discovered later.']
    ];
    // Extensive explanatory text belongs in ONE optional help disclosure,
    // not in four always-expanded cards preceding the repeater groups.
    // aria-describedby continues to reference each original caption id.
    const guidance=make('details','mad4b-ws-dimension-guidance');
    const guidanceSummary=make('summary','','About targeting, WPML & source-price authority');
    guidance.append(guidanceSummary);
    const choiceHelp=make('details','mad4b-ws-dimension-choice-help');
    choiceHelp.append(make('summary','','What do these four options do?'));
    const actionButtons=new Map();
    for(const [key,label,description] of actionHelp){
      const wrapper=make('div','mad4b-ws-dimension-action');
      const button=make('button','button',label);
      const caption=make('small','mad4b-ws-dimension-action-help',description);
      caption.id='mad4b-dimension-action-help-'+key;
      button.type='button';
      button.setAttribute('aria-describedby',caption.id);
      button.title=description;
      wrapper.append(button);
      choiceHelp.append(caption);
      toolbar.append(wrapper);
      actionButtons.set(key,button);
    }
    const recommend=actionButtons.get('recommended');
    const selectAll=actionButtons.get('all');
    const clearAll=actionButtons.get('none');
    const inherit=actionButtons.get('inherit');
    const actionFeedback=make('p','mad4b-ws-dimension-action-feedback');
    actionFeedback.setAttribute('role','status');
    actionFeedback.setAttribute('aria-live','polite');
    let lastAction='';
    const container=make('div','mad4b-ws-dimension-groups');
    const groups=new Map();
    const inputs=new Map();
    const groupCounts=new Map();
    const groupKeys=['audience','booking_model','season','schedule','destination',
      'style','package','duration','group_size','other'];
    for(const item of all){
      const key=dimLabels[item.group]?item.group:'other';
      if(!groups.has(key))groups.set(key,[]);
      groups.get(key).push(item);
    }
    const ordered=[...groupKeys.filter(key=>groups.has(key)),
      ...[...groups.keys()].filter(key=>!groupKeys.includes(key))];
    const updateState=(dirty=false)=>{
      if(mode==='all')for(const item of all)selected.add(item.id);
      else for(const id of [...selected])if(!all.some(item=>item.id===id))selected.delete(id);
      for(const [id,input] of inputs)input.checked=selected.has(id);
      for(const [group,rec] of groupCounts){
        const count=groups.get(group).filter(x=>selected.has(x.id)).length;
        rec.textContent=count+'/'+groups.get(group).length+' active';
      }
      dimInput.mode.value=mode;
      dimInput.selected.value=JSON.stringify([...selected]);
      dimBadge.textContent=selected.size+'/'+all.length+' active';
      const modeText=mode==='all'?'Automatic: all current and future dimensions':
        'Custom: '+selected.size+' of '+all.length+' current dimensions';
      const actionName=lastAction
        ?({recommended:'Recommended preset',all:'Fixed selection of all current fields',
            none:'No dimensions selected',inherit:'Automatic inclusion',
            custom:'Custom field selection'}[lastAction]||'Manual selection')
        :modeText;
      actionFeedback.textContent=(dirty?'Not saved: ':'Current setting: ')+
        actionName+' · '+modeText+
        (dirty?' · Click Save changes to keep this selection.':' · No change to published prices.');
      for(const [key,button] of actionButtons){
        const active=key==='inherit'&&mode==='all' ||
          key==='none'&&mode==='custom'&&selected.size===0 ||
          key==='all'&&mode==='custom'&&selected.size===all.length ||
          key==='recommended'&&mode==='custom'&&selected.size>0 &&
            selected.size===all.filter(item=>recommendedIDs.has(item.id)).length &&
            all.every(item=>selected.has(item.id)===recommendedIDs.has(item.id));
        button.classList.toggle('is-active',active);
        button.setAttribute('aria-pressed',String(active));
      }
      const pending=dimPanel.querySelector('.mad4b-ws-dimension-pending');
      if(pending)pending.textContent=(mode==='all'?'Inherit all available fields':'Custom selection')+
        (dirty?' · Save changes to apply':' · '+(data.saved?'Saved preference loaded':'Existing behavior preserved'));
      if(dirty)change();
    };
    for(const key of ordered){
      const details=make('details','mad4b-ws-dimension-group');
      if(['audience','schedule','destination'].includes(key))details.open=true;
      const summary=make('summary');
      const label=make('strong','',dimLabels[key]||key);
      const number=make('small','');
      summary.append(label,number);
      details.append(summary);
      groupCounts.set(key,number);
      const list=make('div','mad4b-ws-dimension-items');
      for(const item of groups.get(key)){
        const label=make('label','mad4b-ws-dimension-item');
        const check=make('input');
        check.type='checkbox';
        check.value=item.id;check.checked=selected.has(item.id);
        check.setAttribute('aria-label','Use '+item.label+' as strategy dimension');
        const copy=make('span','mad4b-ws-dimension-copy');
        copy.append(make('strong','',item.label));
        const countText=make('small','',
          (item.source==='taxonomy'?'Taxonomy':
            item.source==='meta_box'?'Meta Box':'CPT Meta Field')+
          ' · '+(item.kind==='range'?'numeric range':
            item.options_count+' available values'));
        if(item.kind!=='range' &&
          item.wpml_term_records_count>item.options_count){
          countText.title=String(item.options_count)+' selectable values in '+
            String(item.source_language||'default').toUpperCase()+
            '; '+String(item.wpml_term_records_count)+
            ' source and translated WPML records across display languages.';
        }
        copy.append(countText);
        if(item.is_advanced)copy.append(make('em','','Review before using in campaigns'));
        label.append(check,copy);
        const dimension=make('div','mad4b-ws-dimension-row');
        dimension.append(label,policyRow(item,data,selected,languageScope));
        list.append(dimension);
        inputs.set(item.id,check);
        check.addEventListener('change',()=>{
          mode='custom';lastAction='custom';
          if(check.checked)selected.add(item.id);
          else selected.delete(item.id);
          updateState(true);
        });
      }
      details.append(list);
      container.append(details);
    }
    const pending=make('p','mad4b-ws-dimension-pending','');
    const core=make('p','mad4b-ws-dimension-core',
      'Price calculation inputs (Egyptians / International and Single / Double / Triple) still come from published Tour Rates and the native pricing policy. Configure their rules in Rate Controllers; dimension choices here only scope strategy targeting.');
    const lab=make('a','mad4b-ws-dimension-lab','Open Pricing Lab · impact preview');
    lab.href=dimCfg.pricing_lab;
    lab.setAttribute('aria-label','Inspect matching tours in Pricing Lab');
    recommend.addEventListener('click',()=>{
      mode='custom';lastAction='recommended';selected.clear();
      for(const item of all)if(recommendedIDs.has(item.id))selected.add(item.id);
      updateState(true);
    });
    selectAll.addEventListener('click',()=>{
      mode='custom';lastAction='all';selected.clear();
      for(const item of all)selected.add(item.id);
      updateState(true);
    });
    clearAll.addEventListener('click',()=>{
      mode='custom';lastAction='none';selected.clear();updateState(true);
    });
    inherit.addEventListener('click',()=>{
      mode='all';lastAction='inherit';selected.clear();
      for(const item of all)selected.add(item.id);
      updateState(true);
    });
    // Present the actual dimension groups directly after the action toolbar;
    // long explanations and per-action guidance remain available on demand.
    // No field, checkbox, form input or saved policy is cloned or removed.
    guidance.append(scopePanel,helper,core);
    dimPanel.append(toolbar,actionFeedback,container,pending,
      guidance,choiceHelp,lab);
    updateState(false);
    updateLanguageScope(false);
  };
  const setDimensionOpen=(open)=>{
    dimensionOpen=!!open;
    dimPanel.hidden=!dimensionOpen;
    dimButton.setAttribute('aria-expanded',String(dimensionOpen));
    dimButton.textContent=dimensionOpen?'Hide dimensions':'Configure dimensions';
    if(dimensionOpen)loadDimensions();
  };
  dimButton.addEventListener('click',()=>setDimensionOpen(!dimensionOpen));
  // The existing native JetEngine engine switch remains the only activation
  // control. Opening dimensions after turning it on is UX only.
  const nativeEngineControl=controls.find(c=>c.key==='pricing_engine_enabled')?.el;
  const engineBadge=make('span','mad4b-ws-engine-status','');
  const nativeEngineInput=nativeEngineControl?.querySelector('input[type=checkbox]');
  const showEngineState=()=>{
    if(!dimCfg||!nativeEngineControl)return;
    const saved=!!dimCfg.saved_engine_on;
    const local=nativeEngineInput?!!nativeEngineInput.checked:saved;
    engineBadge.textContent=local===saved
      ?('Engine configuration: '+(saved?'ON':'OFF')+' · Not a live Rate approval')
      :('Engine configuration saved: '+(saved?'ON':'OFF')+
        ' · Unsaved: '+(local?'ON':'OFF'));
    engineBadge.classList.toggle('is-pending',local!==saved);
  };
  if(dimCfg&&nativeEngineControl){
    // Status lives in MAD4B's own header, never inside JetEngine's label
    // column. The original control's layout remains entirely native.
    actions.prepend(engineBadge);
    showEngineState();
  }
  nativeEngineControl?.addEventListener('change',()=>{
    showEngineState();
    const check=nativeEngineControl.querySelector('input[type=checkbox]');
    if(check?.checked)setDimensionOpen(true);
  });

  layout.append(sidebar,content);
  workspace.append(layout);
  root.prepend(workspace);

  // JetEngine native descriptions remain canonical. Only additional
  // company-specific notes can be toggled in a separate panel.
  let companyHelpOpen=false;
  const companyNotesFor=(c)=>{
    const g=aliases.get(c.key)?.guidance;
    if(!g||!g.available)return [];
    return [
      ...(g.source==='company' && g.text?[String(g.text)]:[]),
      ...(g.context?[String(g.context)]:[])
    ].filter(Boolean);
  };
  const hasCompanyNotes=isPricing&&controls.some(c=>companyNotesFor(c).length>0);
  helpMode.textContent='Company guidance';
  helpMode.hidden=!hasCompanyNotes;
  helpMode.addEventListener('click',()=>{
    companyHelpOpen=!companyHelpOpen;
    helpMode.setAttribute('aria-pressed',String(companyHelpOpen));
    renderCompanyGuidance();
  });
  const renderCompanyGuidance=()=>{
    companyGuidance.replaceChildren();
    companyGuidance.hidden=!companyHelpOpen || !isVisual;
    if(companyGuidance.hidden)return;
    const visible=controls.filter(c=>!c.el.classList.contains('mad4b-ws-filtered'));
    for(const c of visible){
      const notes=companyNotesFor(c);
      if(!notes.length)continue;
      const item=make('div','mad4b-ws-company-note');
      item.append(make('strong','',c.label));
      for(const note of notes)item.append(make('p','',note));
      companyGuidance.append(item);
    }
    if(!companyGuidance.children.length)
      companyGuidance.append(make('p','','No additional company notes for these fields.'));
  };

  // The original top-level JetEngine form remains exactly where it was.
  // Only presentation classes are modified. No DOM re-parenting of controls.
  // Style the root's existing direct child as the editor's main column.
  let sectionElement=top;
  while(sectionElement.parentElement && sectionElement.parentElement!==root) {
    sectionElement=sectionElement.parentElement;
  }
  if(sectionElement.parentElement===root) sectionElement.classList.add('mad4b-ws-original');
  else return;
  const controlsBySection=(id)=>controls.filter(c=>c.group===id);
  let current=buttons.keys().next().value||(isPricing?'advanced':'general');
  let isVisual=true;
  let isDirty=false;
  let isSubmitting=false;

  function showSection(id){
    if(!buttons.has(id))return;
    current=id;
    const def=SECTION.find(s=>s.id===id);
    sectionLabel.textContent=def.label;
    sectionHint.textContent=def.hint;
    search.value='';
    render();
  }
  function render(){
    const term=search.value.toLowerCase().trim();
    let visible=0;
    for(const c of controls){
      const matches=!term
        ? c.group===current
        : (c.label+' '+c.key+' '+c.mode+' '+
          (aliases.get(c.key)?.guidance?.text||'')+' '+(aliases.get(c.key)?.guidance?.context||'')).toLowerCase().includes(term);
      c.el.classList.toggle('mad4b-ws-filtered',!matches);
      c.el.classList.toggle('mad4b-ws-selected',matches);
      if(matches)visible++;
    }
    for (const [id,button] of buttons) {
      const selected=(!term && id===current);
      button.classList.toggle('is-active',selected);
      button.setAttribute('aria-current',selected?'true':'false');
    }
    if(term){sectionLabel.textContent='Search results';sectionHint.textContent='Matching existing JetEngine controls';}
    else {const def=SECTION.find(s=>s.id===current);sectionLabel.textContent=def.label;sectionHint.textContent=def.hint;}
    listing.textContent=visible+' editable '+(visible===1?'field':'fields');
    currencyOwner.hidden=!isPricing || !!term || current!=='overview';
    dimLauncher.hidden=!(dimCfg && !term && current==='overview');
    dimPanel.hidden=dimLauncher.hidden || !dimensionOpen;
    providerPanel.hidden=!isPricing||!(current==='sources'&&!term);
    if(!providerPanel.hidden&&providerPanel.open&&window.MAD4BProviderPriceSources)
      window.MAD4BProviderPriceSources.mount(providerPanel);
    renderCompanyGuidance();
    // Hide empty vendor wrappers but do not remove or reparent them.
    for (const comp of top.querySelectorAll('.cx-component')) {
      if (comp.closest('.mad4b-ws-control')) continue;
      const children=comp.querySelectorAll('.mad4b-ws-control');
      if(children.length) comp.classList.toggle('mad4b-ws-wrapper-empty',
        !Array.from(children).some(el=>!el.classList.contains('mad4b-ws-filtered')));
    }
    // JetEngine tab panels are hidden unless they have the native .show class.
    // Functional groups span several native tabs, so they cannot inherit that state.
    // Only empty native panels remain hidden in Functional Mode.
    for (const panel of top.querySelectorAll('.cx-tab__body .cx-settings > .cx-settings__content')) {
      const fields=panel.querySelectorAll('.mad4b-ws-control');
      panel.classList.toggle('mad4b-ws-tab-empty', !Array.from(fields)
        .some(el=>!el.classList.contains('mad4b-ws-filtered')));
    }
  }
  for(const [id,button] of buttons)button.addEventListener('click',()=>showSection(id));
  search.addEventListener('input',render);
  function setView(on){
    isVisual=on;
    root.classList.toggle('mad4b-ws-visual',on);
    root.classList.toggle('mad4b-ws-native',!on);
    document.dispatchEvent(new CustomEvent('mad4b:jetengine:mode-changed'));
    native.hidden=!on;
    visual.hidden=on;
    sidebar.hidden=!on;
    content.hidden=!on;
    if(!on){
      companyGuidance.hidden=true;
      for(const c of controls)c.el.classList.remove('mad4b-ws-filtered');
      top.querySelectorAll('.mad4b-ws-wrapper-empty,.mad4b-ws-tab-empty')
        .forEach(el=>el.classList.remove('mad4b-ws-wrapper-empty','mad4b-ws-tab-empty'));
    }else render();
  }
  native.addEventListener('click',()=>setView(false));
  visual.addEventListener('click',()=>setView(true));
  // One actual Save action: JetEngine's original submit in its original form.
  // Browser validation and JetEngine's POST/redirect contract remain unchanged.
  const vendorSaveBar=nativeSubmit.closest('.dialog-save');
  if(vendorSaveBar){
    vendorSaveBar.classList.add('mad4b-ws-native-save-bar');
    vendorSaveBar.setAttribute('role','group');
    vendorSaveBar.setAttribute('aria-label','Save JetEngine settings');
    const description=make('span','mad4b-ws-native-save-help',
      'Save this page’s settings using JetEngine');
    vendorSaveBar.prepend(description);
  }
  let submitStarted=false;
  form.addEventListener('submit',event=>{
    if(event.defaultPrevented)return;
    if(!form.checkValidity()){
      setView(false);
      state.textContent='Please correct invalid JetEngine fields';
      state.classList.add('is-dirty');
      return;
    }
    submitStarted=true;
    state.textContent='Saving with JetEngine…';
    state.classList.remove('is-dirty');
    state.classList.add('is-saving');
    nativeSubmit.classList.add('is-saving');
    nativeSubmit.textContent='Saving…';
    // Don't disable the native submit: disabling it can alter form serialization
    // or JetEngine's own save handling. The server response owns success state.
  });
  window.addEventListener('beforeunload',event=>{
    if(!isDirty||submitStarted)return;
    event.preventDefault();
    event.returnValue='';
  });
  const change=()=>{
    isDirty=true;
    state.textContent='Unsaved changes';
    state.classList.remove('is-saving');
    state.classList.add('is-dirty');
    if(submitStarted){
      // A native validation/plugin veto can keep this page mounted.
      submitStarted=false;
      nativeSubmit.classList.remove('is-saving');
      nativeSubmit.textContent='Save changes';
    }
  };
  top.addEventListener('input',change);
  top.addEventListener('change',change);
  // No "Saved" message based on a click; only JetEngine's ?dialog-saved=1
  // response to the actual saved native form is treated as confirmation.

  // A large native price-source repeater needs a regular-flow Save button;
  // a sticky footer visually covers fields while scrolling an open row.
  const updatePriceSourcesSave=()=>{
    const priceSources = root.classList.contains('mad4b-ws-visual')
      && root.querySelector('.mad4b-ws-nav-btn.is-active')?.dataset.section==='sources';
    root.classList.toggle('mad4b-ws-price-source-active',Boolean(priceSources));
  };
  root.addEventListener('click',event=>{
    if(event.target.closest('.mad4b-ws-nav-btn,.mad4b-ws-secondary'))
      requestAnimationFrame(updatePriceSourcesSave);
  });
  updatePriceSourcesSave();

  // JetEngine is the sole renderer for numeric fields, sliders and repeaters.
  // MAD4B does not append parallel inputs or actions to native fields.
  // Future modules enhance the live vendor control without duplicating it.
  for (const c of controls) {
    const enhance=window.MAD4BJetModeEnhancers?.[c.mode];
    if(typeof enhance==='function'){
      try {enhance({nativeControl:c.el, field:aliases.get(c.key)||null, form, notifyChange:change});}
      catch(error){console.warn('MAD4B enhancement skipped',c.mode);}
    }
  }
  setView(true);
  // Contextual help augments only vendor captions; no changes to
  // native JetEngine input names, repeater state or save handlers.
  help?.decorateNative(root,cfg.fields);
})();
