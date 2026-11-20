'use strict';
const {chromium}=require('playwright'),fs=require('fs'),path=require('path');
const SITE=process.env.MAD4B_SOURCE_SITE||'https://tours.dev.mad4b.com';
const OUT=process.env.MAD4B_OUTPUT||'/out';
const ITEM=JSON.parse(fs.readFileSync(path.join(OUT,process.env.MAD4B_CANDIDATES_FILE||'candidates.json'),'utf8'));
const languages=(process.env.MAD4B_QA_LANGS||'en,es').split(',');
if(languages.some(x=>!/^[a-z]{2}$/.test(x)))throw Error('INVALID_WPML_LANGUAGE_SELECTION');
const widths=(process.env.MAD4B_QA_WIDTHS||'320,390,414').split(',').map(Number);
const UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const HTML_ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
const CSS_NEGOTIATION_PROFILE='chromium-mobile-webp-v1';
const PRIORITY=['low','auto','high'].includes(process.env.MAD4B_CSS_PRIORITY)?process.env.MAD4B_CSS_PRIORITY:'low';
const PRELOAD=process.env.MAD4B_CSS_PRELOAD==='1';
const QA_SOURCE_QUERY=process.env.MAD4B_QA_SOURCE_QUERY||'';
const DIAG_BYPASS=process.env.MAD4B_DIAG_ORIGIN_BYPASS==='1';
const HERO900=process.env.MAD4B_HERO_900_CANDIDATE==='1';
const DEFER_CITY=process.env.MAD4B_FIRST_CITY_DEFER_CANDIDATE==='1';
const ADAPTIVE_MAX=Number(process.env.MAD4B_CSS_ADAPTIVE_MAX||0);
if(ADAPTIVE_MAX!==0&&(!Number.isInteger(ADAPTIVE_MAX)||ADAPTIVE_MAX<480||ADAPTIVE_MAX>767))throw Error('INVALID_ADAPTIVE_BREAKPOINT');

