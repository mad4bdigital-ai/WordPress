/* Native MAD4B Pricing Controls: length-of-stay financial policy, not a second price engine. */
(()=>{
'use strict';
const cfg=window.MAD4BAccommodationWeights;
const host=document.querySelector('.mad4b-aw-panel .mad4b-aw-body');
const overview=host?.querySelector('.mad4b-aw-overview');
if(!cfg?.endpoint||!cfg?.nonce||!host||!overview||host.querySelector('.mad4b-los'))return;
const node=(tag,cls,text)=>{
 const el=document.createElement(tag);
 if(cls)el.className=cls;
 if(text!==undefined)el.textContent=String(text);
 return el;
};
const label=(title,el)=>{
 const holder=node('label','mad4b-aw-field');
 holder.append(node('span','',title),el);return holder;
};
const control=(type,props={})=>{
 const el=node('input');el.type=type;
 Object.entries(props).forEach(([k,v])=>el.setAttribute(k,String(v)));
 return el;
};
const choices=values=>{
 const el=node('select');
 values.forEach(([value,title])=>el.add(new Option(title,value)));
 return el;
};
const state={revision:null,mode:'global',target:0,seq:0,loadSeq:0};
async function api(mode,payload={}){
 const form=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,mode});
 Object.entries(payload).forEach(([k,v])=>form.set(k,String(v??'')));
 const r=await fetch(cfg.endpoint,{method:'POST',body:form,
  credentials:'same-origin',headers:{Accept:'application/json'}});
 let j;try{j=await r.json()}catch{throw Error('invalid_server_response')}
 if(!r.ok||!j?.success)throw Error(j?.data?.code||'request_failed');
 return j.data;
}
function context(){
 const fields=overview.querySelectorAll('input[type="number"]');
 const nightsInput=host.querySelector('[data-stay="nights"]')||
   document.querySelector('.mad4b-stay-panel [data-stay="nights"]');
 return {property_id:fields[0]?.value||'',unit_id:fields[1]?.value||'',
  nights:nightsInput?.value||'1'};
}
const fieldRows=node('div','mad4b-aw-columns');
const rateId=control('number',{min:'1',step:'1',placeholder:'Optional original Rate ID'});
const nightlyCost=control('number',{min:'0.0001',step:'0.0001',placeholder:'Cost for one night'});
const currency=control('text',{maxlength:'3',value:'USD'});
const basis=choices([
 ['per_room_night','Per room / night'],
 ['per_cabin_night','Per cabin / night'],
 ['per_person_night','Per person / night (no automatic occupancy conversion)']
]);
const source=choices([
 ['manual_cost_reference','Manual cost reference (unapproved)'],
 ['unapproved_sheet_cost','Quotation Sheet (unapproved)'],
 ['explicit_selling_price','Existing sell price — bypass margin']
]);
fieldRows.append(label('Optional native Property Rate',rateId),
 label('Source cost per night',nightlyCost),
 label('Currency',currency),label('Cost basis',basis),
 label('Source amount type',source));
const details=node('details','mad4b-aw-los mad4b-aw-editor-details');
details.open=true;
details.append(node('summary','','Stay length · Profit Margin'));
const root=node('div','mad4b-aw-los-body');
details.append(root);
root.append(node('p','mad4b-aw-intro',
 'Select a Property in the original Accommodation panel. Nights come from its existing Nights field. The margin only applies to a source cost, never a quoted selling rate.'));
