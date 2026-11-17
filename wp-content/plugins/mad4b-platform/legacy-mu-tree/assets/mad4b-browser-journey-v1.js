/* MAD4B Browser Journey v1: client-only context labels.
 * Server-side country/WPML/WCML/quote remain authoritative.
 * No HTML or sensitive values in sessionStorage. No navigation prefetch.
 */
(function(win,doc){
 'use strict';
 if(win.MAD4BBrowserJourney)return;
 var VERSION='1', KEY='mad4b.browser_journey.context.v1';
 var current=null,pending=null,nonceUntil=0,nonceEndpoint='';
 var last='',snapshot=null;
 var blocked=/\/(?:cart|checkout|my-account|wp-admin|wp-login\.php)(?:\/|$)/i;
 function privatePage(){
  return blocked.test(win.location.pathname) ||
   !!doc.body?.classList.contains('logged-in') ||
   !!doc.querySelector('form.checkout,form.woocommerce-checkout,#order_review,form.login');
 }
 function code(css,pattern){
  var nodes=doc.querySelectorAll?
   Array.from(doc.querySelectorAll(css)):
   [doc.querySelector(css)].filter(Boolean);
  if(!nodes.length)return '';
  var values=nodes.filter(function(el){return !el.disabled;}).map(function(el){
   var v=String(el.value||'').trim().toUpperCase();
   return pattern.test(v)?v:'';
  });
  // Conflicting mobile/desktop preferences must never share a market partition.
  if(!values.length || values.some(function(v){return !v || v!==values[0];}))return '';
  return values[0];
 }
 function viewport(){
  return win.matchMedia('(max-width:767px)').matches?'mobile':
   win.matchMedia('(max-width:1024px)').matches?'tablet':'desktop';
 }
 function fingerprint(fields){
  var s=JSON.stringify(fields),h=2166136261;
  for(var i=0;i<s.length;i++)h=Math.imul(h^s.charCodeAt(i),16777619)>>>0;
  return 'b1-'+h.toString(36);
 }
 function derive(){
  var language=String(doc.documentElement.lang||'').replace('_','-').toLowerCase();
  if(!/^[a-z]{2}(?:-[a-z]{2})?$/.test(language))language='';
  var country=code('.elementor-location-header select[name="market_country"],[data-native-preference-switchers] select[name="market_country"]',/^[A-Z]{2}$/);
  var passport=code('.elementor-location-header select[name="nationality"],[data-native-preference-switchers] select[name="nationality"]',/^[A-Z]{2}$/);
  var currency=code('.elementor-location-header .pricing-currency-select,.pricing-currency-select',/^(USD|EUR|EGP|GBP)$/);
  var device=viewport(),network=win.navigator.connection;
  var saveData=!!network?.saveData;
  var effectiveType=String(network?.effectiveType||'unknown').toLowerCase();
  if(!/^(slow-2g|2g|3g|4g|unknown)$/.test(effectiveType))effectiveType='unknown';
  var memory=Number(win.navigator.deviceMemory||0),cores=Number(win.navigator.hardwareConcurrency||0);
  var tier=(saveData||/^(slow-2g|2g)$/.test(effectiveType)||
    (memory>0&&memory<=2)||(cores>0&&cores<=2))?'constrained':'normal';
  // Site locale and static shell are independent of visitor market data.
  // Missing market selections remain a public shell, never a pricing partition.
  var marketReady=!!(country&&passport&&currency);
  var scope=marketReady?'market_display':'public_shell';
  var parts=marketReady?[country,passport,currency]:[];
  return {key:fingerprint([win.location.host,language,device,tier,scope].concat(parts)),
   language:language,device:device,saveData:saveData,performanceTier:tier,
   scope:scope,marketReady:marketReady};
 }
 function read(){
  try{
   if(privatePage())return null;
   var d=JSON.parse(win.sessionStorage.getItem(KEY)||'null');
   if(!d||d.v!==VERSION||!/^b1-[a-z0-9]+$/.test(d.k||'') ||
      !Number.isFinite(d.at)||d.at>Date.now()+60000||Date.now()-d.at>1800000)return null;
   return d;
  }catch(e){return null;}
 }
 function write(context){
  try{if(!privatePage())win.sessionStorage.setItem(KEY,
   JSON.stringify({v:VERSION,k:context.key,at:Date.now()}));}catch(e){}
 }
 function observe(reason){
  if(privatePage())return null;
  var c=derive(),old=read(),reused=!!old&&old.k===c.key;
  var changed=last!==c.key;
  last=c.key;snapshot=c;
  if(!reused)write(c);
  if(changed)doc.dispatchEvent(new CustomEvent('mad4b:browser-context',{
   detail:{key:c.key,language:c.language,device:c.device,saveData:c.saveData,
    scope:c.scope,marketReady:c.marketReady,
    reused:reused,reason:reason||'observed'}
  }));
  return {key:c.key,reused:reused,device:c.device,saveData:c.saveData,
   scope:c.scope,marketReady:c.marketReady};
 }
 function invalidate(){
  last='';snapshot=null;current=null;nonceUntil=0;
  try{win.sessionStorage.removeItem(KEY);}catch(e){}
 }
 function getNonces(url,force){
  var u;
  try{
   u=new URL(url,win.location.href);
   if(u.origin!==win.location.origin ||
    !/^\/(?:ar\/|fr\/|de\/|es\/|it\/)?wp-json\/visitor-preferences\/v1\/state\/?$/.test(u.pathname) ||
    (u.search && !/^\?lang=(en|ar|fr|de|es|it)$/.test(u.search)) ||
    u.hash)return Promise.reject(Error('wrong_nonce_source'));
  }catch(e){return Promise.reject(e);}
  if(force){current=null;nonceUntil=0;}
  if(!force&&current&&nonceEndpoint===u.href&&nonceUntil>Date.now())return Promise.resolve(current);
  if(pending&&nonceEndpoint===u.href)return pending;
  nonceEndpoint=u.href;
  var work=win.fetch(u.href,{credentials:'same-origin',cache:'no-store',
   headers:{Accept:'application/json'}}).then(function(r){
    if(!r.ok)throw Error('nonce_state_http');return r.json();
   }).then(function(data){
    if(data?.contract!=='visitor-preferences.state.v1'||
      !data.nonces?.country||!data.nonces?.passport)throw Error('nonce_state_invalid');
    current={country:data.nonces.country,passport:data.nonces.passport};
    nonceUntil=Date.now()+120000; // memory only, not sessionStorage
    return current;
   }).finally(function(){if(pending===work)pending=null;});
  pending=work;return work;
 }
 doc.addEventListener('visitor-preferences:saved',function(){invalidate();observe('saved');});
 doc.addEventListener('change',function(e){
  if(e.target?.matches?.('.pricing-currency-select'))invalidate();
 },true);
 win.addEventListener('pageshow',function(e){
  if(e?.persisted){current=null;nonceUntil=0;}
  observe('pageshow');
 });
 win.addEventListener('resize',function(){
  if(snapshot&&snapshot.device!==viewport())observe('viewport');
 },{passive:true});
 win.navigator.connection?.addEventListener?.('change',function(){
  invalidate();observe('connection');
 });
 win.MAD4BBrowserJourney=Object.freeze({
  version:VERSION,getNonces:getNonces,context:function(){return observe('read');},
  invalidate:invalidate,
  allowSpeculativeNavigation:function(){
   // No navigation is actually fetched in this release.
   var c=snapshot||derive();return !privatePage()&&c.performanceTier==='normal'&&win.navigator.onLine!==false;
  }
 });
 if(doc.readyState==='loading')doc.addEventListener('DOMContentLoaded',function(){observe('ready');},{once:true});
 else observe('ready');
})(window,document);
