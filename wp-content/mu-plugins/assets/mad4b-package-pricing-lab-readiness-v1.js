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
opts(t,tiers);
// Deep links only select an item from the already-authorized server catalog.
// The AJAX provider independently checks both Tour edit permission and tier.
const query=new URLSearchParams(window.location.search);
const linkedTour=query.get('mad4b_package_tour');
const linkedTier=query.get('mad4b_package_tier');
if(linkedTour&&packages.some(x=>String(x.id)===linkedTour))p.value=linkedTour;
if(linkedTier&&tiers.some(x=>String(x.id)===linkedTier))t.value=linkedTier;
const b=el('button','button button-primary','Inspect original sources');
b.type='submit';
const form=el('form','mad4b-pcl-form');
form.append(
  row('label',[el('span','','Package Tour'),p],'mad4b-pcl-label'),
  row('label',[el('span','','Package level'),t],'mad4b-pcl-label'),
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
if(!packages.length||!tiers.length){
  b.disabled=true;state.textContent='No package source plans and levels are available for review on this company.';
  return;
}
let requestToken=0;
const metric=(value,label)=>{
  const box=el('div','mad4b-pcl-metric');
  box.append(el('strong','',value),el('span','',label));
  return box;
};
const status=(yes,good,bad)=>el('span',yes?'mad4b-pcl-good':'mad4b-pcl-missing',yes?good:bad);
function renderReport(data){
  result.replaceChildren();
  const s=data.summary||{};
  const h=el('div','mad4b-pcl-summary');
  h.append(el('h3','',data.tour_title+' · '+data.tier));
  const sub=el('p','','Duration: '+data.day_count+' days / '+data.night_count+
    ' nights · '+data.plan_status+' · Source only / not a supplier quote');
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
      const properties=Array.isArray(source.properties)?source.properties:[];
      if(properties.length){
        const table=el('table','widefat striped mad4b-pcl-table');
        table.append(row('thead',[row('tr',[
          el('th','','Published Property'),el('th','','Native stars'),
          el('th','','Package Level'),el('th','','Linked Units')
        ])]));
        const tbody=el('tbody');
        for(const prop of properties){
          tbody.append(row('tr',[
            row('td',[link(prop.edit_url,prop.title||('Property #'+prop.id))]),
            el('td','',(prop.stars||[]).join(', ')||'Not classified'),
            el('td','',prop.tier_eligible?'Matches selected tier':
              (prop.tier_status==='tier_unclassified'?'Not assigned to any package tier':
               'Not eligible for selected tier')),
            el('td','',String(prop.native_unit_count||0))
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
      for(const train of trains){
        scout.append(row('p',[
          link(train.edit_url,train.title||('Train #'+train.id)),
          el('span','',' · train '+(train.number||'?')+
            ' · '+String(train.published_transport_rate_count||0)+
            ' published transport rates · sleeper cabin fare not verified')
        ]));
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
  if(!p.value||!t.value)return;
  const token=++requestToken;
  b.disabled=true;
  state.textContent='Reading native Package, Property and Unit sources…';
  const body=new URLSearchParams({
    action:String(cfg.action),nonce:String(cfg.nonce),
    tour_id:p.value,tier_id:t.value
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
p.addEventListener('change',load);
t.addEventListener('change',load);
load();
})();
