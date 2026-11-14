#!/usr/bin/env node
/** Read-only MAD4B native Elementor frame-cache cross-viewport/browser gate.
 * node verify_elementor_device_mode_frame_cache_live.js URL WIDTH EXACT_SOURCE_SHA
 */
'use strict';
const{chromium,devices}=require('playwright'),crypto=require('crypto');const url=process.argv[2],initial=Number(process.argv[3]||820),sha=process.argv[4];
if(!/^https:\/\//.test(url||'')||!Number.isInteger(initial)||initial<320||initial>2560||!/^[a-f0-9]{64}$/.test(sha||'')){console.error('Usage: node verify_elementor_device_mode_frame_cache_live.js https://site.example/es/ 820 SHA256');process.exit(2)}
(async()=>{const browser=await chromium.launch({headless:true,args:['--no-sandbox']});try{
 const ctx=await browser.newContext({...devices['Pixel 5'],viewport:{width:initial,height:844},deviceScaleFactor:2.75,reducedMotion:'reduce'});const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{const q=window.__mad4b_probe={shifts:[],long:[],lcp:[]};
  new PerformanceObserver(e=>e.getEntries().forEach(x=>{if(!x.hadRecentInput)q.shifts.push(x.value)})).observe({type:'layout-shift',buffered:true});
  new PerformanceObserver(e=>e.getEntries().forEach(x=>q.long.push(x.duration))).observe({type:'longtask',buffered:true});
  new PerformanceObserver(e=>e.getEntries().forEach(x=>q.lcp.push(x.startTime))).observe({type:'largest-contentful-paint',buffered:true});
 });
 const cdp=await ctx.newCDPSession(page);await cdp.send('Network.enable');await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:.75*1024*1024/8});await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
 const res=await page.goto(url,{waitUntil:'domcontentloaded',timeout:65000});await page.waitForTimeout(8500);
 const before=await page.evaluate(()=>{const q=window.__mad4b_probe,a=window.elementorFrontend,s=document.querySelector('#mad4b-elementor-device-mode-frame-cache-v1');return{installed:window.__mad4bElementorDeviceModeFrameCacheV1?.installed===true,inline_count:document.querySelectorAll('#mad4b-elementor-device-mode-frame-cache-v1').length,inline_source:s?.textContent||'',CLS:+q.shifts.reduce((a,b)=>a+b,0).toFixed(6),TBT_ms:Math.round(q.long.reduce((a,b)=>a+Math.max(0,b-50),0)),FCP_ms:Math.round(performance.getEntriesByName('first-contentful-paint')[0]?.startTime||0),LCP_ms:Math.round(q.lcp.at(-1)||0),native_device:a?.__mad4bOriginalDeviceModeMethod?.call(a)||'',current_device:a?.getCurrentDeviceMode?.()||'',overflow:document.documentElement.scrollWidth-innerWidth}});
 const digest=s=>crypto.createHash('sha256').update(s,'utf8').digest('hex');const codeShaMatch=digest(before.inline_source)===sha||digest(before.inline_source+'\n')===sha;
 delete before.inline_source;
 const deviceChecks=[];
 for(const w of [390,820,1024,1440,initial]){
  await page.setViewportSize({width:w,height:844});await page.waitForTimeout(220);
  deviceChecks.push(await page.evaluate(()=>{const a=window.elementorFrontend;const el=document.getElementById('elementor-device-mode');return{width:window.innerWidth,source:a?.__mad4bOriginalDeviceModeMethod?.call(a)||'',current:a?.getCurrentDeviceMode?.()||'',css:el?.getBoundingClientRect()?getComputedStyle(el,'::after').content.replace(/"/g,''):null,body:document.body?.getAttribute('data-elementor-device-mode')||''}}));
 }
 const valid=before.installed&&before.inline_count===1&&codeShaMatch&&before.CLS<.1&&before.overflow<=2&&deviceChecks.every(c=>c.source===c.current&&c.current===c.css&&c.current===c.body);
 const result={contract:'mad4b.elementor-device-mode.frame-cache-live-gate.v1',url,initial_width:initial,HTTP:res.status(),cache:res.headers()['x-wayfa-origin-cache']||res.headers()['x-mad4b-preset-cache']||'unknown',pass:res.status()===200&&valid&&!errors.length,code_sha256:sha,code_sha_exact:codeShaMatch, ...before,deviceChecks,errors};console.log(JSON.stringify(result));if(!result.pass)process.exitCode=3;
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exitCode=4});