const actions=node('div','mad4b-aw-actions');
const loadEffective=node('button','button','Show effective stay margin');
const calculate=node('button','button button-secondary','Preview cost and profit');
for(const b of [loadEffective,calculate])b.type='button';
actions.append(loadEffective,calculate);
root.append(fieldRows,actions);
const status=node('p','mad4b-aw-status','Select a Property to inspect the effective nightly margin.');
status.setAttribute('role','status');
const effective=node('div','mad4b-aw-los-effective');
root.append(status,effective);
const inventorySection=node('div','mad4b-aw-los-saved');
const inventoryTitle=node('div','mad4b-aw-section-head');
inventoryTitle.append(node('h4','','Configured stay-length policies'));
const inventoryRefresh=node('button','button','Refresh');
inventoryRefresh.type='button';inventoryTitle.append(inventoryRefresh);
const inventoryStatus=node('p','mad4b-aw-status','Checking saved financial policies…');
const inventoryList=node('div','mad4b-aw-inventory-view');
inventorySection.append(inventoryTitle,inventoryStatus,inventoryList);root.append(inventorySection);
const editor=node('details','mad4b-aw-editor-details mad4b-aw-los-editor');
editor.append(node('summary','','Edit / inherit a stay margin policy'));
const editBody=node('div','mad4b-aw-form');editor.append(editBody);
editBody.append(node('p','mad4b-aw-hint',
 'Edit only this financial dimension. A policy at a narrower scope overrides a matching broader band. Gaps inherit from the next broader policy; overlapping ranges are rejected.'));
