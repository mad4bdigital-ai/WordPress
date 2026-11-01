(function(){
 'use strict';
 var endpoint=window.MAD4B_ARCHIVE_MIN_PRICE_ENDPOINT||new URL('/wp-json/mad4b/v1/archive/tours/min-price',location.origin).href;
 if(typeof endpoint!=='string')return;
 try{var safe=new URL(endpoint,location.origin);if(safe.origin!==location.origin)return;endpoint=safe.href;}catch(e){return;}
 var selector='.elementor-34607 .elementor-element-b512fea .elementor-widget-container';
 var currentPath='',latest=0,waiter=null;
 function render(){
  var target=document.querySelector(selector);
  if(!target)return;
  // Never surface Elementor's untrusted hardcoded Start From: 0$.
  target.replaceChildren();
  var slot=document.createElement('span');
  slot.className='mad4b-archive-price-value';slot.hidden=true;slot.setAttribute('aria-live','polite');
  target.appendChild(slot);
  var request=++latest,path=location.pathname+location.search;currentPath=path;
  fetch(endpoint+'?path='+encodeURIComponent(path),{
   method:'GET',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}
  }).then(function(r){if(!r.ok)throw Error('Price not available');return r.json()})
  .then(function(data){
   if(request!==latest||currentPath!==location.pathname+location.search)return;
   if(!data||data.status!=='available'||!data.amount||!data.currency)return;
   var line=[data.label,data.currency,data.amount].join(' ');
   if(data.unit)line+=' / '+data.unit.replace(/^per\s+/i,'');
   slot.textContent=line;slot.hidden=false;target.style.visibility='visible';
  }).catch(function(){/* On Request and unavailable evidence: no label or amount */});
 }
 function schedule(){
  if(waiter)clearTimeout(waiter);
  waiter=setTimeout(function(){waiter=null;render()},260);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render,{once:true});
 else render();
 window.addEventListener('popstate',schedule);
 window.addEventListener('pageshow',function(e){if(e.persisted)schedule()});
 // SmartFilters may update its URL via pushState without a popstate event.
 // Intercept route state only; no widget ownership or duplicated filter query.
 var push=history.pushState,replace=history.replaceState;
 history.pushState=function(){var x=push.apply(this,arguments);schedule();return x};
 history.replaceState=function(){var x=replace.apply(this,arguments);schedule();return x};
})();
