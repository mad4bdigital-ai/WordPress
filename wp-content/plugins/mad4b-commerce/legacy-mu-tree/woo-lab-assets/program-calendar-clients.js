/* Shared Product program schedule bridge. Read-only: no picker overrides, no checkout state. */
(function () {
 'use strict';
 var config=window.SharedProgramCalendarConfig;
 if (!config || !Number.isSafeInteger(config.tourId) || !config.endpoint) return;
 var current=null, requestId=0, controller=null, lastKey='', cache=null;
 function dateKey(d) {
  if (typeof d==='string') return d;
  if (!(d instanceof Date) || !Number.isFinite(d.getTime())) return '';
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
 }
 function calendarWindow() {
  var a=new Date();var from=new Date(a.getFullYear(),a.getMonth(),a.getDate());
  var to=new Date(from.getFullYear(),from.getMonth()+3,from.getDate());
  return {from:dateKey(from),to:dateKey(to)};
 }
 function isAllowed(date, source) {
  var d=current;
  if (!d)return null;
  var key=dateKey(date);
  if (!key)return false;
  // A client does not know dates outside the fetched window.
  if (key<d.from || key>d.to)return null;
  var calendar=d.program_calendar||{};
  // An explicit blackout is known even if the rest of the program is unconfigured.
  if ((calendar.decision?.exceptions?.exclude||[]).includes(key))return false;
  if (calendar.earliest_request_date && key<calendar.earliest_request_date)return false;
  if (calendar.status==='unconfigured')return null;
  var listed=(calendar.dates||[]).includes(key);
  // Explicit inclusions cannot prove that all other days are closed.
  if (calendar.status==='specific_dates_only' && !listed)return null;
  if (!listed)return false;
  if (source==='rate') {
   // Tour-wide coverage cannot verify a particular selected Rate.
   if (d.pricing_coverage?.error)return null;
   return (d.pricing_coverage?.dates||[]).includes(key)?null:false;
  }
  return true; // Calendar requestable, NOT a verified price or booking.
 }
 async function refresh(options) {
  options=options||{};
  var dates=calendarWindow(),from=options.from||dates.from,to=options.to||dates.to;
  var choices={rate_id:options.rateId||0,property_id:options.propertyId||0,
    unit_id:options.unitId||0,transport_id:options.transportId||0};
  var key=JSON.stringify({from,to,tourId:config.tourId,choices});
  if (!options.force&&lastKey===key&&cache)return cache;
  if (controller)controller.abort();
  current=null;cache=null;
  controller=new AbortController();
  var token=++requestId;
  var url=new URL(config.endpoint,window.location.href);
  url.searchParams.set('tour_id',String(config.tourId));
  url.searchParams.set('from',from);url.searchParams.set('to',to);
  Object.entries(choices).forEach(([name,id])=>{if(Number.isSafeInteger(Number(id))&&Number(id)>0)
   url.searchParams.set(name,String(id));});
  try {
   var response=await fetch(url,{signal:controller.signal,credentials:'same-origin'});
   if (!response.ok)throw new Error('Program schedule '+response.status);
   var data=await response.json();
   if (token!==requestId)return null;
   current=data;cache=data;lastKey=key;
   document.dispatchEvent(new CustomEvent('shared-product-program-schedule-ready',{detail:{
    tourId:config.tourId,schedule:data,source:'program-calendar-bridge'
   }}));
   return data;
  } catch(e) {
   if (e.name!=='AbortError'&&token===requestId) {
    cache=null;lastKey='';
    document.dispatchEvent(new CustomEvent('shared-product-program-schedule-error',{detail:{tourId:config.tourId,message:e.message}}));
   }
   return null;
  }
 }
 async function decision(date, opts) {
  opts=opts||{};
  var travelDate=dateKey(date);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(travelDate))throw new Error('Invalid travel date');
  var source=opts.source||'direct', intent=opts.intent||'quote';
  if (!['direct','rate'].includes(source))throw new Error('Invalid pricing source');
  if (!['inquiry','quote','request','instant'].includes(intent))throw new Error('Invalid booking intent');
  var rateId=Number(opts.rateId||0);
  if (source==='rate' && (!Number.isSafeInteger(rateId)||rateId<=0))throw new Error('Selected rate required');
  if (!config.decisionEndpoint)throw new Error('Scheduling decision endpoint unavailable');
  var url=new URL(config.decisionEndpoint,window.location.href);
  [['tour_id',config.tourId],['travel_date',travelDate],['source',source],
   ['intent',intent],['audience',opts.audience||'general'],
   ['rate_id',rateId],['property_id',opts.propertyId],['unit_id',opts.unitId],
   ['transport_id',opts.transportId],['extra_tour_id',opts.extraTourId],
   ['extra_rate_id',opts.extraRateId]
  ].forEach(([name,value])=>{if(value!==null && value!==undefined && value!=='' && value!==0)url.searchParams.set(name,String(value));});
  var response=await fetch(url,{credentials:'same-origin',signal:opts.signal});
  var data=await response.json();
  if(!response.ok)throw new Error(data.code||('Program decision '+response.status));
  return data; // Full independent decisions; never implies confirmed booking or inventory.
 }
 window.SharedProgramCalendar={
  refresh:refresh,
  decision:decision,
  bindExistingDatepickers:syncPickers,
  bindJetFormBuilderDatefields:bindJetFormDates,
  getCurrent:function(){return current;},
  isAllowed:isAllowed,
  // Rate coverage and program scheduling are intentionally independent diagnostics.
  isRateCovered:function(date){var d=current,key=dateKey(date);return !d||!key||key<d.from||key>d.to||d.pricing_coverage?.error?null:(d.pricing_coverage?.dates||[]).includes(key);},
  // Integration adapters can opt into a datepicker later; no existing form is overwritten.
  attach:function(callback){
   if(typeof callback!=='function')return function(){};
   var listener=function(e){if(e.detail?.tourId===config.tourId)callback(e.detail.schedule);};
   document.addEventListener('shared-product-program-schedule-ready',listener);
   if(current)callback(current);
   return function(){document.removeEventListener('shared-product-program-schedule-ready',listener);};
  }
 };
 // JetFormBuilder: observe the genuine departure field only. The flexible return
 // date is NOT a second program departure. Server confirmation remains authoritative.
 var jetDateBindings=new WeakMap();
 function bindJetFormDates(){
  var count=0;
  document.querySelectorAll('form.jet-form-builder input[name="tour_departial_date_time"]').forEach(function(field){
   if(jetDateBindings.has(field))return;
   var seq=0;
   var onChange=function(){
    var value=field.value;
    if(!/^\d{4}-\d{2}-\d{2}$/.test(value)){field.dataset.programEligibility='unknown';return;}
    var token=++seq;field.dataset.programEligibility='checking';
    decision(value,{source:'direct',intent:'quote'}).then(function(result){
     if(token!==seq||!field.isConnected)return;
     var status=result?.booking_eligibility?.status||'unknown';
     field.dataset.programEligibility=status;
     field.dispatchEvent(new CustomEvent('shared-product-jetform-date-decision',{bubbles:true,detail:{
      tourId:config.tourId,status:status,decision:result
     }}));
    }).catch(function(){
     if(token===seq&&field.isConnected)field.dataset.programEligibility='unknown';
    });
   };
   field.addEventListener('change',onChange);
   jetDateBindings.set(field,onChange);
   count++;
   if(field.value)onChange();
  });
  return count;
 }
 // Progressively bind the canonical schedule to existing Flatpickr instances.
 // Apply operating-day restrictions to the actual arrival date only.
 // The Flex To Date is a window boundary, not a second scheduled departure.
 // Preserve Fluent Forms controls and all other restrictions.
 var pickerBindings=new WeakMap(),syncQueued=false;
 function syncPickers(){
  var calendar=current?.program_calendar;
  if(!calendar || calendar.status==='unconfigured')return;
  document.querySelectorAll('form[id^="fluentform_"] input[name="datetime"]').forEach(function(input){
   var picker=input._flatpickr;
   if(!picker)return;
   var state=pickerBindings.get(input);
   if(!state || state.picker!==picker){
    var existing=Array.isArray(picker.config.disable)?picker.config.disable.slice():[];
    // Do not erase native dates or other vendor disabling rules.
    pickerBindings.set(input,{picker:picker,earliest:null,nativeMin:picker.config.minDate instanceof Date?new Date(picker.config.minDate.getTime()):null});
    picker.set('disable',existing.concat([function(day){
      // A flexible from/to interval is a search window, not a sailing date.
      // Enforce the original weekday policy on fixed departures only.
      var form=input.closest('form');
      var flexible=!!form?.querySelector('input[name="tour_date_flexibility"][value="true"]:checked');
      return !flexible && window.SharedProgramCalendar.isAllowed(day,'direct')===false;
    }]));
    state=pickerBindings.get(input);
   }
   var earliest=calendar.earliest_request_date||null;
   if(earliest && state.earliest!==earliest){
    state.earliest=earliest;
          // Flatpickr's field format is d-M-y; passing YYYY-MM-DD directly
      // is parsed as a different date. Use a validated local Date instead.
      if(/^\d{4}-\d{2}-\d{2}$/.test(earliest)){
       var parts=earliest.split('-').map(Number);
       var parsed=new Date(parts[0],parts[1]-1,parts[2]);
       if(parsed.getFullYear()===parts[0] && parsed.getMonth()+1===parts[1] &&
          parsed.getDate()===parts[2]){
        var nativeMinimum=state.nativeMin;
        if(nativeMinimum instanceof Date && nativeMinimum>parsed)parsed=nativeMinimum;
        picker.set('minDate',parsed);
       }
      }
   }
  });
 }
 function queuePickerSync(){
  if(syncQueued)return;syncQueued=true;
  Promise.resolve().then(function(){syncQueued=false;syncPickers();});
 }
 document.addEventListener('shared-product-program-schedule-ready',queuePickerSync);
 document.addEventListener('fluentform_ready',queuePickerSync);
 if(document.documentElement){
  new MutationObserver(function(records){
   if(records.some(function(record){return Array.from(record.addedNodes).some(function(node){
    return node.nodeType===1 && (node.matches?.('form[id^="fluentform_"],input[name^="datetime"]') ||
     node.querySelector?.('form[id^="fluentform_"] input[name^="datetime"]'));
   });}))queuePickerSync();
  }).observe(document.documentElement,{childList:true,subtree:true});
 }
 function schedule(){refresh().then(function(){syncPickers();setTimeout(syncPickers,600);}).catch(function(){});}
 if (document.readyState==='loading')document.addEventListener('DOMContentLoaded',schedule,{once:true});
 else schedule();
 document.addEventListener('jet-filter-content-rendered',function(){refresh({force:true});});
 document.addEventListener('fluentform_ready',function(){if(current)document.dispatchEvent(new CustomEvent('shared-product-program-schedule-ready',{detail:{tourId:config.tourId,schedule:current,source:'fluentform-ready'}}));});
 document.addEventListener('jet-form-builder/after-render',function(){bindJetFormDates();if(current)document.dispatchEvent(new CustomEvent('shared-product-program-schedule-ready',{detail:{tourId:config.tourId,schedule:current,source:'jetformbuilder-render'}}));});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bindJetFormDates,{once:true});else bindJetFormDates();
})();
