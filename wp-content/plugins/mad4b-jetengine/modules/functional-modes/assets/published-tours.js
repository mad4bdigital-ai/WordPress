(() => {
  'use strict';
  const cfg = window.MAD4BJetModePage;
  const root = document.querySelector('.jet-engine-options-page-wrap');
  const api = cfg?.publishedTours;
  if (!root || !api?.ajax_url || !api?.nonce || !api?.action || root.querySelector('.mad4b-tour-inspector')) return;

  const create = (tag, css, content) => {
    const node=document.createElement(tag);
    if (css) node.className=css;
    if (content !== undefined) node.textContent=String(content);
    return node;
  };
  // Human-readable Rate identity: real WPML season term + native Rate
  // currency + source validity. The <option> value remains the exact Rate ID.
  const rateDisplayLabel = rate => {
    if(typeof rate.canonical_title==='string'
       && rate.canonical_title.startsWith('Rate #'+rate.id+' ·'))
      return rate.canonical_title;
    const parts=['Rate #'+rate.id];
    parts.push(typeof rate.season==='string' && rate.season.trim()
      ?rate.season.trim():'Season not specified');
    const cur=rate.base_currency || rate.price_matrix?.groups?.international?.currency
      || rate.currency;
    parts.push(typeof cur==='string' && /^[A-Z]{3}$/.test(cur)
      ?cur:'Currency not specified');
    const start=typeof rate.start==='string' && /^\d{4}-\d{2}-\d{2}$/.test(rate.start)
      ?rate.start:null;
    const end=typeof rate.end==='string' && /^\d{4}-\d{2}-\d{2}$/.test(rate.end)
      ?rate.end:null;
    if(start || end)parts.push((start||'Unspecified')+' → '+(end||'Unspecified'));
    else parts.push('Validity not specified');
    return parts.join(' · ');
  };
  const aside=create('aside','mad4b-tour-inspector');
  aside.setAttribute('aria-label','Published tours preview');
  const header=create('div','mad4b-tour-inspector__header');
  const heading=create('div','');
  heading.append(create('span','mad4b-tour-inspector__eyebrow','LIVE CATALOG · THIS SITE'),
    create('h3','','Tour preview'));
  const clear=create('button','mad4b-tour-inspector__reset','Clear');
  clear.type='button';
  header.append(heading,clear);
  const note=create('p','mad4b-tour-inspector__note',
    'Published tours only. Starting prices are published source values, not recalculated quotes.');
  const search=create('input','mad4b-tour-inspector__search');
  search.type='search';
  search.placeholder='Search published tours…';
  search.setAttribute('aria-label','Search published tours');
  const scope=create('section','mad4b-tour-inspector__scope');
  const scopeHeader=create('div','mad4b-tour-inspector__subheading');
  scopeHeader.append(create('strong','','Published content scope'),
    create('span','','Language · country'));
  const languageDetails=create('details','mad4b-tour-inspector__scope-group');
  const languageSummary=create('summary','','Content languages');
  const languageList=create('div','mad4b-tour-inspector__scope-options');
  languageDetails.append(languageSummary,languageList);
  const marketDetails=create('details','mad4b-tour-inspector__scope-group');
  const marketSummary=create('summary','','Target market countries');
  const marketList=create('div','mad4b-tour-inspector__scope-options');
  marketDetails.append(marketSummary,marketList);
  scope.append(scopeHeader,languageDetails,marketDetails);
  const filterTitle=create('div','mad4b-tour-inspector__subheading');
  filterTitle.append(create('strong','','Filter by'),create('span','', 'Multi-select'));
  const facetTools=create('div','mad4b-tour-inspector__filter-tools');
  const sourcePicker=document.createElement('select');
  sourcePicker.setAttribute('aria-label','Filter source');
  [['all','All filters'],['taxonomy','Taxonomies'],['meta','CPT Meta Fields'],
    ['relation','Relations & services']].forEach(([value,label])=>{
    sourcePicker.add(new Option(label,value));
  });
  const facetSearch=create('input','mad4b-tour-inspector__facet-search-global');
  facetSearch.type='search';
  facetSearch.placeholder='Find a filter…';
  facetSearch.setAttribute('aria-label','Search filters');
  const inventory=create('span','mad4b-tour-inspector__inventory','');
  const logicPanel=create('label','mad4b-tour-inspector__logic');
  const logicTitle=create('span','','Combine filter groups');
  const logicSelect=document.createElement('select');
  logicSelect.setAttribute('aria-label','Combine filter groups');
  [['and','AND · match all groups'],['or','OR · match any group']]
    .forEach(([value,label])=>logicSelect.add(new Option(label,value)));
  logicPanel.append(logicTitle,logicSelect);
  const logicHelp=create('p','mad4b-tour-inspector__logic-help',
    'Taxonomies, CPT Meta Fields and Relations combine using the selected rule. Language and country always restrict the preview.');
  facetTools.append(sourcePicker,facetSearch,logicPanel,logicHelp,inventory);
  const facetRoot=create('div','mad4b-tour-inspector__facets');
  const selections=create('div','mad4b-tour-inspector__chips');
  selections.setAttribute('aria-label','Selected filters');
  // A compact filter panel leaves real published results as the primary content.
  const filterSection=create('details','mad4b-tour-inspector__filter-section');
  const filterSectionSummary=create('summary','mad4b-tour-inspector__section-summary');
  const filterSectionName=create('span','', 'Filter by');
  const filterSectionBadge=create('small','mad4b-tour-inspector__section-count','All filters');
  filterSectionSummary.append(filterSectionName,filterSectionBadge);
  const filterSectionBody=create('div','mad4b-tour-inspector__section-body');
  const viewResults=create('button','mad4b-tour-inspector__view-results','View matching tours');
  viewResults.type='button';
  filterSectionBody.append(facetTools,facetRoot,selections,viewResults);
  filterSection.append(filterSectionSummary,filterSectionBody);

  const resultsSection=create('details','mad4b-tour-inspector__results-section');
  resultsSection.open=false;
  const resultsSummary=create('summary','mad4b-tour-inspector__section-summary');
  const resultsTitle=create('span','','Published tours');
  const resultsBadge=create('small','mad4b-tour-inspector__section-count','Loading…');
  resultsSummary.append(resultsTitle,resultsBadge);
  const resultsBody=create('div','mad4b-tour-inspector__section-body');
  const status=create('div','mad4b-tour-inspector__status','Loading published tours…');
  status.setAttribute('role','status');
  status.setAttribute('aria-live','polite');
  const sortWrap=create('label','mad4b-tour-inspector__sort');
  sortWrap.append(create('span','','Sort'));
  const sort=document.createElement('select');
  [['newest','Newest first'],['title','Title A–Z']].forEach(([v,label])=>{
    const option=new Option(label,v);
    sort.add(option);
  });
  sortWrap.append(sort);
  const list=create('div','mad4b-tour-inspector__results');
  const pagination=create('div','mad4b-tour-inspector__pagination');
  const previous=create('button','', 'Previous');
  const next=create('button','','Next');
  previous.type=next.type='button';
  const pageLabel=create('span','','');
  pagination.append(previous,pageLabel,next);
  resultsBody.append(status,sortWrap,list,pagination);
  resultsSection.append(resultsSummary,resultsBody);

  // Affected Tours replaces the duplicated Selected Tour accordion.
  // The slide is the Tour identity; detailed native Rates remain below it.
  const previewSection=create('section','mad4b-tour-inspector__affected-tours');
  previewSection.setAttribute('aria-label','Affected tours slider');
  const previewHeader=create('div','mad4b-tour-inspector__affected-header');
  const previewTitle=create('strong','','Affected tours');
  const previewBadge=create('small','mad4b-tour-inspector__section-count','Loading…');
  previewHeader.append(previewTitle,previewBadge);
  const previewBody=create('div','mad4b-tour-inspector__affected-body');
  const sliderTop=create('div','mad4b-tour-inspector__slider-controls');
  const sliderCount=create('span','mad4b-tour-inspector__slider-position','');
  sliderCount.setAttribute('aria-live','polite');
  sliderCount.setAttribute('aria-atomic','true');
  const sliderNav=create('div','mad4b-tour-inspector__slider-nav');
  const slidePrevious=create('button','mad4b-tour-inspector__slider-arrow','‹');
  const slideNext=create('button','mad4b-tour-inspector__slider-arrow','›');
  slidePrevious.type=slideNext.type='button';
  slidePrevious.setAttribute('aria-label','Previous affected tour');
  slideNext.setAttribute('aria-label','Next affected tour');
  sliderNav.append(slidePrevious,slideNext);
  sliderTop.append(sliderCount,sliderNav);
  const sliderViewport=create('div','mad4b-tour-inspector__slider-viewport');
  sliderViewport.setAttribute('aria-label','Affected tours carousel');
  sliderViewport.setAttribute('role','group');
  sliderViewport.tabIndex=0;
  const sliderTrack=create('div','mad4b-tour-inspector__slider-track');
  sliderViewport.append(sliderTrack);
  const detail=create('div','mad4b-tour-inspector__detail');
  previewBody.append(sliderTop,sliderViewport,detail);
  previewSection.append(previewHeader,previewBody);

  // Schema-driven targeting. All source/term labels come from native JetEngine
  // taxonomies, Glossaries and attached Meta Boxes, never a brand-specific list.
  const strategySection=create('details','mad4b-tour-inspector__strategy-targeting');
  const strategyHead=create('summary','mad4b-tour-inspector__section-summary');
  const strategyName=create('strong','','Strategy Registry · Targeting');
  const strategyBadge=create('small','mad4b-tour-inspector__section-count','Inherit');
  strategyHead.append(strategyName,strategyBadge);
  const strategyBody=create('div','mad4b-tour-inspector__strategy-targeting-body');
  const strategyHint=create('p','mad4b-tour-inspector__strategy-targeting-hint',
    'Choose native Tour taxonomies, Glossary-backed fields and attached Meta Boxes. Strategy category is informational: the active pricing evaluator still follows the existing Tour Mode policy. No changes are saved here.');
  const strategyControls=create('div','mad4b-tour-inspector__strategy-targeting-controls');
  const typeSelect=document.createElement('select');
  typeSelect.setAttribute('aria-label','Strategy Registry type');
  typeSelect.add(new Option('Audience / Market / Schedule targeting','segment_targeting'));
  const modeSelect=document.createElement('select');
  modeSelect.setAttribute('aria-label','Strategy targeting mode');
  modeSelect.add(new Option('Inherit · all scoped tours','inherit'));
  modeSelect.add(new Option('Override · match conditions','override'));
  const combineSelect=document.createElement('select');
  combineSelect.setAttribute('aria-label','Strategy conditions logic');
  combineSelect.add(new Option('Match ALL conditions (AND)','and'));
  combineSelect.add(new Option('Match ANY condition (OR)','or'));
  const addStrategyControl=(name,control)=>{
    const label=create('label','mad4b-tour-inspector__strategy-target-label');
    label.append(create('span','',name),control);
    strategyControls.append(label);
  };
  addStrategyControl('Strategy category',typeSelect);
  addStrategyControl('Source mode',modeSelect);
  addStrategyControl('Conditions',combineSelect);
  const ruleRows=create('div','mad4b-tour-inspector__strategy-targeting-rules');
  const strategyActions=create('div','mad4b-tour-inspector__strategy-targeting-actions');
  const addRule=create('button','','Add condition');
  const applyRules=create('button','mad4b-tour-inspector__targeting-apply','Apply to Affected Tours');
  const clearRules=create('button','','Clear / Inherit');
  for(const button of [addRule,applyRules,clearRules])button.type='button';
  strategyActions.append(addRule,applyRules,clearRules);
  const strategyStatus=create('p','mad4b-tour-inspector__strategy-targeting-status',
    'Reading JetEngine schema…');
  strategyStatus.setAttribute('role','status');
  strategyStatus.setAttribute('aria-live','polite');
  strategyBody.append(strategyHint,strategyControls,ruleRows,strategyActions,strategyStatus);
  strategySection.append(strategyHead,strategyBody);

  const affected=create('p','mad4b-tour-inspector__draft','Changes in the editor are not applied to the published prices shown here.');
  const content=create('div','mad4b-tour-inspector__content');
  // Affected Tour is the main inspection surface; list remains an optional
  // expanded result index after the slider rather than pushing it below cards.
  content.append(note,scope,search,strategySection,filterSection,
    previewSection,resultsSection,affected);
  aside.append(header,content);
  root.append(aside);

  const state = {
    filters:{}, facets:[], items:[], page:1, pages:0, found:0,
    search:'', sort:'newest', selected:0, generation:0, controller:null,
    hadFacets:false, loading:false,
    languages:new Set(), defaultLanguage:'', availableLanguages:[],
    markets:new Set(), availableMarkets:[], filterInventory:null,
    logic:'and', taxonomyOperators:{}, expandedFilterGroups:new Set(),
    slidePendingEdge:null, slideIndex:0,
    strategyCatalog:null,strategyMode:'inherit',
    strategyRules:[],strategyCombine:'and'
  };
  const groupLabels={
    audience:'Audience / Good For',booking_model:'Booking model / Tour Mode',
    schedule:'Days & Time',destination:'Destinations',
    group_size:'Group Size',season:'Seasons',
    package:'Package Level',style:'Tour Types / Styles',
    duration:'Duration',other:'Other JetEngine fields'
  };
  const strategyDimensions=()=>state.strategyCatalog?.dimensions||[];
  const nativeDimensionSelect=()=>{
    const select=document.createElement('select');
    select.setAttribute('aria-label','JetEngine condition source');
    const buckets=new Map();
    for(const dimension of strategyDimensions()){
      if(!buckets.has(dimension.group))buckets.set(dimension.group,[]);
      buckets.get(dimension.group).push(dimension);
    }
    for(const [group,dimensions] of buckets){
      const optgroup=document.createElement('optgroup');
      optgroup.label=groupLabels[group]||'Other';
      for(const dimension of dimensions) {
        const hint=dimension.source==='meta_box'?' · Meta Box'
          :(dimension.glossary_id?' · Glossary '+dimension.glossary_id:'');
        optgroup.append(new Option(dimension.label+hint,dimension.id));
      }
      select.append(optgroup);
    }
    return select;
  };
  const addStrategyRule=(existing=null)=>{
    const dimensions=strategyDimensions();
    if(!dimensions.length || ruleRows.children.length>=12)return;
    const line=create('div','mad4b-tour-inspector__strategy-rule');
    const header=create('div','mad4b-tour-inspector__strategy-rule-header');
    const dimension=nativeDimensionSelect();
    if(existing?.id && dimensions.some(d=>d.id===existing.id))
      dimension.value=existing.id;
    const remove=create('button','mad4b-tour-inspector__strategy-rule-remove','Remove');
    remove.type='button';remove.setAttribute('aria-label','Remove strategy condition');
    header.append(dimension,remove);
    const editor=create('div','mad4b-tour-inspector__strategy-rule-values');
    const build=()=>{
      const definition=dimensions.find(d=>d.id===dimension.value);
      editor.replaceChildren();
      if(!definition)return;
      if(definition.kind==='range'){
        const min=document.createElement('input');
        const max=document.createElement('input');
        min.type=max.type='number';
        min.step=max.step='any';
        min.placeholder='Min '+definition.min;max.placeholder='Max '+definition.max;
        min.setAttribute('aria-label','Strategy minimum value');
        max.setAttribute('aria-label','Strategy maximum value');
        min.value=existing?.id===definition.id&&existing?.min!==undefined
          ?String(existing.min):String(definition.min);
        max.value=existing?.id===definition.id&&existing?.max!==undefined
          ?String(existing.max):String(definition.max);
        editor.append(min,create('span','','to'),max);
      }else{
        const selector=document.createElement('select');
        selector.multiple=true;
        selector.size=Math.min(5,Math.max(2,definition.options.length));
        selector.setAttribute('aria-label','Strategy condition options');
        const selected=new Set(existing?.id===definition.id?existing.values||[]:[]);
        for(const entry of definition.options){
          const option=new Option(entry.label,String(entry.id));
          option.selected=selected.has(String(entry.id));
          selector.add(option);
        }
        const selectHelp=create('small','',
          'Select one or more values (Ctrl/Cmd-click for multiple).');
        const matching=document.createElement('select');
        matching.setAttribute('aria-label','Strategy condition term matching');
        matching.add(new Option('Any values (OR)','any'));
        matching.add(new Option('All values (AND)','all'));
        matching.value=existing?.id===definition.id&&existing?.match==='all'
          ?'all':'any';
        editor.append(selector,matching,selectHelp);
      }
      line.dataset.kind=definition.kind;
    };
    dimension.addEventListener('change',()=>{existing=null;build();});
    remove.addEventListener('click',()=>line.remove());
    line.append(header,editor);
    ruleRows.append(line);
    build();
  };
  const syncStrategyControls=()=>{
    const custom=modeSelect.value==='override';
    combineSelect.disabled=!custom;
    addRule.disabled=!custom || !strategyDimensions().length;
    for(const element of ruleRows.querySelectorAll('input,select,button'))
      element.disabled=!custom;
    ruleRows.classList.toggle('is-inherited',!custom);
  };
  modeSelect.addEventListener('change',syncStrategyControls);
  addRule.addEventListener('click',()=>addStrategyRule());
  const restoreStrategy=()=>{
    state.strategyMode='inherit';
    state.strategyRules=[];state.strategyCombine='and';
    modeSelect.value='inherit';combineSelect.value='and';
    ruleRows.replaceChildren();
    strategyBadge.textContent='Inherit';
    strategyStatus.textContent='Inherited scope · saved pricing policies unchanged.';
    syncStrategyControls();
  };
  clearRules.addEventListener('click',()=>{
    restoreStrategy();state.page=1;load();
  });
  const readStrategyRules=()=>{
    const dimensions=new Map(strategyDimensions().map(d=>[d.id,d]));
    const rules=[];
    for(const line of ruleRows.children){
      const select=line.querySelector('.mad4b-tour-inspector__strategy-rule-header select');
      const definition=dimensions.get(select?.value);
      if(!definition)throw new Error('Select a valid JetEngine source.');
      if(definition.kind==='range'){
        const inputs=Array.from(line.querySelectorAll('input[type=number]'));
        const min=Number(inputs[0]?.value),max=Number(inputs[1]?.value);
        if(!Number.isFinite(min)||!Number.isFinite(max)||min>max)
          throw new Error('Invalid numeric range for '+definition.label);
        rules.push({id:definition.id,min,max});
      }else{
        const values=line.querySelector('.mad4b-tour-inspector__strategy-rule-values select[multiple]');
        const selected=Array.from(values?.selectedOptions||[]).map(opt=>opt.value);
        if(!selected.length)throw new Error('Select values for '+definition.label);
        const match=line.querySelector('.mad4b-tour-inspector__strategy-rule-values select:not([multiple])');
        rules.push({id:definition.id,values:selected,
          match:match?.value==='all'?'all':'any'});
      }
    }
    return rules;
  };
  applyRules.addEventListener('click',()=>{
    try{
      const enabled=modeSelect.value==='override';
      const conditions=enabled?readStrategyRules():[];
      if(enabled&&!conditions.length)
        throw new Error('Add at least one targeting condition, or choose Inherit.');
      state.strategyMode=enabled?'override':'inherit';
      state.strategyRules=conditions;
      state.strategyCombine=combineSelect.value==='or'?'or':'and';
      state.page=1;
      strategyBadge.textContent=enabled?conditions.length+' conditions':'Inherit';
      strategyStatus.textContent=enabled?
        'Applying native targeting to published Affected Tours…':
        'Restoring inherited tour scope…';
      load();
    }catch(error){strategyStatus.textContent=error.message;}
  });
  const updateStrategyCatalog=catalog=>{
    if(!catalog?.dimensions || !Array.isArray(catalog.dimensions))return;
    state.strategyCatalog=catalog;
    typeSelect.replaceChildren();
    for(const entry of catalog.strategy_types||[])
      typeSelect.add(new Option(entry.label,entry.id));
    typeSelect.value='segment_targeting';
    if(typeSelect.selectedIndex<0)typeSelect.selectedIndex=0;
    ruleRows.replaceChildren();
    if(state.strategyRules.length){
      // Language-dependent WPML term IDs must be reselected after a catalog refresh.
      restoreStrategy();
    }
    strategyStatus.textContent=catalog.dimensions.length+' filterable dimensions · '+
      (catalog.glossaries?.length||0)+' JetEngine Glossaries · '+
      (catalog.meta_box_fields?.length||0)+' attached Meta Box fields ('+
      catalog.dimensions.filter(d=>d.source==='meta_box').length+' with published values) · '+
      (catalog.strategy_types?.length||0)+' registered strategy types. '+
      'Pricing evaluators remain unchanged; previews do not save.';
    syncStrategyControls();
  };
  syncStrategyControls();
  viewResults.addEventListener('click',()=>{
    filterSection.open=false;
    resultsSection.open=true;
    // Scroll only this sidebar, not the pricing editor.
    content.scrollTo({top:Math.max(0,resultsSection.offsetTop-content.offsetTop-12),behavior:'smooth'});
  });
  const capture = () => {
    const result={};
    for (const [key,value] of Object.entries(state.filters)) {
      if (value instanceof Set && value.size) result[key]=Array.from(value);
      else if (value && typeof value==='object'
          && Number.isFinite(value.min) && Number.isFinite(value.max)) {
        result[key]={min:value.min,max:value.max};
      }
    }
    return result;
  };
  const languageScopeChanged = () => {
    // Translated taxonomies may use different term IDs, so old taxonomy/meta
    // selections must not silently constrain results in a new language.
    for (const facet of state.facets) {
      if(facet.source==='taxonomy'||facet.source==='meta') delete state.filters[facet.id];
    }
    restoreStrategy();
    state.page=1;
    state.hadFacets=false; // Rebuild term options from selected WPML published IDs.
    load();
  };
  const drawScope = () => {
    const languageWasOpen=languageDetails.open;
    const marketWasOpen=marketDetails.open;
    languageList.replaceChildren();
    for(const lang of state.availableLanguages){
      const label=create('label','mad4b-tour-inspector__scope-option');
      const check=document.createElement('input');
      check.type='checkbox';
      check.value=lang.id;
      check.checked=state.languages.has(lang.id);
      check.setAttribute('aria-label','Include '+lang.name);
      label.append(check,create('span','',lang.name),
        create('small','',lang.count));
      languageList.append(label);
      check.addEventListener('change',()=>{
        if(check.checked)state.languages.add(lang.id);
        else if(state.languages.size>1)state.languages.delete(lang.id);
        else check.checked=true;
        languageScopeChanged();
      });
    }
    languageSummary.textContent='Content languages ('+state.languages.size+')';
    marketList.replaceChildren();
    if (!state.availableMarkets.length){
      const msg=create('div','mad4b-tour-inspector__market-notice');
      msg.append(create('p','',
        'No approved target market profiles. Drafts and imported profiles are excluded from commercial targeting.'));
      if(api.market_setup_url){
        const link=create('a','','Review Market Profiles');
        link.href=api.market_setup_url;
        link.target='_blank';link.rel='noopener noreferrer';
        msg.append(link);
      }
      marketList.append(msg);
    }else {
      const notice=create('p','mad4b-tour-inspector__market-notice',
        'WPML locale and Market Profile drafts allow content preview only. Sales/price eligibility still requires an approved site market.');
      marketList.append(notice);
      for(const country of state.availableMarkets){
        const label=create('label','mad4b-tour-inspector__scope-option');
        const check=document.createElement('input');
        check.type='checkbox';check.value=country.id;
        check.checked=state.markets.has(country.id);
        label.append(check,create('span','',country.name));
        marketList.append(label);
        check.addEventListener('change',()=>{
          if(check.checked) {
            state.markets.add(country.id);
            // WPML locale is a language/country association, so selecting a
            // country should never silently show zero just because its language
            // wasn't preselected. Add only that documented language set.
            const choices=Array.isArray(country.languages)?country.languages:[];
            if(!choices.some(lang=>state.languages.has(lang))) {
              for(const lang of choices)state.languages.add(lang);
            }
          } else state.markets.delete(country.id);
          languageScopeChanged();
        });
      }
    }
    marketSummary.textContent='Country / WPML market preview'+
      (state.markets.size ? ' ('+state.markets.size+')' : '');
    languageDetails.open=languageWasOpen || state.languages.size>1;
    marketDetails.open=marketWasOpen || state.markets.size>0;
  };
  const filterVisible = () => {
    const source=sourcePicker.value;
    const phrase=facetSearch.value.trim().toLocaleLowerCase();
    let total=0;
    for (const group of facetRoot.querySelectorAll(':scope > .mad4b-tour-inspector__filter-group')) {
      const category=group.dataset.source;
      const bySource=source==='all'||source===category;
      let matches=0;
      let active=0;
      for (const entry of group.querySelectorAll('.mad4b-tour-inspector__facet')) {
        const allowed=bySource && (!phrase||entry.dataset.filterLabel.includes(phrase));
        entry.hidden=!allowed;
        if (allowed) matches++;
        const selected=state.filters[entry.dataset.filterId];
        if (selected instanceof Set) active+=selected.size;
        else if (selected && Number.isFinite(selected.min) && Number.isFinite(selected.max)) active++;
      }
      total+=matches;
      group.hidden=!bySource || matches===0;
      // Search/quick-source selection expands relevant groups, while All Filters
      // restores the user's manual accordion choices (never expands all by default).
      group.open=!!(bySource && matches &&
        (phrase || state.expandedFilterGroups.has(category)));
      const badge=group.querySelector('.mad4b-tour-inspector__filter-group-count');
      if (badge) badge.textContent=matches+' filters'+(active?' · '+active+' selected':'');
      group.classList.toggle('has-selection',active>0);
    }
    inventory.textContent=total+' filters · indexed counts before selections';
  };
  sourcePicker.addEventListener('change',()=>{
    if (sourcePicker.value!=='all') state.expandedFilterGroups.add(sourcePicker.value);
    filterVisible();
  });
  facetSearch.addEventListener('input',filterVisible);
  logicSelect.addEventListener('change',()=>{
    state.logic=logicSelect.value==='or'?'or':'and';
    state.page=1;load();
  });
  const updateChips = () => {
    selections.replaceChildren();
    for (const facet of state.facets) {
      const active=state.filters[facet.id];
      if (!active) continue;
      if (!(active instanceof Set)) {
        if (Number.isFinite(active.min)&&Number.isFinite(active.max)){
          const chip=create('button','mad4b-tour-inspector__chip',
            facet.label+': '+active.min+'–'+active.max+' ×');
          chip.type='button';
          chip.addEventListener('click',()=>{
            delete state.filters[facet.id];updateChips();buildFacets();state.page=1;load();
          });
          selections.append(chip);
        }
        continue;
      }
      for (const id of active) {
        const term=facet.terms.find(t=>t.id===id);
        if (!term) continue;
        const chip=create('button','mad4b-tour-inspector__chip',facet.label+': '+term.name+' ×');
        chip.type='button';
        chip.setAttribute('aria-label','Remove '+term.name+' from '+facet.label);
        chip.addEventListener('click',()=>{
          active.delete(id);
          const checkbox=facetRoot.querySelector('input[data-facet="'+facet.id+'"][value="'+id+'"]');
          if (checkbox) checkbox.checked=false;
          updateChips();
          state.page=1;
          load();
        });
        selections.append(chip);
      }
    }
    selections.hidden=!selections.childElementCount;
    const filterCount=Object.values(state.filters).reduce((sum,entry)=>{
      if (entry instanceof Set) return sum+entry.size;
      if (entry && typeof entry==='object' && Number.isFinite(entry.min))return sum+1;
      return sum;
    },0);
    filterSectionBadge.textContent=filterCount ? filterCount+' selected' : 'All filters';
    filterSection.classList.toggle('has-selection',filterCount>0);
    filterVisible();
  };
  const buildFacets = () => {
    facetRoot.replaceChildren();
    const sources = [
      ['taxonomy','Taxonomies'],
      ['meta','CPT Meta Fields'],
      ['relation','Relations & services']
    ];
    const groups=new Map();
    for (const [source,label] of sources) {
      const section=create('details','mad4b-tour-inspector__filter-group');
      section.dataset.source=source;
      const summary=create('summary','mad4b-tour-inspector__filter-group-heading');
      summary.append(create('span','mad4b-tour-inspector__filter-group-label',label));
      const counter=create('small','mad4b-tour-inspector__filter-group-count','0 filters');
      summary.append(counter);
      const body=create('div','mad4b-tour-inspector__filter-group-body');
      section.append(summary,body);
      // Save only user clicks, not automatic expansion by search/dropdown.
      summary.addEventListener('click',()=>{
        if (facetSearch.value.trim()) return;
        if (section.open) state.expandedFilterGroups.delete(source);
        else state.expandedFilterGroups.add(source);
      });
      facetRoot.append(section);
      groups.set(source,body);
    }
    for (const facet of state.facets) {
      if ((!facet.terms || !facet.terms.length) && facet.kind!=='range') continue;
      const details=create('details','mad4b-tour-inspector__facet');
      details.dataset.source=facet.source || 'relation';
      details.dataset.filterId=facet.id;
      const targetGroup=groups.get(details.dataset.source)||groups.get('relation');
      details.dataset.filterLabel=(facet.label+' '+(facet.key||'')+' '+facet.id).toLocaleLowerCase();
      if(facet.kind==='range'){
        const summary=create('summary','');
        summary.append(create('span','',facet.label),
          create('small','','Range'));
        details.append(summary);
        const body=create('div','mad4b-tour-inspector__range');
        const current=state.filters[facet.id];
        const min=create('input','');min.type='number';min.step='any';
        min.placeholder='Min '+facet.min;min.setAttribute('aria-label',facet.label+' minimum');
        const max=create('input','');max.type='number';max.step='any';
        max.placeholder='Max '+facet.max;max.setAttribute('aria-label',facet.label+' maximum');
        if(current && !(current instanceof Set)){
          min.value=String(current.min);max.value=String(current.max);
          details.open=true;
        }
        const apply=create('button','', 'Apply range');apply.type='button';
        apply.addEventListener('click',()=>{
          const low=min.value.trim()===''?Number(facet.min):Number(min.value);
          const high=max.value.trim()===''?Number(facet.max):Number(max.value);
          if(!Number.isFinite(low)||!Number.isFinite(high)||low>high){
            min.setCustomValidity('Minimum must be less than maximum');
            min.reportValidity();return;
          }
          min.setCustomValidity('');
          if(min.value.trim()===''&&max.value.trim()==='')delete state.filters[facet.id];
          else state.filters[facet.id]={min:low,max:high};
          state.page=1;updateChips();load();
        });
        body.append(min,create('span','','to'),max,apply);
        details.append(body);targetGroup.append(details);
        continue;
      }
      const summary=create('summary','');
      const heading=create('span','',facet.label);
      const count=create('small','','0');
      summary.append(heading,count);
      details.append(summary);
      const inner=create('div','mad4b-tour-inspector__facet-body');
      if(facet.source==='taxonomy') {
        const matchLabel=create('label','mad4b-tour-inspector__term-logic');
        matchLabel.append(create('span','','Match terms'));
        const termLogic=document.createElement('select');
        termLogic.setAttribute('aria-label',facet.label+' term matching');
        [['any','Any selected (OR)'],['all','All selected (AND)']]
          .forEach(([value,label])=>termLogic.add(new Option(label,value)));
        termLogic.value=state.taxonomyOperators[facet.id]||'any';
        termLogic.addEventListener('change',()=>{
          state.taxonomyOperators[facet.id]=termLogic.value==='all'?'all':'any';
          state.page=1;load();
        });
        matchLabel.append(termLogic);
        inner.append(matchLabel);
      }
      const finder=create('input','mad4b-tour-inspector__facet-search');
      finder.type='search'; finder.placeholder='Find '+facet.label.toLowerCase()+'…';
      finder.setAttribute('aria-label','Search '+facet.label+' terms');
      inner.append(finder);
      const options=create('div','mad4b-tour-inspector__options');
      const selected=state.filters[facet.id] instanceof Set
        ? state.filters[facet.id] : new Set();
      for (const term of facet.terms) {
        const label=create('label','mad4b-tour-inspector__option');
        const input=document.createElement('input');
        input.type='checkbox'; input.value=String(term.id);
        input.dataset.facet=facet.id;
        input.checked=selected.has(term.id);
        label.append(input,create('span','',term.name),create('small','',term.count));
        options.append(label);
        input.addEventListener('change',()=>{
          if (!state.filters[facet.id]) state.filters[facet.id]=new Set();
          if (input.checked) state.filters[facet.id].add(term.id);
          else state.filters[facet.id].delete(term.id);
          count.textContent=String(state.filters[facet.id].size);
          updateChips();
          state.page=1;
          load();
        });
      }
      count.textContent=String(selected.size);
      if (selected.size) details.open=true;
      finder.addEventListener('input',()=>{
        const match=finder.value.trim().toLocaleLowerCase();
        for(const label of options.children) {
          label.hidden=!!match&&!label.textContent.toLocaleLowerCase().includes(match);
        }
      });
      inner.append(options);
      details.append(inner);
      targetGroup.append(details);
    }
    for (const body of groups.values()) {
      if (!body.childElementCount) body.parentElement.remove();
    }
    updateChips();
    filterVisible();
  };
  const humanPrice = item => {
    if (typeof item.starting_price !== 'number' || !item.currency) return 'Price on request / unavailable';
    return new Intl.NumberFormat(undefined,{
      style:'currency',currency:item.currency,maximumFractionDigits:2
    }).format(item.starting_price);
  };
  const termText = (item) => {
    const names=[];
    for(const key of ['destinations','tour-types','duration-options','tour-styles']) {
      if (Array.isArray(item.terms?.[key])) names.push(...item.terms[key].slice(0,2));
      if (names.length>=4) break;
    }
    return names.slice(0,4);
  };
  // Fixed-width translated slide track (no Swiper dependency or animation
  // delaying initial visibility). Only one slide is active at a time.
  const syncSlider = (item) => {
    const at=state.items.findIndex(t=>t.id===item.id);
    if(at<0)return;
    state.slideIndex=at;
    sliderTrack.style.transform='translate3d(-'+(at*100)+'%,0,0)';
    sliderCount.textContent=(at+1)+' / '+state.items.length+
      (state.pages>1?' · page '+state.page+'/'+state.pages:'');
    slidePrevious.disabled=at===0 && state.page<=1;
    slideNext.disabled=at===state.items.length-1 && state.page>=state.pages;
    for(const node of sliderTrack.querySelectorAll('.mad4b-tour-inspector__slide')) {
      const selected=Number(node.dataset.tourId)===item.id;
      node.classList.toggle('is-selected',selected);
      node.setAttribute('aria-current',selected?'true':'false');
      node.tabIndex=selected?0:-1;
      node.inert=!selected;
    }
  };
  const navigateSlider = delta => {
    if (!state.items.length || state.loading) return;
    const target=state.slideIndex+delta;
    if(target>=0 && target<state.items.length) {
      showDetail(state.items[target],true);
      return;
    }
    if(delta>0 && state.page<state.pages) {
      state.slidePendingEdge='first';state.page++;load();return;
    }
    if(delta<0 && state.page>1) {
      state.slidePendingEdge='last';state.page--;load();
    }
  };
  slidePrevious.addEventListener('click',()=>navigateSlider(-1));
  slideNext.addEventListener('click',()=>navigateSlider(1));
  sliderViewport.addEventListener('keydown',event=>{
    if(event.target!==sliderViewport && !event.target.closest?.('.mad4b-tour-inspector__slide'))return;
    if(event.key==='ArrowRight'){event.preventDefault();navigateSlider(1);}
    else if(event.key==='ArrowLeft'){event.preventDefault();navigateSlider(-1);}
  });
  let pointerStart=null;
  let suppressSlideClick=false;
  sliderViewport.addEventListener('pointerdown',event=>{
    if(event.pointerType==='mouse')return;
    pointerStart={id:event.pointerId,x:event.clientX,y:event.clientY};
  });
  sliderViewport.addEventListener('pointerup',event=>{
    if(!pointerStart || pointerStart.id!==event.pointerId)return;
    const dx=event.clientX-pointerStart.x;
    const dy=event.clientY-pointerStart.y;
    pointerStart=null;
    if(Math.abs(dx)>38 && Math.abs(dx)>Math.abs(dy)*1.25){
      suppressSlideClick=true;
      navigateSlider(dx<0?1:-1);
    }
  });
  sliderViewport.addEventListener('pointercancel',()=>{pointerStart=null;});
  sliderViewport.addEventListener('click',event=>{
    if(!suppressSlideClick)return;
    event.preventDefault();event.stopPropagation();
    suppressSlideClick=false;
  },true);

  const showDetail = (item,userSelected=false) => {
    if (!item) return;
    const previousTourId=state.selected;
    const previouslyOpenRateIds=new Set(previousTourId===item.id
      ? Array.from(detail.querySelectorAll('.mad4b-tour-inspector__rate-item[open]'))
          .map(node=>node.dataset.rateId) : []);
    const previouslyOpenAudiences=new Set(previousTourId===item.id
      ? Array.from(detail.querySelectorAll('.mad4b-tour-inspector__rate-audience[open]'))
          .map(node=>node.dataset.rateAudience)
      : []);
    const rateGroupWasOpen=previousTourId===item.id &&
      !!detail.querySelector('.mad4b-tour-inspector__rate-section[open]');
    state.selected=item.id;
    detail.setAttribute('aria-label',item.title+' pricing and services');
    detail.replaceChildren();
    syncSlider(item);
    if(userSelected && !previewSection.hidden){
      const offset=previewSection.offsetTop-content.offsetTop;
      content.scrollTo({top:Math.max(0,offset-12),behavior:'smooth'});
    }
    const detailsHeading=create('strong','mad4b-tour-inspector__detail-subheading',
      'Rates, extras & transport');
    detail.append(detailsHeading);
    if(cfg?.affectedStrategy?.action && cfg?.affectedStrategy?.nonce){
      const strategy=create('section','mad4b-tour-inspector__strategy');
      const head=create('div','mad4b-tour-inspector__strategy-heading');
      head.append(create('strong','','Policy impact & campaign strategy'),
        create('small','','Read-only until a verified draft is explicitly requested'));
      strategy.append(head);
      const choices=create('div','mad4b-tour-inspector__strategy-inputs');
      const ratePick=document.createElement('select');
      ratePick.setAttribute('aria-label','Rate for policy impact');
      for(const rate of item.tour_rates||[]){
        const label=rateDisplayLabel(rate);
        const option=new Option(label,String(rate.id));
        option.title=label;
        ratePick.add(option);
      }
      const audiencePick=document.createElement('select');
      audiencePick.setAttribute('aria-label','Pricing audience');
      for(const [key,label] of [['general','International / General'],['egyptians','Egyptians']]){
        audiencePick.add(new Option(label,key));
      }
      const occupancyPick=document.createElement('select');
      occupancyPick.setAttribute('aria-label','Occupancy for policy impact');
      for(const key of ['single','double','triple'])occupancyPick.add(
        new Option(key[0].toUpperCase()+key.slice(1),key));
      occupancyPick.value='double';
      const travelDate=document.createElement('input');
      travelDate.type='date';
      travelDate.setAttribute('aria-label','Travel date for FOMO verification');
      const makeControl=(label,control)=>{
        const wrapper=create('label','mad4b-tour-inspector__strategy-control');
        wrapper.append(create('span','',label),control);
        choices.append(wrapper);
      };
      makeControl('Published Tour Rate',ratePick);
      makeControl('Audience',audiencePick);
      makeControl('Occupancy',occupancyPick);
      makeControl('Travel date',travelDate);
      const scenarioAction=document.createElement('select');
      scenarioAction.setAttribute('aria-label','What-if price scenario');
      for(const [mode,label] of [['inherit','Current saved policy'],['discount','Discount (What-if)'],
          ['markup','Markup (What-if)']])scenarioAction.add(new Option(label,mode));
      const scenarioPercent=document.createElement('input');
      scenarioPercent.type='number';scenarioPercent.min='0';scenarioPercent.max='60';
      scenarioPercent.step='0.1';scenarioPercent.value='10';
      scenarioPercent.setAttribute('aria-label','What-if percentage');
      scenarioPercent.disabled=true;
      scenarioAction.addEventListener('change',()=>{
        scenarioPercent.disabled=scenarioAction.value==='inherit';
      });
      makeControl('What-if policy',scenarioAction);
      makeControl('Percent',scenarioPercent);
      const run=create('button','mad4b-tour-inspector__strategy-run',
        'Evaluate pricing & FOMO evidence');
      run.type='button';
      const output=create('div','mad4b-tour-inspector__strategy-output',
        'Choose a published Rate and travel date to inspect source prices, effective policy, and verified campaign readiness.');
      output.setAttribute('role','status');
      output.setAttribute('aria-live','polite');
      output.setAttribute('aria-atomic','true');
      const draftPanel=create('div','mad4b-tour-inspector__strategy-draft');
      draftPanel.hidden=true;
      const draftLabel=create('label','mad4b-tour-inspector__strategy-confirm');
      const draftCheck=document.createElement('input');draftCheck.type='checkbox';
      draftCheck.setAttribute('aria-label','Approve creating FOMO review draft');
      draftLabel.append(draftCheck,create('span','',
        'Create an unpublished campaign Draft for review. No price, public offer, coupon or checkout change.'));
      const draftNotice=create('small','mad4b-tour-inspector__strategy-warning',
        'Draft uses the SAVED Last Minute policy and verified inventory, never unsaved What-if values.');
      const draftButton=create('button','mad4b-tour-inspector__strategy-draft-button',
        'Prepare verified FOMO draft');
      draftButton.type='button';draftButton.disabled=true;
      draftPanel.append(draftNotice,draftLabel,draftButton);
      strategy.append(choices,run,output,draftPanel);
      if(!ratePick.options.length){
        run.disabled=true;
        output.textContent='No published Tour Rates linked; policy impact cannot be verified.';
      }
      let activePreview=null;
      let pending=false;
      const showOutput=(payload)=>{
        output.replaceChildren();
        const source=payload.source_price||{};
        const policy=payload.policy_impact||{};
        const whatIf=payload.what_if||{};
        const fomo=payload.fomo||{};
        const row=(label,value)=>{
          const line=create('div','mad4b-tour-inspector__strategy-line');
          line.append(create('span','',label),create('strong','',String(value)));
          output.append(line);
        };
        const money=(v,cur)=>{
          if(typeof v!=='number'||!Number.isFinite(v)||!cur)return 'Unavailable';
          try{return new Intl.NumberFormat(undefined,{
            style:'currency',currency:cur,maximumFractionDigits:2
          }).format(v);}
          catch(_){return String(v)+' '+cur;}
        };
        row('Source price',source.status==='available'
          ?money(source.current,source.currency):'Unavailable · '+(source.reason||'unknown'));
        if(source.status==='available'){
          row('Regular → current',money(source.regular,source.currency)
            +' → '+money(source.current,source.currency));
          row('Existing discount',source.discounted?'Yes, sourced from the published Rate':'None');
        }
        row('Policy impact',policy.status==='illustrative_with_verified_inventory'
          ?((policy.adjustment||'')+' '+policy.percent+'% · '+
             money(policy.reference_before,policy.currency)+' → '+
             money(policy.illustrative_after,policy.currency))
          :'Not verified for a payable quote');
        if(whatIf.status==='illustrative_only'){
          row('What-if · NOT a quote',whatIf.mode+' '+whatIf.percent+'% · '+
            money(whatIf.before,whatIf.currency)+' → '+money(whatIf.after,whatIf.currency));
        }else if(whatIf.status==='existing_sale_stacking_blocked'){
          row('What-if', 'Blocked: a source sale already exists; discounts cannot stack');
        }else if(whatIf.status!=='not_requested'){
          row('What-if','Unavailable with this source price');
        }
        row('FOMO evidence',fomo.status||'unavailable');
        row('Inventory verification',fomo.verified_inventory?'Verified for selected date':'Unavailable');
        if(fomo.band)row('Inventory band',fomo.band);
        if(fomo.flag_from||fomo.flag_until)row('Verified campaign window',
          (fomo.flag_from||'—')+' → '+(fomo.flag_until||'—'));
        output.append(create('small','mad4b-tour-inspector__strategy-warning',
          'Illustrative policy effect only. An Offer requires approved market, verified quote, travel-date availability and non-stacking checks. No fake countdowns or unverified scarcity.'));
        const canDraft=fomo.draft_candidate_allowed===true && fomo.verified_inventory===true;
        draftPanel.hidden=!canDraft;
        draftCheck.checked=false;draftButton.disabled=true;
      };
      draftCheck.addEventListener('change',()=>{
        draftButton.disabled=!draftCheck.checked||!activePreview||pending;
      });
      const resetEvidence=()=>{
        activePreview=null;
        draftCheck.checked=false;draftPanel.hidden=true;draftButton.disabled=true;
        output.textContent='Settings changed. Evaluate again for current Rate, audience, occupancy and date.';
      };
      for(const select of [ratePick,audiencePick,occupancyPick,travelDate,scenarioAction,scenarioPercent]){
        select.addEventListener('change',resetEvidence);
      }
      const request=async operation=>{
        const body=new URLSearchParams({
          action:cfg.affectedStrategy.action,
          nonce:cfg.affectedStrategy.nonce,
          operation, tour_id:String(item.id),
          rate_id:ratePick.value,audience:audiencePick.value,
          occupancy:occupancyPick.value,travel_date:travelDate.value,
          scenario_mode:scenarioAction.value,
          scenario_percent:scenarioAction.value==='inherit'?'0':scenarioPercent.value
        });
        if(operation==='draft_fomo')body.set('confirm','create_review_draft');
        const response=await fetch(cfg.affectedStrategy.ajax_url,{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
          body:body.toString()
        });
        let data;
        try{data=await response.json();}
        catch(_){throw new Error('Invalid strategy engine response');}
        if(!response.ok||!data?.success){
          throw new Error(data?.data?.message || 'Strategy engine unavailable');
        }
        return data.data;
      };
      run.addEventListener('click',async()=>{
        if(!ratePick.value||!travelDate.value||pending){
          output.textContent='Select a published Tour Rate and a travel date.';
          return;
        }
        pending=true;run.disabled=true;resetEvidence();
        output.textContent='Checking live source price, pricing policy and inventory evidence…';
        const expectedRate=ratePick.value;
        const expectedDate=travelDate.value;
        const expectedScenario=scenarioAction.value+'/'+scenarioPercent.value;
        try{
          const response=await request('preview');
          if(!strategy.isConnected||expectedRate!==ratePick.value
              ||expectedDate!==travelDate.value
              ||expectedScenario!==scenarioAction.value+'/'+scenarioPercent.value)return;
          activePreview=response;
          showOutput(response);
        }catch(error){
          if(strategy.isConnected)output.textContent=error?.message||'Strategy preview unavailable';
        }finally{
          pending=false;
          if(strategy.isConnected){run.disabled=false;draftButton.disabled=!draftCheck.checked||!activePreview;}
        }
      });
      draftButton.addEventListener('click',async()=>{
        if(pending||!draftCheck.checked||!activePreview
           ||activePreview.fomo?.draft_candidate_allowed!==true)return;
        pending=true;draftButton.disabled=true;run.disabled=true;
        output.textContent='Rechecking inventory and creating an unpublished review draft…';
        try{
          const result=await request('draft_fomo');
          output.replaceChildren();
          output.append(create('strong','','Campaign controller: '+(result.receipt?.status||'processed')));
          if(result.campaign_draft && result.campaign_url){
            const link=create('a','mad4b-tour-inspector__strategy-draft-link',
              'Open campaign Draft #'+result.campaign_id);
            link.href=result.campaign_url;link.target='_blank';link.rel='noopener noreferrer';
            output.append(link);
          }else{
            output.append(create('p','',
              'No new campaign Draft was created. The event controller may already hold an event or lack a qualifying state change.'));
          }
          output.append(create('small','',
            'Draft only: no publishing, coupon, promotion rule, or checkout approval.'));
          draftPanel.hidden=true;activePreview=null;
        }catch(error){
          if(strategy.isConnected)output.textContent=error?.message||'Draft was not created.';
        }finally{
          pending=false;run.disabled=false;draftButton.disabled=true;
        }
      });
      detail.append(strategy);
    }
    if(item.summary)detail.append(create('p','',item.summary));

    const layer=create('div','mad4b-tour-inspector__layers');
    const mode=create('div','mad4b-tour-inspector__layer');
    mode.append(create('strong','','Tour Mode'),
      create('span','',item.tour_mode ? item.tour_mode.replaceAll('_',' ') : 'Not assigned'));
    layer.append(mode);

    const rates=create('details','mad4b-tour-inspector__rate-section');
    const rateItems=Array.isArray(item.tour_rates)?item.tour_rates:[];
    rates.open=rateGroupWasOpen;
    const rateSummary=create('summary','mad4b-tour-inspector__rate-summary');
    rateSummary.append(create('strong','','Tour Rates'),
      create('small','',rateItems.length+' linked'));
    rates.append(rateSummary);
    const rateBody=create('div','mad4b-tour-inspector__rate-body');
    if(!rateItems.length)rateBody.append(
      create('p','mad4b-tour-inspector__empty','No published Tour Rates linked')
    );
    for(const rate of rateItems){
      const row=create('details','mad4b-tour-inspector__rate-item');
      row.dataset.rateId=String(rate.id);
      if(previouslyOpenRateIds.has(String(rate.id)))row.open=true;
      const summary=create('summary','mad4b-tour-inspector__rate-item-summary');
      summary.append(create('span','',rateDisplayLabel(rate)));
      const body=create('div','mad4b-tour-inspector__rate-item-body');
      body.append(create('span','','Rate ID: #'+rate.id));
      if(rate.season)body.append(create('span','','Season: '+rate.season));
      if(rate.start||rate.end)body.append(create('span','',
        'Validity: '+(rate.start||'Not specified')+' → '+(rate.end||'Not specified')));
      const matrix=rate.price_matrix;
      const moneyValue=(amount,cur)=>{
        if(typeof amount!=='number'||!Number.isFinite(amount)||!cur)return null;
        try{return new Intl.NumberFormat(undefined,{
          style:'currency',currency:cur,maximumFractionDigits:2
        }).format(amount);}
        catch(_){return amount.toLocaleString()+' '+cur;}
      };
      if(matrix && matrix.groups){
        const availablePrices=create('div','mad4b-tour-inspector__rate-price-matrix');
        availablePrices.append(create('strong','mad4b-tour-inspector__rate-matrix-title',
          'Occupancy prices · source rates'));
        const rateStates={
          stop_sale:'Stop sale · reference prices only',
          unavailable:'Rate unavailable · reference prices only',
          not_started:'Future period · reference prices only',
          expired:'Expired period · reference prices only',
          invalid_period:'Invalid rate period · reference prices only',
          not_checkout_verified:'Published source values · checkout not verified'
        };
        availablePrices.append(create('small','mad4b-tour-inspector__rate-matrix-note',
          rateStates[matrix.rate_state]||'Published source values · checkout not verified'));
        const labels={international:'International / General',egyptians:'Egyptians'};
        const cells={single:'Single',double:'Double',triple:'Triple'};
        const invalidText={
          audience_disabled:'Not enabled',
          price_missing:'Not configured',
          currency_missing:'Currency missing'
        };
        for(const audience of ['international','egyptians']){
          const group=matrix.groups[audience];
          if(!group)continue;
          const pane=create('details','mad4b-tour-inspector__rate-audience');
          pane.dataset.rateAudience=rate.id+'/'+audience;
          // Show real International prices first; Egyptian configuration is
          // explicitly unavailable where the native Rate does not enable it.
          pane.open=previouslyOpenAudiences.has(pane.dataset.rateAudience)
            || (previousTourId!==item.id && audience==='international');
          const heading=create('summary','mad4b-tour-inspector__rate-audience-heading');
          heading.append(create('span','',labels[audience]));
          heading.append(create('small','',
            !group.enabled?'Not enabled'
              :(group.configured_count+'/3 prices'+(group.currency?' · '+group.currency:''))));
          pane.append(heading);
          const groupBody=create('div','mad4b-tour-inspector__rate-audience-body');
          if(!group.enabled)groupBody.append(create('p','mad4b-tour-inspector__rate-audience-hint',
            'Egyptian pricing is disabled for this Rate. International prices are not copied here.'));
          const rows=create('div','mad4b-tour-inspector__occupancy-list');
          for(const [key,label] of Object.entries(cells)){
            const cell=group.occupancies?.[key];
            if(!cell)continue;
            const priceRow=create('div','mad4b-tour-inspector__occupancy-row');
            priceRow.append(create('span','mad4b-tour-inspector__occupancy-label',label));
            const values=create('div','mad4b-tour-inspector__occupancy-values');
            if(cell.status==='configured' && typeof cell.amount==='number'){
              if(cell.discounted && typeof cell.regular==='number' && typeof cell.sale==='number'){
                const oldPrice=create('del','mad4b-tour-inspector__occupancy-previous',
                  moneyValue(cell.regular,group.currency));
                values.append(oldPrice);
                values.append(create('strong','mad4b-tour-inspector__occupancy-price is-sale',
                  moneyValue(cell.sale,group.currency)));
                values.append(create('small','mad4b-tour-inspector__occupancy-sale-label','Sale'));
              }else{
                values.append(create('strong','mad4b-tour-inspector__occupancy-price',
                  moneyValue(cell.amount,group.currency)));
              }
            }else{
              values.append(create('span','mad4b-tour-inspector__occupancy-unavailable',
                invalidText[cell.status]||'Unavailable'));
            }
            values.append(create('small','mad4b-tour-inspector__occupancy-source',
              cell.discounted && cell.sale_source
                ? 'Sale source: '+cell.sale_source+' · Base: '+cell.source
                : 'Source: '+cell.source));
            priceRow.append(values);
            rows.append(priceRow);
          }
          groupBody.append(rows);
          pane.append(groupBody);
          availablePrices.append(pane);
        }
        availablePrices.append(create('small','mad4b-tour-inspector__rate-disclaimer',
          'Reference prices per person and occupancy; no quote, market approval or checkout eligibility is established.'));
        body.append(availablePrices);
      }else{
        body.append(create('small','',
          'Detailed occupancy matrix unavailable for this Rate.'));
      }
      if(cfg?.priceGroup?.action && cfg?.priceGroup?.nonce){
        const editor=create('details','mad4b-price-group-editor');
        editor.dataset.rateId=String(rate.id);
        const editorTitle=create('summary','mad4b-price-group-editor__summary',
          'Price Group Controller · Inherit / Override');
        const panel=create('div','mad4b-price-group-editor__panel',
          'Open to load native Rate price controls.');
        editor.append(editorTitle,panel);
        body.append(editor);
      }
      row.append(summary,body);
      rateBody.append(row);
    }
    rateBody.append(create('small','mad4b-tour-inspector__rate-disclaimer',
      'Date eligibility, availability, stop-sale and checkout readiness are not verified.'));
    rates.append(rateBody);
    layer.append(rates);

    const extras=create('section','mad4b-tour-inspector__layer');
    const extrasItems=Array.isArray(item.extras)?item.extras:[];
    extras.append(create('strong','','Extras ('+extrasItems.length+')'));
    if(extrasItems.length){
      for(const extra of extrasItems)extras.append(create('span','','#'+extra.id+' · '+extra.title));
    } else extras.append(create('span','','No eligible published extras linked'));
    layer.append(extras);

    const transport=create('section','mad4b-tour-inspector__layer');
    const transportItems=item.transport&&typeof item.transport==='object'?item.transport:{};
    const activeTransports=Object.entries(transportItems);
    transport.append(create('strong','','Transport ('+
      activeTransports.reduce((n,entry)=>n+(Array.isArray(entry[1])?entry[1].length:0),0)+' linked)'));
    if(!activeTransports.length)transport.append(create('span','','No transport category enabled'));
    for(const [kind,items] of activeTransports){
      const label=kind==='vehicles'?'Vehicles':kind==='train'?'Train':kind==='air'?'Air':kind;
      transport.append(create('span','',label+': '+(items.length
        ? items.map(t=>t.title+' (#'+t.id+')').join(', ')
        : 'enabled · no verified published items linked')));
    }
    layer.append(transport);
    detail.append(layer);
    const tags=create('div','mad4b-tour-inspector__term-tags');
    for(const term of termText(item)) tags.append(create('span','',term));
    detail.append(tags);
    const link=create('a','mad4b-tour-inspector__open-tour','Open published tour');
    link.href=item.link;link.target='_blank';link.rel='noopener noreferrer';
    detail.append(link);
    for (const b of list.querySelectorAll('.mad4b-tour-inspector__tour')) {
      b.classList.toggle('is-selected',Number(b.dataset.tourId)===item.id);
      b.setAttribute('aria-pressed',String(Number(b.dataset.tourId)===item.id));
    }
  };
  const renderResults = () => {
    list.replaceChildren();
    sliderTrack.replaceChildren();
    resultsBadge.textContent=state.found+' tours';
    previewBadge.textContent=state.found+' matches';
    previewSection.hidden=!state.items.length;
    status.textContent=state.found+' published tours · '+Array.from(state.languages).join(', ')+
      (state.markets.size?' · Markets: '+Array.from(state.markets).join(', '):'');
    if (!state.items.length) list.append(create('p','mad4b-tour-inspector__empty','No published tours match these filters.'));
    for(const item of state.items){
      const button=create('button','mad4b-tour-inspector__tour');
      button.type='button';button.dataset.tourId=String(item.id);
      button.setAttribute('aria-label','Preview '+item.title);
      const imgWrap=create('span','mad4b-tour-inspector__thumb');
      if (item.thumbnail) {
        const img=document.createElement('img');
        img.src=item.thumbnail;img.loading='lazy';img.alt='';
        imgWrap.append(img);
      }
      const copy=create('span','mad4b-tour-inspector__tour-copy');
      copy.append(create('b','',item.title),
        create('small','',humanPrice(item)));
      if(item.tour_mode)copy.append(create('small','mad4b-tour-inspector__mode',item.tour_mode.replaceAll('_',' ')));
      button.append(imgWrap,copy);
      button.addEventListener('click',()=>showDetail(item,true));
      list.append(button);
      const slide=create('button','mad4b-tour-inspector__slide');
      slide.type='button';
      slide.dataset.tourId=String(item.id);
      slide.setAttribute('aria-label','Inspect affected tour: '+item.title);
      slide.setAttribute('aria-current','false');
      slide.dir='auto';
      if(item.thumbnail){
        const image=document.createElement('img');
        image.src=item.thumbnail;image.alt='';image.loading='lazy';
        image.decoding='async';
        slide.append(image);
      }
      const slideText=create('div','mad4b-tour-inspector__slide-info');
      const modeLabel=item.tour_mode?item.tour_mode.replaceAll('_',' '):'Tour';
      slideText.append(create('span','mad4b-tour-inspector__slide-kicker',
        'Published · '+(item.language||'')+' · '+modeLabel));
      slideText.append(create('strong','mad4b-tour-inspector__slide-title',item.title));
      slideText.append(create('span','mad4b-tour-inspector__slide-price',humanPrice(item)));
      if(item.price_unit)slideText.append(create('small','',
        item.price_unit.replaceAll('_',' ')));
      slide.append(slideText);
      slide.addEventListener('click',()=>{
        if(suppressSlideClick){suppressSlideClick=false;return;}
        showDetail(item,true);
      });
      sliderTrack.append(slide);
    }
    previous.disabled=state.page<=1;
    next.disabled=state.page>=state.pages;
    pageLabel.textContent=state.pages?state.page+' / '+state.pages:'0 / 0';
    pagination.hidden=state.pages<=1;
    let selected=state.items.find(item=>item.id===state.selected) || state.items[0];
    if(state.slidePendingEdge==='last')selected=state.items[state.items.length-1];
    if(state.slidePendingEdge==='first')selected=state.items[0];
    state.slidePendingEdge=null;
    if(selected)showDetail(selected);
    else{
      state.selected=0;
      sliderCount.textContent='0 matches';
      slidePrevious.disabled=slideNext.disabled=true;
      detail.replaceChildren();
    }
  };
  const load = async () => {
    // Results remain an optional accordion index; Affected Tours stays primary.
    state.loading=true;
    slidePrevious.disabled=slideNext.disabled=true;
    const gen=++state.generation;
    if (state.controller) state.controller.abort();
    state.controller=new AbortController();
    status.textContent='Loading published tours…';
    resultsBadge.textContent='Loading…';
    status.classList.add('is-loading');
    const data=new URLSearchParams({
      action:api.action, nonce:api.nonce,
      filters:JSON.stringify(capture()), search:state.search,
      logic:state.logic,taxonomy_operators:JSON.stringify(state.taxonomyOperators),
      strategy_mode:state.strategyMode,
      strategy_combine:state.strategyCombine,
      strategy_rules:JSON.stringify(state.strategyRules),
      languages:JSON.stringify(Array.from(state.languages)),
      markets:JSON.stringify(Array.from(state.markets)),
      sort:state.sort,paged:String(state.page),
      with_facets:state.hadFacets?'0':'1'
    });
    try{
      const response=await fetch(api.ajax_url,{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body:data.toString(),signal:state.controller.signal
      });
      if(!response.ok) throw new Error('Search unavailable ('+response.status+')');
      const result=await response.json();
      if (gen!==state.generation) return;
      if(result?.success!==true || !Array.isArray(result.data?.items)) throw new Error('Invalid catalog response');
      const value=result.data;
      state.items=value.items;
      state.found=Number(value.found)||0;
      state.pages=Number(value.pages)||0;
      state.page=Number(value.page)||1;
      if(Array.isArray(value.language_facets)){
        state.availableLanguages=value.language_facets;
        state.languages=new Set(value.languages||[]);
        if(!state.defaultLanguage)state.defaultLanguage=value.languages?.[0]||'';
      }
      if(Array.isArray(value.market_facets)){
        state.availableMarkets=value.market_facets;
      }
      if(value.strategy_catalog)updateStrategyCatalog(value.strategy_catalog);
      if(value.strategy_targeting){
        const active=value.strategy_targeting;
        strategyBadge.textContent=active.mode==='override'
          ?state.strategyRules.length+' rules · '+state.found+' matches'
          :'Inherit · '+state.found+' tours';
        if(active.mode==='override')strategyStatus.textContent=
          state.found+' published tours match current constraints. '+
          'Policy prices and commercial eligibility have not changed.';
      }
      if(Array.isArray(value.facets)) {
        state.facets=value.facets;
        state.hadFacets=true;
        state.filterInventory=value.filter_inventory||null;
        buildFacets();
      }
      drawScope();
      renderResults();
    }catch(error){
      if(gen!==state.generation || error.name==='AbortError') return;
      status.textContent=error.message||'Tours unavailable';
      resultsBadge.textContent='Unavailable';
      list.replaceChildren(create('p','mad4b-tour-inspector__empty',
        'The published catalog could not be loaded. Your pricing editor is unaffected.'));
    }finally{
      if(gen===state.generation){
        state.loading=false;
        status.classList.remove('is-loading');
        if(state.items.length)syncSlider(state.items[state.slideIndex]||state.items[0]);
      }
    }
  };
  let searchTimer=null;
  search.addEventListener('input',()=>{
    clearTimeout(searchTimer);
    searchTimer=setTimeout(()=>{state.search=search.value.trim();state.page=1;load();},350);
  });
  sort.addEventListener('change',()=>{state.sort=sort.value;state.page=1;load();});
  previous.addEventListener('click',()=>{
    if(state.page>1){state.slidePendingEdge='first';state.page--;load();}
  });
  next.addEventListener('click',()=>{
    if(state.page<state.pages){state.slidePendingEdge='first';state.page++;load();}
  });
  clear.addEventListener('click',()=>{
    state.filters={};state.search='';search.value='';state.sort='newest';sort.value='newest';
    state.logic='and';logicSelect.value='and';state.taxonomyOperators={};
    state.expandedFilterGroups.clear();
    restoreStrategy();
    state.languages=new Set(state.defaultLanguage?[state.defaultLanguage]:[]);
    state.markets.clear();
    state.page=1;facetSearch.value='';sourcePicker.value='all';
    filterSection.open=false;
    state.slidePendingEdge=null;
    buildFacets();drawScope();load();
  });
  const main=root.querySelector('#settings_top');
  if(main){
    const touched=()=>{
      affected.classList.add('mad4b-tour-inspector__draft--changed');
      affected.textContent='Unsaved editor changes detected. Published tour prices above are unchanged until an authorized pricing workflow recalculates them.';
    };
    main.addEventListener('input',touched,{passive:true});
    main.addEventListener('change',touched,{passive:true});
  }
  aside.addEventListener('mad4b:price-group-saved',()=>{
    // No extra polling: one catalog refresh only after an explicit Rate save.
    load();
  });
  load();
})();
