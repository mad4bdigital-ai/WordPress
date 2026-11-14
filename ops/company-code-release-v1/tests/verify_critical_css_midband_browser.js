#!/usr/bin/env node
/* MAD4B: independent, site-neutral live Critical CSS browser gate.
 * CLI: node verify_critical_css_midband_browser.js URL WIDTH SHA256 [MAX_CLS]
 * Requires Playwright in NODE_PATH or local node_modules, Chromium installed.
 * Never modifies the site. Uses 4x CPU + 1.6Mbps network for reproducibility.
 */
'use strict';
const { chromium, devices }=require('playwright');
const crypto=require('crypto');
const url=process.argv[2],width=Number(process.argv[3]),expected=process.argv[4],maxCLS=Number(process.argv[5]||0.1);
if(!url||!/^https:\/\//.test(url)||!Number.isFinite(width)||width<300||width>2560||!/^[a-f0-9]{64}$/.test(expected)||!(maxCLS>0&&maxCLS<=0.25)){
 console.error('Usage: node verify_critical_css_midband_browser.js https://site.example/ 1024 <critical_css_sha256> [max_cls]');process.exit(2);
}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const context=await browser.newContext({...devices['Pixel 5'],viewport:{width,height:844},deviceScaleFactor:2.75,reducedMotion:'reduce'});
  const page=await context.newPage();const errors=[],failures=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('requestfailed',r=>{if(!r.url().includes('admin-ajax.php'))failures.push({url:r.url().slice(-120),error:r.failure()?.errorText})});
  await page.addInitScript(()=>{
   window.__mad4bCriticalAudit={shifts:[],lcp:[],long:[]};const target=window.__mad4bCriticalAudit;
   new PerformanceObserver(list=>list.getEntries().forEach(e=>{if(!e.hadRecentInput)target.shifts.push({at:Math.round(e.startTime),value:e.value})})).observe({type:'layout-shift',buffered:true});
   new PerformanceObserver(list=>list.getEntries().forEach(e=>target.lcp.push({at:Math.round(e.startTime),url:e.url||''}))).observe({type:'largest-contentful-paint',buffered:true});
   new PerformanceObserver(list=>list.getEntries().forEach(e=>target.long.push(e.duration))).observe({type:'longtask',buffered:true});
  });
  const cdp=await context.newCDPSession(page);await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:0.75*1024*1024/8});
  await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
  const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:65000});await page.waitForTimeout(9500);
  const read=await page.evaluate(()=>{const crit=document.getElementById('mad4b-critical-css-v1');const entries=performance.getEntriesByType('resource');const t=window.__mad4bCriticalAudit;
   return {criticalCSS:crit?.textContent||'',criticalCount:document.querySelectorAll('#mad4b-critical-css-v1').length,fullLoaded:!!document.querySelector('#mad4b-css-rest-v1')?.sheet,CLS:t.shifts.reduce((a,x)=>a+x.value,0),LCP_ms:t.lcp.at(-1)?.at||0,FCP_ms:Math.round(performance.getEntriesByName('first-contentful-paint')[0]?.startTime||0),TBT_ms:Math.round(t.long.reduce((a,x)=>a+Math.max(0,x-50),0)),overflowPx:document.documentElement.scrollWidth-innerWidth,shifts:t.shifts.filter(x=>x.value>.01),cssNetwork:entries.filter(x=>x.name.includes('/litespeed/css/')).map(x=>({url:x.name.split('?')[0].slice(-80),finishMs:Math.round(x.responseEnd)}))}});
  const actual=crypto.createHash('sha256').update(Buffer.from(read.criticalCSS,'utf8')).digest('hex');
  const result={contract:'mad4b.critical-css.browser-readback.v1',url,width,http:response?.status()||0,cssReady:response?.headers()['x-mad4b-mobile-css-ready']||null,cache:response?.headers()['x-wayfa-origin-cache']||null,expectedSha256:expected,actualSha256:actual,CLS:Number(read.CLS.toFixed(6)),LCP_ms:read.LCP_ms,FCP_ms:read.FCP_ms,TBT_ms:read.TBT_ms,shifts:read.shifts,overflowPx:read.overflowPx,criticalCount:read.criticalCount,fullLoaded:read.fullLoaded,cssNetwork:read.cssNetwork,errors,failures};
  const ready=result.http===200&&actual===expected&&read.criticalCount===1&&read.fullLoaded&&result.CLS<=maxCLS&&result.overflowPx<=2&&!errors.length&&!failures.length;
  result.admitted=ready;console.log(JSON.stringify(result));if(!ready)process.exitCode=3;
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=4});