async function measure(browser,candidate,width,asyncCss,frozenHtml){
 const ctx=await browser.newContext({viewport:{width,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2,userAgent:UA,extraHTTPHeaders:{'Accept':HTML_ACCEPT,...(DIAG_BYPASS?{'X-MAD4B-BYPASS-CACHE':'1'}:{})}});
 const page=await ctx.newPage();
 const cdp=await ctx.newCDPSession(page);
 await cdp.send('Network.enable');
 await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:750*1024/8});
 await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
 await page.addInitScript(()=>{
  window.__qa={paint:[],lcp:[],shift:[]};
  new PerformanceObserver(l=>window.__qa.paint.push(...l.getEntries().map(e=>({name:e.name,t:Math.round(e.startTime)})))).observe({type:'paint',buffered:true});
  try{new PerformanceObserver(l=>window.__qa.lcp.push(...l.getEntries().map(e=>({t:Math.round(e.startTime),url:e.url?.split('/').at(-1),
   tag:e.element?.tagName||null,id:e.element?.id||null,
   className:typeof e.element?.className==='string'?e.element.className.slice(0,140):null})))).observe({type:'largest-contentful-paint',buffered:true});}catch(e){}
  try{new PerformanceObserver(l=>window.__qa.shift.push(...l.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true});}catch(e){}
 });
 let rewritten=false,errors=[],failedCss=[];
 page.on('pageerror',e=>errors.push(e.message.slice(0,180)));
 page.on('requestfailed',r=>{if(r.url().includes('.css'))failedCss.push(r.url().slice(-180))});
 const cssText=fs.readFileSync(path.join(OUT,candidate.critical_file),'utf8');
 await page.route(SITE+candidate.route,async route=>{
  let html=frozenHtml;
  if(!html){const response=await route.fetch({timeout:40000});html=await response.text();}
  if(asyncCss) {
   // Native responsive poster chooses the compact asset only below the
   // enrolled mobile breakpoint. Preserve the original wide poster at 768px
   // and above; replacing it globally made tablet LCP QA invalid.
   if(HERO900 && (!ADAPTIVE_MAX || width<=ADAPTIVE_MAX)){
     html=html.replaceAll('home-mix-mad4b-1600.webp','home-mix-mad4b-900.webp');
   }
   if(DEFER_CITY){
     html=html.replace(/<link\b(?=[^>]*data-mad4b-first-city=)[^>]*>/gi,'');
   }
   const re=/<link\b[^>]*data-optimized=["']2["'][^>]*rel=["']stylesheet["'][^>]*href=["']([^"']+\/litespeed\/css\/[^"']+)["'][^>]*>/i;
   if(re.test(html)){
    const source=re.exec(html)[1];
    if(!source.includes('/'+candidate.css_file))throw new Error('SOURCE_STYLESHEET_DRIFT');
    const preload=PRELOAD?'<link rel="preload" as="style" fetchpriority="high"'+(ADAPTIVE_MAX?' media="(max-width:'+ADAPTIVE_MAX+'px)"':'')+' href="$1">':'';
    const criticalAttr=ADAPTIVE_MAX?' media="(max-width:'+ADAPTIVE_MAX+'px)"':'';
    const fullMedia=ADAPTIVE_MAX?'(min-width:'+(ADAPTIVE_MAX+1)+'px)':'print';
    const fullPriority=ADAPTIVE_MAX?'auto':PRIORITY;
    const restoreCss='<script data-no-optimize="1">(function(){var e=document.getElementById("mad4b-qa-complete");if(!e)return;window.addEventListener("load",function(){if(e.media!=="all")e.media="all"},{once:true});setTimeout(function(){if(e.media!=="all")e.media="all"},6500)})()</script>';
    html=html.replace(re,'<style id="mad4b-qa-critical"'+criticalAttr+'>'+cssText+'</style>'+preload+'<link id="mad4b-qa-complete" rel="stylesheet" media="'+fullMedia+'" fetchpriority="'+fullPriority+'" href="$1" onload="this.onload=null;this.media=\'all\'"><noscript><link rel="stylesheet" href="$1"></noscript>'+restoreCss);
    rewritten=true;
   }
  }
  await route.fulfill({status:200,contentType:'text/html; charset=UTF-8',body:html});
 });
 let result={language:candidate.lang,width,variant:asyncCss?'candidate':'baseline'};
 try{
  const response=await page.goto(SITE+candidate.route,{waitUntil:'domcontentloaded',timeout:68000});
  await page.waitForTimeout(7400);
  if(asyncCss){
   // The source's native fallback waits for onload, then a 6500ms safety
   // timer. A heavily throttled document can still be applying the full CSS
   // exactly at the old fixed QA timestamp; measure eventual stylesheet
   // completion without changing the loader or falsifying proof.
   await page.waitForFunction(()=>{
    const el=document.getElementById('mad4b-qa-complete');
    return !!el && el.media==='all' &&
      [...document.styleSheets].some(x=>x.href?.includes('/litespeed/css/'));
   },null,{timeout:5000}).catch(()=>{});
  }
  result={...result,...await page.evaluate(()=>{
    const nav=performance.getEntriesByType('navigation')[0],css=document.getElementById('mad4b-qa-complete')||document.querySelector('link[href*="/litespeed/css/"][rel="stylesheet"]');
    const rect=el=>{const r=el?.getBoundingClientRect();return r?[Math.round(r.x),Math.round(r.y),Math.round(r.width),Math.round(r.height)]:null};
    return {fcp:window.__qa.paint.find(x=>x.name==='first-contentful-paint')?.t||null,lcp:window.__qa.lcp.at(-1)?.t||null,lcpTrace:window.__qa.lcp.slice(-4),cls:+window.__qa.shift.reduce((a,b)=>a+b,0).toFixed(5),ttfb:Math.round(nav.responseStart),dcl:Math.round(nav.domContentLoadedEventEnd),styleLoaded:!![...document.styleSheets].find(x=>x.href?.includes('/litespeed/css/')),cssMedia:css?.media||'',h1:rect(document.querySelector('h1')),hero:rect(document.querySelector('.elementor-element-e991bc9')),overflow:document.documentElement.scrollWidth-innerWidth,buttonVisible:(()=>{const b=document.querySelector('#tours_search_button');return !!(b&&b.getBoundingClientRect().width&&b.getBoundingClientRect().height);})()};
  }),status:response.status(),rewritten,errors,failedCss};
  const trigger=page.locator('#tours_search_button:visible');
  if(await trigger.count()>0){
   try{
    await trigger.first().click({timeout:4000});
    const popup=page.locator('#elementor-popup-modal-41878:visible');
    result.popupOpened=await popup.count()>0;
    if(result.popupOpened) {
     const btn=popup.locator('.mad4b-ud__trigger');
     if(await btn.count()>0){
      await btn.first().click({timeout:3000});
      result.calendarOpened=await popup.locator('.flatpickr-calendar').count()>0;
     }
    }
   }catch(e){result.interactionError=String(e).slice(0,110)}
  }
 }catch(e){result.error=String(e).slice(0,350);}
 console.log('QA_CASE',JSON.stringify(result));await ctx.close();return result;
}
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 let results=[],gates={};
 for(const candidate of ITEM.candidates.filter(x=>languages.includes(x.lang))){
  let cases=[];
  // Freeze anonymous HTML once for each locale. Compare CSS only.
  const prefetch=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2,userAgent:UA,extraHTTPHeaders:{'Accept':HTML_ACCEPT,...(DIAG_BYPASS?{'X-MAD4B-BYPASS-CACHE':'1'}:{})}});
  const prePage=await prefetch.newPage();
  const htmlResponse=await prePage.goto(SITE+candidate.route+(QA_SOURCE_QUERY?'?'+QA_SOURCE_QUERY:''),{waitUntil:'domcontentloaded',timeout:65000});
  const frozenHtml=await htmlResponse.text();
  const verifiedCurrency=(htmlResponse.headers()['x-mad4b-public-ccss-currency']||'').toUpperCase();
  const verifiedMarket=htmlResponse.headers()['x-mad4b-public-ccss-market']||'';
  const prefetchStatus=htmlResponse.status();
  await prefetch.close();
  if(candidate.resolved_currency && (verifiedCurrency!==candidate.resolved_currency
     ||verifiedMarket!==candidate.resolved_market)){
    console.log('QA_MARKET_SOURCE_DRIFT',JSON.stringify({lang:candidate.lang,
      currency:verifiedCurrency,market:verifiedMarket}));
    gates[candidate.lang]={decision:'hold',reason:'country_or_currency_context_drift'};
    continue;
  }
  if(prefetchStatus!==200 || !frozenHtml.includes('/'+candidate.css_file)){
    console.log('QA_SOURCE_DRIFT',JSON.stringify({lang:candidate.lang,status:htmlResponse.status(),expected:candidate.css_file}));
    gates[candidate.lang]={decision:'hold',reason:'source_html_drift'};
    continue;
  }
  for(const width of widths){
   const baseline=await measure(browser,candidate,width,false,frozenHtml);
   const variant=await measure(browser,candidate,width,true,frozenHtml);
   let fcpGain=(baseline.fcp||0)-(variant.fcp||0);
   let lcpReg=(variant.lcp||Infinity)-(baseline.lcp||0);
   const pass= !baseline.error&&!variant.error&&variant.rewritten&&baseline.status===200&&variant.status===200
       &&variant.styleLoaded&&variant.cssMedia==='all' &&variant.failedCss?.length===0
       &&variant.errors?.length===0&&variant.overflow<=0
       &&Number.isFinite(fcpGain)&&fcpGain >= (ADAPTIVE_MAX&&width>ADAPTIVE_MAX ? -250 : 150)
       &&lcpReg<=Math.max(400,(baseline.lcp||0)*0.10)
       &&variant.cls<=0.10
       &&variant.cls <= (baseline.cls+0.02)
       &&JSON.stringify(variant.h1)===JSON.stringify(baseline.h1)
       &&JSON.stringify(variant.hero)===JSON.stringify(baseline.hero)
       &&(!baseline.popupOpened || variant.popupOpened)
       &&(!baseline.calendarOpened || variant.calendarOpened);
   cases.push({width,pass,fcpGain,lcpReg,baseline,variant});
   results.push({lang:candidate.lang,...cases.at(-1)});
   fs.writeFileSync(path.join(OUT,'verification.json'),JSON.stringify({contract:'mad4b.mobile-critical.qa.v1',tested:results.length,results,gates},null,2));
   console.log('QA_DECISION',JSON.stringify({lang:candidate.lang,width,pass,fcpGain,lcpReg,cls:variant.cls}));
  }
  gates[candidate.lang]={decision:cases.length===widths.length&&cases.every(x=>x.pass)?'passed_evidence':'hold',widths,adaptiveMaxWidth:ADAPTIVE_MAX,cssPriority:PRIORITY,fullCssPreload:PRELOAD,hero900:HERO900,deferCity:DEFER_CITY,minimumFcpGainMs:Math.min(...cases.map(x=>x.fcpGain)),worstLcpRegMs:Math.max(...cases.map(x=>x.lcpReg)),worstCls:Math.max(...cases.map(x=>x.variant.cls||1)),testedAt:new Date().toISOString()};
  fs.writeFileSync(path.join(OUT,'verification.json'),JSON.stringify({contract:'mad4b.mobile-critical.qa.v1',tested:results.length,results,gates},null,2));
 }
 await browser.close();
 // Preserve a fail-closed HOLD even when no viewport was measured.
 fs.writeFileSync(path.join(OUT,'verification.json'),JSON.stringify({contract:'mad4b.mobile-critical.qa.v1',tested:results.length,results,gates},null,2));
 console.log('QA_COMPLETE',JSON.stringify({tested:results.length,gates}));
})().catch(e=>{console.log('QA_FATAL',String(e.stack||e).slice(0,1000));process.exitCode=1});
