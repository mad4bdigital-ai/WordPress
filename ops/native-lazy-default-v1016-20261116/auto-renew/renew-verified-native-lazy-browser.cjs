'use strict';
/*
 * First-party browser check. No custom HTTP headers, no WordPress writes,
 * no hidden override plugin. The query-string control is native SSR because
 * the public lazy plugin deliberately excludes nontracking query parameters.
 */
const {chromium}=require('playwright');
const crypto=require('crypto');
const widths=[320,390,767,768,1024,1920];
const site='https://tours.dev.mad4b.com';
const widget='.elementor-element-78d62e0';
const expectedQuery='291';
const documentIds={en:'25927',es:'26001'};
const ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
const MOBILE='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const DESKTOP='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';
const sha=value=>crypto.createHash('sha256').update(value).digest('hex');
const selector=(locale)=>site+(locale==='en'?'/':'/'+locale+'/');
const selected=(process.env.MAD4B_LAZY_RENEW_CASES||'all');
const allCases=[];
for(const locale of ['en','es'])for(const width of widths){
 if(selected==='all'||selected===locale+':'+width)allCases.push({locale,width});
}
if(!allCases.length)throw Error('No valid audit cases');
function canonicalText(item){
 const walk=document.createTreeWalker(item,NodeFilter.SHOW_TEXT);
 let p;const texts=[];
 while((p=walk.nextNode())){
  const parent=p.parentElement;
  if(!parent||parent.closest('script,style,noscript,template'))continue;
  const word=(p.nodeValue||'').replace(/\s+/g,' ').trim();
  if(word)texts.push(word);
 }
 return texts.join(' ');
}
async function render(browser,locale,width,isLazy){
 const mobile=width<=768;
 const ctx=await browser.newContext({viewport:{width,height:844},
  isMobile:mobile,hasTouch:mobile,deviceScaleFactor:1,
  userAgent:mobile?MOBILE:DESKTOP,extraHTTPHeaders:{Accept:ACCEPT}});
 const page=await ctx.newPage(),errors=[],ajaxRequests=[],ajaxReplies=[];
 page.on('pageerror',e=>errors.push(String(e).slice(0,100)));
 page.on('request',r=>{
  const raw=r.postData()||'';
  if(r.method()!=='POST'||!raw.includes('action=jet_engine_ajax'))return;
  const fields=new URLSearchParams(raw);
  ajaxRequests.push({queryId:fields.get('query[query_id]')||'',
   documentId:fields.get('page_settings[post_id]')||'',
   signedQuery:Boolean(fields.get('query[signature]')),
   signedWidget:Boolean(fields.get('widget_settings[_jet_engine_settings_signature]'))});
 });
 page.on('response',r=>{
  const req=r.request(),raw=req.postData()||'';
  if(req.method()==='POST'&&raw.includes('action=jet_engine_ajax'))
   ajaxReplies.push(r.status());
 });
 await page.addInitScript(()=>{
  window.__mad4bObservedCls=0;
  try{new PerformanceObserver(items=>{
   for(const it of items.getEntries())if(!it.hadRecentInput)window.__mad4bObservedCls+=it.value;
  }).observe({type:'layout-shift',buffered:true})}catch(e){}
 });
 const url=selector(locale)+(isLazy?'':'?mad4b_native_audit_ssr=1');
 try{
  const resp=await page.goto(url,{waitUntil:'domcontentloaded',timeout:42000});
  await page.waitForTimeout(600);
  const loc=page.locator(widget).first();
  if(await loc.count()!==1)throw Error('Expected exactly one native Elementor widget');
  const initial=await loc.evaluate(el=>{
   const rect=el.getBoundingClientRect(),g=el.querySelector('.jet-listing-grid');
   return {cards:el.querySelectorAll('.jet-listing-grid__item').length,
    lazy:!!g?.classList.contains('jet-listing-grid--lazy-load'),
    width:Math.round(rect.width),height:Math.round(rect.height),
    reservation:el.classList.contains('mad4b-native-measured-lazy')};
  });
  await loc.scrollIntoViewIfNeeded({timeout:15000});
  if(isLazy){
   await page.waitForFunction(sel=>{
    const el=document.querySelector(sel);
    return !!el&&el.querySelectorAll('.jet-listing-grid__item').length>0
       &&!!el.querySelector('.jet-listing-grid--lazy-load-completed');
   },widget,{timeout:21000});
  }
  await page.waitForTimeout(650);
  const result=await loc.evaluate(el=>{
   const items=[...el.querySelectorAll('.jet-listing-grid__item')];
   const rect=el.getBoundingClientRect();
   const texts=[],links=[];
   for(const item of items){
    const walk=document.createTreeWalker(item,NodeFilter.SHOW_TEXT);
    let part;const lines=[];
    while((part=walk.nextNode())){
     const parent=part.parentElement;
     if(!parent||parent.closest('script,style,noscript,template'))continue;
     const word=(part.nodeValue||'').replace(/\s+/g,' ').trim();
     if(word)lines.push(word);
    }
    texts.push(lines.join(' '));
    links.push(...[...item.querySelectorAll('a[href]')].map(a=>a.href));
   }
   return {cards:items.length,texts,links,
    height:Math.round(rect.height),width:Math.round(rect.width),
    documentOverflow:Math.round(document.documentElement.scrollWidth-innerWidth),
    cls:+(window.__mad4bObservedCls||0).toFixed(5)};
  });
  const summary={
   status:resp.status(),url:page.url(),
   responseCache:resp.headers()['x-mad4b-preset-cache']||'',
   initial,
   cards:result.cards,linksHash:sha(result.links.join('\n')),
   contentHash:sha(result.texts.join('\n')),
   width:result.width,height:result.height,
   overflow:result.documentOverflow,cls:result.cls,errors,
   ajaxRequests,ajaxReplies,
  };
  if(!isLazy && (initial.lazy||initial.reservation||summary.responseCache!=='BYPASS'))
   throw Error('SSR control was deferred or cached');
  if(isLazy && (!initial.lazy||!initial.reservation))
   throw Error('The live anonymous candidate was not admitted');
  return summary;
 }finally{await ctx.close()}
}
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 console.log('RENEW_STARTED '+new Date().toISOString());
 let failures=0;
 try{
  for(const {locale,width} of allCases){
   try{
    const ssr=await render(browser,locale,width,false);
    const lazy=await render(browser,locale,width,true);
    const maxCls=lazy.cls;
    const nativeSigned=lazy.ajaxRequests.some(a=>a.queryId===expectedQuery
       &&a.documentId===documentIds[locale]&&a.signedQuery&&a.signedWidget)
       &&lazy.ajaxReplies.includes(200);
    const pass=ssr.status===200&&lazy.status===200
       &&ssr.cards>2&&ssr.cards===lazy.cards
       &&ssr.linksHash===lazy.linksHash&&ssr.contentHash===lazy.contentHash
       &&lazy.initial.cards===0
       &&ssr.initial.height===lazy.initial.height
       &&ssr.initial.width===lazy.initial.width
       &&lazy.overflow<=1&&ssr.overflow<=1&&maxCls<=0.10&&maxCls>=0
       &&nativeSigned&&!ssr.errors.length&&!lazy.errors.length;
    const row={locale,width,pass,
      ssr_items:ssr.cards,ajax_items:lazy.cards,
      ssr_links_sha256:ssr.linksHash,ajax_links_sha256:lazy.linksHash,
      ssr_content_sha256:ssr.contentHash,ajax_content_sha256:lazy.contentHash,
      ssr_height_px:ssr.initial.height,reserved_height_px:lazy.initial.height,
      ssr_width_px:ssr.initial.width,reserved_width_px:lazy.initial.width,
      max_cls:maxCls,signed_ajax_verified:nativeSigned,
      browser_cache:lazy.responseCache,
      issues:[]};
    if(!pass)row.issues.push('browser_parity_or_layout_guard_failed');
    console.log('RENEW_CASE '+JSON.stringify(row));
    if(!pass)failures++;
   }catch(e){
    console.log('RENEW_CASE '+JSON.stringify({locale,width,pass:false,
      issues:[String(e).slice(0,280)]}));
    failures++;
   }
  }
 }finally{await browser.close()}
 console.log('RENEW_FINISHED '+new Date().toISOString());
 if(failures){console.log('RENEW_FAILURES '+failures);process.exitCode=2}
 else console.log('RENEW_PASS '+allCases.length);
})().catch(e=>{console.log('FATAL '+String(e));process.exitCode=3});
