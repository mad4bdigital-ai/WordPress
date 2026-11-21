'use strict';
// Performance QA on original LIVE CSS resource URLs. Only anonymous HTML is
// frozen and rewritten within Chromium. No WordPress or CDN mutation.
const fs=require('fs'),path=require('path'),crypto=require('crypto');
const {chromium}=require('playwright');
const core=require('./verify-ordered-css.js');
const {needsReobservation}=require('./lcp-observation-window.js');
const {manifest,summary,source,origin,pathname,OUT,sha}=core;
const candidate=JSON.parse(fs.readFileSync(path.join(OUT,'ordered-critical-candidate.json'),'utf8'));
const smoke=process.env.MAD4B_SMOKE_WIDTH?Number(process.env.MAD4B_SMOKE_WIDTH):null;
if(smoke!==null && ![320,390,414,767,768].includes(smoke))
  throw Error('INVALID_SINGLE_WIDTH_SMOKE_PROBE');
const widths=smoke?[smoke]:[320,390,414,767,768];
if(candidate.manifest_sha256!==manifest.manifest_sha256 ||
  candidate.critical_slots.length!==summary.chunks.length)
 throw Error('SOURCE_MANIFEST_CRITICAL_MISMATCH');
const critical=new Map();
for(let i=0;i<summary.chunks.length;i++){
 const chunk=summary.chunks[i],a=candidate.critical_slots[i];
 if(a.slot!==chunk.slot||a.source_sha256!==chunk.sha256)throw Error('CRITICAL_ORDER_DRIFT');
 const css=a.critical_bytes?fs.readFileSync(path.join(OUT,a.critical_file),'utf8'):'';
 if(sha(css)!==a.critical_sha256||Buffer.byteLength(css)!==a.critical_bytes||css.includes('</style'))
  throw Error('UNTRUSTED_CRITICAL_BYTE');
 critical.set(a.slot,css);
}
function rewriteNative(width){
 if(width>=768)return source;
 const end=source.toLowerCase().indexOf('</head>');
 if(end<0)throw Error('NO_ORIGINAL_HEAD');
 let i=0,slot=0,applied=new Set();
 const head=source.slice(0,end).replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>|<link\b[^>]*>/gi,tag=>{
  if(/^<style/i.test(tag)){slot++;return tag;}
  const rel=tag.match(/\brel=["']([^"']+)["']/i)?.[1]?.toLowerCase().split(/\s+/)||[];
  if(!rel.includes('stylesheet'))return tag;
  const href=tag.match(/\bhref=["']([^"']+)["']/i)?.[1]?.replace(/&amp;/g,'&');
  const media=tag.match(/\bmedia=["']([^"']+)["']/i)?.[1]?.trim().toLowerCase()||'all';
  const fact=manifest.files[i++];
  if(!fact||new URL(href,origin).href!==fact.href||fact.media!==media||fact.slot!==slot)
    throw Error('ORIGINAL_STYLESHEET_SOURCE_DRIFT_'+i);
  if(/\bonload\s*=|\bonerror\s*=/.test(tag))throw Error('UNPROVEN_NATIVE_LOAD_HANDLER');
  let criticalStyle='';
  if(!applied.has(slot)){
    applied.add(slot);
    const css=critical.get(slot);
    if(css)criticalStyle='<style data-mad4b-critical-slot="'+slot+'" media="(max-width:767px)">'+css+'</style>';
  }
  // Defer every original stylesheet rather than one combined pseudo URL.
  // Its original media condition is restored on load. Document order stays
  // unchanged, including inline styles between cascade slots.
  const clean=tag.replace(/\smedia\s*=\s*(["'])[^"']*\1/gi,'').replace(/>$/,'');
  return criticalStyle+clean+' data-mad4b-full-slot="'+slot+'" data-mad4b-original-media="'+media+'" media="(min-width:768px)" onload="this.onload=null;this.media=\''+media+'\'">';
 });
 if(i!==manifest.files.length||applied.size!==summary.chunks.length)
  throw Error('NATIVE_CSS_ORDERED_REPLACEMENT_INCOMPLETE');
 const html=head+source.slice(end);
 // Conservative eventual restoration protects slow CSS loads. Links with
 // ordinary onload already restore to the original screen/print media.
 return html.replace('</head>','<script data-no-optimize="1">(function(){window.addEventListener("load",function(){document.querySelectorAll("link[data-mad4b-full-slot]").forEach(function(e){if(e.media==="(min-width:768px)")e.media=e.getAttribute("data-mad4b-original-media")||"all"})},{once:true})})()</script></head>');
}
if(process.env.MAD4B_VALIDATE_ONLY==='1'){
 for(const width of [320,390,414,767,768])rewriteNative(width);
 console.log('NATIVE_CRITICAL_REWRITE_VALIDATED',JSON.stringify({sourceFiles:manifest.files.length,
   slots:summary.chunks.length,criticalBytes:candidate.critical_total_bytes}));
 process.exit(0);
}
const ua='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
async function measure(browser,width,variant,minObservedMs=0){
 const ctx=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,userAgent:ua,
    extraHTTPHeaders:{'Accept':'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'}});
 await ctx.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(route.request().isNavigationRequest()&&u.origin===origin&&u.pathname===pathname)
    return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:variant?rewriteNative(width):source});
  return route.continue();
 });
 const page=await ctx.newPage(),failed=[],errors=[];
 const cdp=await ctx.newCDPSession(page);
 await cdp.send('Network.enable');
 await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1.6*1024*1024/8,uploadThroughput:750*1024/8});
 await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
 await page.addInitScript(()=>{
  // Non-PII resource timing records make large late image LCP regression
  // actionable without a second Chromium run on a resource-constrained host.
  performance.setResourceTimingBufferSize(1200);
  window.__crit={paint:[],lcp:[],cls:[]};
  new PerformanceObserver(l=>window.__crit.paint.push(...l.getEntries().map(e=>({name:e.name,at:e.startTime})))).observe({type:'paint',buffered:true});
  try{new PerformanceObserver(l=>window.__crit.lcp.push(...l.getEntries().map(e=>({
    t:Math.round(e.startTime),size:Math.round(e.size||0),
    tag:e.element?.tagName||null,
    id:typeof e.element?.id==='string'?e.element.id.slice(0,80):null,
    className:typeof e.element?.className==='string'?e.element.className.slice(0,120):null,
    resource:e.url?e.url.split('?')[0].split('/').at(-1)?.slice(0,120):null
  })))).observe({type:'largest-contentful-paint',buffered:true})}catch(e){}
  try{new PerformanceObserver(l=>window.__crit.cls.push(...l.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true})}catch(e){}
 });
 page.on('pageerror',e=>errors.push(String(e.message).slice(0,150)));
 page.on('requestfailed',r=>{if(/\.css(?:$|\?)/.test(r.url()))failed.push(r.url().slice(-90))});
 try{
  const navStartedAt=Date.now();
  const response=await page.goto(origin+pathname,{waitUntil:'domcontentloaded',timeout:68000});
  // Observe both peers at a comparable navigation-relative horizon, rather
  // than a fixed delay after their potentially different DCL timings.
  await page.waitForTimeout(Math.max(8000,minObservedMs-(Date.now()-navStartedAt)));
  const measures=await page.evaluate(()=>{
   const fold=[];
   const all=[...document.querySelectorAll('body *')].slice(0,650);
   for(let n=0;n<all.length;n++){
    const x=all[n],b=x.getBoundingClientRect();
    if(b.width<1||b.height<1||b.top>1000||b.bottom<-100)continue;
    const c=getComputedStyle(x);
    fold.push({n,tag:x.tagName,box:[b.left,b.top,b.width,b.height].map(Math.round),
      css:[c.display,c.position,c.fontSize,c.color,c.fontWeight,c.backgroundColor,c.visibility]});
    if(fold.length>=90)break;
   }
   const links=[...document.querySelectorAll('link[data-mad4b-full-slot]')];
   const resourceTiming=performance.getEntriesByType('resource');
   const lastLcp=window.__crit.lcp.at(-1);
   const lcpFilename=lastLcp&&lastLcp.resource?lastLcp.resource:'';
   function basename(url){
     try{return new URL(url,location.origin).pathname.split('/').pop()||''}
     catch(e){return ''}
   }
   const matchedImage=lcpFilename?resourceTiming.filter(r=>{
     const n=basename(r.name);
     try{return n===lcpFilename||decodeURIComponent(n)===decodeURIComponent(lcpFilename)}
     catch(e){return n===lcpFilename}
   }):[];
   const cssRequests=resourceTiming.filter(r=>{
     try{return /\.css$/i.test(new URL(r.name,location.origin).pathname)}
     catch(e){return false}
   });
   const imageTiming={requests:matchedImage.length,
     first_start_ms:matchedImage.length?Math.round(Math.min(...matchedImage.map(r=>r.startTime))):null,
     last_response_end_ms:matchedImage.length?Math.round(Math.max(...matchedImage.map(r=>r.responseEnd))):null};
   const cssTiming={requests:cssRequests.length,
     last_response_end_ms:cssRequests.length?Math.round(Math.max(...cssRequests.map(r=>r.responseEnd))):null};
   const hero=document.querySelector('.elementor-element-ef55af8');
   const heroStyle=hero?getComputedStyle(hero):null;
   const heroState=heroStyle?{display:heroStyle.display,opacity:heroStyle.opacity,
      visibility:heroStyle.visibility,
      animation_name:heroStyle.animationName,
      animation_duration:heroStyle.animationDuration}:null;
   return {lcp_image_resource_timing:imageTiming,
      css_resource_timing:cssTiming,lcp_hero_state:heroState,
      fcp:Math.round(window.__crit.paint.find(e=>e.name==='first-contentful-paint')?.at||0),
      lcp:Math.round(window.__crit.lcp.at(-1)?.t||0),
      observedUntilMs:Math.round(performance.now()),lcpTrace:window.__crit.lcp.slice(-8),
      cls:window.__crit.cls.reduce((a,b)=>a+b,0),fold,
      links:links.length,linksRestored:links.every(e=>e.media!=='(min-width:768px)'),
      overflow:document.documentElement.scrollWidth-innerWidth};
  });
  const trigger=page.locator('#tours_search_button:visible');let popup=null;
  if(await trigger.count()){
   try{await trigger.first().click({timeout:4000});popup=await page.locator('#elementor-popup-modal-41878:visible').count()>0;}
   catch(e){popup=false;}
  }
  return {...measures,status:response.status(),errors,failed,popup,variant,width};
 }finally{await ctx.close()}
}
function layout(a,b){
 if(a.fold.length!==b.fold.length||a.overflow!==b.overflow)return false;
 return a.fold.every((x,i)=>{
  const y=b.fold[i];
  return x.n===y.n&&x.tag===y.tag&&JSON.stringify(x.css)===JSON.stringify(y.css)
      &&x.box.every((v,j)=>Math.abs(v-y.box[j])<=2);
 });
}
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 const results=[];
 try {
  for(const width of widths){
   let a=await measure(browser,width,false),b=await measure(browser,width,true);
   // An apparent 21s regression can be a baseline stopped before its
   // own late image LCP. Re-observe only the earlier peer, up to twice.
   // An unresolved unequal observation horizon always FAILS CLOSED.
   for(let replay=0;replay<2;replay++){
    if(needsReobservation(a,b)){
     const until=b.observedUntilMs+600;
     console.log('NATIVE_LCP_EQUAL_WINDOW_RECHECK',JSON.stringify({width,side:'original',until,replay}));
     a=await measure(browser,width,false,until);
    }else if(needsReobservation(b,a)){
     const until=a.observedUntilMs+600;
     console.log('NATIVE_LCP_EQUAL_WINDOW_RECHECK',JSON.stringify({width,side:'critical',until,replay}));
     b=await measure(browser,width,true,until);
    }else break;
   }
   const unequalLateLcp=needsReobservation(a,b)||needsReobservation(b,a);
   const fcpGain=a.fcp-b.fcp,lcpReg=b.lcp-a.lcp;
   const same=layout(a,b);
   let pass=!unequalLateLcp&&same&&b.status===200&&a.status===200&&b.failed.length===0&&
    b.errors.length===0&&b.cls<=Math.max(.10,a.cls+.02)
    &&(!a.popup||b.popup)&&(width>=768||(b.links===manifest.files.length&&b.linksRestored&&fcpGain>=150))
    &&lcpReg<=Math.max(400,a.lcp*.1);
   // A single slow original-source observation can falsely approve a
   // candidate whose LCP always takes ~9s. Confirm a 320px PASS with the
   // opposite Chromium navigation order (critical first, original second).
   // Issuance is still release-first: this is *post-release* QA only.
   let peerOrderConfirmation=null;
   if(width===320 && pass){
    let reverseB=await measure(browser,width,true);
    let reverseA=await measure(browser,width,false);
    // The same navigation-relative source/critical LCP horizon applies.
    for(let replay=0;replay<2;replay++){
     if(needsReobservation(reverseA,reverseB)){
      reverseA=await measure(browser,width,false,reverseB.observedUntilMs+600);
     }else if(needsReobservation(reverseB,reverseA)){
      reverseB=await measure(browser,width,true,reverseA.observedUntilMs+600);
     }else break;
    }
    const reverseUnequal=needsReobservation(reverseA,reverseB)||
                         needsReobservation(reverseB,reverseA);
    const reverseSame=layout(reverseA,reverseB);
    const reverseFcpGain=reverseA.fcp-reverseB.fcp;
    const reverseLcpReg=reverseB.lcp-reverseA.lcp;
    const reversePass=!reverseUnequal&&reverseSame&&
      reverseA.status===200&&reverseB.status===200&&
      reverseA.lcp>0&&reverseB.lcp>0&&
      reverseB.failed.length===0&&reverseB.errors.length===0&&
      reverseB.cls<=Math.max(.10,reverseA.cls+.02)&&
      (!reverseA.popup||reverseB.popup)&&
      reverseB.links===manifest.files.length&&
      reverseB.linksRestored&&reverseFcpGain>=150&&
      reverseLcpReg<=Math.max(400,reverseA.lcp*.1);
    peerOrderConfirmation={
      critical_first:true,pass:reversePass,
      fcp_gain_ms:reverseFcpGain,lcp_reg_ms:reverseLcpReg,
      source_lcp_ms:reverseA.lcp,critical_lcp_ms:reverseB.lcp,
      layout_pass:reverseSame,unequal_late_lcp:reverseUnequal,
      real_css_links_loaded:reverseB.linksRestored};
    // A source result only becomes certified if BOTH independent orderings
    // meet the exact same original CSS restoration, layout and LCP bounds.
    pass=pass&&reversePass;
    console.log('NATIVE_LCP_PEER_ORDER_CONFIRMATION',
      JSON.stringify({width,pass:reversePass,reverseFcpGain,reverseLcpReg}));
   }
   results.push({width,pass,fcp_gain_ms:fcpGain,lcp_reg_ms:lcpReg,
      layout_pass:same,unequal_late_lcp:unequalLateLcp,
      peer_order_confirmation:peerOrderConfirmation,
      source:{fcp:a.fcp,lcp:a.lcp,cls:a.cls,popup:a.popup,
              observed_until_ms:a.observedUntilMs,lcp_trace:a.lcpTrace,
              lcp_image_resource_timing:a.lcp_image_resource_timing,
              css_resource_timing:a.css_resource_timing,
              lcp_hero_state:a.lcp_hero_state},
      critical:{fcp:b.fcp,lcp:b.lcp,cls:b.cls,popup:b.popup,
                observed_until_ms:b.observedUntilMs,lcp_trace:b.lcpTrace,
              lcp_image_resource_timing:b.lcp_image_resource_timing,
              css_resource_timing:b.css_resource_timing,
              lcp_hero_state:b.lcp_hero_state},
      real_css_links_loaded:b.linksRestored,stylesheet_count:b.links,
      failed_css:b.failed,errors:b.errors});
   console.log('NATIVE_ORDERED_CRITICAL_QA',JSON.stringify({width,pass,fcpGain,lcpReg,layout:same,cssLinks:b.links,cssRestored:b.linksRestored}));
  }
 }finally{await browser.close()}
 const report={contract:'mad4b.public-css.ordered-critical-real-origin-qa.v1',
     host:manifest.host,path:pathname,manifest_sha256:manifest.manifest_sha256,
     critical_candidate_sha256:sha(fs.readFileSync(path.join(OUT,'ordered-critical-candidate.json'))),
     source_css_mode:'original-css-urls',viewport_results:results,
     pass:results.length===5&&results.every(x=>x.pass),
     smoke_only:smoke!==null,
     public_certificate_issued:false,fastcgi_purged:false};
 fs.writeFileSync(path.join(OUT,smoke?'ordered-native-critical-smoke.json':'ordered-native-critical-qa.json'),JSON.stringify(report,null,2));
 if(!report.pass && !smoke)process.exitCode=2;
})().catch(e=>{console.error('HOLD_NATIVE_ORDERED_CRITICAL',String(e).slice(0,280));process.exitCode=4});
