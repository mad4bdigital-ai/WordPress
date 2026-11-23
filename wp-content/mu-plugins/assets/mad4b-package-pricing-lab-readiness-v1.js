(() => {
'use strict';
const cfg=window.MAD4BPackageCostLab;
const outer=document.getElementById('mad4b-package-cost-lab-v1');
const inner=document.getElementById('mad4b-package-cost-lab-inner');
if(!outer || !inner || !cfg || !cfg.endpoint) return;
const existing=document.querySelector('.mad4b-pricing-lab-page #mad4b-pricing-lab-root');
if(existing && existing.parentNode)existing.parentNode.insertBefore(outer,existing);
const el=(tag,cls,value)=>{
  const node=document.createElement(tag);
  if(cls)node.className=cls;
  if(value!==undefined)node.textContent=String(value);
  return node;
};
const row=(tag,children,cls)=>{
  const node=el(tag,cls);
  for(const child of children)if(child)node.append(child);
  return node;
};
const link=(url,text)=>{
  if(!url)return el('span','',text);
  const a=el('a','',text);a.href=url;a.target='_self';return a;
};
// Help copy always appears ABOVE the icon on hover, focus and keyboard navigation.
let helpNumber=0;
const help=(subject,note)=>{
  const wrap=el('span','mad4b-pcl-help');
  const button=el('button','mad4b-pcl-help-trigger','?');
  button.type='button';button.setAttribute('aria-label',subject+': '+note);
  const bubble=el('span','mad4b-pcl-help-note',note);
  bubble.id='mad4b-pcl-help-'+(++helpNumber);bubble.setAttribute('role','tooltip');
  button.setAttribute('aria-describedby',bubble.id);
  wrap.append(button,bubble);
  return wrap;
};
const opts=(select,items)=>{
  for(const item of items){
    const option=document.createElement('option');
    option.value=String(item.id);option.textContent=item.label || item.title || String(item.id);
    select.appendChild(option);
  }
};
const packages=Array.isArray(cfg.packages)?cfg.packages:[];
const tiers=Array.isArray(cfg.tiers)?cfg.tiers:[];
const p=el('select','regular-text');
p.id='mad4b-pcl-select-tour';p.setAttribute('aria-label','Package Tour');
opts(p,packages);
const t=el('select','regular-text');
t.id='mad4b-pcl-select-tier';t.setAttribute('aria-label','Package level');
// Only the active operational assignment for the selected Tour is shown.
// If there is no explicit tier, only the registered company policy may fill it.
function applyTourTierOptions(preferred=''){
  const pkg=packages.find(x=>String(x.id)===String(p.value));
  const allowed=new Set((pkg&&Array.isArray(pkg.effective_tier_ids)
     ?pkg.effective_tier_ids:[]).map(String));
  const options=tiers.filter(x=>allowed.has(String(x.id)));
  t.replaceChildren();
  opts(t,options);
  if(preferred && options.some(x=>String(x.id)===String(preferred)))t.value=String(preferred);
  t.disabled=!options.length;
  b.disabled=!options.length;
  return {pkg,options};
}
// Deep links only select an item from the already-authorized server catalog.
// The AJAX provider independently checks both Tour edit permission and tier.
const query=new URLSearchParams(window.location.search);
const linkedTour=query.get('mad4b_package_tour');
const linkedTier=query.get('mad4b_package_tier');
if(linkedTour&&packages.some(x=>String(x.id)===linkedTour))p.value=linkedTour;
const b=el('button','button button-primary','Inspect original sources');
b.type='submit';
const date=el('input','regular-text');
date.type='date';date.id='mad4b-pcl-preview-start-date';
date.setAttribute('aria-label','Tour travel start date for stage-date preview only');
const numeric=(id,min,max,value)=>{const field=el('input','small-text');field.type='number';
  field.id=id;field.min=String(min);field.max=String(max);field.step='1';
  field.value=String(value);field.required=true;return field;};
const rooms=numeric('mad4b-pcl-preview-rooms',1,30,1);
const adults=numeric('mad4b-pcl-preview-adults',1,60,2);
const children=numeric('mad4b-pcl-preview-children',0,30,0);
const currency=el('select','regular-text');currency.id='mad4b-pcl-preview-currency';
opts(currency,['USD','EGP','EUR','GBP'].map(x=>({id:x,label:x})));
const form=el('form','mad4b-pcl-form');
form.append(
  row('label',[el('span','','Package Tour'),p],'mad4b-pcl-label'),
  row('label',[el('span','','Package level'),t],'mad4b-pcl-label'),
  row('label',[el('span','','Tour start date — operational preview only'),date],'mad4b-pcl-label'),
  row('label',[el('span','','Rooms (cost preview)'),rooms],'mad4b-pcl-label'),
  row('label',[el('span','','Adults'),adults],'mad4b-pcl-label'),
  row('label',[el('span','','Children'),children],'mad4b-pcl-label'),
  row('label',[el('span','','Cost currency'),currency],'mad4b-pcl-label'),
  b
);
// One primary editor per source: navigation never duplicates editable JetEngine fields.
const responsibilities=el('nav','mad4b-pcl-responsibilities');
responsibilities.setAttribute('aria-label','Pricing workspace section responsibilities');
const owners=[
  ['Overview',cfg.overview_url,'Monitor readiness, blockers and pricing coverage. No component prices are edited here.'],
  ['Pricing Lab',null,'Review one Package and tier, inspect stages, simulate strategy and prepare a draft. This source review does not approve checkout.'],
  ['Cost Sources',cfg.property_pricing_url,'Edit hotel, room and unit source data in their original WordPress editors. Transportation, guides and extras keep their original owners.'],
  ['Pricing Controls',cfg.pricing_controls_url,'Define global pricing policies, taxes, margins and payment defaults in the original JetEngine options page.'],
  ['Policy Bindings',cfg.policy_bindings_url,'Trace inheritance and source precedence; use the original policy editor for changes.']
];
for(const [name,url,explanation] of owners){
  const item=el('span','mad4b-pcl-owner');
  item.append(url?link(url,name):el('strong','',name),help(name,explanation));
  responsibilities.append(item);
}
const note=el('p','mad4b-pcl-note','Prices from legacy Tour Rates and QA-only Property Rates are never used as supplier cost.');
const state=el('p','mad4b-pcl-status','Select a Package and tier to inspect source evidence.');
state.setAttribute('role','status');
const result=el('div','mad4b-pcl-result');
inner.replaceChildren(form,responsibilities,note,state,result);
applyTourTierOptions(linkedTier||'');
if(!packages.length||!tiers.length){
  b.disabled=true;
  state.textContent=!packages.length
    ? 'No editable Package tours with saved stage plans are available to this signed-in WordPress user. Check Package tour type, source plan and edit permissions; do not interpret this as an empty company catalog.'
    : 'Package Level terms are missing. Check the original Silver, Gold and Diamond taxonomy terms.';
  return;
}
let requestToken=0;
const metric=(value,label)=>{
  const box=el('div','mad4b-pcl-metric');
  box.append(el('strong','',value),el('span','',label));
  return box;
};
const status=(yes,good,bad)=>el('span',yes?'mad4b-pcl-good':'mad4b-pcl-missing',yes?good:bad);
function dayDate(offset){
  const raw=date.value||'';
  if(!/^\d{4}-\d{2}-\d{2}$/.test(raw))return '';
  const d=new Date(raw+'T12:00:00Z');
  if(!Number.isFinite(d.getTime())||d.toISOString().slice(0,10)!==raw)return '';
  d.setUTCDate(d.getUTCDate()+offset);
  return d.toISOString().slice(0,10);
}
let latestReport=null;
function renderReport(data){
  latestReport=data;
  result.replaceChildren();
  const s=data.summary||{};
  const h=el('div','mad4b-pcl-summary');
  h.append(el('h3','',data.tour_title+' · '+data.tier));
  const sourceLabel=data.tier_source==='company_default_inherited'
    ?'Inherited Company Package Levels (no Tour override)'
    :'Assigned by operations to this Tour';
  const sub=el('p','','Duration: '+data.day_count+' days / '+data.night_count+
    ' nights · '+sourceLabel+' · '+data.plan_status+' · Source only / not a supplier quote');
  h.append(sub);
  const history=[];
  if(data.source_plan_policy_error)
    history.push('Recorded source-plan policy warning: '+data.source_plan_policy_error);
  if(Array.isArray(data.source_plan_warnings)&&data.source_plan_warnings.length)
    history.push('Source review: '+data.source_plan_warnings.join(', '));
  if(history.length){
    const userFriendly={
      policy_company_binding_invalid:'Company pricing policy link needs review',
      cruise_cabin_and_vessel_not_yet_bound:'Cruise vessel and cabin are not linked',
      pending_quote:'Supplier quote is pending'
    };
    const friendly=history.map(value=>{
      let words=String(value);
      for(const [code,description] of Object.entries(userFriendly))
        words=words.replaceAll(code,description);
      return words.replaceAll('_',' ');
    });
    h.append(el('p','mad4b-pcl-warning',
      'Saved source conditions need review: '+friendly.join(' · ')+
      '. These are recorded findings, not a new live approval check.'));
    const technical=el('details','mad4b-pcl-diagnostics');
    technical.append(el('summary','','Technical source references'));
    technical.append(el('p','',history.join(' · ')));
    h.append(technical);
  }
  const kpis=el('div','mad4b-pcl-kpis');
  kpis.append(
    metric(String(s.stages||0),'Travel and stay stages'),
    metric(String(s.units_referenced||0),'Unique linked units'),
    metric(String(s.unit_occupancy_recorded||0),'Units with recorded occupancy'),
    metric(String(s.unit_cost_role_positive_prices||0),'Units with cost-source fields (unapproved)')
  );
  h.append(kpis);
  const diagnostics=el('details','mad4b-pcl-diagnostics');
  const diagnosticTitle=el('summary','','Additional source diagnostics');
  diagnosticTitle.append(help('Source diagnostics','Additional candidate, availability and itinerary counts. These figures do not imply a cost or an approved quote.'));
  diagnostics.append(diagnosticTitle);
  const more=el('div','mad4b-pcl-more-metrics');
  more.append(
    metric(String(s.candidate_mentions||0),'Hotel-stage candidate mentions (may repeat)'),
    metric(String(s.unique_candidate_properties||0),'Unique candidate properties'),
    metric(String(s.unit_available||0),'Units marked available (not confirmed live)'),
    metric(String(s.stages_without_candidates||0),'Hotel stages without candidates'),
    metric(String(s.destination_excluded_candidates||0),'Excluded stage mismatches'),
    metric(String(s.hotel_stages_requiring_itinerary_review||0),'Stages awaiting itinerary evidence')
  );
  diagnostics.append(more);
  const warn=el('p','mad4b-pcl-warning','No approved Package Cost total. Missing unit rates, supplier receipts, inventory, and stage assignments must not be treated as zero.');
  // Cost approval notice belongs to the cost step, not the global header.
  const admission=data.native_generator_stage_admission||{};
  const blockers=Array.isArray(admission.blockers)?admission.blockers:[];
  const gate=el('div','mad4b-pcl-native-admission');
  const label=admission.ready
    ?'Native Package Rate generator: stage-source check passed for this inspection (not a price or checkout approval)'
    :'Native Package Rate generator: blocked · '+blockers.length+' source/itinerary/cost finding(s)';
  gate.append(el('p',admission.ready?'mad4b-pcl-good':'mad4b-pcl-warning',label));
  if(blockers.length){
    const detail=el('details','mad4b-pcl-native-findings');
    detail.append(el('summary','','See exact reasons preventing Tour Rate generation'));
    const items=el('ul','');
    for(const code of blockers) items.append(el('li','',String(code)));
    detail.append(items);gate.append(detail);
  }
  gate.append(el('p','mad4b-pcl-note',
    'Pricing Lab does not select a date range here. Native Rate Planner rechecks the original sources for the requested travel window immediately before any proposed generation.'));
  h.append(gate);
  const draft=data.indicative_offer_draft||null;
  if(draft && draft.contract==='mad4b.package-indicative-offer-draft.v1'){
    const pane=el('section','mad4b-pcl-draft-market-offer');
    pane.append(el('h3','','Indicative Package Offer · Sales Draft (not confirmed)'));
    pane.append(el('p','mad4b-pcl-warning',
      'NO complete Package price or Starting From can be published yet. Web prices are retail offers, NOT supplier net cost, selling Rates, or availability.'));
    pane.append(el('p','mad4b-pcl-note',
      'Stage coverage: '+String(draft.stage_count||0)+
      ' stay/overnight stages · '+String(draft.stage_total_missing||0)+
      ' awaiting Reservations or Contracting. Tour guide, activities and non-overnight services also require complete pricing.'));
    const disclosure=el('details','mad4b-pcl-draft-offer-details');
    disclosure.open=Number(data.tour_id)===60629;
    disclosure.append(el('summary','','Inspect original market offers and remaining supplier quote gaps'));
    for(const item of draft.market_offer_stages||[]){
      const content=el('div','mad4b-pcl-market-offer-stage');
      content.append(el('strong','',String(item.stage_key||item.kind||'Stage')+
        ' · '+String(item.nights||0)+' night(s) · '+String(item.status||'unpriced').replaceAll('_',' ')));
      const offers=Array.isArray(item.web_offer_examples)?item.web_offer_examples:[];
      if(item.kind==='sleeper_train'){
        content.append(el('p','mad4b-pcl-note',
          'Proposed train '+String(item.proposed_train_number||'?')+
          ' · public Abela '+String(item.public_reference_year||'')+
          ' fares only; choose travel date, nationality, cabin and passengers before using any supplier quote.'));
      }
      if(offers.length){
        content.append(el('p','mad4b-pcl-note',
          'WEB MARKET REFERENCE ONLY — all offers need 3-night date-specific room, nationality, All Inclusive, tax and guest validation.'));
        const ul=el('ul','');
        for(const offer of offers){
          const line=el('li','');
          line.append(el('span','',
            String(offer.title||'Resort')+
            ' · $'+String(offer.web_offer_usd_per_night)+
            '/night · 3× illustration $'+String(offer.three_night_illustration_usd)+
            ' (NOT an approved 3-night offer)'+
            (offer.planning_eligible_by_operator_tier
              ?' · tier assigned by Operations':' · tier needs Operations assignment')+
            ' · '+String(offer.web_offer_date||'Date unknown')+
            ' · '+String(offer.meal_evidence||'meal not verified')));
          if(offer.source_url)line.append(document.createTextNode(' · '),
              link(offer.source_url,'Original web offer'));
          ul.append(line);
        }
        content.append(ul);
      }else if(item.kind!=='sleeper_train'){
        content.append(el('p','mad4b-pcl-note',
          'No confirmed room/cabin cost or date-specific inventory. Not priced as zero.'));
      }
      disclosure.append(content);
    }
    pane.append(disclosure);
    if(draft.reservations_review_sheet_url)
      pane.append(row('p',[link(draft.reservations_review_sheet_url,
        'Reservations review and supplier net cost worksheet')]));
    pane.append(el('p','mad4b-pcl-warning',
      'Draft-only · Cannot send as a binding quotation, generate a final Tour Rate, accept deposit or activate checkout.'));
    h.append(pane);
  }
  const actions=el('p','mad4b-pcl-links');
  if(data.tour_edit_url)actions.append(link(data.tour_edit_url,'Open original Tour editor'),document.createTextNode(' · '));
  actions.append(link(cfg.property_pricing_url,'Open native Property & Unit Pricing'));
  // Editor links belong to review and follow-up, not every stage header.
  const components=el('div','mad4b-pcl-components');
  const guide=data.guide_review;
  const transport=data.transport_review;
  components.append(
    el('span','','Guide days: '+(guide?
      (String(guide.reviewed_days||0)+' of '+String(guide.day_count||0)+' reviewed'):'Not evaluated')),
    el('span','','Rate transport stages: '+(transport?String(transport.native_rate_stage_count||0):'Not evaluated')),
    el('span','','Existing non-published linked Rates: '+String(data.linked_original_rate_count||0)+' (never treated as supplier cost)')
  );
  result.append(h);
  const hotel=data.hotel_cost_preview||null;
  if(hotel && hotel.contract==='mad4b.package-hotel-stage-cost-preview.v1'){
    const scope=el('section','mad4b-pcl-stage-cost-preview');
    const header=el('h3','','Hotel / Resort Cost by Stage · Property Standard fallback');
    scope.append(header);
    scope.append(el('p','mad4b-pcl-note',
      String(hotel.preliminary_stage_count||0)+' / '+String(hotel.hotel_resort_stage_count||0)+
      ' hotel/resort stages with source-based preliminary cost. Remaining: '+
      String(hotel.unpriced_stage_count||0)+' · Currency '+String(hotel.currency||'not selected')+
      '. Each stage is priced independently; Units are optional.'));
    const profit=hotel.company_profit_policy||null;
    if(profit)scope.append(el('p','mad4b-pcl-note','Company-wide profit policy ACTIVE: '+
      String(profit.percent)+'% '+String(profit.kind||'')+
      ' · applied ONCE to total certified Package cost, never again to selling Rates.'));
    else scope.append(el('p','mad4b-pcl-warning','Company-wide profit policy needs review: '+
      String(hotel.company_profit_status||'missing')));
    if(hotel.preliminary_partial_total!==null)
      scope.append(el('p','mad4b-pcl-warning','Preliminary PARTIAL hotel cost subtotal: '+
        String(hotel.preliminary_partial_total)+' '+String(hotel.currency||'')+
        ' · Not an approved Package cost, price, or payable amount.'));
    const margin=hotel.hotel_cost_component_margin_preview;
    if(margin && margin.margin_applied_once)
      scope.append(el('p','mad4b-pcl-note','Hotel component ONLY — hypothetical selling share after '+
        String(profit?.percent||'?')+'% company policy: '+String(margin.indicative_sale_component)+
        ' '+String(margin.currency||'')+' (unapproved partial component; not a Tour Rate or payment amount).'));
    const comparison=hotel.candidate_comparison_margin_preview;
    if(comparison && comparison.company_profit_applied_once)
      scope.append(el('p','mad4b-pcl-warning',
        'UNASSIGNED candidate comparison (hotel stages ONLY): referenced cost '+
        String(comparison.candidate_cost_total)+' '+String(comparison.currency||'')+
        ' · indicative hotel-only selling component with company margin ONCE '+
        String(comparison.hypothetical_hotel_component_with_margin)+' '+
        String(comparison.currency||'')+
        '. Not a Package Tour Rate; other services, inventory and approval not included.'));
    const disclosure=el('details','mad4b-pcl-diagnostics');
    disclosure.open=Boolean(hotel.start_date);
    disclosure.append(el('summary','','Stage cost source and review status'));
    const tbl=el('table','mad4b-pcl-table widefat striped');
    const caption=el('caption','','Per-stage reference costs. No supplier certification or availability guaranteed.');
    const head=el('thead');head.append(row('tr',[
      el('th','','Stage / travel dates'),el('th','','Source'),
      el('th','','Preliminary cost'),el('th','','Review needed')]));
    tbl.append(caption,head);
    const body=el('tbody');
    for(const item of (Array.isArray(hotel.stages)?hotel.stages:[])){
      const identity=String(item.destination||item.kind||'stay')+' · '+String(item.stage_key||'')+
        (item.from?' · '+String(item.from)+' to '+String(item.to):'');
      const src=item.source==='property'?'Property Standard Rate':
        item.source==='unit'?'Unit-Specific Rate':
        'No rate selected';
      const amount=item.preliminary_cost===null?'Not priced':
        String(item.preliminary_cost)+' '+String(item.currency||'');
      const sourceCell=el('td','',src+(item.rate_id?' · #'+item.rate_id:''));
      const alternatives=Array.isArray(item.candidate_costs)?item.candidate_costs:[];
      if(alternatives.length){
        sourceCell.append(el('p','mad4b-pcl-note','Eligible dated Property cost alternatives (not selected):'));
        const choices=el('ul','mad4b-pcl-candidate-costs');
        for(const x of alternatives.slice(0,5)){
          choices.append(el('li','',String(x.property_name||'Property #'+x.property_id)+
            ' · '+String(x.preliminary_cost)+' '+String(x.currency||'')+
            ' · Rate #'+String(x.rate_id||'')+
            (x.stop_sale?' · STOP SALE / cost reference':'')+
            ' · unapproved'));
        }
        sourceCell.append(choices);
      }
      body.append(row('tr',[
        el('td','',identity),sourceCell,
        el('td','',amount),
        el('td','',String(item.reason||'review_required').replaceAll('_',' '))
      ]));
    }
    const scroll=el('div','mad4b-pcl-property-body');
    scroll.append(tbl);disclosure.append(scroll);scope.append(disclosure);
    scope.append(el('p','mad4b-pcl-note',String(hotel.notice||'No commercial approval.')));
    result.append(scope);
  }
  const stages=Array.isArray(data.stages)?data.stages:[];
  const tabs=[
    ['sources','1 · Sources & stages','Confirm Package itinerary, destination and tier before selecting costs. This step reads source data only.'],
    ['assignments','2 · Assignments','Inspect qualified properties and units for each independent stay. Editing stays in original source editors.'],
    ['cost','3 · Cost & margin','Review missing supplier receipts, dates, occupancy and inventory. No zero-cost assumptions or invented margins.'],
    ['review','4 · Review & generate','Review readiness gates and open original editors. New Tour Rate generation stays disabled until all approvals exist.']
  ];
  const nav=el('div','mad4b-pcl-steps');nav.setAttribute('role','tablist');
  nav.setAttribute('aria-label','Package pricing workflow');
  const panes=el('div','mad4b-pcl-panes');
  const panels={};const buttons={};
  for(const [id,label,description] of tabs){
    const tabItem=el('div','mad4b-pcl-step-item');
    const tab=el('button','mad4b-pcl-step',label);
    tab.type='button';tab.id='mad4b-pcl-tab-'+id;
    tab.setAttribute('role','tab');tab.setAttribute('aria-controls','mad4b-pcl-panel-'+id);
    tab.addEventListener('click',()=>activate(id));
    tabItem.append(tab,help(label,description));nav.append(tabItem);buttons[id]=tab;
    const panel=el('section','mad4b-pcl-panel');
    panel.id='mad4b-pcl-panel-'+id;panel.setAttribute('role','tabpanel');
    panel.setAttribute('aria-labelledby',tab.id);
    panels[id]=panel;panes.append(panel);
  }
  function activate(id){
    for(const [name,tab] of Object.entries(buttons)){
      const selected=name===id;
      tab.setAttribute('aria-selected',String(selected));
      tab.tabIndex=selected?0:-1;panels[name].hidden=!selected;
      tab.closest('.mad4b-pcl-step-item').classList.toggle('is-active',selected);
    }
  }
  nav.addEventListener('keydown',event=>{
    if(!['ArrowRight','ArrowLeft','Home','End'].includes(event.key))return;
    const keys=Object.keys(buttons),index=keys.findIndex(k=>buttons[k]===document.activeElement);
    if(index<0)return;
    event.preventDefault();
    const next=event.key==='Home'?0:event.key==='End'?keys.length-1:
      (index+(event.key==='ArrowRight'?1:-1)+keys.length)%keys.length;
    activate(keys[next]);buttons[keys[next]].focus();
  });
  result.append(nav,panes);
  const sourceIntro=el('p','mad4b-pcl-step-intro',
    'Source map only. Each return stay has its own pricing identity, even when eligible hotel names repeat.');
  panels.sources.append(sourceIntro,diagnostics);
  for(let i=0;i<stages.length;i++){
    const item=stages[i];const line=el('p','mad4b-pcl-source-line',
      'Stage '+(i+1)+' · '+String(item.kind||'stay')+
      (item.destination?' · '+String(item.destination).replaceAll('_',' '):'')+
      ' · '+String(item.nights||0)+' night(s)');
    panels.sources.append(line);
  }
  panels.assignments.append(el('p','mad4b-pcl-step-intro',
    'Open one stage to inspect candidate hotels or cruise source requirements. Returning to Cairo does not copy room prices, dates or inventory.'));
  panels.cost.append(warn,el('p','mad4b-pcl-step-intro',
    'Missing supplier costs are unknown, never zero. Cost and margin remain unavailable until source receipts, dates, occupancy and availability are approved.'));
  const costGaps=el('ul','mad4b-pcl-cost-gaps');
  const addGap=(message)=>costGaps.append(el('li','',message));
  if(Number(s.unit_cost_role_positive_prices||0)===0)
    addGap('No linked units have a recorded supplier cost source.');
  if(Number(s.units_referenced||0)>Number(s.unit_occupancy_recorded||0))
    addGap('Occupancy information is incomplete for '+String(
      Number(s.units_referenced||0)-Number(s.unit_occupancy_recorded||0))+' linked unit(s).');
  if(Number(s.unit_available||0)===0)
    addGap('No linked units are currently marked available. This is not a live availability verdict.');
  if(Number(s.hotel_stages_requiring_itinerary_review||0)>0)
    addGap(String(s.hotel_stages_requiring_itinerary_review)+
      ' hotel stay stage(s) require overnight itinerary confirmation.');
  if(guide && Number(guide.reviewed_days||0)<Number(guide.day_count||0))
    addGap('Guide pricing review: '+String(guide.reviewed_days||0)+' of '+
      String(guide.day_count||0)+' days completed.');
  if(transport && Number(transport.native_rate_stage_count||0)===0)
    addGap('No transport stages have been recorded in the linked Rate review.');
  if(stages.some(stage=>stage.kind==='cruise'))
    addGap('Cruise supplier cost requires confirmed vessel, program, cabin and departure.');
  if(costGaps.childElementCount)panels.cost.append(costGaps);
  panels.review.append(el('p','mad4b-pcl-step-intro',
    'Review source gaps before generating any Rate. This read-only section does not write a Tour Rate, activate a policy or approve checkout.'));
  panels.review.append(components,actions);
  const reviewGate=el('p','mad4b-pcl-warning',
    'Generation unavailable: no approved Package Cost total, stage assignments or supplier receipts have been established.');
  panels.review.append(reviewGate);
  const generate=el('button','button button-primary','Generate Tour Rates');
  generate.type='button';generate.disabled=true;
  generate.title='Not available until approved cost, occupancy, dates, inventory and policies are established.';
  panels.review.append(generate);
  const chosen=window.MAD4BPackageCostLabActiveStep||'sources';
  activate(buttons[chosen]?chosen:'sources');
  for(const [id,btn] of Object.entries(buttons)){
    btn.addEventListener('click',()=>{window.MAD4BPackageCostLabActiveStep=id;});
  }
  for(let index=0;index<stages.length;index++){
    const stage=stages[index];
    const section=el('section','mad4b-pcl-stage');
    const title=el('h4','',
      'Stage '+(index+1)+' · '+String(stage.kind||'Stay')+
      (stage.destination?' · '+stage.destination.replaceAll('_',' '):'')+
      ' · day '+String(stage.day_start||'?')+'–'+
      String(Math.max(Number(stage.day_start||0),Number(stage.departure_day_number||1)-1))+
      ' ('+String(stage.nights||0)+' night(s))');
    const stageDisclosure=el('details','mad4b-pcl-stage-disclosure');
    const stageToggle=el('summary','mad4b-pcl-stage-toggle');
    const candidateCount=Array.isArray(stage.candidates)?stage.candidates.length:0;
    stageToggle.append(title,el('span','mad4b-pcl-stage-meta',
      (stage.kind==='cruise'?'Vessel / sailing / cabin review':candidateCount+' eligible property candidate(s)')+
      ' · '+(stage.selected_property_id?'Property assigned':'Not assigned')));
    stageDisclosure.append(stageToggle,section);
    const badgeLine=el('p','mad4b-pcl-stage-status');
    badgeLine.append(
      status(false,'','Supplier cost: '+String(stage.price_status||'not approved').replaceAll('_',' ')),
      status(Boolean(stage.selected_property_id),
        stage.kind==='cruise'?'Vessel selected':'Hotel selected',
        stage.kind==='cruise'?'No vessel selected':'No hotel selected'),
      status(stage.selected_unit_count>0,
        stage.kind==='cruise'?'Cabin selected':'Room selected',
        stage.kind==='cruise'?'No cabin selected':'No room selected'),
      status(Boolean(stage.native_rate_stage_written),'In native Rate','Not exported to Tour Rate')
    );
    section.append(badgeLine);
    const scope=stage.destination_scope||{};
    const hotelStay=['hotel','resort'].includes(stage.kind);
    const locationRule=hotelStay
      ? ('Hotel destination: '+(scope.accepted_slugs||[]).join(' / ')+
         (scope.metro_exception?' (sourced Cairo/Giza scope)':'')+
         (scope.status==='mapped'?'':' · destination taxonomy needs review'))
      : ('Separate '+String(stage.kind||'travel')+' component — hotel destination matching does not apply');
    const scopeP=el('p','mad4b-pcl-stage-scope',
      locationRule+
      ' · Stay instance: '+String(stage.key||'')+
      ' · arrival day '+String(stage.day_start||'?')+
      ' · departure day '+String(stage.departure_day_number||'?'));
    section.append(scopeP);
    if(stage.same_destination_revisit){
      section.append(el('p','mad4b-pcl-info',
        'Return to the same destination. Some hotel alternatives may overlap, but Property/Unit selection, dates, occupancy, availability and supplier tariff must be assessed independently for this stage.'));
    }
    const evidence=stage.itinerary_evidence||{};
    if(['hotel','resort'].includes(stage.kind)){
      const itineraryOk=evidence.status==='explicit_overnights_matched';
      const dayLabel=itineraryOk?'Explicit overnight destinations match the saved stage':
        evidence.status==='itinerary_destination_conflict'?'Itinerary conflicts with the saved stage destination':
        'Saved stage needs more itinerary overnight evidence; no automatic confirmation';
      section.append(el('p',
        itineraryOk?'mad4b-pcl-stage-itinerary mad4b-pcl-confirmed':'mad4b-pcl-stage-itinerary mad4b-pcl-needs-review',
        dayLabel));
      const nights=Array.isArray(evidence.night_days)?evidence.night_days:[];
      if(nights.length){
        const details=el('details','mad4b-pcl-night-evidence');
        details.append(el('summary','','Itinerary days used for this stay ('+nights.length+' nights)'));
        const list=el('ol','');
        for(const night of nights){
          list.append(el('li','',
            'Day '+night.day+' · '+(night.source_title||'Untitled')+
            ' · '+(night.observed_overnight_destinations||[]).join(', ')+
            ' · '+night.result));
        }
        details.append(list);section.append(details);
      }
    }
    // Show original-source research Drafts without creating a second editor.
    // Research-only Unit references NEVER grant tier selection, rates or checkout.
    const er=stage.editorial_source_review||{};
    const draftProps=Array.isArray(er.draft_property_alternatives)?er.draft_property_alternatives:[];
    const draftUnits=Array.isArray(er.draft_units)?er.draft_units:[];
    if(draftProps.length || draftUnits.length || er.content_review || er.brand_review){
      const review=el('details','mad4b-pcl-source-scout');
      if(!stage.candidates || !stage.candidates.length)review.open=true;
      const summary=el('summary','',
        'Editorial source review · '+draftProps.length+' Draft hotel alternatives · '+draftUnits.length+' linked Draft rooms');
      review.append(summary);
      review.append(row('p',[
        el('strong','','Responsibilities'),
        help('Source review responsibilities',
          'Content & Property Editors verify hotel identity, room labels, itinerary and media rights. Operations & Contracting verify supplier agreements, island/ferry access, dated availability and children policies. Pricing only generates rates after original signed cost receipts.'),
      ],'mad4b-pcl-source-line'));
      review.append(el('p','mad4b-pcl-warning',
        'Research-only records. Draft Properties and Draft Units are not bookable, published stock, or an approved supplier cost, even when their room category appears on the official website.'));
      if(er.content_review)review.append(el('p',er.content_review_state==='resolved_source_only'?'mad4b-pcl-info':'mad4b-pcl-warning',String(er.content_review)));
      if(er.brand_review)review.append(el('p','mad4b-pcl-warning',String(er.brand_review)));
      for(const prop of draftProps){
        const item=el('div','mad4b-pcl-source-review-property');
        item.append(row('p',[
          link(prop.edit_url,prop.title || ('Property #'+prop.id)),
          el('span','',' · Draft · '+String(prop.role||'review pending'))
        ]));
        const roomList=Array.isArray(prop.draft_units)?prop.draft_units:[];
        if(prop.official_url)item.append(row('p',[link(prop.official_url,'Official room evidence')]));
        if(roomList.length){
          const more=el('details','mad4b-pcl-source-unit-drafts');
          more.append(el('summary','','Review '+roomList.length+' original Draft Units'));
          const ul=el('ul','');
          for(const u of roomList)ul.append(row('li',[
             link(u.edit_url,u.title||('Unit #'+u.id)),
             el('small','',' · occupancy, inventory and cost unapproved')
          ]));
          more.append(ul);item.append(more);
        }
        review.append(item);
      }
      if(draftUnits.length){
        const more=el('details','mad4b-pcl-source-unit-drafts');
        more.append(el('summary','','Review '+draftUnits.length+' Draft Units at the existing hotel'));
        const ul=el('ul','');
        for(const u of draftUnits)ul.append(row('li',[
          link(u.edit_url,u.title||('Unit #'+u.id)),
          el('small','',' · not bookable, supplier contract pending')
        ]));
        more.append(ul);review.append(more);
      }
      section.append(review);
    }
    const excluded=Array.isArray(stage.excluded_candidates)?stage.excluded_candidates:[];
    if(excluded.length){
      section.append(el('p','mad4b-pcl-warning',
        excluded.length+' saved hotel candidate(s) excluded because destination, tier, publication or Property type did not match. Excluded candidates are never priced or shown as selectable.'));
      const hidden=el('details','mad4b-pcl-excluded');
      hidden.append(el('summary','','Inspect excluded source candidates ('+excluded.length+')'));
      const entries=el('ul','');
      for(const item of excluded){
        entries.append(el('li','',(item.title||'Property #'+item.property_id)+
          ' · '+String(item.reason||'unqualified')));
      }
      hidden.append(entries);section.append(hidden);
    }
    // A separate source discovery does not grant candidate eligibility,
    // unit assignment or supplier price authority.
    const source=stage.source_discovery||{};
    if(source.type==='published_hotel_property_pool'){
      const scout=el('details','mad4b-pcl-source-scout');
      const count=Number(source.source_property_count||0);
      scout.append(el('summary','',
        'Discover real '+String(stage.destination||'')+' hotel Properties ('+count+
        ') · '+String(source.tier_matched_count||0)+' with matching Package Level'+
        ' · '+String(source.tier_unclassified_count||0)+' unclassified'));
      scout.append(el('p','mad4b-pcl-warning',
        'Editorial source pool only. A real hotel is not a selected hotel for this tier or date; add the correct native Package Level, actual Room Units and positive supplier rates before generation.'));
      if(source.excluded_wrong_locality){
        scout.append(el('p','mad4b-pcl-info',
          String(source.excluded_wrong_locality)+' Abu Simbel Property record(s) excluded from an Aswan-city overnight.'));
      }
      if(stage.destination==='aswan' && source.city_location_unverified_count){
        scout.append(el('p','mad4b-pcl-warning',
          String(source.city_location_unverified_count)+
          ' Aswan governorate candidate(s) still lack native Aswan-city destination proof. Assigning a tier alone will not make them eligible.'));
      }
      if(source.draft_native_units_discovered){
        scout.append(el('p','mad4b-pcl-info',
          String(source.draft_native_units_discovered)+
          ' official-source Unit category draft(s) are linked to these Properties. Drafts are not available stock, occupancy approval or supplier costs.'));
      }
      const properties=Array.isArray(source.properties)?source.properties:[];
      if(properties.length){
        const table=el('table','widefat striped mad4b-pcl-table');
        table.append(row('thead',[row('tr',[
          el('th','','Published Property'),el('th','','Native stars'),
          el('th','','Package Level'),el('th','','Units: published / draft')
        ])]));
        const tbody=el('tbody');
        for(const prop of properties){
          const unitCell=el('td','');
          unitCell.append(el('span','',String(prop.native_published_unit_count||0)+
            ' published / '+String(prop.native_draft_unit_count||0)+' draft'));
          const drafts=Array.isArray(prop.source_unit_draft_editors)
            ?prop.source_unit_draft_editors:[];
          if(drafts.length){
            const more=el('details','mad4b-pcl-source-unit-drafts');
            more.append(el('summary','','Open original room drafts ('+drafts.length+')'));
            const links=el('ul','');
            for(const item of drafts){
              const li=el('li','');
              li.append(link(item.edit_url,item.title||('Unit #'+item.id)));
              li.append(el('small','',
                ' · capacity '+(item.capacity===null?'unverified':String(item.capacity))+
                ' · supplier cost pending'));
              links.append(li);
            }
            more.append(links);unitCell.append(more);
          }
          tbody.append(row('tr',[
            row('td',[link(prop.edit_url,prop.title||('Property #'+prop.id))]),
            el('td','',(prop.stars||[]).join(', ')||'Not classified'),
            el('td','',prop.tier_eligible?'Matches selected tier':
              (prop.tier_status==='tier_unclassified'?'Not assigned to any package tier':
               'Not eligible for selected tier')),
            unitCell
          ]));
        }
        table.append(tbody);scout.append(table);
      }else scout.append(el('p','mad4b-pcl-empty','No source-backed published hotel Properties for this destination.'));
      section.append(scout);
    }else if(source.type==='published_sleeper_train_route_candidates'){
      const route=source.route||{};
      const trains=Array.isArray(source.train_candidates)?source.train_candidates:[];
      const scout=el('details','mad4b-pcl-source-scout');
      scout.append(el('summary','','Original sleeper train route '+(route.from||'?')+
        ' → '+(route.to||'?')+' · '+trains.length+' published train candidate(s)'));
      scout.append(el('p','mad4b-pcl-warning',
        'Train station order is a route proposal, not a verified operating timetable, booked sleeper cabin or approved supplier fare.'));
      const proposed=source.operational_route_proposal||null;
      if(proposed && proposed.commercially_selected===false &&
         proposed.rate_generation_authorized===false){
        const contract=el('div','mad4b-pcl-rail-qualification');
        const seg=proposed.segment||{};
        const serviceDate=dayDate(Number(proposed.day_offset||0));
        contract.append(el('h4','','Rail Supplier Qualification · Day '+String(proposed.day_number)));
        contract.append(el('p','',
          'Route: '+String(proposed.route_from||'?')+' → '+String(proposed.route_to||'?')+
          ' · suggested train #'+String(proposed.train_number||'?')+
          ' · declared operator '+String(proposed.declared_operator||'Not declared')));
        contract.append(el('p','',
          'Boarding station #'+String(seg.boarding_station_id||'?')+
          ' at '+String(seg.boarding_time||'?')+
          ' → arrival station #'+String(seg.arrival_station_id||'?')+
          ' at '+String(seg.arrival_time||'?')+
          '. These times are source records, NOT a confirmed timetable.'));
        contract.append(el('p','mad4b-pcl-note',
          'Planned service date: '+(serviceDate||'select a Tour start date above')+
          ' · cabin types to quote: Standard or Elite · possible supplier cost basis: per cabin per trip or per passenger per trip.'));
        const priceRole=proposed.direct_or_rate_price_source_review||null;
        if(priceRole && priceRole.contract==='mad4b.transport.start-price-roles-and-source-weight.v1'){
          const order=priceRole.global_priority||{};
          const modeLabels={
            indicative_only:'Indicative only',
            cost_reference_only:'Cost reference for planning only',
            both:'Both: indicative + planning cost'
          };
          contract.append(el('h4','','Transportation Price Source and Fallback'));
          contract.append(el('p','',
            'Source usage: '+(modeLabels[priceRole.mode]||String(priceRole.mode))+
            ' · direct: '+(priceRole.direct&&priceRole.direct.price_present
              ?String(priceRole.direct.amount)+' '+String(priceRole.direct.currency)+' / '+String(priceRole.direct.unit)
              :'not entered')+
            ' · published eligible cost Rate candidates: '+
               String((priceRole.rate_cost_candidates||[]).filter(x=>x.planning_cost_candidate).length)));
          contract.append(el('p','mad4b-pcl-note',
            'Weights: Rate '+String(order.rate_weight??'—')+
            ' / Direct '+String(order.direct_weight??'—')+
            ' · fallback '+String(order.fallback||'—')+
            ' · one qualifying source wins, amounts are never blended.'));
          contract.append(el('p','mad4b-pcl-warning',
            'Planning cost: '+(priceRole.planning_cost_source
             ?priceRole.planning_cost_source+' '+String(priceRole.planning_cost_amount)+' '+
                String(priceRole.planning_cost_currency)+' / '+String(priceRole.planning_cost_unit)
             :'not available')+
            '. Mode activation is not a signed supplier price or booking authorization.'));
          if((priceRole.reasons||[]).length){
            contract.append(el('p','mad4b-pcl-note',
              'Price admission notes: '+priceRole.reasons.join(', ')));
          }
          const tariff=priceRole.published_public_tariff_evidence;
          if(tariff && tariff.contract==='mad4b.abela.public-tariff-evidence.v1'){
            const fares=tariff.money&&tariff.money.foreign_usd_one_way_per_person||{};
            contract.append(el('h4','','Abela published fares · foreign adults · 2026 · USD per person one-way'));
            for(const [season,seasonLabel] of [['off_peak','Outside peak'],['peak','Peak season']]){
              for(const [kind,label] of [['standard','Standard'],['elite','Elite']]){
                const row=fares[season]&&fares[season][kind];
                if(!row)continue;
                contract.append(el('p','mad4b-pcl-note',seasonLabel+' · '+label+
                  ': shared two-berth $'+String(row.shared_double)+' / sole occupancy $'+
                  String(row.single_sole_use)));
              }
            }
            contract.append(el('p','mad4b-pcl-warning',
              'Public tariff only. Children have conflicting published figures. Do not infer Egyptian/Arab, 2027, supplier contract, stock or commercial cost.'));
            if(tariff.sources&&tariff.sources.official_2026_current_link)
              contract.append(link(tariff.sources.official_2026_current_link,
                'Abela current tariff publication'));
          }
        }
        const todo=el('ul','');
        const tasks={
          operator_confirmed_current_date_timetable:'Confirm actual train, station and times for the selected travel date',
          supplier_agreement_or_actual_ticket_quote:'Collect Abela contractual rate or dated ticket quotation',
          cabin_class_and_number_of_passengers:'Specify Standard/Elite and single/double cabin allocations',
          adults_children_infants_cabin_rules:'Confirm adult, child and infant berth-sharing rules',
          ticket_cost_currency_meals_and_cancellation_terms:'Confirm total cost, currency, dinner/breakfast and cancellation policy',
          date_specific_cabin_availability_reference:'Record supplier inventory or booking reference and expiration',
          signed_supplier_cost_approval:'Obtain original authorized supplier cost approval before enabling any Rate'
        };
        for(const code of proposed.missing_evidence||[]){
          todo.append(el('li','',tasks[code]||String(code)));
        }
        contract.append(todo);
        contract.append(el('p','mad4b-pcl-warning',
          'Operations proposal only. No train, compartment or cost has been approved. Avoid using the hotel-night rate for this stage.'));
        scout.append(contract);
      }
      for(const train of trains){
        scout.append(row('p',[
          link(train.edit_url,train.title||('Train #'+train.id)),
          el('span','',' · train '+(train.number||'?')+
            ' · '+String(train.published_transport_rate_count||0)+
            ' published transport rates · sleeper cabin fare not verified')
        ]));
        scout.append(el('p','',
          'Tour Rate opt-in: '+(train.tour_rate_generation_enabled?'enabled for train record (not cost-authorized)':
            'OFF — operator has not enabled this transport service for automatic Tour Rates')+
          ' · approved supplier contract: NO · dated sleeper fare: NOT VERIFIED'));
        scout.append(row('p',[
          link(train.public_timetable_reference,'Public timetable reference'),
          el('span','',' · '),
          link(train.official_operator_booking_url,'Verify directly with Abela')
        ],'mad4b-pcl-source-line'));
        if(train.native_terminal_needs_schedule_revalidation){
          scout.append(el('p','mad4b-pcl-warning',
            'Station source conflict: the saved train stop data uses Ramses. The late-2026 published reference lists Upper Egypt (Bashtil) as the Cairo terminal. Revalidate the service station, date, ticket class and fare with Abela before booking.'));
          scout.append(row('p',[
            link(train.public_timetable_reference,'Review published timetable reference'),
            el('span','',' · '),
            link(train.official_operator_booking_url,'Verify on Abela')
          ],'mad4b-pcl-source-line'));
        }
      }
      if(!trains.length)scout.append(el('p','mad4b-pcl-empty',
        'No published sleeping train supports the route in the original itinerary.'));
      section.append(scout);
    }else if(source.type==='cruise_native_program_source'){
      const scout=el('div','mad4b-pcl-source-program');
      scout.append(el('p','',
        'Original cruise program: '+(source.program_title||'Unassigned')+
        ' · '+(source.route_from||'?')+' → '+(source.route_to||'?')+
        ' · '+String(source.program_days||0)+' program days'+
        ' · vessel/cabin/sailing and supplier tariff still unverified'));
      section.append(scout);
    }
    const candidates=Array.isArray(stage.candidates)?stage.candidates:[];
    if(!candidates.length){
      let msg='No destination- and tier-matched hotel candidates are linked to this stage. Pricing remains unavailable.';
      if(stage.kind==='cruise')
        msg='Cruise is a separate component: confirm vessel, sailing program, cabin Unit, departure, and supplier tariff.';
      else if(stage.kind==='sleeper_train')
        msg='Sleeper train requires its own transport/cabin cost source. Hotel unit rates do not cover it.';
      section.append(el('p','mad4b-pcl-empty',msg));
      panels.assignments.append(stageDisclosure);continue;
    }
    const list=el('div','mad4b-pcl-properties');
    for(const property of candidates){
      const block=el('details','mad4b-pcl-property');
      const sum=el('summary','');
      const heading=el('strong','',property.title || ('Property #'+property.id));
      const sub=el('span','',
        String(property.native_units_linked||0)+' units · '+String(property.unit_capacity_known||0)+' occupancy known · '+
        String(property.unit_cost_fields||0)+' cost fields entered');
      sum.append(heading,sub);
      block.append(sum);
      const body=el('div','mad4b-pcl-property-body');
      const flags=el('p','mad4b-pcl-property-flags');
      flags.append(
        status(property.status==='publish','Property published','Property not published'),
        status(property.native_units_linked>0,'Units linked','No Units linked'),
        status(property.unit_available>0,'Some units available','No units confirmed available'),
        status(property.published_property_cost_rate_count>0,'Published cost rate','No published cost rate')
      );
      body.append(flags);
      if(property.edit_url)body.append(row('p',[link(property.edit_url,'Edit original Property')]));
      const units=Array.isArray(property.units)?property.units:[];
      if(!units.length)body.append(el('p','mad4b-pcl-empty','No unit source IDs were recorded for this Property candidate.'));
      else{
        const table=el('table','widefat striped mad4b-pcl-table');
        table.append(row('thead',[row('tr',[
          el('th','','Native Unit'),el('th','','Occupancy'),el('th','','Availability'),
          el('th','','Supplier cost source')
        ])]));
        const tbody=el('tbody');
        for(const unit of units){
          const cell=row('td',[link(unit.edit_url,unit.title||('Unit #'+unit.id))]);
          if(!unit.source_relation_match)cell.append(el('small','mad4b-pcl-missing',' · Property relation needs review'));
          tbody.append(row('tr',[
            cell,
            el('td','',unit.capacity!==null?String(unit.capacity):'Missing'),
            el('td','',unit.available?'Marked available':'Not available'),
            el('td','',unit.declared_unit_cost_field?'Declared cost field (not approved)':'Missing')
          ]));
        }
        table.append(tbody);body.append(table);
      }
      block.append(body);
      list.append(block);
    }
    section.append(list);
    panels.assignments.append(stageDisclosure);
  }
  panels.sources.append(el('p','mad4b-pcl-footer',
    'Each stage is a separate stay and pricing identity. Exact native Destination and Package Level taxonomy are checked independently against every candidate Property (with documented Cairo/Giza metro scope only). Repeated options do not share a saved room, nightly tariff or availability. The itinerary overnight text provides review evidence, not booking approval. No Tour Rate, supplier contract, checkout or pricing settings were changed.'));
}
async function load(event){
  if(event)event.preventDefault();
  if(!p.value||!t.value){
    result.replaceChildren();
    const selected=packages.find(x=>String(x.id)===String(p.value));
    state.textContent=selected&&selected.tier_source==='unassigned_company_policy_missing'
      ?'No operational tier assignment or active company fallback. Set Company Package Levels in Pricing Controls.'
      :'No allowed level for this Tour under its operational assignment or company strategy.';
    b.disabled=true;
    return;
  }
  const token=++requestToken;
  b.disabled=true;
  state.textContent='Reading native Package, Property and Unit sources…';
  const body=new URLSearchParams({
    action:String(cfg.action),nonce:String(cfg.nonce),
    tour_id:p.value,tier_id:t.value,
    travel_date:date.value,rooms:rooms.value,adults:adults.value,
    children:children.value,infants:'0',currency:currency.value
  });
  try{
    const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:body.toString()});
    const json=await response.json();
    if(token!==requestToken)return;
    if(!response.ok||!json||json.success!==true||!json.data||!json.data.result){
      throw new Error((json&&json.data&&json.data.code)||'Package source unavailable');
    }
    renderReport(json.data.result);
    state.textContent='Source inspection complete. No commercial pricing changes made.';
  }catch(error){
    if(token===requestToken){
      result.replaceChildren();
      state.textContent='Unable to inspect this Package: '+String(error.message||error);
    }
  }finally{
    if(token===requestToken)b.disabled=false;
  }
}
form.addEventListener('submit',load);
date.addEventListener('change',load);
for(const field of [rooms,adults,children,currency])field.addEventListener('change',load);
p.addEventListener('change',()=>{applyTourTierOptions();load();});
t.addEventListener('change',load);
load();
})();
