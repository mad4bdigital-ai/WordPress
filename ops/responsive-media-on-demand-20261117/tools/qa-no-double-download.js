'use strict';
const fs=require('fs'),{chromium}=require('playwright');
const src=fs.readFileSync('/assets/mad4b-responsive-media-demand-v1.js','utf8');
const derivative=fs.readFileSync('/qa/derivative.webp');
const base='https://wayfatours.com';
const img=base+'/wp-content/uploads/2026/11/fallback-city.webp';
const optimized=base+'/wp-content/uploads/2026/11/fallback-city.jpg.mad4b-demand-576-aaaaaaaaaaaa.webp';
const ua='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36';
const cases=[{name:'pre-reveal-ready',reveal:'after',delay:0,expected:{rest:1,baseline:0,optimized:1}},{name:'reveal-before-ready',reveal:'early',delay:1100,expected:{rest:1,baseline:1,optimized:0}},{name:'initially-visible',reveal:'before',delay:0,expected:{rest:0,baseline:1,optimized:0}},
 {name:'primary-lcp-protected',reveal:'protected',delay:0,expected:{rest:0,baseline:1,optimized:0}}];
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  for(const tc of cases){
   const ctx=await browser.newContext({viewport:{width:390,height:820},deviceScaleFactor:2,isMobile:true,hasTouch:true,userAgent:ua});
   const page=await ctx.newPage();const requests={rest:0,baseline:0,optimized:0};
   const errors=[];page.on('pageerror',e=>errors.push(e.message.slice(0,130)));
   await page.route('**/wp-json/mad4b/v1/responsive-media?*',async route=>{
     requests.rest++;
     if(tc.delay)await new Promise(ok=>setTimeout(ok,tc.delay));
     const q=new URL(route.request().url()).searchParams.get('items');
     const items={};for(const item of (q||'').split(',')){items[item]={state:'ready',width:576,url:optimized};}
     await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({contract:'mad4b.responsive-media.demand.v1',items})});
   });
   await page.route('**/fallback-city.webp',route=>{requests.baseline++;return route.fulfill({status:200,contentType:'image/webp',body:derivative});});
   await page.route('**/fallback-city.jpg.mad4b-demand-576-aaaaaaaaaaaa.webp',route=>{requests.optimized++;return route.fulfill({status:200,contentType:'image/webp',body:derivative});});
   await page.goto(base+'/robots.txt',{waitUntil:'domcontentloaded',timeout:25000});
   const cls=tc.reveal==='before'?' mad4b-city-geometry-visible':'';
   const markup='<html class="mad4b-city-geometry-lazy"><head><style>.elementor-element-95d1c9c{background-image:url("'+img+'");width:288px;height:288px;}html.mad4b-city-geometry-lazy .elementor-element-95d1c9c:not(.mad4b-city-geometry-visible){background-image:none!important;}</style></head><body><main style="height:930px"></main><div class="jet-listing-grid__item jet-listing-dynamic-post-123"><div class="elementor-element-95d1c9c'+cls+'"></div></div></body></html>';
   await page.setContent(markup,{waitUntil:'load'});
   await page.evaluate((protectedIds)=>window.MAD4BResponsiveMedia={endpoint:location.origin+'/wp-json/mad4b/v1/responsive-media',selector:'.elementor-element-95d1c9c',protectedTermIds:protectedIds,rootLazyClass:'mad4b-city-geometry-lazy',cardVisibleClass:'mad4b-city-geometry-visible',step:64,minWidth:192,maxWidth:1536},tc.name==='primary-lcp-protected'?[123]:[]);
   await page.addScriptTag({content:src});
   if(tc.reveal==='protected'){
     await page.waitForTimeout(600);
     await page.evaluate(()=>document.querySelector('.elementor-element-95d1c9c').classList.add('mad4b-city-geometry-visible'));
   }
   if(tc.reveal==='early'){
     await page.waitForTimeout(250);
     await page.evaluate(()=>document.querySelector('.elementor-element-95d1c9c').classList.add('mad4b-city-geometry-visible'));
   }
   if(tc.reveal==='after'){
     await page.waitForFunction(()=>performance.getEntriesByType('resource').some(x=>x.name.endsWith('aaaaaaaaaaaa.webp')),{timeout:7500}).catch(()=>{});
     await page.waitForTimeout(220);
     await page.evaluate(()=>document.querySelector('.elementor-element-95d1c9c').classList.add('mad4b-city-geometry-visible'));
   }
   await page.waitForTimeout(tc.delay+600);
   const proof=await page.evaluate(()=>({ready:document.querySelector('.elementor-element-95d1c9c').dataset.mad4bResponsiveImage||null,bg:document.querySelector('.elementor-element-95d1c9c').style.backgroundImage||''}));
   console.log('NO_DUPLICATE_MEDIA_QA',JSON.stringify({case:tc.name,requests,expected:tc.expected,proof,errors}));
   if(errors.length)throw new Error(tc.name+': console errors');
   for(const [k,v] of Object.entries(tc.expected))if(requests[k]!==v)throw new Error(tc.name+': '+k+' expected '+v+' got '+requests[k]);
   if(tc.name==='pre-reveal-ready'&&proof.ready!=='ready')throw new Error(tc.name+': optimized image not shown');
   await ctx.close();
  }
 }finally{await browser.close()}
})().catch(e=>{console.error('QA_FAILED',e.stack?.slice(0,800));process.exit(1)});
