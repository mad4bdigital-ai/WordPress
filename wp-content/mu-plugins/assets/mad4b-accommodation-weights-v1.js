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
details.append(summary);wrap.append(details);host.append(wrap);
const body=document.createElement('div');body.className='mad4b-aw-body';details.append(body);
const make=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=String(text);return e};
const label=(name,control)=>{const l=make('label','mad4b-aw-field');l.append(make('span','',name),control);return l};
const input=(type,attrs={})=>{const e=make('input','');e.type=type;Object.entries(attrs).forEach(([k,v])=>e.setAttribute(k,String(v)));return e};
const selector=(options)=>{const e=make('select');options.forEach(([v,t])=>e.add(new Option(t,v)));return e};
const status=make('p','mad4b-aw-status','Loading policy…');status.setAttribute('role','status');
const intro=make('p','mad4b-aw-intro','Weights never overwrite a supplier quotation or existing Property Rate. Explicit Rate prices take priority; a weight-based result is a review estimate only. Source: Global → Property Type → Destination → Property → Unit.');
body.append(intro);
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
body.append(form);
const preview=make('div','mad4b-aw-preview');
preview.append(make('h4','','Shadow calculation (never creates Rates)'));
const previewFields=make('div','mad4b-aw-columns');
const propertyId=input('number',{min:'1',step:'1',placeholder:'Original Property ID'});
const unitId=input('number',{min:'1',step:'1',placeholder:'Optional original Unit ID'});
const amount=input('number',{min:'0.0001',step:'0.0001',placeholder:'Reference amount'});
const currency=input('text',{maxlength:'3',value:'USD',placeholder:'USD'});
const unitBasis=selector([['per_person_night','Per person / night'],['per_room_night','Per room / night'],['per_cabin_night','Per cabin / night'],['per_person','Per person'],['per_stay','Per stay']]);
const occupancy=selector([['double','Double'],['single','Single'],['triple','Triple']]);
const roomGrade=selector([['standard','Standard (verify original Unit)'],['deluxe','Deluxe (verify original Unit)'],['suite','Suite (verify original Unit)']]);
const origin=selector([['manual_preview','Manual reference (review only)'],['sheet_unapproved','Sheet quotation (unapproved)']]);
[
 ['Original Property ID',propertyId],['Original Unit ID (optional)',unitId],
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
async function load(){
 status.textContent='Loading the selected source policy…';
 if(!selectState()){status.textContent='Choose one original record before loading.';return}
 try{
  const data=await request('load',{scope:state.scope,target_id:state.target});
  state.revision=data.revision;
  ref.value=data.policy?.reference||'inherit';
  Object.entries(controls).forEach(([key,node])=>{
    const value=data.policy?.weights?.[key];
    node.value=value===null||value===undefined?'':String(value);
  });
  status.textContent=data.configured
    ?'Policy loaded. Source '+state.scope+':'+state.target+' · revision checked · shadow only.'
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
  for(const item of data.items||[])result.add(new Option(item.title+'  #'+item.id,String(item.id)));
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
    'Saved. Original Property Rates and checkout unchanged; reload to verify.':
    'Unchanged. Current policy already matches this form.';
 }catch(err){
  status.textContent='Save refused: '+err.message+
    (err.message.includes('stale')?' Reload the latest policy and reapply your changes.':'');
 }
}
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
  output.append(make('p','mad4b-aw-important',
    'ESTIMATE ONLY. Supplier cost, room capacity, period, meal board, inventory, published Rates and checkout have not been approved.'));
 }catch(err){output.textContent='Cannot calculate: '+err.message}
}
scope.addEventListener('change',()=>{
 state.revision=null;fieldsReset();result.replaceChildren(new Option('Select an original record',''));
 syncTarget();status.textContent='Choose a matching native record and load its policy.';
 if(scope.value==='global')load();
});
result.addEventListener('change',()=>{
 state.revision=null;fieldsReset();
 if(scope.value==='property')propertyId.value=result.value;
 if(scope.value==='unit')unitId.value=result.value;
 if(result.value)load();
});
find.addEventListener('click',searchNative);
search.addEventListener('keydown',(event)=>{if(event.key==='Enter'){event.preventDefault();searchNative()}});
reload.addEventListener('click',load);
save.addEventListener('click',savePolicy);
calc.addEventListener('click',calculate);
syncTarget();load();
})();
