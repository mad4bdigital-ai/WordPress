#!/usr/bin/env node
/**
 * MAD4B read-only native JavaScript workload profiler.
 * Measures JS long tasks and animation-frame script attribution without
 * changing LiteSpeed, Elementor, JetEngine, or the page cache. JSON only.
 * Usage: node profile_native_frontend_longtasks.js https://site.example/ 820
 */
'use strict';
const { chromium, devices } = require('playwright');
const url = process.argv[2];
const width = +(process.argv[3] || 820);
if(!url || !/^https:\/\//.test(url) || width<320 || width>2560 || !Number.isFinite(width)) {
  process.stderr.write('Usage: node profile_native_frontend_longtasks.js https://site.example/ 820\n');
  process.exit(2);
}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
  const ctx=await browser.newContext({...devices['Pixel 5'],viewport:{width,height:844},deviceScaleFactor:2.75,reducedMotion:'reduce'});
  const page=await ctx.newPage();const errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(()=>{
   const x=window.__mad4b_qa={long:[],loaf:[],shift:[],lcp:[]};
   for(const type of ['longtask','long-animation-frame','layout-shift','largest-contentful-paint']){
    try{new PerformanceObserver(list=>{
      for(const v of list.getEntries()) {
       if(type==='longtask') x.long.push({t:Math.round(v.startTime),duration:v.duration});
       else if(type==='long-animation-frame') {
        if(v.duration<50) continue;
        x.loaf.push({t:Math.round(v.startTime),duration:Math.round(v.duration),blocking:Math.round(v.blockingDuration),scripts:v.scripts?.map(s=>({url:s.sourceURL?.split('?')[0]||'',duration:Math.round(s.duration),name:s.sourceFunctionName||'',invoker:s.invokerType||'',layout_ms:Math.round(s.forcedStyleAndLayoutDuration||0)}))||[]});
       } else if(type==='layout-shift'){if(!v.hadRecentInput)x.shift.push(v.value)}
       else x.lcp.push(Math.round(v.startTime));
      }
    }).observe({type,buffered:true})}catch(e){}
   }
  });
  const cdp=await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:.75*1024*1024/8});
  await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
  const res=await page.goto(url,{waitUntil:'domcontentloaded',timeout:65000});
  await page.waitForTimeout(9600);
  const data=await page.evaluate(()=>{
   const p=window.__mad4b_qa;
   return {long:p.long,loaf:p.loaf,cls:p.shift.reduce((a,b)=>a+b,0),lcp:p.lcp.at(-1)||0,fcp:Math.round(performance.getEntriesByName('first-contentful-paint')[0]?.startTime||0),
    resources:performance.getEntriesByType('resource').filter(r=>r.initiatorType==='script'||/\/litespeed\/js\//.test(r.name)).map(r=>({url:r.name.split('?')[0],transfer_bytes:r.transferSize,encoded_bytes:r.encodedBodySize,finish:Math.round(r.responseEnd)}))};
  });
  const sources=new Map();
  for(const f of data.loaf)for(const s of f.scripts){
   const id=s.url||'(unattributed)';let row=sources.get(id)||{url:id,total_script_ms:0,forced_layout_ms:0,count:0,longest_ms:0};
   row.count++;row.total_script_ms+=s.duration;row.forced_layout_ms+=s.layout_ms;row.longest_ms=Math.max(row.longest_ms,s.duration);sources.set(id,row);
  }
  const top=[...sources.values()].sort((a,b)=>b.total_script_ms-a.total_script_ms).slice(0,12);
  const result={contract:'mad4b.native-frontend-js.longtask-profile.v1',url,width,http:res.status(),cache:res.headers()['x-wayfa-origin-cache']||res.headers()['x-mad4b-preset-cache']||'unknown',
   lab_conditions:{cpu_throttle:4,latency_ms:150,downstream_mbps:1.6,sampling_ms:9600,device:'Pixel 5 emulation with viewport override'},
   fcp_ms:data.fcp,lcp_ms:data.lcp,cls:Number(data.cls.toFixed(6)),tbt_ms:Math.round(data.long.reduce((a,t)=>a+Math.max(0,t.duration-50),0)),
   longtask_count:data.long.length,unattributed_longtask_ms:Math.round(data.long.filter(t=>t.duration>50).reduce((a,v)=>a+v.duration,0)),
   blocking_loaf_ms:data.loaf.reduce((a,x)=>a+x.blocking,0),largest_loaf:data.loaf.sort((a,b)=>b.blocking-a.blocking).slice(0,8),script_owners:top,resources:data.resources,errors};
  console.log(JSON.stringify(result));
  if(res.status()!==200||errors.length)process.exitCode=3;
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=4});
