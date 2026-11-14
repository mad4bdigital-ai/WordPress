#!/usr/bin/env node
/**
 * Read-only, site-neutral live City Slider first-paint admission.
 * Usage: node verify_city_slider_ssr_browser.js https://site.example/es/ <listing-id> <viewport-width> <max-CLS>
 * Proof/Elementor IDs come from each company's native WPML/JetEngine settings,
 * never from this runner. Requires Playwright+Chromium.
 */
'use strict';
const {chromium,devices}=require('playwright');
const uri=process.argv[2],listing=Number(process.argv[3]),width=Number(process.argv[4]),budget=Number(process.argv[5]||0.1);
if(!/^https:\/\//.test(uri||'')||!Number.isSafeInteger(listing)||listing<=0||!Number.isInteger(width)||width<320||width>2560||!Number.isFinite(budget)||budget<=0||budget>0.25){console.error('Usage: node verify_city_slider_ssr_browser.js https://site.example/ 45927 820 0.1');process.exit(2)}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const ctx=await browser.newContext({...devices['Pixel 5'],viewport:{width,height:844},deviceScaleFactor:2.75,reducedMotion:'reduce'});
  const page=await ctx.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(()=>{
   const q=window.__mad4b_ssr_qa={shifts:[],long:[],lcp:[]};
   new PerformanceObserver(e=>e.getEntries().forEach(x=>{if(!x.hadRecentInput)q.shifts.push({t:Math.round(x.startTime),value:x.value})})).observe({type:'layout-shift',buffered:true});
   new PerformanceObserver(e=>e.getEntries().forEach(x=>q.long.push(x.duration))).observe({type:'longtask',buffered:true});
   new PerformanceObserver(e=>e.getEntries().forEach(x=>q.lcp.push(x.startTime))).observe({type:'largest-contentful-paint',buffered:true});
  });
  const cdp=await ctx.newCDPSession(page);await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:0.75*1024*1024/8});
  await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
  const res=await page.goto(uri,{waitUntil:'domcontentloaded',timeout:65000});await page.waitForTimeout(9800);
  const rendered=await page.evaluate(expected=>{
   const selector='.jet-listing-grid__slider:has(> .jet-listing-grid--'+expected+')';
   const slider=document.querySelector(selector),wrapper=slider?.querySelector(':scope > .swiper-wrapper'),slide=wrapper?.querySelector(':scope > .swiper-slide');
   const css=document.getElementById('mad4b-city-ssr-slider-geometry-v1')?.textContent||'';
   const q=window.__mad4b_ssr_qa;
   return {styleCount:document.querySelectorAll('#mad4b-city-ssr-slider-geometry-v1').length,styleMatches:css.includes('jet-listing-grid--'+expected),mediaBounds:css.match(/@media[^{}]+/)?.[0]||null,
    sliderFound:!!slider,swiperInitialized:slider?.classList.contains('swiper-initialized')||false,
    wrapperWidth:wrapper?.getBoundingClientRect().width||0,slideWidth:slide?.getBoundingClientRect().width||0,
    slideCount:wrapper?.querySelectorAll(':scope > .swiper-slide').length||0,
    cls:q.shifts.reduce((a,x)=>a+x.value,0),shifts:q.shifts.filter(x=>x.value>.01),
    tbt:Math.round(q.long.reduce((a,x)=>a+Math.max(0,x-50),0)),fcp:Math.round(performance.getEntriesByName('first-contentful-paint')[0]?.startTime||0),lcp:Math.round(q.lcp.at(-1)||0),overflow:document.documentElement.scrollWidth-innerWidth};
  },listing);
  const ratio=rendered.wrapperWidth>0?rendered.slideWidth/rendered.wrapperWidth:0;
  const tablet=width>767&&width<=991;
  const good= res.status()===200&&rendered.styleCount===1&&rendered.styleMatches&&rendered.sliderFound&&rendered.swiperInitialized&&rendered.slideCount>0&&rendered.cls<=budget&&rendered.overflow<=2&&errors.length===0&&(!tablet||Math.abs(ratio-.5)<.025);
  const out={contract:'mad4b.native-city-slider.ssr-browser-readback.v1',site:uri,listing,width,http:res.status(),cache:res.headers()['x-wayfa-origin-cache']||res.headers()['x-mad4b-preset-cache']||'unknown',pass:good,CLS:Number(rendered.cls.toFixed(6)),TBT_ms:rendered.tbt,FCP_ms:rendered.fcp,LCP_ms:rendered.lcp,slideRatio:Number(ratio.toFixed(3)),...rendered,errors};
  console.log(JSON.stringify(out));if(!good)process.exitCode=3;
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=4});
