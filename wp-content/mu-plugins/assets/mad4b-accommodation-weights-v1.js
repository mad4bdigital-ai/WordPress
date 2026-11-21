/* MAD4B Accommodation Weights: native Pricing Controls addendum, shadow only. */
(() => {
'use strict';
const config=window.MAD4BAccommodationWeights;
const host=document.querySelector('.mad4b-stay-panel__body');
if(!config?.nonce||!config?.endpoint||!host||host.querySelector('.mad4b-aw-panel'))return;
const wrap=document.createElement('section');wrap.className='mad4b-aw-panel';
const details=document.createElement('details');details.className='mad4b-aw-details';
const summary=document.createElement('summary');
summary.innerHTML='<strong>Accommodation Weight Policies</strong><small>Occupancy + room grade · Inherit / Override · shadow preview</small>';
details.append(summary);wrap.append(details);
const beforeRates=host.querySelector('.mad4b-stay-panel__rates');
if(beforeRates)beforeRates.before(wrap);else host.append(wrap);
details.open=true;
const body=document.createElement('div');body.className='mad4b-aw-body';details.append(body);
const make=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=String(text);return e};
const label=(name,control)=>{const l=make('label','mad4b-aw-field');l.append(make('span','',name),control);return l};
const input=(type,attrs={})=>{const e=make('input','');e.type=type;Object.entries(attrs).forEach(([k,v])=>e.setAttribute(k,String(v)));return e};
const selector=(options)=>{const e=make('select');options.forEach(([v,t])=>e.add(new Option(t,v)));return e};
const status=make('p','mad4b-aw-status','Loading policy…');status.setAttribute('role','status');
const intro=make('p','mad4b-aw-intro','Policy layers: Global → Property Type → Destination → Property → Unit. Exact supplier Property Rates always take precedence. Weight calculations are internal previews, not approved costs or booking prices.');
body.append(intro);
const propertyId=input('number',{min:'1',step:'1',placeholder:'Select existing Property'});
const unitId=input('number',{min:'1',step:'1',placeholder:'Optional linked Unit'});
const overview=make('section','mad4b-aw-overview');
overview.append(make('h4','','Effective weights for selected accommodation'));
const effectiveSelect=make('div','mad4b-aw-target');
const effectiveLoad=make('button','button','Show effective policy');effectiveLoad.type='button';
effectiveSelect.append(label('Property ID',propertyId),label('Unit ID (optional)',unitId),effectiveLoad);
overview.append(effectiveSelect);
const effectiveStatus=make('p','mad4b-aw-status','Select a Property above to display inherited weights and their source.');
effectiveStatus.setAttribute('role','status');
const effectiveView=make('div','mad4b-aw-effective-view');
overview.append(effectiveStatus,effectiveView);
body.append(overview);
const inventorySection=make('section','mad4b-aw-inventory');
const inventoryHead=make('div','mad4b-aw-section-head');
inventoryHead.append(make('h4','','Configured weight policies'));
const inventoryRefresh=make('button','button','Refresh policies');inventoryRefresh.type='button';
inventoryHead.append(inventoryRefresh);inventorySection.append(inventoryHead);
const inventoryStatus=make('p','mad4b-aw-status','Loading saved policies…');inventoryStatus.setAttribute('role','status');
const inventoryView=make('div','mad4b-aw-inventory-view');
inventorySection.append(inventoryStatus,inventoryView);body.append(inventorySection);
const editorDetails=make('details','mad4b-aw-editor-details');
const editorSummary=make('summary','','Edit / override scoped policy');
editorDetails.append(editorSummary);
const form=make('div','mad4b-aw-form');
const title=make('h4','','Policy editor — source-owned native fields');
form.append(title);
const scope=selector(config.scopes.map(x=>[x.id,x.label]));
const search=input('search',{placeholder:'Search original taxonomy / Property / Unit name',autocomplete:'off'});
const result=selector([['','Select an original record']]);
const find=make('button','button','Find');find.type='button';
const targetBox=make('div','mad4b-aw-target');
targetBox.append(label('Scope',scope),label('Find existing record',search),find,label('Matching records',result));
form.append(targetBox);
const ref=selector([['inherit','Inherit'],['double','Double reference'],['single','Single reference'],['triple','Triple reference']]);
form.append(label('Reference occupancy',ref));
const groups=make('div','mad4b-aw-columns');
const fields=[
 ['single_pct','Single adjustment (%)'],
 ['double_pct','Double adjustment (%)'],
 ['triple_pct','Triple adjustment (%)'],
 ['standard_pct','Standard room adjustment (%)'],
 ['deluxe_pct','Deluxe room adjustment (%)'],
 ['suite_pct','Suite adjustment (%)']];
const controls={};
fields.forEach(([id,text])=>{
 const e=input('number',{min:'-80',max:'300',step:'0.01',placeholder:'Inherit'});
 controls[id]=e;
 groups.append(label(text,e));
});
form.append(groups);
const help=make('p','mad4b-aw-hint','Leave any field empty to inherit. The selected reference occupancy must be 0%. Room percentages are only estimates when no exact supplier rate covers that room grade.');
form.append(help);
const actions=make('div','mad4b-aw-actions');
const reload=make('button','button','Reload policy'),save=make('button','button button-primary','Save policy');
reload.type='button';save.type='button';
actions.append(reload,save);form.append(actions,status);
editorDetails.append(form);body.append(editorDetails);
const preview=make('div','mad4b-aw-preview');
preview.append(make('h4','','Shadow calculation (never creates Rates)'));
const previewFields=make('div','mad4b-aw-columns');
const amount=input('number',{min:'0.0001',step:'0.0001',placeholder:'Reference amount'});
const currency=input('text',{maxlength:'3',value:'USD',placeholder:'USD'});
const unitBasis=selector([['per_person_night','Per person / night'],['per_room_night','Per room / night'],['per_cabin_night','Per cabin / night'],['per_person','Per person'],['per_stay','Per stay']]);
const occupancy=selector([['double','Double'],['single','Single'],['triple','Triple']]);
const roomGrade=selector([['standard','Standard (verify original Unit)'],['deluxe','Deluxe (verify original Unit)'],['suite','Suite (verify original Unit)']]);
const origin=selector([['manual_preview','Manual reference (review only)'],['sheet_unapproved','Sheet quotation (unapproved)']]);
[
 ['Reference price (must be confirmed separately)',amount],['Currency',currency],
 ['Source price unit',unitBasis],['Selected occupancy',occupancy],
 ['Unit grade for estimate',roomGrade],['Source origin',origin]
].forEach(([t,e])=>previewFields.append(label(t,e)));
preview.append(previewFields);
const calc=make('button','button','Preview weighted amount');
calc.type='button';
const output=make('div','mad4b-aw-output','Enter an original Property and a source amount to preview.');output.setAttribute('aria-live','polite');
preview.append(calc,output);
body.append(preview);
const state={scope:'global',target:0,revision:null};
const fieldsReset=()=>{ref.value='inherit';for(const e of Object.values(controls))e.value=''};
const selectState=()=>{
 state.scope=scope.value;
 state.target=state.scope==='global'?0:Number(result.value||0);
 return !!(state.scope==='global'||state.target>0);
};
function syncTarget(){
 const global=scope.value==='global';
 search.disabled=global;result.disabled=global;find.disabled=global;
 if(global){result.replaceChildren(new Option('Global settings', '0'));state.target=0;}
}
async function request(mode,payload={}){
 const params=new URLSearchParams({action:config.action,nonce:config.nonce,mode});
 for(const [key,value] of Object.entries(payload))params.set(key,String(value??''));
 const resp=await fetch(config.endpoint,{method:'POST',body:params,
     credentials:'same-origin',headers:{Accept:'application/json'}});
 let answer;
 try{answer=await resp.json()}catch{throw Error('invalid_json_response')}
 if(!resp.ok||!answer?.success)throw Error(answer?.data?.code||'request_failed');
 return answer.data;
}
let loadSeq=0;
async function load(){
 const seq=++loadSeq;
 status.textContent='Loading the selected source policy…';
 if(!selectState()){status.textContent='Choose one original record before loading.';return}
 try{
  const data=await request('load',{scope:state.scope,target_id:state.target});
  if(seq!==loadSeq)return;
  state.revision=data.revision;
  ref.value=data.policy?.reference||'inherit';
  Object.entries(controls).forEach(([key,node])=>{
    const value=data.policy?.weights?.[key];
    node.value=value===null||value===undefined?'':String(value);
  });
  save.disabled=!data.can_write;
  save.title=data.can_write?'Save this native scoped policy':'Read-only: this user cannot change this scope';
  status.textContent=data.configured
    ?(data.has_override?'Configured policy':'Stored Inherit-only policy')+
      ' · Scope '+state.scope+':'+state.target+' · revision checked · shadow only'+
      (data.can_write?'.':'. Read-only for this user.')
    :'No override saved here. All fields inherit from a more general policy; shadow only.';
 }catch(err){status.textContent='Policy read denied: '+err.message}
}
async function searchNative(){
 if(scope.value==='global')return;
 if(search.value.trim().length<2){status.textContent='Enter at least 2 characters to search.';return}
 result.replaceChildren(new Option('Searching…',''));
 try{
  const data=await request('search',{scope:scope.value,search:search.value.trim()});
  result.replaceChildren(new Option('Select original record',''));
  for(const item of data.items||[]){
    const option=new Option(item.title+'  #'+item.id,String(item.id));
    if(item.related_property_id)option.dataset.propertyId=String(item.related_property_id);
    result.add(option);
  }
  status.textContent=(data.items||[]).length+' matching original records. Choose one to load its policy.';
 }catch(err){status.textContent='Search failed: '+err.message}
}
function gather(){
 const policy={schema:1,mode:'shadow_only',reference:ref.value,weights:{}};
 for(const [name,node] of Object.entries(controls))
   policy.weights[name]=node.value.trim()===''?null:node.value.trim();
 return policy;
}
async function savePolicy(){
 if(!selectState()||!state.revision){status.textContent='Load an original record and its current revision before saving.';return}
 const policy=gather();
 const reference=policy.reference;
 if(reference!=='inherit'&&policy.weights[reference+'_pct']!==null&&Number(policy.weights[reference+'_pct'])!==0){
   status.textContent='Selected reference occupancy must remain 0%. No changes saved.';return;
 }
 status.textContent='Saving only scoped policy weights…';
 try{
  const data=await request('save',{scope:state.scope,target_id:state.target,
    revision:state.revision,policy:JSON.stringify(policy)});
  state.revision=data.revision;
  status.textContent=data.write_performed?
    'Saved. Original Property Rates and checkout unchanged; effective rules refreshed.':
    'Unchanged. Current policy already matches this form.';
  refreshInventory();refreshEffective();
 }catch(err){
  status.textContent='Save refused: '+err.message+
    (err.message.includes('stale')?' Reload the latest policy and reapply your changes.':'');
 }
}

const fieldLabels=Object.fromEntries(fields);
const scopeLabels=Object.fromEntries(config.scopes.map(x=>[x.id,x.label]));
const neutralPercent=value=>Number(value)===0? '0%':(Number(value)>0?'+':'')+String(Number(value))+'%';
let effectiveSeq=0;
function selectEditor(entry){
  scope.value=entry.scope;
  state.revision=null;fieldsReset();
  syncTarget();
  if(entry.scope!=='global'){
    result.replaceChildren(new Option(entry.label||'Original record #'+entry.target_id,String(entry.target_id)));
    result.value=String(entry.target_id);
  }
  if(entry.scope==='property'){propertyId.value=entry.target_id;unitId.value='';refreshEffective();}
  else if(entry.scope==='unit'){
    unitId.value=entry.target_id;
    if(entry.related_property_id)propertyId.value=String(entry.related_property_id);
    refreshEffective();
  }
  editorDetails.open=true;
  load();
  editorDetails.scrollIntoView({block:'nearest',behavior:'smooth'});
}
function renderInventory(data){
  inventoryView.replaceChildren();
  const counts=data.counts||{};
  const total=(data.items||[]).length;
  inventoryStatus.textContent=total+
    ' saved scope records: '+(counts.configured_adjustments||0)+
    ' with nonzero weights · '+(counts.configured_neutral||0)+
    ' configured with neutral weights · '+(counts.stored_inherit_only||0)+
    ' stored as Inherit only.'+(data.truncated?' Results are limited; further policies may exist.':'');
  if(!total){
    inventoryView.append(make('p','mad4b-aw-empty','No saved policies. Global neutral default applies until a policy is configured.'));
    return;
  }
  const inheritOnly=(data.items||[]).filter(item=>item.status==='stored_inherit_only');
  const inheritDetails=make('details','mad4b-aw-inherit-records');
  inheritDetails.append(make('summary','',
    'Show '+inheritOnly.length+' stored inherit-only record(s) — no effective adjustments'));
  const inheritList=make('div','mad4b-aw-inherit-list');
  inheritDetails.append(inheritList);
  for(const entry of data.items){
    const row=make('div','mad4b-aw-rule');
    const info=make('div','mad4b-aw-rule-info');
    info.append(make('strong','',entry.label||scopeLabels[entry.scope]+' #'+entry.target_id));
    const metadata=make('small','',entry.status.replaceAll('_',' ')+' · '+(entry.fields||[]).length+' stored fields');
    info.append(metadata);
    if(entry.fields?.length){
      const summary=make('span','mad4b-aw-rule-values',entry.fields.map(item=>
        item.field==='reference'?'Reference: '+item.value:
        (fieldLabels[item.field]||item.field)+': '+neutralPercent(item.value)
      ).join(' · '));
      info.append(summary);
    }
    row.append(info);
    const edit=make('button','button','View policy');edit.type='button';
    edit.addEventListener('click',()=>selectEditor(entry));
    row.append(edit);
    if(entry.status==='stored_inherit_only')inheritList.append(row);
    else inventoryView.append(row);
  }
  if(inheritOnly.length)inventoryView.append(inheritDetails);
}
async function refreshInventory(){
  inventoryStatus.textContent='Loading saved policies from the original JetEngine taxonomy and post records…';
  try{
    const data=await request('inventory');
    renderInventory(data);
  }catch(err){inventoryStatus.textContent='Could not read configured policies: '+err.message}
}
function renderEffective(data){
  effectiveView.replaceChildren();
  effectiveStatus.textContent='Effective on '+data.property_name+
    (data.unit_name?' / '+data.unit_name:' (Property level)')+
    ' · '+data.configured_layer_count+' scope(s) with explicit settings. Read-only policy evidence.';
  const ref=make('p','mad4b-aw-reference','Occupancy reference: '+data.reference+
    ' · Source: '+data.reference_source.replaceAll('_',' '));
  effectiveView.append(ref);
  const table=make('table','widefat striped mad4b-aw-weight-table');
  const head=make('thead');
  const hr=make('tr');
  for(const h of ['Weight dimension','Effective adjustment','Winning policy source'])
    hr.append(make('th','',h));
  head.append(hr);table.append(head);
  const tbody=make('tbody');
  for(const row of data.fields||[]){
    const tr=make('tr');
    tr.append(make('td','',fieldLabels[row.field]||row.field));
    const pct=make('strong','mad4b-aw-percent',neutralPercent(row.percent));
    const factor=make('small','mad4b-aw-factor',' ×'+(1+Number(row.percent)/100).toFixed(2));
    const td=make('td');td.append(pct,factor);tr.append(td);
    const source=(data.layers||[]).find(l=>l.scope+':'+l.target_id===row.source);
    tr.append(make('td','',source?.label|| (row.source==='neutral_default'?'System neutral default':row.source)));
    tbody.append(tr);
  }
  table.append(tbody);effectiveView.append(table);
  const layerTitle=make('p','mad4b-aw-layer-heading','Policy inheritance (configured vs inherited)');
  effectiveView.append(layerTitle);
  const layerList=make('div','mad4b-aw-layer-list');
  for(const layer of data.layers||[]){
    const item=make('div','mad4b-aw-layer-row');
    const labelNode=make('span','',layer.label);
    const stateNode=make('small','',
      layer.has_override?(layer.status==='configured_neutral'?'Configured — neutral':'Configured — overrides'):
      (layer.configured?'Stored — inherits all':'Not configured — inherit'));
    item.append(labelNode,stateNode);
    if(layer.has_override){
      const edit=make('button','button-link','Edit');edit.type='button';
      edit.addEventListener('click',()=>selectEditor(layer));
      item.append(edit);
    }
    layerList.append(item);
  }
  effectiveView.append(layerList);
  effectiveView.append(make('p','mad4b-aw-important',
    'These are effective policy weights, not supplier-approved rates. Published Property Rates and booking prices are unchanged.'));
}
async function refreshEffective(){
  const property=Number(propertyId.value);
  const unit=Number(unitId.value||0);
  const seq=++effectiveSeq;
  effectiveView.replaceChildren();
  if(!Number.isInteger(property)||property<1){
    effectiveStatus.textContent='Select a Property from the existing native list above or enter its ID.';
    return;
  }
  effectiveStatus.textContent='Resolving original policy layers and winning weights…';
  try{
    const data=await request('effective',{property_id:property,unit_id:unit});
    if(seq!==effectiveSeq)return;
    renderEffective(data);
  }catch(err){
    if(seq!==effectiveSeq)return;
    effectiveStatus.textContent='Effective policy unavailable: '+err.message+
      (String(err.message).includes('ambiguous')?' Multiple taxonomy policies conflict; choose one precedence source.':'');
  }
}
function nativeSelectedId(button){
  const row=button.closest('.mad4b-stay-panel__result-row');
  const link=row?.querySelector('a.mad4b-stay-panel__row-edit');
  if(!link)return null;
  try{
    const url=new URL(link.href,location.origin);
    if(url.origin!==location.origin)return null;
    const id=Number(url.searchParams.get('post'));
    return Number.isInteger(id)&&id>0?id:null;
  }catch{return null}
}
// Bind to original native Property/Unit cards without modifying or duplicating
// their source, selection state, Ajax, or JetEngine forms.
host.addEventListener('click',event=>{
  const button=event.target.closest('.mad4b-stay-panel__pick');
  if(!button)return;
  const id=nativeSelectedId(button);
  if(!id)return;
  if(button.closest('[data-stay="property-results"]')){
    propertyId.value=String(id);unitId.value='';
    refreshEffective();
  }else if(button.closest('[data-stay="unit-results"]')){
    unitId.value=String(id);refreshEffective();
  }
});
const useProperty=host.querySelector('[data-stay="use-property"]');
useProperty?.addEventListener('click',()=>{unitId.value='';refreshEffective()});
effectiveLoad.addEventListener('click',refreshEffective);
inventoryRefresh.addEventListener('click',refreshInventory);

async function calculate(){
 output.textContent='Validating policy, original Property and Unit link…';
 try{
  const data=await request('preview',{
   property_id:propertyId.value,unit_id:unitId.value,base_amount:amount.value,
   currency:currency.value.trim().toUpperCase(),price_unit:unitBasis.value,
   occupancy:occupancy.value,room_grade:roomGrade.value,origin:origin.value
  });
  output.replaceChildren();
  output.append(make('strong','',data.estimated_unit_amount+' '+data.currency+
    ' / '+data.reference_basis.replaceAll('_',' ')));
  output.append(make('p','','Occupancy '+data.occupancy+
    ' '+(data.occupancy_weight_percent>=0?'+':'')+data.occupancy_weight_percent+
    '% · '+data.room_grade+' '+(data.room_weight_percent>=0?'+':'')+
    data.room_weight_percent+'%'));
  const scopes=(data.effective_policy?.applied_scopes||[]);
  output.append(make('p','','Applied scopes: '+(scopes.length?scopes.join(' → '):'neutral inherited')));
  if(data.unit_id){
    const evidence=data.room_grade_evidence||{};
    output.append(make('p','',
      'Original Unit grade: '+(evidence.source_category||'unclassified')+
      ' · '+(evidence.verified?'source category matched':'category unverified')+
      ' · capacity '+(data.unit_capacity||'not verified')));
  }else{
    output.append(make('p','','No Unit selected: room-grade weight is a scenario, not a room assignment.'));
  }
  const meal=data.meal_base_policy||{};
  if(meal.base_board)
    output.append(make('p','',
      'Inherited meal base: '+meal.base_board.replaceAll('-',' ')+
      ' · '+(meal.source||'policy')+' · not a supplier-contract confirmation.'));
  output.append(make('p','mad4b-aw-important',
    'ESTIMATE ONLY. Supplier cost, occupancy, period, included board contract, inventory, published Rates and checkout have not been approved.'));
 }catch(err){output.textContent='Cannot calculate: '+err.message}
}
scope.addEventListener('change',()=>{
 state.revision=null;fieldsReset();result.replaceChildren(new Option('Select an original record',''));
 syncTarget();status.textContent='Choose a matching native record and load its policy.';
 if(scope.value==='global')load();
});
result.addEventListener('change',()=>{
 state.revision=null;fieldsReset();
 if(scope.value==='property'){propertyId.value=result.value;unitId.value='';refreshEffective();}
 if(scope.value==='unit'){
   unitId.value=result.value;
   const parent=result.selectedOptions[0]?.dataset?.propertyId;
   if(parent)propertyId.value=parent;
   refreshEffective();
 }
 if(result.value)load();
});
find.addEventListener('click',searchNative);
search.addEventListener('keydown',(event)=>{if(event.key==='Enter'){event.preventDefault();searchNative()}});
reload.addEventListener('click',load);
save.addEventListener('click',savePolicy);
calc.addEventListener('click',calculate);
syncTarget();load();refreshInventory();
// The native panel is the primary Property picker; no parallel catalog.
})();
