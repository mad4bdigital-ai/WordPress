/**
 * Fluent Forms #6 projection of existing native tour selections.
 * Read-only preview model. No form inputs, payment, pricing or submission.
 */
(() => {
 'use strict';
 const cfg=window.SharedPresentationFluentConfigV1;
 const broker=window.SharedPresentationSelectionV1;
 if(!cfg||!broker||broker.version!=='1.0.0'
  ||String(broker.getSnapshot().tourId)!==String(cfg.tourId)
  ||window.SharedPresentationFormProjectionV1)return;
 const bound=new WeakSet();
 const forms=new Set();
 const last=new WeakMap();
 const formSelector=cfg.formScopeSelector;
 const allowedForm=form=>!!(form&&cfg.scope==='tour_single_booking'&&
   document.body.classList.contains('single-tours-and-activities')&&
   typeof formSelector==='string'&&formSelector.length>0&&
   form.matches(formSelector));
 const id=v=>/^[1-9][0-9]*$/.test(String(v??''))?Number(v):null;
 const safeCount=value=>{
  const text=String(value??'').trim();
  if(!/^(0|[1-9][0-9]{0,2})$/.test(text))return null;
  const n=Number(text);return n<=100?n:null;
 };
 const month={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
 function isoDate(raw){
  const text=String(raw??'').trim();
  if(!text)return null;
  let year,mo,day;
  let m=/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(text);
  if(m){year=Number(m[1]);mo=Number(m[2]);day=Number(m[3]);}
  else {
   m=/^([0-9]{1,2})-([A-Za-z]{3})-([0-9]{2,4})$/.exec(text);
   if(!m||!month[m[2].toLowerCase()])return null;
   day=Number(m[1]);mo=month[m[2].toLowerCase()];
   year=Number(m[3]);if(m[3].length===2)year+=2000;
  }
  if(year<2000||year>2100||mo<1||mo>12||day<1||day>31)return null;
  const d=new Date(Date.UTC(year,mo-1,day));
  return d.getUTCFullYear()===year&&d.getUTCMonth()===mo-1&&d.getUTCDate()===day
   ?[year,String(mo).padStart(2,'0'),String(day).padStart(2,'0')].join('-'):null;
 }
 const input=(form,name)=>form.querySelector('[name="'+name+'"]')?.value??'';
 function project(form){
  if(!allowedForm(form))return null;
  const snapshot=broker.getSnapshot();
  const adults=safeCount(input(form,'no_of_adults'));
  const children=safeCount(input(form,'no_of_children'));
  const infants=safeCount(input(form,'no_of_infants'));
  const countsValid=adults!==null&&children!==null&&infants!==null&&adults>=1;
  const travelers=countsValid?adults+children+infants:null;
  const dateRaw=input(form,'datetime'); // Existing Fluent Form #6 Arrival Date
  const date=isoDate(dateRaw);
  const selectedRateId=id(snapshot.selectedRateId);
  const selectedRate=snapshot.rates.find(row=>row.rateId===selectedRateId)||null;
  const stayStages=[];
  for(const row of (selectedRate?[selectedRate]:[])){
   // Stage assignments remain per Rate. Never collapse a multi-stage
   // hotel choice to Booking Configuration's singular property_id.
   for(const [stageKey,value]of Object.entries(row.stages||{})){
    const propertyId=id(value);
    if(propertyId)stayStages.push({rateId:row.rateId,stageKey,propertyId});
   }
  }
  const extras=[...snapshot.extras];
  const components=(selectedRate?[selectedRate]:[]).flatMap(row=>
   Object.entries(row.components||{}).flatMap(([kind,groups])=>
    Object.entries(groups).map(([groupKey,optionIds])=>
     ({rateId:row.rateId,kind,groupKey,optionIds:[...optionIds]}))));
  const needs=[];
  if(!selectedRateId)needs.push('select_rate');
  if(!countsValid||!travelers||travelers>100)needs.push('valid_travelers');
  if(!date)needs.push('valid_arrival_date');
  // Browser data is not rate/date/occupancy eligibility evidence.
  // Match the read-only server selection adapter, not financial Booking v2.
  // Rooms/meals and explicit occupancy are not authored by this projection.
  const transportIds=[...new Set(components.filter(row=>row.kind==='transport').flatMap(row=>row.optionIds))];
  const candidate=!needs.length?{
   tour_id:Number(cfg.tourId),rate_id:selectedRateId,
   stay_stages:stayStages.map(row=>({stage_key:row.stageKey,property_id:row.propertyId})),
   extras:extras.map(value=>({tour_id:Number(value)})),
   transportations:transportIds.map(value=>({id:Number(value)})),booking_intent:'quote'
  }:null;
  if(candidate)needs.push('explicit_occupancy','server_selection_validation');
  return {
   schemaVersion:'1.0.0',tourId:Number(cfg.tourId),formId:6,
   readOnly:true,bookingAuthorized:false,serverValidated:false,
   status:candidate?'selection_candidate_requires_occupancy_and_server_validation':'incomplete',
   requirements:needs,
   travelers:{adults,children,infants,total:travelers},
   arrivalDate:{source:'fluent_forms_6.datetime',label:'Arrival Date',raw:dateRaw,iso:date},
   selectedRateId,stayStages,components,unverifiedExtraTourIds:extras,
   visual:{...snapshot.visual},
   occupancy:'requires_explicit_verified_selection',
   selectionCandidate:candidate,
   form6Input:{no_of_adults:adults,no_of_children:children,no_of_infants:infants,datetime:dateRaw},
   staySelectionScope:'property_only',transportationsStatus:'unverified_preferences',
   bookingConfigurationV2Candidate:null,
   sourceRevision:snapshot.revision
  };
 }
 function render(form){
  const data=project(form);if(!data)return;
  let output=form.querySelector('[data-presentation-form-projection-v1]');
  if(!output)return;
  const lines=[];
  if(data.selectedRateId)lines.push('Selected Rate #'+data.selectedRateId);
  else lines.push('Select a Tour Rate to preview your choices');
  const t=data.travelers;
  if(t.total!==null)lines.push('Travelers: '+t.adults+' adults, '+t.children+' children, '+t.infants+' infants');
  else lines.push('Enter valid traveler quantities');
  if(data.arrivalDate.iso)lines.push('Arrival Date: '+data.arrivalDate.iso);
  else lines.push('Choose a valid Arrival Date in the booking form');
  if(data.stayStages.length)lines.push(data.stayStages.length+' selected accommodation stage'+(data.stayStages.length!==1?'s':''));
  if(data.components.length)lines.push(data.components.length+' component group'+(data.components.length!==1?'s':''));
  if(data.unverifiedExtraTourIds.length)lines.push(data.unverifiedExtraTourIds.length+' optional activity selection'+(data.unverifiedExtraTourIds.length!==1?'s':''));
  lines.push('Preview only — price, occupancy, availability and booking require server validation');
  // Developer-only projection data remains available via the existing API
  // and events. The customer sees a single price, not source IDs or debug text.
  output.textContent='';
  const comparable=JSON.stringify(data);
  if(last.get(form)===comparable)return;
  last.set(form,comparable);
  form.dispatchEvent(new CustomEvent('shared-presentation-form-projected',{
   bubbles:true,detail:data
  }));
 }
 function attach(form){
  if(!allowedForm(form)||bound.has(form))return;
  bound.add(form);forms.add(form);
  const output=document.createElement('output');
  output.className='shared-presentation-form-projection';
  output.dataset.presentationFormProjectionV1='1';
  output.setAttribute('role','status');
  output.setAttribute('aria-live','polite');
  output.hidden=true;output.setAttribute('aria-hidden','true');
  output.style.display='none';
  const action=form.querySelector('.ff_submit_btn_wrapper,button[type=submit],input[type=submit]');
  if(action)action.parentElement.insertBefore(output,action);
  else form.append(output);
  form.addEventListener('change',()=>render(form));
  form.addEventListener('input',()=>render(form));
  render(form);
 }
 function initialize(){
  if(formSelector)document.querySelectorAll(formSelector).forEach(attach);
 }
 document.addEventListener('shared-presentation-selection-changed',()=>{
  for(const form of forms)if(form.isConnected)render(form);
 });
 document.addEventListener('shared-presentation-rehydration-requested',()=>{
  initialize();
  for(const form of forms)if(form.isConnected)render(form);
 });
 let queued=false;
 new MutationObserver(records=>{
  if(queued||!records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1)))return;
  queued=true;queueMicrotask(()=>{queued=false;initialize();});
 }).observe(document.body,{subtree:true,childList:true});
 initialize();
 window.SharedPresentationFormProjectionV1=Object.freeze({
  version:'1.0.0',getProjection:project,
  getAll:()=>[...forms].filter(f=>f.isConnected).map(project)
 });
})();
