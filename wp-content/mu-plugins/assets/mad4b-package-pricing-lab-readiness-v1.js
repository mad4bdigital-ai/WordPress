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
const note=el('p','mad4b-pcl-note','Prices from legacy Tour Rates and QA-only Property Rates are never used as supplier cost.');
const state=el('p','mad4b-pcl-status','Select a Package and tier to inspect source evidence.');
state.setAttribute('role','status');
const result=el('div','mad4b-pcl-result');
inner.replaceChildren(form,note,state,result);
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
  if(history.length)
    h.append(el('p','mad4b-pcl-warning',history.join(' · ')+
      ' (as recorded in saved source data; not a new live policy verdict)'));
  const kpis=el('div','mad4b-pcl-kpis');
  kpis.append(
    metric(String(s.stages||0),'Stay / travel stages'),
    metric(String(s.candidate_mentions||0),'Hotel-stage candidates (may repeat)'),
    metric(String(s.unique_candidate_properties||0),'Unique candidate Properties in tier'),
    metric(String(s.units_referenced||0),'Unique linked units in this tier'),
    metric(String(s.unit_occupancy_recorded||0),'Units with recorded occupancy'),
    metric(String(s.unit_cost_role_positive_prices||0),'Units with declared cost source'),
    metric(String(s.unit_available||0),'Units marked available'),
    metric(String(s.stages_without_candidates||0),'Hotel stages without candidates'),
    metric(String(s.destination_excluded_candidates||0),'Excluded candidate-stage mismatches'),
    metric(String(s.hotel_stages_requiring_itinerary_review||0),'Stay stages awaiting itinerary confirmation')
  );
  h.append(kpis);
  const warn=el('p','mad4b-pcl-warning','No approved Package Cost total. Missing unit rates, supplier receipts, inventory, and stage assignments must not be treated as zero.');
  h.append(warn);
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
  h.append(actions);
  const components=el('div','mad4b-pcl-components');
  const guide=data.guide_review;
  const transport=data.transport_review;
  components.append(
    el('span','','Guide days: '+(guide?
      (String(guide.reviewed_days||0)+' of '+String(guide.day_count||0)+' reviewed'):'Not evaluated')),
    el('span','','Rate transport stages: '+(transport?String(transport.native_rate_stage_count||0):'Not evaluated')),
    el('span','','Existing non-published linked Rates: '+String(data.linked_original_rate_count||0)+' (never treated as supplier cost)')
  );
  h.append(components);
  result.append(h);
  const stages=Array.isArray(data.stages)?data.stages:[];
  for(let index=0;index<stages.length;index++){
    const stage=stages[index];
    const section=el('section','mad4b-pcl-stage');
    const title=el('h4','',
      'Stage '+(index+1)+' · '+String(stage.kind||'Stay')+
      (stage.destination?' · '+stage.destination.replaceAll('_',' '):'')+
      ' · day '+String(stage.day_start||'?')+'–'+
      String(Math.max(Number(stage.day_start||0),Number(stage.departure_day_number||1)-1))+
      ' ('+String(stage.nights||0)+' night(s))');
    section.append(title);
    const badgeLine=el('p','mad4b-pcl-stage-status');
    badgeLine.append(
      status(false,'','Supplier price: '+String(stage.price_status||'unpriced')),
      status(Boolean(stage.selected_property_id),'Hotel selected','No hotel selected'),
      status(stage.selected_unit_count>0,'Room selected','No room/cabin selected'),
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
    const candidates=Array.isArray(stage.candidates)?stage.candidates:[];
    if(!candidates.length){
      let msg='No destination- and tier-matched hotel candidates are linked to this stage. Pricing remains unavailable.';
      if(stage.kind==='cruise')
        msg='Cruise is a separate component: confirm vessel, sailing program, cabin Unit, departure, and supplier tariff.';
      else if(stage.kind==='sleeper_train')
        msg='Sleeper train requires its own transport/cabin cost source. Hotel unit rates do not cover it.';
      section.append(el('p','mad4b-pcl-empty',msg));
      result.append(section);continue;
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
        status(property.unit_available>0,'Some units available','No Units available'),
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
    result.append(section);
  }
  result.append(el('p','mad4b-pcl-footer',
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
