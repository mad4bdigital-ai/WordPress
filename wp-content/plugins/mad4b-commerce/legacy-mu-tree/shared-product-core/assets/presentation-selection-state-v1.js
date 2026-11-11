/** Shared Presentation Selection v1. Untrusted in-memory view state, never booking authority. */
(() => {
 'use strict';
 const cfg=window.SharedPresentationSelectionConfigV1;
 const numeric=v=>/^[1-9][0-9]*$/.test(String(v??''));
 if(!cfg||!numeric(cfg.tourId)||window.SharedPresentationSelectionV1)return;
 const tourId=Number(cfg.tourId),rates=new Map(),extras=new Set(),visual={};
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
  out.sort((a,b)=>a.rateId-b.rateId);
  return {schemaVersion:'1.0.0',tourId,revision,readOnly:true,selectedRateId,extras:[...extras].sort(),visual:{...visual},rates:out};
 }
 function notify(kind,source){revision++;document.dispatchEvent(new CustomEvent('shared-presentation-selection-changed',{detail:{schemaVersion:'1.0.0',tourId,revision,kind,source,snapshot:serialize(),readOnly:true,bookingAuthorized:false}}));}
 function clearTransport(){for(const row of rates.values())row.components.delete('transport');}
 function changeRate(next){
  if(next===selectedRateId)return false;
  selectedRateId=next;clearTransport();
  for(const key of ['property_id','transport_id','package_term_id'])delete visual[key];
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
  const rate=knownRate(d.rateId);
  if(d.componentType!=='transport'||!rate||Number(rate)!==selectedRateId||!validKey(d.groupKey))return;
  const row=ensure(rate);if(!row.components.has('transport'))row.components.set('transport',new Map());
  const groups=row.components.get('transport');if(!groups.has(d.groupKey))groups.set(d.groupKey,new Set());
  const set=groups.get(d.groupKey),next=new Set(set);
  if(Array.isArray(d.selections)){
   if(d.selections.length>200)return;next.clear();
   for(const x of d.selections){if(!x||!owns(x)||knownRate(x.rateId)!==rate||x.componentType!=='transport'||x.groupKey!==d.groupKey)continue;const value=id(x.optionId);if(value)next.add(value);}
  }else d.selected?next.add(option):next.delete(option);
  if(next.size===set.size&&[...next].every(value=>set.has(value)))return;
  next.size?groups.set(d.groupKey,next):groups.delete(d.groupKey);
  notify('component.transport','booking-component-selection-change');
 });
 document.addEventListener('booking-component-context-change',event=>{
  const d=event.detail||{};if(!sameTourWhenPresent(d)||!Array.isArray(d.selections)||d.selections.length>200)return;
  const clearing=d.rateId===0||d.rateId==='0',rate=clearing?null:knownRate(d.rateId);if(!clearing&&!rate)return;
  const before=JSON.stringify(serialize());changeRate(rate?Number(rate):null);clearTransport();
  if(rate)for(const x of d.selections){
   if(!x||!owns(x)||knownRate(x.rateId)!==rate||x.componentType!=='transport'||!validKey(x.groupKey))continue;
   const option=id(x.optionId);if(!option)continue;
   const row=ensure(rate);if(!row.components.has('transport'))row.components.set('transport',new Map());
   const groups=row.components.get('transport');if(!groups.has(x.groupKey))groups.set(x.groupKey,new Set());groups.get(x.groupKey).add(option);
  }
  if(before!==JSON.stringify(serialize()))notify('component.context','booking-component-context-change');
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
})();
