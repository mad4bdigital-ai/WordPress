'use strict';
const {chromium}=require('playwright'), fs=require('fs'),path=require('path');
const ROOT='/usr/src/app/mad4b-private-css-qa';
const SITE='https://wayfatours.com';
const UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const crit=Object.fromEntries(['en','es'].map(l=>[l,fs.readFileSync(path.join(ROOT,l+'-critical.css'),'utf8')]));
function snap(doc){
return doc.evaluate(()=>{
 const candidates=['.elementor-location-header','h1','.elementor-heading-title','.mad4b-country-switcher','.travel-booking-nationality-switcher','.elementor-button','.elementor-location-header select'];
 const out=[];
 for(const sel of candidates){const e=document.querySelector(sel);if(!e)continue;const r=e.getBoundingClientRect(),s=getComputedStyle(e);out.push({sel,x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),h:Math.round(r.height),font:s.fontSize,color:s.color,display:s.display,opacity:s.opacity});}
 return {samples:out,h1:document.querySelector('h1')?.textContent?.slice(0,65)||'',head:Boolean(document.querySelector('.elementor-location-header')),critical:Boolean(document.querySelector('#mad4b-critical-css-v1')),async:Boolean(document.querySelector('#mad4b-css-rest-v1')),inline:Boolean(document.querySelector('script[data-mad4b-css-async-lib="inline-v1"]'))};
});
}
function compare(a,b){
const av=new Map(a.samples.map(x=>[x.sel,x])),bval=new Map(b.samples.map(x=>[x.sel,x]));let errs=[];
for(const [key,x] of av){const y=bval.get(key);if(!y){errs.push(key+':missing');continue;}
 if(x.w===0&&x.h===0&&y.w===0&&y.h===0)continue; // zero-area hidden Elementor aliases do not affect visible first paint
 if(Math.abs(x.y-y.y)>22||Math.abs(x.w-y.w)>24||Math.abs(x.h-y.h)>22||x.font!==y.font)errs.push({sel:key,native:x,candidate:y});
}
return errs;
}
async function seed(browser,country,passport){
const cx=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2,userAgent:UA});
const page=await cx.newPage();
await page.goto(SITE+'/',{waitUntil:'domcontentloaded',timeout:65000});
const r=await page.evaluate(async({country,passport})=>{
const headers={'Content-Type':'application/json','Accept':'application/json'};
const state=await(await fetch('/wp-json/visitor-preferences/v1/state',{credentials:'same-origin',cache:'no-store'})).json();
const apply=async(a,c,n)=>{const r=await fetch('/wp-json/visitor-preferences/v1/apply',{method:'POST',credentials:'same-origin',cache:'no-store',headers,body:JSON.stringify({action:a,country:c,nonce:n})});if(!r.ok)throw Error('apply:'+a+':'+r.status)};
await apply('country',country,state.nonces.country);
if(passport)await apply('manual_passport',passport,state.nonces.passport);
return true;
},{country,passport});
const cookies=await cx.cookies();
await cx.close();return cookies;
}
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
const report={contract:'mad4b.private-css-family.browser-candidate.v1',timestamp:new Date().toISOString(),results:[]};
try{
for(const scenario of [{country:'AE',passport:'GB'},{country:'EG',passport:null},{country:'US',passport:null}]){
 const seeded=await seed(browser,scenario.country,scenario.passport);
 for(const lang of ['en','es']){
  const uri=lang==='en'?'/':'/es/';
  for(const width of [320,390,414,767]){
   const context=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,userAgent:UA});
   await context.addCookies(seeded);
   const native=await context.newPage(),nerrs=[];
   native.on('pageerror',e=>nerrs.push(e.message.slice(0,160)));
   const resp=await native.goto(SITE+uri,{waitUntil:'domcontentloaded',timeout:65000});
   await native.waitForTimeout(450);
   const baseline=await snap(native);
   const page=await context.newPage(),errors=[];let transformed=false,fullURL='',fullResponse=null;
   page.on('pageerror',e=>errors.push(e.message.slice(0,160)));
   await page.route('**/wp-content/litespeed/css/*.css*',async route=>{
      const req=route.request();
      // Hold only the complete combined CSS from the tested variant,
      // to measure first-screen critical while the rest remains deferred.
      if(req.url().includes('/wp-content/litespeed/css/'))await new Promise(r=>setTimeout(r,2700));
      await route.continue();
   });
   await page.route(SITE+uri,async route=>{
     const response=await route.fetch({timeout:45000});
     let html=await response.text();
     const found=[...html.matchAll(/<link\b[^>]*rel=["']stylesheet["'][^>]*>/gi)].filter(x=>x[0].includes('/wp-content/litespeed/css/'));
     if(found.length!==1)throw Error('combined bundle count '+found.length);
     const link=found[0][0];const href=(link.match(/href=["']([^"']+)/i)||[])[1];
     if(!href)throw Error('no css URL');
     fullURL=href;
     if(/mad4b-critical-css-v1|mad4b-css-rest-v1/.test(html))throw Error('native already transformed');
     const escaped=href.replace(/&/g,'&amp;');
     const markup='<style id="mad4b-critical-css-v1">'+crit[lang]+'</style>'+
      '<link id="mad4b-css-rest-v1" rel="stylesheet" href="'+escaped+'" media="print" fetchpriority="low" onload="this.onload=null;this.media=&#39;all&#39;">'+
      '<noscript>'+link+'</noscript>'+
      '<script data-no-optimize="1" data-mad4b-css-async-lib="inline-v1">(function(w,d){var e=d.getElementById("mad4b-css-rest-v1");if(!e)return;var done=function(){if(e.media!=="all")e.media="all";};e.addEventListener("load",done,{once:true});w.setTimeout(done,6500)})(window,document)</script>';
     html=html.replace(link,markup);
     transformed=true;
     await route.fulfill({response,body:html,headers:{...response.headers(),'cache-control':'private, no-store, max-age=0'}});
   });
   const can=await page.goto(SITE+uri,{waitUntil:'domcontentloaded',timeout:65000});
   await page.waitForTimeout(430);
   const candidate=await snap(page);
   const visualErrors=compare(baseline,candidate);
   const row={country:scenario.country,passport:scenario.passport||'auto',lang,width,status:resp.status(),baseline:baseline.samples.length,candidate:candidate.samples.length,transformed,fullURL:fullURL.split('?')[0].split('/').at(-1),critical:candidate.critical,async:candidate.async,inline:candidate.inline,visualErrors,pageErrors:errors,baselineErrors:nerrs};
   row.pass=resp.status()===200 && candidate.critical&&candidate.async&&candidate.inline&&visualErrors.length===0&&errors.length===0;
   report.results.push(row);
   console.log('FAMILY_QA',JSON.stringify(row));
   fs.writeFileSync(path.join(ROOT,'qa-report.json'),JSON.stringify(report,null,2));
   await context.close();
  }
 }
}
} finally{await browser.close();}
const pass=report.results.filter(x=>x.pass).length;
console.log('FAMILY_QA_SUMMARY',JSON.stringify({total:report.results.length,pass,fail:report.results.length-pass}));
if(pass!==report.results.length)process.exitCode=1;
})().catch(e=>{console.error('QA_FATAL',e.stack||String(e));process.exitCode=2});
