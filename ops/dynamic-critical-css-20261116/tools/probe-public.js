'use strict';
// Inspect the exact anonymous Chromium/WebP CSS negotiation profile used by
// capture.js and verify.js; curl has a DIFFERENT CSS bundle on this origin.
const {chromium}=require('playwright'),fs=require('fs'),path=require('path');
const site=process.env.MAD4B_SOURCE_SITE;
const out=process.env.MAD4B_PROBE_OUTPUT;
const langs=(process.env.MAD4B_PROBE_LANGS||'en,es').split(',');
const DEFAULT=process.env.MAD4B_DEFAULT_LANG||'en';
const PUBLIC_ROUTE=process.env.MAD4B_PUBLIC_CSS_ROUTE||'';
const PUBLIC_OBJECT_ID=process.env.MAD4B_PUBLIC_CSS_OBJECT_ID?Number(process.env.MAD4B_PUBLIC_CSS_OBJECT_ID):null;
const PUBLIC_TYPE=process.env.MAD4B_PUBLIC_CSS_TYPE||'';
const TERM_TYPES=['destinations','tour-types','property-types','tour-categories','category'];
const VALID_TYPES=['page','post','tours-and-activities','properties',...TERM_TYPES,
 'archive:tours-and-activities','archive:properties'];
function assertBodyIdentity(html){
 const classes=((html.match(/<body[^>]*class=["']([^"']+)/i)||[])[1]||'').split(/\s+/);
 if(PUBLIC_TYPE.startsWith('archive:')){
  if(PUBLIC_OBJECT_ID!==0||!classes.includes('post-type-archive-'+PUBLIC_TYPE.split(':')[1]))
   throw Error('PUBLIC_ARCHIVE_BOUND_SOURCE_DRIFT');
  return;
 }
 const idType=PUBLIC_TYPE==='category'?'category':(TERM_TYPES.includes(PUBLIC_TYPE)?'term':'(?:page-id|postid)');
 const m=classes.join(' ').match(new RegExp('(?:^|\\s)'+idType+'-(\\d+)(?:\\s|$)'));
 if(!m || Number(m[1])!==PUBLIC_OBJECT_ID)throw Error('BOUND_WP_OBJECT_DRIFT');
}
if(PUBLIC_ROUTE && (!/^\/[a-zA-Z0-9/_%.-]{0,345}$/.test(PUBLIC_ROUTE)
    ||PUBLIC_ROUTE.includes('//')||PUBLIC_ROUTE.includes('..')
    ||!Number.isInteger(PUBLIC_OBJECT_ID)||PUBLIC_OBJECT_ID<0
    ||!VALID_TYPES.includes(PUBLIC_TYPE)
    ||langs.length!==1))throw Error('INVALID_BOUND_PUBLIC_ROUTE');
const UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const HTML_ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
if(!/^[a-z]{2}$/.test(DEFAULT)||!site||!/^https:\/\/(tours\.dev\.mad4b\.com|wayfatours\.com)$/.test(site)||!out||!langs.length||langs.some(x=>!/^[a-z]{2}$/.test(x)))throw Error('INVALID_COMPANY_OR_LOCALES');
(async()=>{
 fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 let successful=0;
 const held=[];
 try{
  for(const lang of langs){
   const uri=PUBLIC_ROUTE||(lang===DEFAULT?'/':'/'+lang+'/');
   const ctx=await browser.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,userAgent:UA,extraHTTPHeaders:{Accept:HTML_ACCEPT}});
   try{
    const page=await ctx.newPage();
    const r=await page.goto(site+uri,{waitUntil:'domcontentloaded',timeout:55000});
    if(!r||r.status()!==200)throw Error('ANONYMOUS_HTML_NOT_200:'+lang+':'+(r?r.status():'none'));
    const html=await r.text();
    if(PUBLIC_ROUTE)assertBodyIdentity(html);
    const h=await r.allHeaders();
    const headers='HTTP/2 '+r.status()+'\n'+Object.keys(h).map(k=>k+': '+h[k]).join('\n')+'\n';
    // Preserve public, query-free evidence for first-party diagnosis of
    // unexpected optimizer variants. Never silently mark such HTML ready.
    fs.writeFileSync(path.join(out,lang+'.html'),html,{mode:0o600});
    fs.writeFileSync(path.join(out,lang+'.headers'),headers,{mode:0o600});
    if(!html.includes('<body')||!html.includes('/wp-content/litespeed/css/')){
      const links=[...html.matchAll(/<link\b[^>]{0,500}>/gi)].map(x=>x[0])
        .filter(x=>/stylesheet|litespeed/i.test(x)).slice(0,4);
      console.error('UNEXPECTED_PUBLIC_CSS_FORMAT',JSON.stringify({
        locale:lang,bytes:html.length,links,criticalInline:html.includes('id="mad4b-critical-css-v1"'),
        htmlOptimized:h['x-litespeed-tag']||null,cssReady:h['x-mad4b-mobile-css-ready']||null}));
      throw Error('PUBLIC_LSC_CSS_MISSING:'+lang);
    }
    const files=[...new Set([...html.matchAll(/\/wp-content\/litespeed\/css\/([a-f0-9]{32}\.css)/g)].map(x=>x[1]))];
    console.log('PUBLIC_CHROMIUM_SOURCE',JSON.stringify({locale:lang,status:r.status(),css_files:files,ready:h['x-mad4b-mobile-css-ready']||null,bytes:html.length,profile:'chromium-mobile-webp-v1'}));
    successful++;
   }catch(e){
    const reason=String(e).slice(0,270);
    held.push({locale:lang,reason});
    console.warn('PUBLIC_CHROMIUM_LOCALE_HOLD',JSON.stringify({locale:lang,reason}));
   }finally{await ctx.close();}
  }
 }finally{await browser.close();}
 fs.writeFileSync(path.join(out,'locale-holds.json'),JSON.stringify({contract:'mad4b.chromium-locale-holds.v1',successful,held},null,2)+String.fromCharCode(10),{mode:0o600});
 if(successful===0)throw Error('NO_VALID_PUBLIC_WPML_CSS_SOURCE');
})().catch(e=>{console.error('PUBLIC_CHROMIUM_PROBE_HOLD',String(e));process.exitCode=4;});
