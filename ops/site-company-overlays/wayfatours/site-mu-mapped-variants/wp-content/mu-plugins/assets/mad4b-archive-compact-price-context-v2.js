/* MAD4B archive min-price conversion CTA: filter-aware public price evidence. */
(function(){
 'use strict';
 var key='jet-engine/tours_query_archive';
 var endpoint=window.MAD4B_ARCHIVE_MIN_PRICE_ENDPOINT||
   new URL('/wp-json/mad4b/v1/archive/tours/min-price',location.origin).href;
 if(typeof endpoint!=='string')return;
 try {
  var safe=new URL(endpoint,location.origin);
  if(safe.origin!==location.origin||safe.protocol!==location.protocol)return;
  endpoint=safe.href;
 }catch(error){return;}
 var widgetSelector='.elementor-34607 .elementor-element-b512fea';
 var sequence=0, timer=null, controller=null, loading=false, subscribed=false;
 var lastSignature='';
 function group(){
  return window.JetSmartFilters&&window.JetSmartFilters.filterGroups?
    window.JetSmartFilters.filterGroups[key]:null;
 }
 function currentQuery(){
  var g=group();
  // Deep-link filters may not be hydrated yet: never briefly advertise the
  // unfiltered amount until JetSmartFilters has established the real query.
  if(!g)return null;
  var q=g.currentQuery&&typeof g.currentQuery==='object'?g.currentQuery:{};
  if(!q||Array.isArray(q))return '{}';
  var sorted={};
  Object.keys(q).sort().forEach(function(k){sorted[k]=q[k]});
  try{return JSON.stringify(sorted)}catch(e){return null}
 }
 function priceWidget(){return document.querySelector(widgetSelector)}
 function clearStale(){
  var widget=priceWidget();
  if(!widget)return null;
  // Elementor's separate WPML templates may contain the untrusted 0$;
  // remove it before loading scoped pricing. Never overwrite tour card labels.
  var target=widget.querySelector('.elementor-widget-container')||widget;
  target.replaceChildren();
  var slot=document.createElement('span');
  slot.className='mad4b-archive-price-value';
  slot.hidden=true;
  slot.setAttribute('aria-live','polite');
  target.appendChild(slot);
  widget.style.setProperty('display','none','important');
  target.style.visibility='hidden';
  return {widget:widget,target:target,slot:slot};
 }
 function cancelPending(){
  sequence++;
  if(controller){controller.abort();controller=null}
  clearStale();
 }
 function render(){
  if(loading)return;
  var query=currentQuery();
  // Marketing query params (utm_, gclid, etc.) are NOT catalog constraints.
  // CurrentQuery remains the authoritative native filter state.
  var path=location.pathname;
  var params=new URLSearchParams(location.search);
  if(params.has('jsf')&&params.has('tax'))
   path+='?jsf='+encodeURIComponent(params.get('jsf'))+
     '&tax='+encodeURIComponent(params.get('tax'));
  var sig=path+'|'+query;
  if(sig===lastSignature)return;
  lastSignature=sig;
  cancelPending();
  if(!query||query.length>2600)return;
  var dom=priceWidget();
  if(!dom)return;
  var request=sequence;
  var url=endpoint+(endpoint.includes('?')?'&':'?')+'path='+encodeURIComponent(path)
    +'&query='+encodeURIComponent(query);
  controller=new AbortController();
  fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',
    signal:controller.signal,headers:{'Accept':'application/json'}})
  .then(function(response){
   if(!response.ok)throw Error('price_scope_unavailable');
   return response.json();
  })
  .then(function(data){
   if(request!==sequence||loading)return;
   if(!data||data.status!=='available'||!data.amount||!data.currency)return;
   if(!Number.isFinite(Number(String(data.amount).replace(/,/g,'')))||
     Number(String(data.amount).replace(/,/g,''))<=0||
     !/^[A-Z]{3}$/.test(String(data.currency)))return;
   var widget=priceWidget();
   if(!widget)return;
   var target=widget.querySelector('.elementor-widget-container')||widget;
   var slot=target.querySelector('.mad4b-archive-price-value');
   if(!slot)return;
   var line=[data.label||'Start from',data.currency,data.amount].join(' ');
   if(data.unit)line+=' / '+String(data.unit);
   slot.textContent=line;
   slot.hidden=false;
   target.style.visibility='visible';
   widget.style.removeProperty('display');
   widget.style.visibility='visible';
  })
  .catch(function(error){
   // Aborted request, unknown filter, unavailable FX or empty results:
   // leave the conversion CTA active but NEVER advertise stale/global pricing.
  });
 }
 function schedule(delay){
  if(timer)clearTimeout(timer);
  timer=setTimeout(function(){timer=null;render()},delay===undefined?160:delay);
 }
 function isMain(provider,queryId){
  return provider==='jet-engine' && queryId==='tours_query_archive';
 }
 function bindEvents(){
  if(subscribed)return;
  var jsf=window.JetSmartFilters;
  if(!jsf||!jsf.events||typeof jsf.events.subscribe!=='function')return;
  subscribed=true;
  jsf.events.subscribe('ajaxFilters/start-loading',function(provider,queryId){
   if(!isMain(provider,queryId))return;
   loading=true;lastSignature='';cancelPending();
  });
  jsf.events.subscribe('ajaxFilters/updated',function(provider,queryId){
   if(!isMain(provider,queryId))return;
   loading=false;lastSignature='';schedule(100);
  });
  jsf.events.subscribe('ajaxFilters/end-loading',function(provider,queryId){
   if(!isMain(provider,queryId))return;
   loading=false;lastSignature='';schedule(180);
  });
 }
 function refresh(){
  bindEvents();
  lastSignature='';
  schedule(120);
 }
 // Bootstrap even when Elementor JS or JSF initializes after document ready.
 if(document.readyState==='loading')
  document.addEventListener('DOMContentLoaded',refresh,{once:true});
 else refresh();
 document.addEventListener('jet-smart-filters/inited',refresh);
 window.addEventListener('popstate',refresh);
 window.addEventListener('pageshow',function(ev){if(ev.persisted)refresh()});
 var push=history.pushState,replace=history.replaceState;
 history.pushState=function(){var result=push.apply(this,arguments);schedule(250);return result};
 history.replaceState=function(){var result=replace.apply(this,arguments);schedule(250);return result};
})();
