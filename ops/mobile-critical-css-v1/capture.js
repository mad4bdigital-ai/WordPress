'use strict';
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const SITE = process.env.MAD4B_SOURCE_SITE || 'https://tours.dev.mad4b.com';
const OUT = process.env.MAD4B_OUTPUT || '/out';
const CSSROOT = process.env.MAD4B_CSS_ROOT || '/source-css';
const CAPTURE_QUERY=process.env.MAD4B_CAPTURE_QUERY||'';
const DIAG_BYPASS=process.env.MAD4B_DIAG_ORIGIN_BYPASS==='1';
// The source's dynamic CSS normalization is intentionally disabled for
// QA query strings. Capturing a probe URL yields a different LiteSpeed
// bundle per request and must never be published as a public CSS manifest.
if(CAPTURE_QUERY)throw new Error('CSS_CAPTURE_REQUIRES_PUBLIC_QUERY_FREE_ROUTE');

const langs = (process.env.MAD4B_LANGS || 'en,es,fr,de,it,ar').split(',').filter(x=>/^(en|es|fr|de|it|ar)$/.test(x));
const widths = (process.env.MAD4B_WIDTHS || '320,390,414,768').split(',').map(Number).filter(x=>[320,390,414,768].includes(x));
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const mobileUA = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
// Pin navigation and style source to the HTTP monitor's Chromium/WebP profile.
// LiteSpeed may generate JPEG or JPEG.webp URLs inside combined CSS based on
// the client Accept header. Neither variant is a substitute for CSS QA.
const HTML_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
const CSS_NEGOTIATION_PROFILE = 'chromium-mobile-webp-v1';

const head = (html) => html.slice(html.toLowerCase().indexOf('<body'));
function getDocHash(html) {
 const ids = [...head(html).matchAll(/(?<![a-z0-9_-])elementor-(\d+)\b/ig)].map(x=>Number(x[1]));
 return sha([...new Set(ids)].sort((a,b)=>a-b).join(','));
}
async function main() {
 if(!langs.length || !widths.length)throw new Error('empty locale/width selection');
 const browser = await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 let output = {contract:'mad4b.mobile-critical.capture.v1',host:new URL(SITE).host, at:new Date().toISOString(),widths,css_negotiation_profile:CSS_NEGOTIATION_PROFILE,origin_cache_bypassed:DIAG_BYPASS,scenarios:[]};
 for(const lang of langs) {
  const uri = lang==='en'?'/':'/'+lang+'/';
  for(const width of widths) {
   const context = await browser.newContext({viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,userAgent:mobileUA,extraHTTPHeaders:{'Accept':HTML_ACCEPT,...(DIAG_BYPASS?{'X-MAD4B-BYPASS-CACHE':'1'}:{})}});
   const page=await context.newPage();
   let item={lang,uri,width};
   try {
    await page.coverage.startCSSCoverage({resetOnNavigation:false});
    const response=await page.goto(SITE+uri+(CAPTURE_QUERY?'?'+CAPTURE_QUERY:''),{waitUntil:'domcontentloaded',timeout:55000});
    await page.waitForFunction(()=>[...document.styleSheets].some(s=>s.href?.includes('/wp-content/litespeed/css/')),{timeout:15000}).catch(()=>{});
    await page.waitForTimeout(500);
    const css=(await page.coverage.stopCSSCoverage()).filter(e=>e.url.includes('/wp-content/litespeed/css/'));
    const html=await response.text();
    if(response.status()!==200 || css.length!==1)throw new Error('status='+response.status()+' bundles='+css.length);
    const filename=(new URL(css[0].url).pathname.match(/\/([a-f0-9]{32}\.css)$/)||[])[1];
    if(!filename)throw new Error('invalid css filename');
    const sourceFile=path.join(CSSROOT,filename);
    const sourceBytes=fs.readFileSync(sourceFile);
    const domId = (html.match(/<body[^>]*class=["'][^"']*page-id-(\d+)/i)||[])[1]||'';
    if(!domId)throw new Error('missing page ID');
    const key=lang+'-'+filename;
    const textFile=key+'.css.txt', rangesFile=key+'-'+width+'.ranges.json';
    fs.writeFileSync(path.join(OUT,textFile),css[0].text);
    fs.writeFileSync(path.join(OUT,rangesFile),JSON.stringify(css[0].ranges));
    item={...item,css_file:filename,source_sha:sha(sourceBytes),source_bytes:sourceBytes.length,css_text_file:textFile,ranges_file:rangesFile,page_id:Number(domId),dom_sha:getDocHash(html),range_count:css[0].ranges.length,css_text_bytes:css[0].text.length};
    console.log('CAPTURE_OK',JSON.stringify({lang,width,css:filename,range_count:item.range_count,dom_sha:item.dom_sha.slice(0,12)}));
   }catch(e){item.error=String(e).slice(0,350);console.log('CAPTURE_FAIL',JSON.stringify(item))}
   output.scenarios.push(item);
   fs.writeFileSync(path.join(OUT,'capture-index.json'),JSON.stringify(output,null,2));
   await context.close();
  }
 }
 await browser.close();
 console.log('CAPTURE_COMPLETE',JSON.stringify({scenarios:output.scenarios.length,errors:output.scenarios.filter(x=>x.error).length}));
}
main().catch(e=>{console.log('CAPTURE_FATAL',String(e));process.exitCode=1});
