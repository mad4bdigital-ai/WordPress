'use strict';
/* First-party browser observation of ONE due Adaptive route in the existing
 * Presentation Browser Audit. No change to CSS, locale, query or WordPress.
 * A successful HTTP/render check returns HOLD, never a fake certificate. */
const {chromium}=require('playwright');
const site=process.argv[2],path=process.argv[3];
if(!/^https:\/\/[a-z0-9.-]+\/$/i.test(site||'')
   || !/^\/[A-Za-z0-9/_~.-]*$/.test(path||'')
   || path.includes('..') || path.includes('//')
   || /\/(?:cart|checkout|payment|booking|account|wp-admin|wp-json)(?:\/|$)/i.test(path)){
 throw Error('INVALID_PUBLIC_ROUTE_OBSERVATION');
}
const output={
 contract:'mad4b.adaptive-asset.native-route-recheck.v1',
 site_host:new URL(site).hostname,
 route:path,observed_utc:new Date().toISOString(),
 authorization_created:false,
 certification_issued:false,
 automatic_lazy_promotion:false,
 state:'HOLD',
 observations:[]
};
const AC='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
 for(const width of [390,768]){
  const context=await browser.newContext({
   viewport:{width,height:844},isMobile:width<=768,hasTouch:width<=768,
   userAgent:'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36',
   extraHTTPHeaders:{Accept:AC}});
  try{
   const page=await context.newPage();
   const errors=[];page.on('pageerror',e=>errors.push(String(e).slice(0,90)));
   await page.addInitScript(()=>{
    window.__shadowCLS=0;
    try{
     new PerformanceObserver(x=>{
      for(const e of x.getEntries())if(!e.hadRecentInput)window.__shadowCLS+=e.value;
     }).observe({type:'layout-shift',buffered:true});
    }catch(e){}
   });
   const start=Date.now();
   const response=await page.goto(site.replace(/\/$/,'')+path,{waitUntil:'domcontentloaded',timeout:40000});
   await page.waitForTimeout(500);
   const dom=await page.evaluate(()=>({
    h1:document.querySelectorAll('h1').length,
    viewport_overflow:Math.round(document.documentElement.scrollWidth-innerWidth),
    cls:+(window.__shadowCLS||0).toFixed(5)
   }));
   output.observations.push({
    width,status:response.status(),css_ready:response.headers()['x-mad4b-mobile-css-ready']||'unknown',
    origin_cache:response.headers()['x-mad4b-preset-cache']||'unknown',
    elapsed_ms:Date.now()-start,...dom,
    javascript_error_count:errors.length
   });
  }finally{await context.close()}
 }
 output.complete=output.observations.length===2
  && output.observations.every(x=>x.status===200 && x.viewport_overflow<=1 && x.javascript_error_count===0);
 output.reason=output.complete?'NATIVE_RENDER_OBSERVED_PARITY_CERT_NOT_ISSUED':'NATIVE_RENDER_NOT_VERIFIED';
 console.log(JSON.stringify(output));
 if(!output.complete)process.exitCode=2;
 }finally{await browser.close()}
})().catch(e=>{
 output.complete=false;output.reason='NATIVE_RENDER_AUDIT_FAILED';
 output.error_code=String(e.message||e).slice(0,90);
 console.log(JSON.stringify(output));process.exitCode=3;
});
