'use strict';
// Performance and interaction QA for a candidate already captured by the
// original ordered compiler. The browser intercepts HTML/CSS in memory:
// NEVER modifies live WordPress, public certificates, or FastCGI.
const fs=require('fs'),path=require('path');
const {chromium}=require('playwright');
const original=require('./verify-ordered-css.js');
const {manifest,summary,source,origin,pathname,OUT,styleUrls,sha,rewrite}=original;
const candidate=JSON.parse(fs.readFileSync(path.join(OUT,'ordered-critical-candidate.json'),'utf8'));
const widths=(process.env.MAD4B_WIDTHS||'320,390,414,767,768').split(',').map(Number);
const VIEWPORTS=[320,390,414,767,768];
if(JSON.stringify(widths)!==JSON.stringify(VIEWPORTS))throw Error('FIVE_VIEWPORT_QA_REQUIRED');
if(candidate.manifest_sha256!==manifest.manifest_sha256
   ||candidate.path!==pathname||candidate.host!==manifest.host
   ||candidate.viewports.join(',')!=='320,390,414,768'
   ||candidate.critical_slots.length!==summary.chunks.length
   ||candidate.critical_total_bytes>1048576
   ||candidate.full_browser_performance_qa_complete!==false
   ||candidate.public_route_certificate_issued!==false)throw Error('UNTRUSTED_CRITICAL_CANDIDATE_IDENTITY');
