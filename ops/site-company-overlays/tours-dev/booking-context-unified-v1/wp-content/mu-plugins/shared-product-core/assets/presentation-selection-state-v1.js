/** Shared Presentation Selection v1. Untrusted in-memory view state, never booking authority. */
(() => {
 'use strict';
 const cfg=window.SharedPresentationSelectionConfigV1;
 const numeric=v=>/^[1-9][0-9]*$/.test(String(v??''));
 if(!cfg||!numeric(cfg.tourId)||window.SharedPresentationSelectionV1)return;
 const tourId=Number(cfg.tourId),rates=new Map(),extras=new Set(),visual={};
 // Tour-level intent survives Rate changes. It never certifies price, stock,
 // compatibility, supplier confirmation or a payable booking.
 const transportIntent=new Map();
 const transportAllow=new Map(Object.entries(cfg.allowedTransportGroups||{})
  .filter(([key,ids])=>/^transport-(vehicles|train|air)$/.test(key)&&Array.isArray(ids))
  .map(([key,ids])=>[key,new Set(ids.filter(numeric).map(String))]));
 const allowedRates=new Set((Array.isArray(cfg.allowedRateIds)?cfg.allowedRateIds:[]).filter(numeric).map(String));
 const allowedExtras=new Set((Array.isArray(cfg.allowedExtraTourIds)?cfg.allowedExtraTourIds:[]).filter(numeric).map(String));
 const fields=new Set(['property_id','transport_id','extra_tour_id','package_term_id','guide_language_term_id']);
 let selectedRateId=null,revision=0;
 const validKey=value=>typeof value==='string'&&value.length>0&&value.length<=128&&/^[\w:.-]+$/.test(value);
 const id=value=>numeric(value)?String(value):null;
 const owns=d=>d&&numeric(d.tourId)&&Number(d.tourId)===tourId;
 const knownRate=value=>{const valueId=id(value);return valueId&&allowedRates.has(valueId)?valueId:null;};
 const sameTourWhenPresent=d=>d.tourId===undefined||owns(d);
 function ensure(rateId){if(!rates.has(rateId))rates.set(rateId,{stages:new Map(),components:new Map()});return rates.get(rateId);}
 function serialize(){
  const out=[];
  for(const [rateId,row] of rates){
   const stages={};for(const [k,v] of row.stages)stages[k]=v;
   const components={};for(const [type,groups]of row.components){components[type]={};for(const [group,set]of groups)if(set.size)components[type][group]=[...set].sort();}
   out.push({rateId:Number(rateId),stages,components});
  }
  // Legacy selected-Rate projection remains a view of the same intent.
  // Direct-per-person Tours do not invent a Rate ID.
  if(selectedRateId!==null){
   let selected=out.find(row=>row.rateId===selectedRateId);
   if(!selected){selected={rateId:selectedRateId,stages:{},components:{}};out.push(selected);}
   const groups={};for(const [key,set]of transportIntent)if(set.size)groups[key]=[...set].sort();
   if(Object.keys(groups).length)selected.components.transport=groups;
  }
  out.sort((a,b)=>a.rateId-b.rateId);
  const transport={};
  for(const [group,set]of transportIntent)if(set.size)transport[group]=[...set].sort();
  return {schemaVersion:'1.0.0',tourId,revision,readOnly:true,selectedRateId,
    extras:[...extras].sort(),transportIntent:transport,visual:{...visual},rates:out};
 }
 function notify(kind,source){revision++;document.dispatchEvent(new CustomEvent('shared-presentation-selection-changed',{detail:{schemaVersion:'1.0.0',tourId,revision,kind,source,snapshot:serialize(),readOnly:true,bookingAuthorized:false}}));}
 function changeRate(next){
  if(next===selectedRateId)return false;
  selectedRateId=next;
  // Rate-dependent accommodation/visual values are re-evaluated; retain
  // transport intent until the traveller explicitly removes it.
  for(const key of ['property_id','package_term_id'])delete visual[key];
  return true;
 }
 function setExtra(option,selected,source){
  if(!allowedExtras.has(option)||extras.has(option)===selected)return;
  selected?extras.add(option):extras.delete(option);notify('extra',source);
 }
 document.addEventListener('booking-option-selection-change',event=>{
  const d=event.detail||{},rate=knownRate(d.contextId),option=id(d.optionId);
  if(!sameTourWhenPresent(d)||!rate||Number(rate)!==selectedRateId||!validKey(d.stageKey)||!option||typeof d.selected!=='boolean')return;
  const row=ensure(rate),previous=row.stages.get(d.stageKey);
  if(d.selected&&previous===option||!d.selected&&previous!==option)return;
  d.selected?row.stages.set(d.stageKey,option):row.stages.delete(d.stageKey);
  notify('accommodation.stage','booking-option-selection-change');
 });
 document.addEventListener('booking-component-selection-change',event=>{
  const d=event.detail||{},option=id(d.optionId);
  if(!owns(d)||!option||typeof d.selected!=='boolean')return;
  if(d.componentType==='extra'){setExtra(option,d.selected,'booking-component-selection-change');return;}
  if(d.componentType!=='transport'||!validKey(d.groupKey))return;
  const allowed=transportAllow.get(d.groupKey);
  if(!allowed||!allowed.has(option))return;
  const set=transportIntent.get(d.groupKey)||new Set(),next=new Set(set);
  if(Array.isArray(d.selections)){
   if(d.selections.length>200)return;
   next.clear();
   for(const x of d.selections){
    if(!x||!owns(x)||x.componentType!=='transport'||x.groupKey!==d.groupKey)continue;
    const value=id(x.optionId);if(value&&allowed.has(value))next.add(value);
   }
  }else d.selected?next.add(option):next.delete(option);
  if(next.size===set.size&&[...next].every(value=>set.has(value)))return;
  next.size?transportIntent.set(d.groupKey,next):transportIntent.delete(d.groupKey);
  notify('component.transport','booking-component-selection-change');
 });
 document.addEventListener('booking-component-context-change',event=>{
  const d=event.detail||{};if(!sameTourWhenPresent(d)||!Array.isArray(d.selections)||d.selections.length>200)return;
  const clearing=d.rateId===0||d.rateId==='0',rate=clearing?null:knownRate(d.rateId);
  if(!clearing&&!rate)return;
  // Context re-evaluates policy, not consent. Do not rebuild the preference
  // from a DOM snapshot that may exclude an unavailable/hidden transport.
  if(changeRate(rate?Number(rate):null))
   notify('component.context','booking-component-context-change');
 });

 // Source/date reconciliation is view-only; never grants price, stock or
 // payable booking authority. A new applied date invalidates all previously
 // chosen room/property stages, even when a Rate remains date-eligible.
 let latestSourceDate='';
 document.addEventListener('shared-presentation-source-context-changed',event=>{
  const d=event.detail||{};
  if(!owns(d)||typeof d.travelDate!=='string'||
      !/^\d{4}-\d{2}-\d{2}$/.test(d.travelDate)||
      !['direct','rate','unavailable'].includes(d.source)||
      !Array.isArray(d.eligibleRateIds)||d.eligibleRateIds.length>100)return;
  const eligible=new Set();
  for(const value of d.eligibleRateIds){
   const rate=knownRate(value);
   if(!rate)return;
   eligible.add(rate);
  }
  if(d.source!=='rate'&&eligible.size)return;
  const changedDate=latestSourceDate!==''&&latestSourceDate!==d.travelDate;
  latestSourceDate=d.travelDate;
  let changed=false;
  for(const [rate,row] of [...rates]){
   if(changedDate||!eligible.has(rate)){
    rates.delete(rate);
    changed=true;
   }
  }
  if(selectedRateId!==null&&!eligible.has(String(selectedRateId))){
   changed=changeRate(null)||changed;
  }
  if(changed)notify('source.context','shared-presentation-source-context-changed');
 });

 document.addEventListener('shared-extra-selection-change',event=>{const d=event.detail||{},option=id(d.extraTourId);if(option&&sameTourWhenPresent(d)&&typeof d.selected==='boolean')setExtra(option,d.selected,'shared-extra-selection-change');});
 document.addEventListener('rate-selection-request',event=>{
  const d=event.detail||{},rate=knownRate(d.rateId);if(!sameTourWhenPresent(d)||!rate)return;
  const next=d.remove?(selectedRateId===Number(rate)?null:selectedRateId):Number(rate);
  if(changeRate(next))notify('rate','rate-selection-request');
 });
 window.addEventListener('shared-booking-visual-change',event=>{
  const d=event.detail||{};if(!owns(d)||!fields.has(d.field)||!(d.value===''||id(d.value)))return;
  const value=d.value===''?'':String(d.value);if((visual[d.field]??'')===value)return;
  value?visual[d.field]=value:delete visual[d.field];notify('visual','shared-booking-visual-change');
 });
 window.addEventListener('shared-booking-choice-state',event=>{
  const d=event.detail||{};if(!owns(d)||!d.choices||typeof d.choices!=='object'||Array.isArray(d.choices))return;
  let changed=false;for(const [key,value]of Object.entries(d.choices)){if(!fields.has(key)||!(value===''||id(value)))continue;const v=value===''?'':String(value);if((visual[key]??'')===v)continue;v?visual[key]=v:delete visual[key];changed=true;}if(changed)notify('visual','shared-booking-choice-state');
 });
 document.addEventListener('jet-filter-content-rendered',()=>document.dispatchEvent(new CustomEvent('shared-presentation-rehydration-requested',{detail:{tourId,schemaVersion:'1.0.0',snapshot:serialize(),readOnly:true}})));
 window.SharedPresentationSelectionV1=Object.freeze({version:'1.0.0',getSnapshot:serialize});
 // Original Elementor Tour Hero stars view, projected from the existing Rate selection.
 // Only published Rate->Property classifications authorized by the server are shown.
 const starMatrix=window.SharedPresentationPropertyStarClassificationV1;
 if(starMatrix&&Number(starMatrix.tourId)===tourId&&starMatrix.rates&&typeof starMatrix.rates==='object'){
  const heroSelector='.elementor-24780 .elementor-element-19c79e4, .elementor-33711 .elementor-element-19c79e4';
  const starAttr='data-mad4b-property-star';
  const number=v=>/^[1-7]$/.test(String(v??''))?Number(v):0;
  const rateClass=()=>{
   const snapshot=serialize(),rate=snapshot.selectedRateId;
   if(!rate)return null;
   const known=starMatrix.rates[String(rate)];
   if(!known||typeof known!=='object'||Array.isArray(known))return null;
   const keys=Object.keys(known);
   if(!keys.length)return null;
   const selected=snapshot.rates.find(r=>r.rateId===rate)||null;
   const selectedProps=selected?Object.values(selected.stages||{}).map(String):[];
   const visualProp=String(snapshot.visual?.property_id||'');
   if(!selectedProps.length&&visualProp)selectedProps.push(visualProp);
   const scope=selectedProps.length?'selected_property':'uniform_rate';
   const props=selectedProps.length?[...new Set(selectedProps)]:keys;
   if(!props.length||props.some(key=>!Object.prototype.hasOwnProperty.call(known,key)))return null;
   let score=0,label='';
   for(const key of props){
    const data=known[key];
    const n=number(data?.stars),name=String(data?.label||'').trim();
    if(!n||!name||(score&&score!==n))return null;
    score=n;
    if(!label)label=name;
   }
   return score?{score,label,scope}:null;
  };
  let lastTarget=null,lastLabel='';
  const renderStars=()=>{
   const node=document.querySelector(heroSelector);
   if(!node){lastTarget=null;lastLabel='';return;}
   const data=rateClass(),key=data?data.score+'|'+data.label+'|'+data.scope:'';
   let current=node.querySelector('['+starAttr+']');
   if(!data){
    if(current)current.remove();
    lastTarget=node;lastLabel='';return;
   }
   if(node===lastTarget&&key===lastLabel&&current)return;
   if(!current){
    current=document.createElement('span');
    current.className='mad4b-property-classification';
    current.setAttribute(starAttr,'');
    current.setAttribute('role','status');
    current.setAttribute('aria-live','polite');
    const glyph=document.createElement('span');
    glyph.setAttribute('aria-hidden','true');
    glyph.className='mad4b-property-classification-stars';
    current.append(glyph,document.createElement('span'));
    node.append(current);
   }
   const glyph=current.firstElementChild,description=current.lastElementChild;
   glyph.textContent='★'.repeat(data.score);
   description.textContent=data.label;
   // The JetEngine star term name is the localized, authoritative label.
   current.setAttribute('aria-label',data.label);
   current.dataset.classificationScope=data.scope;
   lastTarget=node;lastLabel=key;
  };
  let pending=false;
  const scheduleStars=()=>{
   if(pending)return;
   pending=true;
   requestAnimationFrame(()=>{pending=false;renderStars();});
  };
  document.addEventListener('shared-presentation-selection-changed',scheduleStars);
  document.addEventListener('shared-presentation-rehydration-requested',scheduleStars);
  document.addEventListener('jet-filter-content-rendered',scheduleStars);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scheduleStars,{once:true});
  else scheduleStars();
  // JetEngine/Elementor may render a lazily loaded original stars widget.
  const scope=document.querySelector('.elementor-24780,.elementor-33711');
  if(scope)new MutationObserver(records=>{
   if(records.some(x=>[...x.addedNodes].some(n=>n.nodeType===1&&
     (n.matches?.('.elementor-element-19c79e4')||n.querySelector?.('.elementor-element-19c79e4')))))
     scheduleStars();
  }).observe(scope,{childList:true,subtree:true});
 }

})();
