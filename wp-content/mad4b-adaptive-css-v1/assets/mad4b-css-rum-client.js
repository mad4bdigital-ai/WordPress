/* MAD4B Adaptive CSS RUM v1: consent-first, sampled, first-party transport.
 * No cookies, visitor IDs, raw URLs, query strings, IPs or user agents sent.
 * Google web-vitals runs locally only after explicit analytics consent.
 */
(function(w,d){
  'use strict';
  var c=w.MAD4B_CSS_RUM;
  if(!c||c.contract!=='mad4b.adaptive-css.rum.v1'||c.mode!=='observe')return;
  if(!w.crypto||typeof w.crypto.getRandomValues!=='function')return;
  if(w.navigator&&(w.navigator.globalPrivacyControl===true||
                    w.navigator.doNotTrack==='1'))return;
  function consent(){
    var gate=w.MAD4BConsent;
    if(!gate||typeof gate.choice!=='function')return false;
    var choice=gate.choice();
    return !!(choice&&choice.a===true);
  }
  if(!consent())return;
  if(!Number.isInteger(c.sampleBps)||c.sampleBps<1||c.sampleBps>10000)return;
  var v=new Uint32Array(1);
  w.crypto.getRandomValues(v);
  if(v[0]%10000>=c.sampleBps)return;
  var origin=w.location&&w.location.origin;
  var vendor;
  try{
    vendor=new URL(c.vendor,origin);
    if(vendor.origin!==origin||vendor.protocol!=='https:')return;
    var endpoint=new URL(c.endpoint,origin);
    if(endpoint.origin!==origin||endpoint.protocol!=='https:')return;
  }catch(e){return;}
  var m={},sent=false,started=false;
  function receive(metric){
    if(!metric||!Number.isFinite(metric.value)||metric.value<0)return;
    var k=metric.name.toLowerCase();
    if(k!=='lcp'&&k!=='inp'&&k!=='cls')return;
    var value=k==='cls'?Math.round(metric.value*1000):Math.round(metric.value);
    if(value>60000)return;
    m[k]=value;
  }
  function send(){
    if(sent||!consent()||!Object.keys(m).length)return;
    sent=true;
    var links=[].slice.call(d.querySelectorAll('link[data-mad4b-ccss-v2-rest="1"]'));
    var restored=links.length?links.every(function(e){
      return e.media!=='(min-width:768px)';
    }):null;
    var arm=d.querySelector('style[data-mad4b-ccss-v2-critical="1"]')
      ?'optimized':d.getElementById('mad4b-public-critical-css-v1')?'optimized':'native';
    var payload=JSON.stringify({contract:c.contract,proof:c.proof,
      arm:arm,metrics:m,cssRestored:restored});
    if(payload.length>1200)return;
    try{
      w.fetch(endpoint.href,{method:'POST',credentials:'omit',
        mode:'same-origin',keepalive:true,
        headers:{'Content-Type':'application/json'},
        body:payload}).catch(function(){});
    }catch(e){}
  }
  function observe(){
    if(started||!consent())return;
    started=true;
    var api=w.webVitals;
    if(!api||typeof api.onLCP!=='function'||typeof api.onINP!=='function'||
        typeof api.onCLS!=='function')return;
    api.onLCP(receive,{reportAllChanges:true});
    api.onINP(receive,{reportAllChanges:true});
    api.onCLS(receive,{reportAllChanges:true});
    // The page may be destroyed immediately after pagehide. Keepalive
    // fetch must be initiated synchronously, never through a future timer.
    d.addEventListener('visibilitychange',function(){
      if(d.visibilityState==='hidden')send();
    });
    w.addEventListener('pagehide',send);
    w.addEventListener('pageshow',function(e){if(e.persisted){sent=false;m={};}});
  }
  var script=d.createElement('script');
  script.src=vendor.href;script.async=true;
  script.onload=observe;
  script.onerror=function(){};
  d.head.appendChild(script);
})(window,document);