const critical=new Map();
for(let i=0;i<summary.chunks.length;i++){
 const owned=summary.chunks[i],proof=candidate.critical_slots[i];
 if(owned.slot!==proof.slot||owned.sha256!==proof.source_sha256||
    owned.bytes!==proof.source_bytes)throw Error('CHUNK_ORDER_OR_SOURCE_SHA_MISMATCH');
 let text='';
 if(proof.critical_bytes){
   if(!/^[a-f0-9]{64}\.css$/.test(proof.critical_file))throw Error('CRITICAL_FILENAME_UNTRUSTED');
   text=fs.readFileSync(path.join(OUT,proof.critical_file),'utf8');
   if(sha(text)!==proof.critical_sha256||
      Buffer.byteLength(text)!==proof.critical_bytes||text.toLowerCase().includes('</style'))
     throw Error('CRITICAL_CHUNK_SHA_OR_HTML_UNSAFE');
 }
 critical.set(owned.slot,text);
}
const base=rewrite(source);
function candidateHtml(width){
 if(width>767)return base;
 const matched=new Set();
 const converted=base.replace(/<link\b[^>]*data-mad4b-qa-compiled=["']1["'][^>]*>/g,tag=>{
   const href=tag.match(/\bhref=["']([^"']+)["']/i)?.[1];
   if(!href)throw Error('MISSING_QA_COMPILED_URL');
   const chunk=summary.chunks.find(x=>href.endsWith('/__mad4b_qa_compiled_'+x.sha256+'.css'));
   if(!chunk||matched.has(chunk.slot))throw Error('UNBOUNDED_OR_DUPLICATE_CRITICAL_SLOT');
   matched.add(chunk.slot);
   const css=critical.get(chunk.slot);
   const id='mad4b-qa-full-slot-'+chunk.slot;
   const restore='<script data-no-optimize="1">(function(){var e=document.getElementById("'+id+'");if(!e)return;window.addEventListener("load",function(){if(e.media!=="all")e.media="all"},{once:true});setTimeout(function(){if(e.media!=="all")e.media="all"},6500)})()</script>';
   return (css?'<style data-mad4b-qa-critical-slot="'+chunk.slot+'" media="(max-width:767px)">'+css+'</style>':'')+
     '<link rel="preload" as="style" media="(max-width:767px)" href="'+href+'">'+
     '<link id="'+id+'" rel="stylesheet" href="'+href+'" media="(min-width:768px)" onload="this.onload=null;this.media=\'all\'">'+
     '<noscript><link rel="stylesheet" href="'+href+'"></noscript>'+restore;
 });
 if(matched.size!==summary.chunks.length)throw Error('NOT_ALL_CRITICAL_SLOTS_MATCHED');
 return converted;
}
const ua='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
async function measure(browser,width,variant){
 const ctx=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,userAgent:ua});
 await ctx.route('**/*',async route=>{
  const url=new URL(route.request().url());
  if(url.origin===origin&&styleUrls.has(url.pathname))
    return route.fulfill({status:200,contentType:'text/css; charset=utf-8',body:fs.readFileSync(styleUrls.get(url.pathname))});
  if(route.request().isNavigationRequest()&&url.origin===origin&&url.pathname===pathname)
    return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:variant?candidateHtml(width):base});
  return route.continue();
 });
 const page=await ctx.newPage(),failed=[],errors=[];
 const cdp=await ctx.newCDPSession(page);
 await cdp.send('Network.enable');
 await cdp.send('Network.emulateNetworkConditions',
     {offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:750*1024/8});
 await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
 await page.addInitScript(()=>{
  window.__cssqa={paint:[],lcp:[],cls:[]};
  new PerformanceObserver(l=>window.__cssqa.paint.push(...l.getEntries().map(e=>({n:e.name,t:Math.round(e.startTime)})))).observe({type:'paint',buffered:true});
  try{new PerformanceObserver(l=>window.__cssqa.lcp.push(...l.getEntries().map(e=>({t:Math.round(e.startTime)})))).observe({type:'largest-contentful-paint',buffered:true});}catch(e){}
  try{new PerformanceObserver(l=>window.__cssqa.cls.push(...l.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true});}catch(e){}
 });
 page.on('pageerror',e=>errors.push(e.message.slice(0,180)));
 page.on('requestfailed',r=>{if(/\.css(?:$|\?)/.test(r.url()))failed.push(r.url().slice(-130))});
 try{
  const nav=await page.goto(origin+pathname,{waitUntil:'domcontentloaded',timeout:68000});
  await page.waitForTimeout(7500);
  const data=await page.evaluate(()=>{
    const all=[...document.querySelectorAll('body *')].slice(0,650),fold=[];
    for(let n=0;n<all.length;n++){
      const e=all[n],b=e.getBoundingClientRect();
      if(b.width<1||b.height<1||b.top>1000||b.bottom<-100)continue;
      const c=getComputedStyle(e);
      fold.push({n,tag:e.tagName,box:[b.left,b.top,b.width,b.height].map(Math.round),
         css:[c.display,c.position,c.fontSize,c.fontWeight,c.color,c.backgroundColor,c.visibility,c.opacity]});
      if(fold.length>=90)break;
    }
    return {fcp:window.__cssqa.paint.find(x=>x.n==='first-contentful-paint')?.t||0,
      lcp:window.__cssqa.lcp.at(-1)?.t||0,
      cls:window.__cssqa.cls.reduce((a,b)=>a+b,0),
      viewportWidth:innerWidth,fold,overflow:document.documentElement.scrollWidth-innerWidth,
      fullCssLoaded:[...document.querySelectorAll('link[id^="mad4b-qa-full-slot-"]')].every(e=>e.media==='all'),
      allCssLoaded:[...document.styleSheets].filter(e=>e.href?.includes('__mad4b_qa_compiled_')).length>0};
  });
  let interaction={};
  const trigger=page.locator('#tours_search_button:visible');
  if(await trigger.count()){
   try{
    await trigger.first().click({timeout:3000});
    interaction.searchModalOpened=await page.locator('#elementor-popup-modal-41878:visible').count()>0;
   }catch(e){interaction.searchModalError=String(e).slice(0,100)}
  }
  return {width,variant,status:nav.status(),...data,interaction,failed,errors};
 }finally{await ctx.close()}
}
function compare(a,b){
 if(a.fold.length!==b.fold.length)return {sameLayout:false,reason:'different-fold-elements'};
 let mismatches=0;
 for(let i=0;i<a.fold.length;i++){
  const x=a.fold[i],y=b.fold[i];
  if(x.n!==y.n||x.tag!==y.tag||JSON.stringify(x.css)!==JSON.stringify(y.css)
     ||x.box.some((v,j)=>Math.abs(v-y.box[j])>2))mismatches++;
 }
 return {sameLayout:mismatches===0&&a.overflow===b.overflow,checked:a.fold.length,mismatches};
}
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 let rows=[];
 try{
  for(const width of widths){
   const native=await measure(browser,width,false);
   const adaptive=await measure(browser,width,true);
   const cmp=compare(native,adaptive);
   const fcpGain=native.fcp-adaptive.fcp;
   const lcpReg=adaptive.lcp-native.lcp;
   const valid=cmp.sameLayout&&native.status===200&&adaptive.status===200&&
     native.failed.length===0&&adaptive.failed.length===0&&
     native.errors.length===0&&adaptive.errors.length===0&&
     adaptive.allCssLoaded&&(width>767||adaptive.fullCssLoaded)&&
     adaptive.cls<=Math.max(.1,native.cls+.02)&&
     (native.interaction.searchModalOpened!==true||adaptive.interaction.searchModalOpened===true)&&
     (width>767||fcpGain>=150)&&lcpReg<=Math.max(400,native.lcp*.1);
   rows.push({width,pass:valid,layout:cmp,source:{fcp:native.fcp,lcp:native.lcp,cls:native.cls,interaction:native.interaction},
       critical:{fcp:adaptive.fcp,lcp:adaptive.lcp,cls:adaptive.cls,interaction:adaptive.interaction},
       fcp_gain_ms:fcpGain,lcp_reg_ms:lcpReg,
       stylesheet_loaded:adaptive.fullCssLoaded,failed_css:adaptive.failed,
       page_errors:adaptive.errors});
   console.log('MULTI_CSS_CRITICAL_QA',JSON.stringify({width,pass:valid,fcpGain,lcpReg,layout:cmp.sameLayout,mismatches:cmp.mismatches}));
  }
 }finally{await browser.close()}
 const passed=rows.length===5&&rows.every(r=>r.pass);
 const report={contract:'mad4b.public-css.ordered-critical-performance-qa.v1',
   source_manifest_sha256:manifest.manifest_sha256,
   critical_candidate_sha256:sha(fs.readFileSync(path.join(OUT,'ordered-critical-candidate.json'))),
   host:manifest.host,path:pathname,viewports:widths,pass:passed,
   results:rows,certificate_published:false,fastcgi_purged:false};
 fs.writeFileSync(path.join(OUT,'ordered-critical-performance-qa.json'),JSON.stringify(report,null,2));
 if(!passed)process.exitCode=2;
})().catch(e=>{console.error('HOLD_MULTI_CSS_CRITICAL_QA',String(e).slice(0,300));process.exitCode=4});
