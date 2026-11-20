/* Internal transport cost model only; protected AJAX and no checkout writes. */
(() => {
'use strict';
const cfg=window.MAD4BTransportDistanceTime;
if(!cfg?.template)return;
function init(){
const base=document.querySelector('#mad4b-transport-self-controls');
if(!base)return;
const mount=document.createElement('section');
mount.className='mad4b-kinetic-mount';
mount.innerHTML=cfg.template;
(base.querySelector('.mad4b-ts-stack')||base).append(mount);
const q=id=>mount.querySelector('[data-id="'+id+'"]');
const vehicles=['limousine','luxury_7_seats','h1','hiace','coaster','bus'];
const services=['transfer','half_day','full_day','sightseeing','overnight'];
const roads=['city','highway','desert'];
const profileFields=[
 ['fixed','Vehicle Call-out BASE EGP (included, not additive)'],['per_km','EGP / operating km'],
 ['per_drive_min','EGP / driving minute'],['per_wait_min','EGP / waiting minute'],
 ['overnight','EGP / overnight'],['min_fare','Minimum fare EGP'],
 ['deadhead_pct','Empty return distance %']
];
let policy=null,saved=null,calibration=null,revision=0,terms=[];
let nativeSources=[],nativeRates=[];
const display=s=>String(s).replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
const num=v=>Number(v).toLocaleString('en-US',{maximumFractionDigits:2});
const clone=o=>JSON.parse(JSON.stringify(o));
const select=(node,pairs,placeholder='Choose')=>{
 node.replaceChildren(new Option(placeholder,''));
 for(const [key,label] of pairs)node.add(new Option(label,String(key)));
};
const inp=(host,label,id,val)=>{
 const wrap=document.createElement('label');
 wrap.textContent=label;
 const el=document.createElement('input');
 el.type='number';el.step='.25';el.dataset.id=id;el.value=String(val??0);
 wrap.append(el);host.append(wrap);return el;
};
async function api(mode,extra={}){
 const data=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,mode});
 for(const [k,v] of Object.entries(extra))data.set(k,String(v));
 const res=await fetch(cfg.url,{method:'POST',credentials:'same-origin',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data.toString()});
 const body=await res.json();
 if(!res.ok||!body.success)throw Error(body.data?.code||'request_failed');
 return body.data;
}
function dirty(){
 const changed=JSON.stringify(policy)!==JSON.stringify(saved);
 q('dirty').textContent=changed?
  'Unsaved draft — route previews use the last saved profile':
  'Saved draft model · NOT an approved Transport Rate.';
 q('dirty').classList.toggle('is-unsaved',changed);
}
function persistFields(host,target,fields){
 host.replaceChildren();
 for(const [key,label] of fields){
  const el=inp(host,label,key,target[key]);
  el.addEventListener('input',()=>{
   target[key]=Number(el.value);dirty();
  });
 }
}
function showProfile(){
 if(!policy)return;
 const key=q('profile-vehicle').value;
 if(!key)return;
 const p=policy.profiles[key];
 persistFields(q('profile-fields'),p,profileFields);
 q('vehicle-mode').value=policy.vehicle_markups[key].mode;
 q('vehicle-pct').value=policy.vehicle_markups[key].pct;
 q('profile-meta').textContent='Profile basis: '+p.source+
  ' · calibration examples: '+p.samples+' · commercial approval: no';
}
function showService(){
 const key=q('service-key').value;
 if(!key||!policy)return;
 q('service-mode').value=policy.service_markups[key].mode;
 q('service-pct').value=policy.service_markups[key].pct;
}
function showRules(){
 const host=q('route-rules');host.replaceChildren();
 for(const [route,rule] of Object.entries(policy.route_markups)){
  const box=document.createElement('div');
  box.className='mad4b-kinetic-rule';
  const title=document.createElement('span');
  title.textContent=route+' · '+rule.mode+' '+rule.pct+'%';
  const remove=document.createElement('button');
  remove.type='button';remove.className='button';remove.textContent='Remove';
  remove.addEventListener('click',()=>{
   delete policy.route_markups[route];showRules();dirty();
  });
  box.append(title,remove);host.append(box);
 }
}
function showFactors(){
 const road=q('road-fields');road.replaceChildren();
 for(const kind of roads)
  for(const [key,label] of [
    ['circuity','Estimated road distance factor'],
    ['speed','Assumed speed km/h'],['factor','Operating road cost factor']
  ]){
   const obj=policy.road[kind];
   const el=inp(road,display(kind)+' · '+label,
     kind+'-'+key,obj[key]);
   el.addEventListener('input',()=>{obj[key]=Number(el.value);dirty();});
  }
 q('dispatch-enabled').checked=policy.dispatch_policy.enabled;
 const host=q('factor-fields');host.replaceChildren();
 for(const [key,label] of [
  ['global_markup_pct','Global markup %'],
  ['night_factor','Night cost factor'],
  ['peak_factor','Peak demand factor'],
  ['discount_pct','Post-markup discount %'],
  ['time_share','Calibration time-cost allocation (0.05–0.45)']
 ]){
  const el=inp(host,label,key,policy[key]);
  el.addEventListener('input',()=>{policy[key]=Number(el.value);dirty();});
 }
 showService();showRules();
}
function drawCalibration(){
 const host=q('calibration-table');host.replaceChildren();
 if(!calibration?.profiles)return;
 const table=document.createElement('table');table.className='widefat striped';
 const row=(cells,head=false)=>{
  const tr=document.createElement('tr');
  for(const value of cells){
   const cell=document.createElement(head?'th':'td');
   cell.textContent=String(value);tr.append(cell);
  }
  table.append(tr);
 };
 row(['Vehicle','Examples','Used','Review','Median deviation',
  'Status','EGP/km','EGP/min'],true);
 for(const vehicle of vehicles){
  const v=calibration.profiles[vehicle];
  row([display(vehicle),v.samples,v.accepted,v.excluded,
    v.median_absolute_deviation_pct+'%',v.status,
    v.proposal?num(v.proposal.per_km):'—',
    v.proposal?num(v.proposal.per_drive_min):'—']);
 }
 host.append(table);
 q('calibration-note').textContent='Assumed driving-time allocation: '+
   num(100*calibration.time_allocation_share)+
   '%. PDF prices are confirmed actual supplier costs; splitting them into per-km and per-minute coefficients is a calibration estimate, not direct cost accounting.';
}
function drawQuote(result){
 const host=q('quote-result');host.replaceChildren();
 const h=document.createElement('h4');
 h.textContent='Indicative scenario: '+num(result.indicative_selling_scenario_egp)+' EGP';
 host.append(h);
 const note=document.createElement('p');
 note.className='mad4b-kinetic-warning';
 note.textContent='NOT APPROVED FOR CHECKOUT · '+result.distance_provenance+
  ' · supplier availability and vehicle assignment unverified.';
 host.append(note);
 const table=document.createElement('table');table.className='widefat striped';
 const values=[
  ['Operating km',num(result.distance_km)+' km'],
  ['Driving time',num(result.drive_minutes)+' min'],
  ['Deadhead',num(result.deadhead_km)+' km'],
  ['Vehicle BASE enabled',result.dispatch_base.enabled?'Yes':'No'],
  ['BASE minimum (included)',num(result.dispatch_base.vehicle_base_egp)+' EGP'],
  ['BASE covered by movement',num(result.dispatch_base.covered_by_movement_egp)+' EGP'],
  ['BASE minimum floor difference',num(result.dispatch_base.floor_difference_egp)+' EGP'],
  ['Extra BASE surcharge',num(result.dispatch_base.billed_additional_surcharge_egp)+' EGP'],
  ['Distance',num(result.line_items.distance)+' EGP'],
  ['Driving',num(result.line_items.driving_time)+' EGP'],
  ['Waiting',num(result.line_items.waiting)+' EGP'],
  ['Overnight',num(result.line_items.overnight)+' EGP'],
  ['Road / night / peak factors',
    [result.road_cost_factor,result.night_factor,result.peak_factor].join(' / ')],
  ['Tolls + parking + other',num(
    result.line_items.tolls+result.line_items.parking+result.line_items.extras)+' EGP'],
  ['Modeled operating cost',num(result.estimated_operating_cost_egp)+' EGP'],
  ...(result.native_inheritance?[
   ['PDF actual source cost',num(result.native_inheritance.source_actual_cost_egp)+' EGP'],
   ['Native source',result.native_inheritance.source_pdf_key+' · #'+result.native_inheritance.transportation_id],
   ['Cost profile inheritance',Object.entries(result.native_inheritance.cost_field_sources).
     map(([k,v])=>k+': '+v).join(' / ')]
  ]:[]),
  ['Markup',result.markup.effective_pct+'% · '+result.markup.base_rule],
  ['Minimum fare',num(result.minimum_fare_egp)+' EGP'],
  ['Discount',result.discount_pct+'%'],
  ['Proposed sales scenario',num(result.indicative_selling_scenario_egp)+' EGP']
 ];
 for(const [label,value] of values){
  const tr=document.createElement('tr');
  for(const t of [label,value]){
   const cell=document.createElement('td');cell.textContent=String(t);tr.append(cell);
  }
  table.append(tr);
 }
 host.append(table);
}
function updateNativeSelection(){
 const rateId=Number(q('native-rate').value||0);
 const rate=nativeRates.find(x=>x.id===rateId);
 if(rate){
  q('native-source').value=String(rate.source_id);
  q('native-source').disabled=true;
  q('quote-vehicle').value=rate.vehicle;
  q('quote-vehicle').disabled=true;
  q('native-context-info').textContent=
    'Transport Rate '+rateId+' binds to Transportation #'+rate.source_id+
    ' and its native vehicle '+display(rate.vehicle)+
    '. Cost model overrides are read from JetEngine; customer extra-km/hour prices remain independent.';
 }else{
  q('native-source').disabled=false;
  q('quote-vehicle').disabled=false;
  const sourceId=Number(q('native-source').value||0);
  const source=nativeSources.find(x=>x.id===sourceId);
  q('native-context-info').textContent=source?
   'Source '+source.source_key+' · original PDF company cost EGP by selected vehicle.'+
   ' Choose a vehicle; overrides are inherited only where explicitly set.':
   'No native service selected: quote uses only the company vehicle profile.'+
   ' Customer extra km / hourly rates remain separate.';
 }
}
function hydrate(d){
 revision=Number(d.policy.revision);
 saved=clone(d.policy);policy=clone(d.policy);
 calibration=d.calibration;terms=d.terms||[];
 nativeSources=d.native_context?.sources||[];
 nativeRates=d.native_context?.native_rates||[];
 select(q('native-source'),nativeSources.map(x=>[x.id,x.source_key+' · '+x.title]),'Company model only');
 select(q('native-rate'),nativeRates.map(x=>[x.id,x.title+' · '+display(x.vehicle)]),'No native Transport Rate');
 q('native-tour-rate').value='';
 q('native-rate').disabled=nativeRates.length===0;
 select(q('profile-vehicle'),vehicles.map(k=>[k,display(k)]));
 select(q('quote-vehicle'),vehicles.map(k=>[k,display(k)]));
 select(q('quote-service'),services.map(k=>[k,display(k)]));
 select(q('service-key'),services.map(k=>[k,display(k)]));
 select(q('quote-road'),roads.map(k=>[k,display(k)]));
 const destinations=terms.map(t=>[t.id,t.name+
  (t.has_coordinates?'':' · location needs confirmation')]);
 select(q('from'),destinations,'Choose origin');
 select(q('to'),destinations,'Choose destination');
 q('profile-vehicle').value='limousine';
 q('quote-vehicle').value='limousine';
 q('quote-service').value='transfer';
 q('quote-road').value='highway';
 q('service-key').value='transfer';
 showProfile();showFactors();drawCalibration();updateNativeSelection();dirty();
}
function bind(){
 mount.querySelectorAll('[data-tab]').forEach(button=>{
  button.addEventListener('click',()=>{
   for(const other of mount.querySelectorAll('[data-tab]'))
    other.setAttribute('aria-current',other===button?'page':'false');
   for(const view of mount.querySelectorAll('[data-view]'))
    view.hidden=view.dataset.view!==button.dataset.tab;
  });
 });
 q('profile-vehicle').addEventListener('change',showProfile);
 q('native-source').addEventListener('change',()=>{
  if(q('native-rate').value)q('native-rate').value='';
  updateNativeSelection();
 });
 q('native-rate').addEventListener('change',updateNativeSelection);
 q('dispatch-enabled').addEventListener('change',()=>{
  if(!policy)return;
  policy.dispatch_policy.enabled=q('dispatch-enabled').checked;
  dirty();
 });
 q('service-key').addEventListener('change',showService);
 for(const [kind,id,property] of [
  ['vehicle','vehicle-mode','mode'],['vehicle','vehicle-pct','pct'],
  ['service','service-mode','mode'],['service','service-pct','pct']
 ])q(id).addEventListener('change',()=>{
  if(!policy)return;
  const selected=kind==='vehicle'?q('profile-vehicle').value:
    q('service-key').value;
  if(!selected)return;
  const obj=kind==='vehicle'?policy.vehicle_markups[selected]:
    policy.service_markups[selected];
  obj[property]=property==='pct'?Number(q(id).value):q(id).value;
  dirty();
 });
 q('add-rule').addEventListener('click',()=>{
  if(!policy)return;
  const key=q('route-rule-key').value.trim().toLowerCase();
  if(!/^[a-z0-9_-]+:[a-z0-9_-]+$/.test(key)||key.length>120){
   q('status').textContent='Route key must be origin-slug:destination-slug';return;
  }
  if(Object.keys(policy.route_markups).length>=60&&!policy.route_markups[key]){
   q('status').textContent='Maximum 60 route rules.';return;
  }
  policy.route_markups[key]={
   mode:q('route-rule-mode').value,pct:Number(q('route-rule-pct').value)
  };
  showRules();dirty();q('status').textContent='Route rule added to draft. Save to persist.';
 });
 q('apply-proposal').addEventListener('click',()=>{
  if(!policy)return;
  const vehicle=q('profile-vehicle').value;
  const proposal=calibration?.profiles?.[vehicle]?.proposal;
  if(!proposal){
   q('status').textContent='Insufficient clear source routes for '+display(vehicle);
   return;
  }
  policy.profiles[vehicle]=clone(proposal);showProfile();dirty();
  q('status').textContent='Loaded provisional '+display(vehicle)+
   ' cost coefficients. Not saved or approved for checkout.';
 });
 q('save').addEventListener('click',async()=>{
  if(!policy)return;
  q('save').disabled=true;q('status').textContent='Saving draft model…';
  try{
   const payload={};
   for(const key of [
    'profiles','dispatch_policy','global_markup_pct','vehicle_markups','service_markups',
    'route_markups','road','night_factor','peak_factor','discount_pct','time_share'])
    payload[key]=policy[key];
   const result=await api('save',{policy:JSON.stringify(payload),revision});
   // Load calibrated evidence again: time_share/road factors may have changed.
   await load();
   q('status').textContent='Saved simulation revision '+result.policy.revision+
    '. No Transport Rates or checkout settings changed.';
  }catch(error){
   q('status').textContent='Save failed: '+error.message;
  }finally{q('save').disabled=false;}
 });
 q('reload').addEventListener('click',load);
 q('quote').addEventListener('click',async()=>{
  q('quote').disabled=true;
  q('quote-result').textContent='Calculating draft operating scenario…';
  try{
   const from=q('from').value,to=q('to').value;
   const customFrom=q('custom-from').value.trim(),customTo=q('custom-to').value.trim();
   const coords=['from-lat','from-lon','to-lat','to-lon'].map(k=>q(k).value.trim());
   const count=coords.filter(Boolean).length;
   if(count!==0&&count!==4)throw Error('Provide all four coordinates together.');
   const hasTermRoute=Boolean(from&&to&&from!==to);
   const hasCoordinates=count===4;
   const hasCustomManual=Boolean(customFrom&&customTo&&q('km').value&&q('drive').value);
   if(!hasTermRoute&&!hasCoordinates&&!hasCustomManual&&!q('native-source').value&&!q('native-rate').value)
    throw Error('Choose a Transportation source, two destinations, coordinate pairs, or custom labels with km/minutes.');
   const sourceId=Number(q('native-source').value||0);
   const rateId=Number(q('native-rate').value||0);
   const tourRateId=Number(q('native-tour-rate').value||0);
   if(tourRateId&&!sourceId&&!rateId)
    throw Error('Choose a Transportation source before linking a Tour Rate.');
   const payload={
    transportation_id:sourceId,transport_rate_id:rateId,tour_rate_id:tourRateId,
    vehicle:q('quote-vehicle').value,service:q('quote-service').value,
    road:q('quote-road').value,from_term_id:Number(from),to_term_id:Number(to),
    from_label:customFrom,to_label:customTo,
    km:q('km').value,drive_min:q('drive').value,
    wait_min:q('wait').value,overnight_units:q('overnight-units').value,
    tolls:q('tolls').value,parking:q('parking').value,extras:q('extras').value,
    night:q('night').checked,peak:q('peak').checked
   };
   if(hasCoordinates){
    payload.from_lat_lon=[Number(coords[0]),Number(coords[1])];
    payload.to_lat_lon=[Number(coords[2]),Number(coords[3])];
   }
   const result=await api('quote',{payload:JSON.stringify(payload)});
   drawQuote(result);
  }catch(error){
   q('quote-result').textContent='Unable to calculate: '+error.message;
  }finally{q('quote').disabled=false;}
 });
}
async function load(){
 q('status').textContent='Loading existing company draft settings…';
 try{
  const result=await api('catalog');hydrate(result);
  q('status').textContent='EGP · Self Provider · Verified PDF cost source · simulated route factors only.';
 }catch(error){
  q('status').textContent='Could not load cost model: '+error.message;
 }
}
bind();load();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
else init();
})();
