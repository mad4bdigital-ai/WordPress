'use strict';
const {chromium}=require('playwright');
const crypto=require('crypto');
const fs=require('fs');
const config=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const locale=config.locale;
const widths=(process.argv[3]||'320,390,767,768,1024,1920').split(',').map(Number);
const token=process.env.MAD4B_COVERAGE_QA_TOKEN||'';
if(!/^[a-z]{2}$/.test(locale)||typeof config.url!=='string'||!config.url.startsWith('https://')
 ||!Number.isSafeInteger(config.query_id)||!Number.isSafeInteger(config.listing_id)
 ||!Number.isSafeInteger(config.document_id)||!/^[a-f0-9]{7,9}$/.test(config.widget_id)
 ||!/^([a-f0-9]{64})$/.test(config.listing_sha256)
 ||!/^([a-f0-9]{64})$/.test(config.listing_css_sha256)
 ||!/^scoped:v1:[a-f0-9]{64}$/.test(config.query_generation)
 ||!/^([a-f0-9]{64})$/.test(token)
 ||widths.length>7||widths.some(w=>![320,390,767,768,1024,1440,1920].includes(w)))
  throw Error('Invalid dynamic QA input');
const uri=new URL(config.url);
if(uri.protocol!=='https:'||uri.host!==config.host||uri.username||uri.password||uri.search||uri.hash||!uri.pathname.endsWith('/'))throw Error('Untrusted route');
const url=config.url;
const sha=x=>crypto.createHash('sha256').update(String(x)).digest('hex');
const BROW='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const DESK='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';
const ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
function norm(v){return String(v||'').replace(/\s+/g,' ').trim()}
async function visit(browser,width,canary){
 const mobile=width<=768;
 const context=await browser.newContext({viewport:{width,height:844},
  isMobile:mobile,hasTouch:mobile,deviceScaleFactor:1,
  userAgent:mobile?BROW:DESK,extraHTTPHeaders:{Accept:ACCEPT}});
 const page=await context.newPage(),failed=[],errors=[],ajax=[],jetResponses=[];
 if(canary)await page.route('**/*',async route=>{
  const request=route.request();
  const uri=new URL(request.url());
  if(request.isNavigationRequest()&&uri.origin===new URL(url).origin&&request.resourceType()==='document'){
   await route.continue({headers:{...request.headers(),'x-mad4b-coverage-probe':token,'x-mad4b-widget-probe':'native-grid-20261116'}});
  }else await route.continue();
 });
 page.on('pageerror',e=>errors.push(String(e).slice(0,100)));
 page.on('requestfailed',r=>{
   if(r.url().endsWith('.css'))failed.push('css_failed');
 });
 page.on('response',r=>{
  const q=r.request(),raw=q.postData()||'';
  if(q.method()==='POST' && raw.includes('action=jet_engine_ajax')){
   jetResponses.push({status:r.status(),path:new URL(r.url()).pathname.slice(0,100)});
  }
 });
 page.on('request',r=>{
  if(!['xhr','fetch'].includes(r.resourceType()) &&
     !/admin-ajax|jet-engine|wp-json/.test(r.url()))return;
  const raw=r.postData()||'';
  const u=new URL(r.url());
  const bodyAction=raw.match(/(?:^|&)action=([a-zA-Z0-9_%_-]+)/);
  const action=u.searchParams.get('action')||(bodyAction?decodeURIComponent(bodyAction[1]):'unknown');
  const form=new URLSearchParams(raw);
  const sig=form.get('query[signature]')||'';
  const wSig=form.get('widget_settings[_jet_engine_settings_signature]')||'';
  if(ajax.length<30)ajax.push({kind:r.resourceType(),path:u.pathname.slice(0,120),
   method:r.method(),action,
   query_keys:[...u.searchParams.keys()].filter(x=>/^[a-z0-9_-]{1,45}$/i.test(x)).slice(0,12),
   body_keys:[...form.keys()].slice(0,15),
   query_id:form.get('query[query_id]')||'',
   template_id:form.get('page_settings[post_id]')||'',
   signed_query:Boolean(sig.length>=8&&sig.length<=300),
   signed_widget:Boolean(wSig.length>=8&&wSig.length<=300),
   nonce_present:/(?:nonce|_nonce|security|_wpnonce)/i.test(raw)
     || [...u.searchParams.keys()].some(k=>/(?:nonce|_nonce|security|_wpnonce)/i.test(k))});
 });
 const start=Date.now();
 await page.addInitScript(()=>{
  window.__qaCls=0;
  window.__qaPaint=[];
  try{new PerformanceObserver(list=>{
   for(const item of list.getEntries())if(!item.hadRecentInput)window.__qaCls+=item.value
  }).observe({type:'layout-shift',buffered:true})}catch(e){}
  try{new PerformanceObserver(list=>{
   for(const item of list.getEntries())window.__qaPaint.push([item.name,item.startTime])
  }).observe({type:'paint',buffered:true})}catch(e){}
 });
 try{
  const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:40000});
  await page.waitForTimeout(1100);
  const selector='.elementor-element-'+config.widget_id;
  const container=page.locator(selector).first();
  if(await container.count()===0)throw Error('target grid not found');
  const before=await container.evaluate(el=>{
   const grid=el.querySelector('.jet-listing-grid');
   const r=el.getBoundingClientRect();
   return {items:el.querySelectorAll('.jet-listing-grid__item').length,
    width:Math.round(r.width),height:Math.round(r.height),
    lazy:!!grid?.classList.contains('jet-listing-grid--lazy-load')};
  });
  await container.scrollIntoViewIfNeeded({timeout:10000});
  if(canary)await page.waitForFunction(sel=>{
   const el=document.querySelector(sel);
   return !!el&&el.querySelectorAll('.jet-listing-grid__item').length>0
       &&!!el.querySelector('.jet-listing-grid--lazy-load-completed');
  },selector,{timeout:21000}).catch(()=>{});
  await page.waitForTimeout(canary?850:550);
  const after=await container.evaluate(el=>{
   const grid=el.querySelector('.jet-listing-grid');
   const rect=el.getBoundingClientRect();
   const cards=[...el.querySelectorAll('.jet-listing-grid__item')].slice(0,150);
   const entries=cards.map(item=>{
    // Interactions, translated labels and static text all count towards
    // parity, excluding hidden script/style markup.
    const walk=document.createTreeWalker(item,NodeFilter.SHOW_TEXT);
    let part;const texts=[];
    while((part=walk.nextNode())){
     const host=part.parentElement;
     if(!host || host.closest('script,style,noscript,template'))continue;
     const t=(part.nodeValue||'').replace(/\s+/g,' ').trim();
     if(t)texts.push(t);
    }
    const links=[...item.querySelectorAll('a[href]')].map(a=>a.href);
    return {text:texts.join(' '),links};
   });
   return {
    width:Math.round(rect.width),height:Math.round(rect.height),
    items:cards.length,
    truncated:el.querySelectorAll('.jet-listing-grid__item').length>150,
    all_links:entries.flatMap(x=>x.links),
    all_text:entries.map(x=>x.text),
    entry_lengths:entries.slice(0,6).map(x=>x.text.length),
    lazy_completed:!!grid?.classList.contains('jet-listing-grid--lazy-load-completed'),
    overflow:Math.round(document.documentElement.scrollWidth-innerWidth),
    cls:+(window.__qaCls||0).toFixed(5),
    fcp:Math.round(window.__qaPaint.find(x=>x[0]==='first-contentful-paint')?.[1]||0)
   };
  });
  return {locale,width,canary,http:response.status(),canaryHeader:response.headers()['x-mad4b-native-grid-qa']||'',
    before,after:{...after,links_sha256:sha(after.all_links.join('\n')),
      content_sha256:sha(after.all_text.join('\n')),
      first_links:after.all_links.slice(0,4),first_lengths:after.entry_lengths,
      all_links:undefined,all_text:undefined,entry_lengths:undefined},
    evidence:{links:after.all_links,text:after.all_text},
    ajax,jetResponses,errors,failed,
    market:response.headers()['x-mad4b-native-grid-qa-market']||'',
    elapsed_ms:Date.now()-start};
 }finally{await context.close()}
}
async function main(){
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  for(const width of widths){
   let base,canary,result;
   try{
    base=await visit(browser,width,false);
    canary=await visit(browser,width,true);
    const a=base.after,b=canary.after;
    result={locale,width,baseline_http:base.http,canary_http:canary.http,
      canary_signed_header:canary.canaryHeader==='1',baseline_count:a.items,ajax_count:b.items,
      count_match:a.items>0&&a.items===b.items,
      links_match:a.links_sha256===b.links_sha256,
      content_match:a.content_sha256===b.content_sha256,
      first_different_text_index:base.evidence.text.findIndex((x,i)=>x!==canary.evidence.text[i]),
      first_different_link_index:base.evidence.links.findIndex((x,i)=>x!==canary.evidence.links[i]),
      base_before:base.before,canary_before:canary.before,
      cls_base:a.cls,cls_canary:b.cls,
      fcp_base:a.fcp,fcp_canary:b.fcp,
      content_hash:b.content_sha256,link_hash:b.links_sha256,
      base_text_lengths:a.first_lengths,ajax_text_lengths:b.first_lengths,
      base_overflow:a.overflow,ajax_overflow:b.overflow,
      ajax_requests:canary.ajax,jet_responses:canary.jetResponses,market:canary.market,
      native_signed_ajax:canary.ajax.some(x=>x.action==='jet_engine_ajax' && x.method==='POST'
        && x.signed_query && x.signed_widget && x.query_id===String(config.query_id)&&x.template_id===String(config.document_id))
        && canary.jetResponses.some(x=>x.status===200),
      errors:canary.errors,failed_css:canary.failed,
      pass:base.http===200&&canary.http===200&&canary.canaryHeader==='1'
        &&canary.market===config.market
        &&a.items>0&&a.items===b.items&&a.links_sha256===b.links_sha256
        &&a.content_sha256===b.content_sha256
        &&!a.truncated&&!b.truncated
        &&base.before.items>0&&!base.before.lazy&&canary.before.items===0
        &&base.before.height===canary.before.height
        &&base.before.width===canary.before.width
        &&b.items>=18&&b.fcp>0&&a.fcp>0
        &&b.fcp<=a.fcp*1.20
        &&b.cls<=0.10&&b.overflow<=1&&a.overflow<=1&&canary.errors.length===0
        &&canary.failed.length===0&&canary.before.lazy&&b.lazy_completed
        &&canary.ajax.some(x=>x.action==='jet_engine_ajax'&&x.method==='POST'
          &&x.signed_query&&x.signed_widget&&x.query_id===String(config.query_id)&&x.template_id===String(config.document_id))
        &&canary.jetResponses.some(x=>x.status===200)};
    }catch(e){result={locale,width,pass:false,error:String(e).slice(0,270)}}
    console.log('QA_RESULT '+JSON.stringify({...result,widget_id:config.widget_id,
       query_id:config.query_id,listing_id:config.listing_id,
       document_id:config.document_id,site_host:new URL(url).host}));
   }
 }finally{await browser.close()}
}
main().catch(e=>{console.log('FATAL',String(e));process.exitCode=3});
