/* In-page, self-provider, native JetEngine accommodation pricing selector. */
(() => {
'use strict';
const cfg=window.MAD4BStayPricingUI, root=document.querySelector('.jet-engine-options-page-wrap');
if(!cfg?.ajax_url||!cfg?.nonce||!cfg?.template||!root||root.querySelector('.mad4b-stay-panel'))return;
const panel=document.createElement('section');
panel.className='mad4b-stay-panel';
panel.setAttribute('aria-label','Accommodation & Unit Pricing');
panel.innerHTML=cfg.template;
root.append(panel);
const q=name=>panel.querySelector('[data-stay="'+name+'"]');
const state={property:null,unit:null,propertyPage:1,propertyPages:0,unitPage:1,unitPages:0,
  filters:{},facets:[],requests:{},requestVersion:0};
const el=(tag,css,text)=>{
  const node=document.createElement(tag);
  if(css)node.className=css;
  if(text!==undefined)node.textContent=String(text);
  return node;
};
const clear=node=>node.replaceChildren();
function safeLink(href,label,css='button'){
  if(typeof href!=='string'||!href)return null;
  try{
    const u=new URL(href,location.origin);
    if(u.origin!==location.origin||!['https:','http:'].includes(u.protocol))return null;
    const a=el('a',css,label);a.href=u.toString();return a;
  }catch{return null}
}
function debounce(fn,ms=260){let timer;return (...args)=>{
  window.clearTimeout(timer);timer=window.setTimeout(()=>fn(...args),ms);
}}
async function request(mode,values={},slot=mode){
  if(state.requests[slot])state.requests[slot].abort();
  const ctrl=new AbortController();
  state.requests[slot]=ctrl;
  const form=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,mode});
  Object.entries(values).forEach(([key,value])=>form.set(key,
    key==='filters'?JSON.stringify(value):String(value??'')));
  try{
    const response=await fetch(cfg.ajax_url,{method:'POST',body:form,
      credentials:'same-origin',signal:ctrl.signal,headers:{Accept:'application/json'}});
    const reply=await response.json();
    if(!response.ok||!reply?.success)throw new Error(reply?.data?.code||'request_failed');
    if(state.requests[slot]!==ctrl){
      const e=new Error('Request replaced');e.name='AbortError';throw e;
    }
    return reply.data;
  }finally{
    if(state.requests[slot]===ctrl)delete state.requests[slot];
  }
}
function showError(error,slot){
  if(error?.name==='AbortError')return;
  slot.textContent='Unable to load ('+(error?.message||'unknown_error')+'). Retry or check permissions.';
}
function options(select,items,first,previous){
  const was=previous===undefined?select.value:previous;
  clear(select);
  select.add(new Option(first,''));
  (items||[]).forEach(item=>select.add(new Option(item.label,String(item.id))));
  if(was&&!Array.from(select.options).some(o=>o.value===was))
    select.add(new Option('Selected classification #'+was,was));
  select.value=was||'';
}
function loadFacets(data){
  state.facets=Array.isArray(data?.properties)?data.properties:[];
  state.facets.forEach(f=>{
    const wrap=el('div','mad4b-stay-panel__facet');
    const label=el('label','mad4b-stay-panel__field');
    const select=el('select','');
    select.dataset.taxonomy=f.taxonomy;
    label.append(el('span','',f.label));
    options(select,f.options,'All '+f.label.toLowerCase());
    select.addEventListener('change',()=>{
      if(select.value)state.filters[f.taxonomy]=select.value;
      else delete state.filters[f.taxonomy];
      state.propertyPage=1;loadProperties();
    });
    label.append(select);wrap.append(label);
    if(f.taxonomy==='destinations'){
      const finder=el('label','mad4b-stay-panel__field mad4b-stay-panel__facet-search');
      const search=el('input');search.type='search';
      search.placeholder='Search other destinations…';
      finder.append(el('span','','Find destination'),search);wrap.append(finder);
      search.addEventListener('input',debounce(async()=>{
        const term=search.value.trim();
        if(term.length<2){
          options(select,f.options,'All '+f.label.toLowerCase(),state.filters[f.taxonomy]||'');
          return;
        }
        try{
          const found=await request('term_search',{taxonomy:f.taxonomy,search:term},'terms');
          options(select,found.options,'All '+f.label.toLowerCase(),state.filters[f.taxonomy]||'');
        }catch(e){showError(e,q('property-status'))}
      },300));
    }
    q('property-filters').append(wrap);
  });
  options(q('unit-type'),data?.unit_types||[],'All unit types');
  options(q('board'),data?.boards||[],'Inherit Property / Cruise base');
  renderSupplierStatus(data?.supplier_policy);
}
function renderSupplierStatus(policy){
  const node=q('supplier-status');
  if(!node)return;
  if(!policy||!Array.isArray(policy.supplier_order)){
    node.textContent='Supplier priority is unavailable; do not authorize prices.';
    return;
  }
  const external=policy.supplier_order.find(x=>x.id==='external_contract');
  const self=policy.supplier_order.find(x=>x.id==='self');
  if(!['configured_preview_only','self_fallback_active'].includes(policy.status)){
    node.textContent='Supplier policy is not approved for preview ('+(policy.status||'unknown')+').';
    return;
  }
  node.textContent='External priority 1: '+(external?.status==='connector_registered'?'connector registered (not price-certified)':'no external connector')+
    ' · Self priority 2: '+(policy.self_fallback_enabled===true?'ACTIVE FALLBACK':'preview only')+
    ' · no automatic checkout or rate publication.';
}
const tag=value=>el('span','mad4b-stay-panel__tag',value);
function choice(item,kind){
  const row=el('div','mad4b-stay-panel__result-row');
  row.setAttribute('role','listitem');
  const pick=el('button','mad4b-stay-panel__pick');
  pick.type='button';
  const active=(kind==='property'?state.property?.id:state.unit?.id)===item.id;
  if(active)pick.classList.add('is-selected');
  pick.setAttribute('aria-pressed',active?'true':'false');
  const detail=el('span','mad4b-stay-panel__result-meta');
  if(kind==='property'){
    (item.type||[]).slice(0,2).forEach(t=>detail.append(tag(t.label)));
    (item.destinations||[]).slice(0,1).forEach(t=>detail.append(tag(t.label)));
    if(item.meal_base)detail.append(tag(item.meal_base.replaceAll('-',' ')));
  }else{
    (item.types||[]).slice(0,2).forEach(t=>detail.append(tag(t.label)));
    if(item.capacity)detail.append(tag('Capacity '+item.capacity));
  }
  if(item.status==='draft')detail.append(tag('Draft · review only'));
  detail.append(tag(item.direct_price==='entered'?'Amount entered · verify terms':'No direct price'));
  pick.append(el('strong','',item.title),detail);
  pick.addEventListener('click',()=>kind==='property'?selectProperty(item):selectUnit(item));
  row.append(pick);
  const edit=safeLink(item.edit_url,'Edit','mad4b-stay-panel__row-edit');
  if(edit)row.append(edit);
  return row;
}
function pager(kind,page,pages,total){
  q(kind+'-prev').disabled=page<=1;
  q(kind+'-next').disabled=page>=pages;
  q(kind+'-page').textContent=pages?'Page '+page+' / '+pages:'No pages';
  q(kind+'-count').textContent=total+' records';
}
async function loadProperties(){
  q('property-status').textContent='Loading published and draft Properties…';
  try{
    const data=await request('properties',{search:q('property-search').value.trim(),
      filters:state.filters,paged:state.propertyPage},'properties');
    state.propertyPages=data.pages;
    clear(q('property-results'));
    data.items.forEach(item=>q('property-results').append(choice(item,'property')));
    q('property-status').textContent=data.found?
      'Select a matching Property. Classification filters use AND.':
      'No Properties match these filters.';
    pager('property',data.page,data.pages,data.found);
  }catch(e){showError(e,q('property-status'))}
}
function resetSelection(){
  state.unit=null;state.unitPage=1;
  clear(q('unit-results'));clear(q('rate-results'));
  q('preview-result').hidden=true;
  q('unit-status').textContent=state.property?'Loading linked Units…':'Select a Property to load Units.';
  q('preview-status').textContent=state.property?'Ready to preview.':'No source selected.';
  q('rate-count').textContent='Choose a Property';
  q('selection').textContent=state.property?state.property.title:'Select a Property.';
  clear(q('edit-links'));
  pager('unit',1,0,0);
}
function selectProperty(item){
  state.property=item;resetSelection();
  loadProperties();
  loadUnits();loadRates();preview();
}
async function loadUnits(){
  if(!state.property)return;
  q('unit-status').textContent='Loading linked Units from JetEngine…';
  try{
    const data=await request('units',{property_id:state.property.id,
      paged:state.unitPage,unit_type:q('unit-type').value},'units');
    state.unitPages=data.pages;
    clear(q('unit-results'));
    data.items.forEach(item=>q('unit-results').append(choice(item,'unit')));
    q('unit-status').textContent=data.found?
      'Choose a room/cabin or use Property-level pricing.':
      'No matching linked Units. Property-level preview remains available.';
    pager('unit',data.page,data.pages,data.found);
  }catch(e){showError(e,q('unit-status'))}
}
function selectUnit(item){
  state.unit=item;
  q('selection').textContent=state.property.title+' / '+item.title;
  loadUnits();loadRates();preview();
}
async function loadRates(){
  if(!state.property)return;
  q('rate-status').textContent='Loading existing Property Rates…';
  try{
    const data=await request('rates',{property_id:state.property.id,
      unit_id:state.unit?.id||0},'rates');
    q('rate-count').textContent=(data.counts?.published||0)+' published · '+
      (data.counts?.draft||0)+' draft';
    q('rate-status').textContent=data.items.length?
      'Only eligible published Rates can affect this preview.':
      'No saved Rates for this Property / Unit.';
    clear(q('rate-results'));
    data.items.forEach(rate=>{
      const row=el('div','mad4b-stay-panel__rate-row');
      const title=el('strong','',rate.title||'Rate #'+rate.id);
      const meta=el('small','',[
        (rate.kind||'unscoped').replaceAll('_',' '),rate.status,
        rate.source_kind==='cost'?'Internal cost · approval not established':'Selling price / unclassified',
        rate.board||'Meal not declared',
        rate.valid_from&&rate.valid_to?rate.valid_from+' – '+rate.valid_to:'Dates missing',
        rate.amount&&rate.currency?rate.amount+' '+rate.currency:'Amount missing',
        rate.season_window_source==='season_term_meta'?'Season dates inherited from Term Meta':'',
        rate.supplier_quote_pending_review?'SUPPLIER SHEET · REVIEW / STOP SALE':'',
        rate.source_cell?'Cell '+rate.source_cell:'',
        rate.source_occupancy?'Occupancy '+rate.source_occupancy:'',
        rate.qa_only?'QA ONLY':''
      ].filter(Boolean).join(' · '));
      row.append(title,meta);
      const edit=safeLink(rate.edit_url,'Edit Rate','button');
      if(edit)row.append(edit);
      q('rate-results').append(row);
    });
    const rateUrl=safeLink(data.rates_index_url,'Open native Property Rates');
    if(rateUrl)q('rate-index').href=rateUrl.href;
    else q('rate-index').hidden=true;
  }catch(e){showError(e,q('rate-status'))}
}
async function preview(){
  if(!state.property)return;
  q('preview-status').textContent='Resolving source…';
  q('preview-result').hidden=true;
  try{
    const data=await request('preview',{property_id:state.property.id,
      unit_id:state.unit?.id||0,checkin:q('checkin').value,
      nights:q('nights').value,adults:q('adults').value,
      children:q('children').value,board_type:q('board').value},'preview');
    const price=data.quote?.price,source=data.quote?.selected_source||'No eligible price';
    renderSupplierStatus(data?.supplier_policy);
    const sel=data.supplier_resolution;
    q('preview-status').textContent=data.quote?.price_status==='draft_entity_pending_review'
      ?'SELF FALLBACK selected · Draft Property / Unit: not eligible for price or booking.'
      :(price?'SELF FALLBACK selected · Indicative price source, checkout not authorized.':
      'SELF FALLBACK selected · No eligible price. Enter and validate company Direct Price or Rate.');
    if(!sel?.fallback_used)q('preview-status').textContent='Supplier routing not verified; do not authorize price.';
    q('price-source').textContent=source.replaceAll('_',' ').toUpperCase();
    q('price-value').textContent=price?
      Number(price.amount).toLocaleString(undefined,{maximumFractionDigits:4})+' '+price.currency:
      'Price not configured';
    q('price-basis').textContent=price?(price.price_unit||'').replaceAll('_',' '):'';
    q('price-meal').textContent='Base: '+(data.quote?.meal_base||'not classified')+
      ' · Requested: '+(data.quote?.requested_board||'inherit');
    const cost=data.quote?.cost_reference;
    q('cost-reference').textContent=cost?
      'Unapproved cost reference: '+cost.source.replaceAll('_',' ')+
      ' #'+cost.rate_id+' · '+Number(cost.amount).toLocaleString(undefined,
        {maximumFractionDigits:4})+' '+cost.currency+' / '+cost.price_unit.replaceAll('_',' ')+
      '. Supplier receipt, native Unit and inventory still required.':
      'No approved Unit/Property cost reference for this stay. Direct prices cannot substitute for supplier cost.';
    const weights=data.effective_weight_policy;
    const fields=Array.isArray(weights?.fields)?weights.fields:[];
    q('effective-weight').textContent=weights?.status==='effective_shadow_only'?
      'Weights (preview only): reference '+weights.reference+
      ' · '+fields.map(x=>x.field.replace('_pct','')+' '+x.percent+'%'+
        ' ['+x.source+']').join(' · ')+
      '. A documented explicit Rate is NOT multiplied by these weights again.':
      'Effective weights unavailable for this selection; review native policy ('+
        (weights?.code||weights?.status||'unknown')+').';
    q('preview-result').hidden=false;
    clear(q('edit-links'));
    [safeLink(data.edit_property_url,'Edit Property'),safeLink(data.edit_unit_url,'Edit Unit'),
      safeLink(data.rate_index_url,'Manage Rates')].filter(Boolean)
      .forEach(link=>q('edit-links').append(link));
  }catch(e){showError(e,q('preview-status'))}
}
q('property-search').addEventListener('input',debounce(()=>{state.propertyPage=1;loadProperties()},320));
q('reset-filters').addEventListener('click',()=>{
  state.filters={};
  q('property-filters').querySelectorAll('select').forEach(select=>select.value='');
  q('property-filters').querySelectorAll('input').forEach(input=>input.value='');
  state.propertyPage=1;loadProperties();
});
q('property-prev').addEventListener('click',()=>{state.propertyPage=Math.max(1,state.propertyPage-1);loadProperties()});
q('property-next').addEventListener('click',()=>{state.propertyPage=Math.min(state.propertyPages,state.propertyPage+1);loadProperties()});
q('unit-prev').addEventListener('click',()=>{state.unitPage=Math.max(1,state.unitPage-1);loadUnits()});
q('unit-next').addEventListener('click',()=>{state.unitPage=Math.min(state.unitPages,state.unitPage+1);loadUnits()});
q('unit-type').addEventListener('change',()=>{state.unitPage=1;loadUnits()});
q('use-property').addEventListener('click',()=>{
  state.unit=null;q('selection').textContent=state.property?.title||'Select a Property.';
  loadUnits();loadRates();preview();
});
q('preview').addEventListener('click',preview);
['checkin','nights','adults','children','board'].forEach(key=>{
  q(key).addEventListener('change',debounce(preview,180));
});
q('rate-index').href=cfg.rates_url;
(async()=>{
  pager('unit',1,0,0);
  try{
    const facets=await request('facets',{},'facets');
    loadFacets(facets);await loadProperties();
  }catch(e){showError(e,q('property-status'))}
})();
})();