const scope=choices([
 ['global','Global'],
 ['property_type','Property type'],
 ['destination','Destination'],
 ['property','Property'],
 ['unit','Unit / room'],
 ['rate','Original Property Rate']
]);
const search=control('search',{placeholder:'Search existing native records',autocomplete:'off'});
const finder=node('button','button','Find record');finder.type='button';
const target=choices([['','Select a source record']]);
const pick=node('div','mad4b-aw-los-target');
pick.append(label('Policy scope',scope),label('Find target',search),finder,label('Original record',target));
editBody.append(pick);
const useProperty=node('button','button','Use selected Property');
const useUnit=node('button','button','Use selected Unit');
useProperty.type='button';useUnit.type='button';
const shortcuts=node('div','mad4b-aw-actions');
shortcuts.append(useProperty,useUnit);editBody.append(shortcuts);
const kind=choices([
 ['inherit','Inherit — no rule at this scope'],
 ['target_margin_percent','Target margin % of selling price'],
 ['markup_percent','Markup % on cost']
]);
editBody.append(label('Financial calculation',kind));
const bands=node('div','mad4b-aw-bands');
editBody.append(bands);
const commands=node('div','mad4b-aw-actions');
const add=node('button','button','Add nights band');
const reload=node('button','button','Reload policy');
const save=node('button','button button-primary','Save stay margin');
for(const b of [add,reload,save])b.type='button';
commands.append(add,reload,save);editBody.append(commands);
const editStatus=node('p','mad4b-aw-status','Choose a policy scope and load its current version.');
editStatus.setAttribute('role','status');editBody.append(editStatus);
root.append(editor);
overview.after(details);
function toPct(p){return Number(p).toLocaleString(undefined,{maximumFractionDigits:2})+'%'}
function activeTarget(){
 const id=scope.value==='global'?0:Number(target.value||0);
 if(scope.value!=='global'&&(!Number.isInteger(id)||id<1))return null;
 return {scope:scope.value,target_id:id};
}
function toggleScope(){
 const global=scope.value==='global';
 search.disabled=global;finder.disabled=global;target.disabled=global;
 if(global)target.replaceChildren(new Option('Global', '0'));
}
function makeBand(data={}){
 if(bands.querySelectorAll('[data-nights-band]').length>=24){
  editStatus.textContent='Maximum 24 bands per scope.';return;
 }
 const row=node('div','mad4b-aw-los-band');
 row.dataset.nightsBand='1';
 const from=control('number',{min:'1',max:'365',step:'1',placeholder:'1'});
 const to=control('number',{min:'1',max:'365',step:'1',placeholder:'365'});
 const pct=control('number',{min:'0',max:kind.value==='target_margin_percent'?'80':'100',
  step:'0.01',placeholder:'Enter %'});
 from.value=data.min_nights??'';
 to.value=data.max_nights??'';
 pct.value=data.percent??'';
 const remove=node('button','button-link-delete','Remove');
 remove.type='button';remove.addEventListener('click',()=>row.remove());
 row.append(label('From night',from),label('Through night',to),
  label('Margin / markup (%)',pct),remove);
 bands.append(row);
}
function drawBands(policy){
 kind.value=policy?.pricing_rule_kind||'inherit';
 bands.replaceChildren();
 for(const b of policy?.bands||[])makeBand(b);
 const inherit=kind.value==='inherit';
 bands.hidden=inherit;add.disabled=inherit;
}
function readForm(){
 const rows=[];
 if(kind.value!=='inherit')for(const row of bands.querySelectorAll('[data-nights-band]')){
  const inp=row.querySelectorAll('input');
  rows.push({min_nights:inp[0].value,max_nights:inp[1].value,percent:inp[2].value});
 }
 return {schema:1,mode:'preview_only',pricing_rule_kind:kind.value,bands:rows};
}
async function loadPolicy(){
 const selection=activeTarget();
 if(!selection){
  state.revision=null;
  editStatus.textContent='Choose a native record (or use selected Property).';return;
 }
 const seq=++state.loadSeq;
 editStatus.textContent='Loading scoped stay policy…';
 try{
  const data=await api('margin_load',selection);
  if(seq!==state.loadSeq)return;
  state.revision=data.revision;
  save.disabled=!data.can_write;
  save.title=data.can_write?'Save scoped financial policy':'Read-only policy';
  drawBands(data.policy);
  editStatus.textContent=data.configured
    ?'Configured: '+data.policy.bands.length+' bands · source '+selection.scope+
      ' · cost-only preview, not checkout.'
    :'No active bands at this scope. Broader rules remain inherited; selling prices stay unchanged.';
 }catch(e){if(seq===state.loadSeq){state.revision=null;editStatus.textContent='Load refused: '+e.message}}
}
async function savePolicy(){
 const selection=activeTarget();
 if(!selection||!state.revision){editStatus.textContent='Load the selected source before saving.';return}
 editStatus.textContent='Checking revision and saving stay bands…';
 try{
  const r=await api('margin_save',{...selection,revision:state.revision,
    policy:JSON.stringify(readForm())});
  state.revision=r.revision;
  editStatus.textContent=r.written
    ?'Saved and read back. Original Property Rates and checkout unchanged.'
    :'No change; this policy already has the same values.';
  loadEffectivePolicy();loadInventory();
 }catch(e){editStatus.textContent='Save rejected: '+e.message+
  (String(e.message).includes('stale')?' Reload before applying new edits.':'')}
}
function contextRequest(){
 const c=context();
 if(!Number.isInteger(Number(c.property_id))||Number(c.property_id)<1)
  throw Error('select_property_in_original_panel');
 const n=Number(c.nights);
 if(!Number.isInteger(n)||n<1||n>365)throw Error('invalid_original_nights_1_to_365');
 return {...c,rate_id:rateId.value||'0'};
}
async function loadEffectivePolicy(){
 const seq=++state.seq;
 effective.replaceChildren();
 try{
  const inputs=contextRequest();
  status.textContent='Resolving margin for '+inputs.nights+' nights…';
  const res=await api('margin_effective',inputs);
  if(seq!==state.seq)return;
  const active=res.effective;
  status.textContent=active
    ?'Matching stay margin · '+active.scope+' · '+active.label
    :'No configured margin for this stay length; do not assume zero markup.';
  if(active){
   const kindLabel=active.pricing_rule_kind==='target_margin_percent'
     ?'target gross margin':'cost markup';
   effective.append(node('strong','mad4b-aw-los-value',
     toPct(active.band.percent)+' '+kindLabel));
   effective.append(node('p','',
     'Nights '+active.band.min_nights+'–'+active.band.max_nights+
     ' · Source: '+active.scope+' · '+active.label));
  }
  if(res.source_chain?.length){
   const layers=node('div','mad4b-aw-layer-list');
   for(const entry of res.source_chain){
    const row=node('div','mad4b-aw-layer-row');
    row.append(node('span','',entry.label),
      node('small','',entry.match?'Applied at this scope':'No band for '+inputs.nights+' nights → inherit'));
    layers.append(row);
   }
   effective.append(layers);
  }
  effective.append(node('p','mad4b-aw-important',
    'Source-backed policy only. This is not a supplier contract, quoted Price, or automatic checkout approval.'));
 }catch(e){if(seq===state.seq)status.textContent='Policy resolution unavailable: '+e.message}
}
async function previewCost(){
 effective.replaceChildren();
 try{
  const c=contextRequest();
  status.textContent='Calculating from unapproved cost reference…';
  const res=await api('margin_preview',{
   ...c,source_kind:source.value,cost_basis:basis.value,
   unit_cost_per_night:nightlyCost.value,currency:currency.value.trim().toUpperCase()
  });
  if(res.status==='native_selling_price_takes_precedence'||
     res.status==='native_selling_rate_takes_precedence'){
   status.textContent='Original selling rate wins: no second margin applied.';return;
  }
  if(res.status==='margin_not_configured'){
   status.textContent='No matching night-margin policy. No selling proposal created.';return;
  }
  status.textContent='Unapproved internal cost-to-sell estimate; no Price or Rate was written.';
  const table=node('table','widefat striped mad4b-aw-weight-table');
  const tbody=node('tbody');
  const facts=[
   ['Nights',res.nights],
   ['Cost / night',res.unit_cost_per_night+' '+res.currency],
   ['Stay total cost',res.total_cost+' '+res.currency],
   ['Policy',res.pricing_rule_kind.replaceAll('_',' ')+' '+toPct(res.requested_percent)],
   ['Gross profit estimate',res.estimated_profit+' '+res.currency],
   ['Proposed selling amount',res.proposed_sell_price+' '+res.currency],
   ['Actual gross margin',toPct(res.actual_gross_margin_percent)]
  ];
  for(const [k,v] of facts){
   const tr=node('tr');tr.append(node('td','',k),node('td','',v));tbody.append(tr);
  }
  table.append(tbody);effective.append(table);
  effective.append(node('p','mad4b-aw-important',
   'Cost Receipt, season, meal supplements, currency, tax and availability require independent approval.'));
 }catch(e){status.textContent='Cost estimate refused: '+e.message}
}
function chooseRecord(entry){
 editor.open=true;
 scope.value=entry.scope;
 toggleScope();
 if(entry.scope!=='global'){
  target.replaceChildren(new Option(entry.label||('Record #'+entry.target_id),String(entry.target_id)));
  target.value=String(entry.target_id);
 }
 if(entry.scope==='property'){
  const field=overview.querySelector('input[type="number"]');if(field)field.value=String(entry.target_id);
 }
 if(entry.scope==='rate')rateId.value=String(entry.target_id);
 state.revision=null;loadPolicy();
 editor.scrollIntoView({block:'nearest'});
}
async function loadInventory(){
 inventoryStatus.textContent='Loading saved scoped stay policies…';
 try{
  const data=await api('margin_inventory');
  inventoryList.replaceChildren();
  inventoryStatus.textContent=data.configured_count+' configured · '+
    data.inherit_only_count+' saved Inherit only'+
    (data.truncated?' · result limit reached':'');
  if(!data.items?.length){
   inventoryList.append(node('p','mad4b-aw-empty',
    'No stay-margin rules configured. Rates and existing prices remain unchanged.'));
   return;
  }
  const inherited=node('details','mad4b-aw-inherit-records');
  inherited.append(node('summary','','Show saved Inherit-only records'));
  const hidden=node('div','mad4b-aw-inherit-list');inherited.append(hidden);
  for(const entry of data.items){
   const line=node('div','mad4b-aw-rule');
   const detail=node('div','mad4b-aw-rule-info');
   detail.append(node('strong','',entry.label+' · '+entry.scope.replaceAll('_',' ')));
   detail.append(node('small','',entry.configured
      ?entry.pricing_rule_kind.replaceAll('_',' ')+' · '+entry.bands.length+' band(s)'
      :'No active bands · inherited'));
   if(entry.configured)detail.append(node('span','mad4b-aw-rule-values',
     entry.bands.map(b=>b.min_nights+'–'+b.max_nights+' nights: '+toPct(b.percent)).join(' · ')));
   const edit=node('button','button','Edit');edit.type='button';
   edit.addEventListener('click',()=>chooseRecord(entry));
   line.append(detail,edit);(entry.configured?inventoryList:hidden).append(line);
  }
  if(data.inherit_only_count)inventoryList.append(inherited);
 }catch(e){inventoryStatus.textContent='Could not list policies: '+e.message}
}
async function searchTargets(){
 if(scope.value==='global')return;
 const value=search.value.trim();
 if(value.length<2){editStatus.textContent='Enter two or more characters.';return}
 target.replaceChildren(new Option('Searching…',''));
 try{
  const data=await api('margin_search',{scope:scope.value,search:value});
  target.replaceChildren(new Option('Choose original record',''));
  for(const item of data.items||[]){
   const opt=new Option(item.title+' #'+item.id,String(item.id));
   if(item.property_id)opt.dataset.propertyId=item.property_id;
   if(item.unit_id)opt.dataset.unitId=item.unit_id;
   target.add(opt);
  }
  editStatus.textContent=(data.items||[]).length+' matching native records.';
 }catch(e){editStatus.textContent='Search refused: '+e.message}
}
function shortcut(type){
 const inputs=overview.querySelectorAll('input[type="number"]');
 const id=type==='property'?inputs[0]?.value:inputs[1]?.value;
 if(!id||Number(id)<1){editStatus.textContent='Select a matching Property / Unit first.';return}
 scope.value=type;toggleScope();
 target.replaceChildren(new Option('Original '+type+' #'+id,String(id)));
 target.value=String(id);loadPolicy();
}
scope.addEventListener('change',()=>{
 toggleScope();target.replaceChildren(new Option('Choose original record',''));
 state.revision=null;bands.replaceChildren();kind.value='inherit';
 if(scope.value==='global')loadPolicy();
 else editStatus.textContent='Find an original record or use the current Property / Unit.';
});
target.addEventListener('change',()=>{
 if(!target.value)return;
 const selected=target.selectedOptions[0];
 const input=overview.querySelectorAll('input[type="number"]');
 if(scope.value==='property'&&input[0])input[0].value=target.value;
 if(scope.value==='unit'&&input[1])input[1].value=target.value;
 if(scope.value==='rate'){
  rateId.value=target.value;
  if(selected.dataset.propertyId&&input[0])input[0].value=selected.dataset.propertyId;
  if(selected.dataset.unitId&&input[1])input[1].value=selected.dataset.unitId;
 }
 loadPolicy();loadEffectivePolicy();
});
kind.addEventListener('change',()=>{
 const inherited=kind.value==='inherit';
 bands.hidden=inherited;add.disabled=inherited;
 if(inherited)bands.replaceChildren();
 for(const el of bands.querySelectorAll('[data-nights-band]')){
  el.querySelectorAll('input')[2].max=kind.value==='target_margin_percent'?'80':'100';
 }
});
finder.addEventListener('click',searchTargets);
search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchTargets()}});
useProperty.addEventListener('click',()=>shortcut('property'));
useUnit.addEventListener('click',()=>shortcut('unit'));
add.addEventListener('click',()=>makeBand({min_nights:'1',max_nights:'365',percent:''}));
reload.addEventListener('click',loadPolicy);
save.addEventListener('click',savePolicy);
loadEffective.addEventListener('click',loadEffectivePolicy);
calculate.addEventListener('click',previewCost);
inventoryRefresh.addEventListener('click',loadInventory);
rateId.addEventListener('change',loadEffectivePolicy);
host.addEventListener('click',event=>{
 if(event.target.closest('.mad4b-stay-panel__pick')||
    event.target.closest('[data-stay="use-property"]')){
  rateId.value='';
  Promise.resolve().then(loadEffectivePolicy);
 }
});
document.querySelector('.mad4b-stay-panel [data-stay="nights"]')
 ?.addEventListener('change',loadEffectivePolicy);
toggleScope();loadPolicy();loadInventory();
})();
