'use strict';
// Reuse the existing first-party manifest, original CSS selection algorithm,
// and the trusted HTML rewrite. All outputs are private QA artifacts.
// Does NOT write WordPress CSS, certificates, cache headers or live HTML.
const fs=require('fs'),path=require('path'),crypto=require('crypto');
const {chromium}=require('playwright');
const original=require('./verify-ordered-css.js');
const {assemble}=require('./assemble.js');
const {manifest,summary,source,origin,pathname,OUT,styleUrls,sha,rewrite}=original;
const widths=[320,390,414,768];
const allowedWidths=(process.env.MAD4B_WIDTHS||'320,390,414,768').split(',').map(Number);
if(JSON.stringify(allowedWidths)!==JSON.stringify(widths))
  throw Error('FOUR_SOURCE_CAPTURES_REQUIRED');
if(summary.manifest_sha256!==manifest.manifest_sha256)
  throw Error('SOURCE_MANIFEST_CAPTURE_MISMATCH');
if(!Array.isArray(summary.chunks)||summary.chunks.length===0||summary.chunks.length>256)
  throw Error('SOURCE_CHUNKS_INVALID');
const chunks=summary.chunks.map(x=>{
 const file=path.join(OUT,x.sha256+'.css');
 const text=fs.readFileSync(file,'utf8');
 if(!/^[a-f0-9]{64}$/.test(x.sha256)||sha(text)!==x.sha256||
    Buffer.byteLength(text)!==x.bytes)throw Error('COMPILED_CSS_CHUNK_SHA_MISMATCH');
 return {...x,url:origin+'/__mad4b_qa_compiled_'+x.sha256+'.css',source:text};
});
const rewritten=rewrite(source);
const mobileUA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
async function capture(browser,width){
 const ctx=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,userAgent:mobileUA});
 await ctx.route('**/*',async route=>{
  const url=new URL(route.request().url());
  if(url.origin===origin&&styleUrls.has(url.pathname))
   return route.fulfill({status:200,contentType:'text/css; charset=utf-8',
           body:fs.readFileSync(styleUrls.get(url.pathname))});
  if(route.request().isNavigationRequest()&&url.origin===origin&&url.pathname===pathname)
   return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:rewritten});
  return route.continue();
 });
 const page=await ctx.newPage(),errors=[],failed=[];
 page.on('pageerror',e=>errors.push(e.message.slice(0,150)));
 page.on('requestfailed',r=>{if(/\.css(?:$|\?)/.test(r.url()))failed.push(r.url().slice(-130))});
 let observation;
 try{
  await page.coverage.startCSSCoverage({resetOnNavigation:false});
  const r=await page.goto(origin+pathname,{waitUntil:'domcontentloaded',timeout:55000});
  await page.waitForTimeout(1100);
  const css=await page.coverage.stopCSSCoverage();
  const map=new Map(css.map(x=>[x.url,x]));
  if(r.status()!==200||failed.length||errors.length)throw Error('PUBLIC_CSS_CAPTURE_BROWSER_FAILED');
  const ranges={};
  for(const chunk of chunks){
   const v=map.get(chunk.url);
   if(!v||sha(v.text)!==chunk.sha256||!Array.isArray(v.ranges))
    throw Error('COMPILED_CSS_COVERAGE_SOURCE_IDENTITY_DRIFT:'+chunk.slot);
   ranges[String(chunk.slot)]=v.ranges;
  }
  observation={width,status:r.status(),ranges,errors,failed,
   docGraph:await page.evaluate(()=>[...new Set([...document.querySelectorAll('[class]')]
     .flatMap(el=>(el.getAttribute('class')||'').match(/(?:^|\s)elementor-\d+/g)||[]))].sort()),
   route:pathname,manifest_sha256:manifest.manifest_sha256};
 }finally{await ctx.close()}
 return observation;
}
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 const observations=[];
 try{
  for(const width of widths){
   const row=await capture(browser,width);
   observations.push(row);
   console.log('MULTI_CSS_COVERAGE_CAPTURE',JSON.stringify({width,slots:Object.keys(row.ranges).length}));
  }
 }finally{await browser.close()}
 const candidates=[];let total=0;
 for(const chunk of chunks){
  const ranges=observations.flatMap(o=>o.ranges[String(chunk.slot)]||[]);
  const selected=assemble(chunk.source,ranges);
  const css=selected.css;
  const bytes=Buffer.byteLength(css);
  // If nothing is genuinely used, emit no critical CSS and retain the
  // original native source for this slot rather than inventing coverage.
  if(selected.stats.failed||bytes>1048576||css.includes('</style')||
      !Number.isFinite(bytes)||bytes<0)throw Error('UNTRUSTED_MULTI_CSS_CRITICAL_ASSEMBLY_SLOT:'+chunk.slot);
  if(bytes)total+=bytes;
  if(total>1048576)throw Error('MULTI_CSS_CRITICAL_EXCEEDS_ONE_MIB_LIMIT');
  const hash=sha(css);
  if(bytes)fs.writeFileSync(path.join(OUT,hash+'.css'),css);
  candidates.push({slot:chunk.slot,source_sha256:chunk.sha256,
      source_bytes:chunk.bytes,critical_sha256:hash,
      critical_bytes:bytes,critical_file:bytes?hash+'.css':null,
      stats:selected.stats});
 }
 const report={contract:'mad4b.public-css.ordered-critical-candidate.v1',
   manifest_sha256:manifest.manifest_sha256,host:manifest.host,path:pathname,
   viewports:widths,source_cascade_slots:chunks.length,
   critical_total_bytes:total,critical_slots:candidates,
   observation_count:observations.length,
   full_browser_performance_qa_complete:false,
   public_route_certificate_issued:false,
   source_css_unmodified:true};
 fs.writeFileSync(path.join(OUT,'ordered-critical-candidate.json'),JSON.stringify(report,null,2));
 console.log('MULTI_CSS_CRITICAL_CANDIDATE',JSON.stringify({slots:candidates.length,bytes:total,sha:sha(JSON.stringify(candidates))}));
})().catch(e=>{console.error('HOLD_MULTI_CSS_CRITICAL_CAPTURE',String(e).slice(0,250));process.exitCode=4});
