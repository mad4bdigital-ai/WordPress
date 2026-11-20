'use strict';
// QA-only browser parity for an original first-party ordered CSS manifest.
// Never promotes a route, modifies WordPress, changes a cookie or purges cache.
const fs=require('fs'),path=require('path'),crypto=require('crypto');
const {chromium}=require('playwright');
const OUT=process.env.MAD4B_OUTPUT||'/out';
const manifest=JSON.parse(fs.readFileSync(path.join(OUT,'css-dependencies.json'),'utf8'));
const summary=JSON.parse(fs.readFileSync(path.join(OUT,'compiled-chunks.json'),'utf8'));
const source=fs.readFileSync(path.join(OUT,'source.html'),'utf8');
const widths=(process.env.MAD4B_WIDTHS||'390,768').split(',').map(Number);
const target=process.env.MAD4B_SOURCE_SITE||'https://'+manifest.host;
const pathname=process.env.MAD4B_PUBLIC_CSS_ROUTE||'/';
const sha=s=>crypto.createHash('sha256').update(s).digest('hex');
const origin=new URL(target).origin;
function rewrite(document) {
  const end=document.toLowerCase().indexOf('</head>');
  if(end<0)throw Error('HTML_WITHOUT_HEAD');
  const head=document.slice(0,end),tail=document.slice(end);
  let i=0,slot=0,used=new Set(),seen=[];
  const chunks=new Map(summary.chunks.map(c=>[c.slot,c]));
  const transformed=head.replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>|<link\b[^>]*>/gi,tag=>{
    if(/^<style/i.test(tag)){slot++;return tag;}
    const rel=tag.match(/\brel=["']([^"']+)["']/i)?.[1]?.toLowerCase().split(/\s+/)||[];
    if(!rel.includes('stylesheet'))return tag;
    const href=tag.match(/\bhref=["']([^"']+)["']/i)?.[1]?.replace(/&amp;/g,'&');
    const media=tag.match(/\bmedia=["']([^"']+)["']/i)?.[1]?.toLowerCase().trim()||'all';
    const fact=manifest.files[i++];
    if(!fact||new URL(href,origin).href!==fact.href||fact.slot!==slot||fact.media!==media)
      throw Error('ORIGINAL_CSS_MANIFEST_SOURCE_DRIFT_'+(i-1));
    seen.push(fact);
    if(used.has(slot))return '';
    const chunk=chunks.get(slot);
    if(!chunk)throw Error('UNMATCHED_CASCADE_SLOT');
    used.add(slot);
    const url=origin+'/__mad4b_qa_compiled_'+chunk.sha256+'.css';
    return '<link rel="stylesheet" data-mad4b-qa-compiled="1" href="'+url+'">';
  });
  if(i!==manifest.files.length||used.size!==chunks.size)
    throw Error('SOURCE_STYLESHEET_COUNT_DRIFT');
  return transformed+tail;
}
const rewritten=rewrite(source);
if(process.env.MAD4B_VALIDATE_ONLY==='1'){
 console.log('PASS_SOURCE_HTML_ORDER',JSON.stringify({files:manifest.files.length,chunks:summary.chunks.length}));process.exit(0);
}
const styleUrls=new Map(summary.chunks.map(x=>['/__mad4b_qa_compiled_'+x.sha256+'.css',path.join(OUT,x.sha256+'.css')]));
async function view(browser,width,compiled) {
  const context=await browser.newContext({viewport:{width,height:850},deviceScaleFactor:1,isMobile:true,hasTouch:true,
    userAgent:'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'});
  await context.route('**/*',async route=>{
    const u=new URL(route.request().url());
    if(u.origin===origin&&styleUrls.has(u.pathname)){
      const css=fs.readFileSync(styleUrls.get(u.pathname));
      return route.fulfill({status:200,contentType:'text/css; charset=utf-8',body:css});
    }
    if(route.request().isNavigationRequest()&&u.origin===origin&&u.pathname===pathname)
      return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:compiled?rewritten:source});
    return route.continue();
  });
  const page=await context.newPage();
  const errors=[],failed=[];
  page.on('pageerror',e=>errors.push(e.message.slice(0,160)));
  page.on('requestfailed',r=>{if(/\.css(?:$|\?)/.test(r.url()))failed.push(r.url().slice(-100))});
  try {
    await page.goto(origin+pathname,{waitUntil:'domcontentloaded',timeout:55000});
    await page.waitForTimeout(3000);
    const data=await page.evaluate(()=>{
      const nodes=[...document.querySelectorAll('body *')].slice(0,700);
      const fold=[];
      for(let n=0;n<nodes.length;n++){
        const e=nodes[n],b=e.getBoundingClientRect();
        if(b.width<1||b.height<1||b.top>1100||b.bottom<-100)continue;
        const c=getComputedStyle(e);
        fold.push({n,tag:e.tagName,box:[b.left,b.top,b.width,b.height].map(x=>Math.round(x)),
         css:[c.display,c.position,c.fontSize,c.fontWeight,c.color,c.backgroundColor,c.visibility,c.opacity,c.flexDirection,c.gridTemplateColumns]});
        if(fold.length>=120)break;
      }
      return {fold,overflow:document.documentElement.scrollWidth-innerWidth,
       cssLoaded:[...document.styleSheets].filter(s=>s.href&&s.href.includes('css')).length,
       heading:document.querySelector('h1')?.textContent?.trim().slice(0,80)||''};
    });
    return {...data,width,variant:compiled?'ordered-compiler':'original',errors,failed};
  } finally {await context.close()}
}
function diff(base,candidate){
  if(base.fold.length!==candidate.fold.length)return {pass:false,reason:'different-fold-elements',base:base.fold.length,candidate:candidate.fold.length};
  let violations=[];
  for(let i=0;i<base.fold.length;i++){
    const a=base.fold[i],b=candidate.fold[i];
    if(a.tag!==b.tag||a.n!==b.n||a.css.join('|')!==b.css.join('|')||
       a.box.some((x,j)=>Math.abs(x-b.box[j])>2))violations.push({index:i,original:a,compiled:b});
    if(violations.length>=3)break;
  }
  return {pass:violations.length===0&&base.overflow===candidate.overflow&&candidate.errors.length===0&&candidate.failed.length===0,
          checked:base.fold.length,violations,overflow:[base.overflow,candidate.overflow],
          failed: candidate.failed,errors: candidate.errors};
}
(async()=>{
  const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
  const results=[];
  try{
    for(const width of widths){
      const original=await view(browser,width,false),compiled=await view(browser,width,true);
      const proof=diff(original,compiled);
      results.push({width,...proof});
      console.log('ORDERED_CSS_PARITY',JSON.stringify({width,pass:proof.pass,checked:proof.checked||0,failures:proof.violations?.length,errors:proof.errors?.length}));
    }
  }finally{await browser.close()}
  const report={contract:'mad4b.ordered-css.browser-parity.qa.v1',host:manifest.host,pathname,
                manifest_sha256:manifest.manifest_sha256,
                widths,pass:results.every(x=>x.pass),results,
                css_certificate_issued:false,html_delivery_activated:false};
  fs.writeFileSync(path.join(OUT,'browser-parity.json'),JSON.stringify(report,null,2));
  if(!report.pass)process.exitCode=2;
})().catch(e=>{console.error('ORDERED_CSS_QA_HOLD',String(e).slice(0,300));process.exitCode=4});
