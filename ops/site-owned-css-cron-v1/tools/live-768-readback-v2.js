'use strict';
// Anonymous, read-only 768px verification; no WordPress/cache/CSS mutation.
const {chromium}=require('playwright'),fs=require('fs');
const {composeSample,evaluateSamples}=require('./live-768-readback-proof-v2');
const PROBE='mad4b.css.verified-live-768-readback.v2';
const routes={en:'https://wayfatours.com/',es:'https://wayfatours.com/es/'};
const ua='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const acc='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
const out=process.env.MAD4B_READBACK||'/probe/768-readback-v2.json';
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 const records=[];
 try{
  for(const [locale,url] of Object.entries(routes)){
   for(let trial=1;trial<=3;trial++){
    const ctx=await browser.newContext({viewport:{width:768,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2,userAgent:ua,extraHTTPHeaders:{Accept:acc}});
    const page=await ctx.newPage();
    const cdp=await ctx.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:750*1024/8});
    await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
    await page.addInitScript(()=>{
      window.__mad4bQA={paint:[],lcp:[],shift:[]};
      new PerformanceObserver(list=>window.__mad4bQA.paint.push(...list.getEntries().map(e=>({name:e.name,t:Math.round(e.startTime)})))).observe({type:'paint',buffered:true});
      new PerformanceObserver(list=>window.__mad4bQA.lcp.push(...list.getEntries().map(e=>({timeMs:Math.round(e.startTime),resource:e.url?.split('/').at(-1)||'',tag:e.element?.tagName||''})))).observe({type:'largest-contentful-paint',buffered:true});
      new PerformanceObserver(list=>window.__mad4bQA.shift.push(...list.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true});
    });
    let failures=[],errors=[];
    page.on('pageerror',e=>errors.push(e.message.slice(0,130)));
    page.on('requestfailed',r=>{if(r.url().includes('.css'))failures.push(r.url().split('/').at(-1).slice(0,80))});
    let result={locale,trial,width:768};
    try{
     const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:65000});
     await page.waitForTimeout(8400);
     const metrics=await page.evaluate(()=>{
       const nav=performance.getEntriesByType('navigation')[0];
       const r=document.querySelector('#mad4b-css-rest-v1');
       const css=document.querySelector('#mad4b-critical-css-v1');
       const img=document.querySelector('link[data-mad4b-hero-poster]');
       const hero=performance.getEntriesByType('resource').filter(x=>x.name.includes('home-mix-mad4b-1600.webp'));
       return {fcpMs:window.__mad4bQA.paint.find(x=>x.name==='first-contentful-paint')?.t||null,
        lcpMs:window.__mad4bQA.lcp.at(-1)?.timeMs||null,
        lcpResource:window.__mad4bQA.lcp.at(-1)?.resource||'',
        ttfbMs:Math.round(nav.responseStart),cls:+window.__mad4bQA.shift.reduce((a,b)=>a+b,0).toFixed(5),
        restCssMedia:r?.media||'',criticalCssMedia:css?.media||'',
        restCssLoaded:!![...document.styleSheets].some(s=>s.href?.includes('/litespeed/css/')),
        heroPreload:img?.getAttribute('href')?.split('/').at(-1)||'',
        heroDownload:hero.length?{startMs:Math.round(hero.at(-1).startTime),durationMs:Math.round(hero.at(-1).duration),transferSize:hero.at(-1).transferSize}:null,
        pageWidth:document.documentElement.scrollWidth,viewportWidth:innerWidth};
     });
     result={...composeSample({...result,status:response.status()},await response.allHeaders(),metrics),cssRequestFailures:failures,errorCount:errors.length};
    }catch(e){result.error=String(e).slice(0,180)}
    records.push(result);console.log('MAD4B_LIVE_768_SAMPLE',JSON.stringify(result));
    fs.writeFileSync(out,JSON.stringify({contract:PROBE,verifiedAt:new Date().toISOString(),scope:'wayfa_768_read_only_no_css_changes',samples:records},null,2));
    await ctx.close();
   }
  }
 }finally{await browser.close()}
 const {invalid:bad}=evaluateSamples(records);
 console.log('MAD4B_LIVE_768_COMPLETE',JSON.stringify({tested:records.length,invalid:bad,contract:PROBE}));
 if(bad)process.exitCode=4;
})().catch(e=>{console.log('HOLD_MAD4B_LIVE_768',String(e.stack||e).slice(0,800));process.exitCode=5});
