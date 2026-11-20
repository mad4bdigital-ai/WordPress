/* Native read-only package supplier eligibility. NEVER writes/creates a Rate. */
(()=>{
'use strict';
const cfg=window.MAD4BPackageSupplierAdmissionReview;
const nativeRoot=document.querySelector('.jet-engine-options-page-wrap');
if(!cfg||!nativeRoot||!nativeRoot.parentNode)return;
const el=(tag,text,cl)=>{
 const n=document.createElement(tag);
 if(text!==undefined)n.textContent=String(text);
 if(cl)n.className=cl;
 return n;
};
const panel=el('section',undefined,'mad4b-supplier-readiness');
const heading=el('details');
const summary=el('summary','Package supplier cost readiness — review only');
const body=el('div',undefined,'mad4b-supplier-readiness__body');
const hint=el('p','Check original supplier-cost evidence before generating Rates. This panel does not save settings, calculate customer prices, or enable Checkout.');
body.append(hint);
const controls=el('div',undefined,'mad4b-supplier-readiness__controls');
const makeLabeled=(text,control)=>{
 const label=el('label',text);
 label.append(control);controls.append(label);
 return control;
};
const tour=el('select');
tour.setAttribute('aria-label','Package tour');
const first=el('option','Select a published tour');first.value='';
tour.append(first);
const priority=new Set([60629,60627,60623,60443,60441,60439,60437,57562]);
const options=[...(cfg.tours||[])].sort((a,b)=>
 Number(priority.has(b.id))-Number(priority.has(a.id))||
 a.title.localeCompare(b.title));
for(const item of options) {
 const option=el('option',item.title+' (#'+item.id+')');
 option.value=String(item.id);
 tour.append(option);
}
makeLabeled('Tour',tour);
const date=el('input');date.type='date';date.value=cfg.today||'';
makeLabeled('Service date',date);
const currency=el('select');
for(const code of ['EGP','USD','EUR','GBP']){
 const opt=el('option',code);
 opt.value=code;currency.append(opt);
}
makeLabeled('Quote currency',currency);
const numberField=(label,min,max,value)=>{
 const input=el('input');
 input.type='number';input.min=String(min);input.max=String(max);input.step='1';
 input.value=String(value);
 makeLabeled(label,input);
 return input;
};
const adults=numberField('Adults',1,30,2);
const children=numberField('Children',0,30,0);
const infants=numberField('Infants',0,20,0);
const actions=el('div',undefined,'mad4b-supplier-readiness__actions');
const inspect=el('button','Inspect supplier evidence','button button-secondary');
inspect.type='button';
actions.append(inspect);
const status=el('p','Select a tour and service date to inspect cost sources.','mad4b-supplier-readiness__status');
status.setAttribute('role','status');status.setAttribute('aria-live','polite');
const output=el('div',undefined,'mad4b-supplier-readiness__result');
body.append(controls,actions,status,output);
heading.append(summary,body);
panel.append(heading);
nativeRoot.parentNode.insertBefore(panel,nativeRoot);
const names={
 stay_program_binding_missing:'The itinerary has no approved accommodation stage binding.',
 stay_unit_not_selected:'A hotel room or cruise cabin has not been selected as bookable.',
 stay_supplier_cost_receipt_missing:'Accommodation supplier contract and date-specific cost are not certified.',
 transport_supplier_cost_role_missing:'Transport supplier-cost role has not been approved.',
 transport_original_source_missing:'The transport source is unavailable.',
 transport_route_cost_unapproved:'Transport route, vehicle and minimum base cost need an approved supplier receipt.',
 extra_supplier_rate_not_certified:'Optional extras supplier costs and included quantities are not certified.',
 guide_cost_source_not_certified:'Guide daily coverage and cost source require review.',
 child_infant_component_costs_not_certified:'Children and infant occupancy/cost policies are not certified.',
 certified_fx_receipt_missing:'No approved exchange-rate receipt exists for the requested currency.',
 company_margin_tax_source_unapproved:'Commercial markup/margin and applicable tax rules are not approved.',
 stay_link_checkout_state_unexpected:'Stay source returned an inconsistent checkout state.'
};
const messageFor=code=>{
 if(code.startsWith('guide:'))return 'Guide qualification: '+code.slice(6).replaceAll('_',' ');
 return names[code]||code.replaceAll('_',' ');
};
const line=(k,v)=>{
 const row=el('div',undefined,'mad4b-supplier-readiness__line');
 row.append(el('strong',k),el('span',v));
 return row;
};
const render=(result,editUrl)=>{
 output.replaceChildren();
 const approved=result.rate_generation_ready===true &&
                result.checkout_ready===true;
 const head=el('h4',approved?'Ready for commercial review':'Not commercially qualified');
 output.append(head);
 if(editUrl && typeof editUrl==='string' &&
    /^https:\/\//.test(editUrl) &&
    new URL(editUrl).host===window.location.host){
  const link=el('a','Edit the original Tour and its stages');
  link.href=editUrl;link.className='button button-link';
  output.append(link);
 }
 const meta=el('div',undefined,'mad4b-supplier-readiness__lines');
 meta.append(
  line('Tour',String(result.tour_id)),
  line('Date',result.date),
  line('Currency',result.currency),
  line('Passengers',result.occupancy.adults+' adult(s), '+
    result.occupancy.children+' child(ren), '+result.occupancy.infants+' infant(s)')
 );
 output.append(meta);
 const components=el('div',undefined,'mad4b-supplier-readiness__components');
 for(const [kind,label] of [
   ['accommodation','Accommodation'],['transportation','Transportation'],
   ['guide','Guide'],['extras','Extras']]){
  const item=result.components?.[kind]||{};
  const card=el('section',undefined,'mad4b-supplier-readiness__component');
  card.append(el('h5',label));
  card.append(el('p',item.cost_qualified?'Cost source qualified':
    'Supplier cost not yet certified'));
  if(kind==='accommodation'){
   card.append(el('small',(item.binding_count||0)+' original itinerary stage(s)'));
  }
  if(kind==='transportation'){
   card.append(el('small','Source role: '+(item.source_role||'unassigned')+
     ', currency '+(item.currency||'not approved')));
  }
  if(kind==='guide')card.append(el('small','Status: '+(item.status||'missing')));
  if(kind==='extras')card.append(el('small','Status: '+(item.runtime_status||'missing')));
  components.append(card);
 }
 output.append(components);
 const blockers=el('section',undefined,'mad4b-supplier-readiness__blockers');
 blockers.append(el('h5','Required source evidence ('+result.blockers.length+')'));
 const list=el('ul');
 for(const code of result.blockers){
  const li=el('li',messageFor(String(code)));
  list.append(li);
 }
 blockers.append(list);output.append(blockers);
 const stages=result.components?.accommodation?.stages||[];
 if(stages.length){
  const heading=el('h5','Stay / cruise stages');
  output.append(heading);
  const scroller=el('div',undefined,'mad4b-supplier-readiness__table-scroll');
  const table=el('table',undefined,'widefat striped');
  const thead=el('thead'),tr=el('tr');
  for(const t of ['Stage','Nights','Price status','Bookable unit','Supplier cost'])
   tr.append(el('th',t));
  thead.append(tr);table.append(thead);
  const tbody=el('tbody');
  for(const stage of stages){
    const row=el('tr');
    const values=[stage.stage_key,stage.nights,stage.price_status,
      stage.bookable_unit_selection_present?'Assigned':'Missing',
      stage.supplier_cost_receipt_present?'Unverified provider':'Missing'];
    for(const v of values)row.append(el('td',v));
    tbody.append(row);
  }
  table.append(tbody);scroller.append(table);output.append(scroller);
 }
 output.append(el('p','Read-only source diagnostics. No Rate or Checkout change.',
   'mad4b-supplier-readiness__note'));
};
inspect.addEventListener('click',async()=>{
 if(!tour.value || !date.value){
  status.textContent='Select a published tour and a date.';
  return;
 }
 inspect.disabled=true;
 status.textContent='Checking native component sources…';
 output.replaceChildren();
 try{
  const data=new URLSearchParams({
   action:cfg.action,nonce:cfg.nonce,tour_id:tour.value,date:date.value,
   currency:currency.value,adults:adults.value,
   children:children.value,infants:infants.value
  });
  const response=await fetch(cfg.url,{
   method:'POST',credentials:'same-origin',cache:'no-store',
   headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
   body:data
  });
  const json=await response.json();
  if(!response.ok || json.success!==true ||
     !json.data?.result || json.data.saved!==false)
   throw Error(json.data?.code||'Source inspection unavailable');
  status.textContent='Inspection complete — original source only.';
  render(json.data.result,json.data.edit_tour_url);
 }catch(e){
  status.textContent='Inspection failed: '+String(e.message)+
    '. No settings were changed.';
 }finally{inspect.disabled=false;}
});
})();
