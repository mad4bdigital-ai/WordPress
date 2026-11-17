'use strict';
const {chromium}=require('playwright');
const langs=(process.argv[2]||'en,es').split(',');
const widths=(process.argv[3]||'390,1920').split(',').map(Number);
if(langs.some(x=>!['en','es'].includes(x))||widths.some(x=>!([320,390,767,768,1024,1920].includes(x))))throw Error('INVALID_CASE');
const u='https://tours.dev.mad4b.com/';
const mobileUA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const desktopUA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';
async function once(browser,locale,width){
 const mobile=width<=768;
 const context=await browser.newContext({viewport:{width,height:844},isMobile:mobile,hasTouch:mobile,
  deviceScaleFactor:1,userAgent:mobile?mobileUA:desktopUA,
  extraHTTPHeaders:{Accept:'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'}});
 const page=await context.newPage();
 const errors=[], requests=[],ajaxResponses=[];
 page.on('pageerror',e=>errors.push(String(e).slice(0,90)));
 page.on('request',r=>{if(r.method()==='POST'&&r.postData()?.includes('action=jet_engine_ajax'))requests.push({method:r.method(),signature:r.postData()?.includes('query%5Bsignature%5D')||r.postData()?.includes('query[signature]'),widget:r.postData()?.includes('_jet_engine_settings_signature')});});
 page.on('response',r=>{if(r.request().method()==='POST'&&r.request().postData()?.includes('action=jet_engine_ajax'))ajaxResponses.push(r.status());});
 await page.addInitScript(()=>{
  window.__lazyCls=0;
  try{new PerformanceObserver(x=>{for(const e of x.getEntries())if(!e.hadRecentInput)window.__lazyCls+=e.value}).observe({type:'layout-shift',buffered:true})}catch(e){}
 });
 const outcome={locale,width};
 try{
  const response=await page.goto(u+(locale==='en'?'':locale+'/'),{waitUntil:'domcontentloaded',timeout:45000});
  outcome.status=response.status();
  outcome.cache=response.headers()['x-mad4b-preset-cache'];
  outcome.cssReady=response.headers()['x-mad4b-mobile-css-ready'];
  outcome.private=response.headers()['cache-control'];
  await page.waitForTimeout(650);
  const w=page.locator('.elementor-element-78d62e0').first();
  if(await w.count()===0)throw Error('MISSING_GRID');
  outcome.before=await w.evaluate(el=>{const g=el.querySelector('.jet-listing-grid');const b=el.getBoundingClientRect();return {
   lazy:!!g?.classList.contains('jet-listing-grid--lazy-load'),
   wrapper:el.classList.contains('mad4b-native-measured-lazy'),
   items:el.querySelectorAll('.jet-listing-grid__item').length,width:Math.round(b.width),height:Math.round(b.height)}});
  await w.scrollIntoViewIfNeeded({timeout:14000});
  if(outcome.before.lazy){await page.waitForFunction(()=>{const e=document.querySelector('.elementor-element-78d62e0');return !!e&&e.querySelectorAll('.jet-listing-grid__item').length>0&&!!e.querySelector('.jet-listing-grid--lazy-load-completed')},null,{timeout:20000}).catch(()=>{});}
  await page.waitForTimeout(750);
  outcome.after=await w.evaluate(el=>{const g=el.querySelector('.jet-listing-grid');return {
   completed:!!g?.classList.contains('jet-listing-grid--lazy-load-completed'),
   items:el.querySelectorAll('.jet-listing-grid__item').length,
   cls:+(window.__lazyCls||0).toFixed(5),
   overflow:Math.round(document.documentElement.scrollWidth-innerWidth),
   firstLink:el.querySelector('.jet-listing-grid__item a[href]')?.href||''
  }});
  outcome.requests=requests;outcome.ajaxResponses=ajaxResponses;outcome.errors=errors;
  outcome.pass=outcome.status===200&&outcome.before.lazy&&outcome.before.wrapper
    &&outcome.before.height>=400&&outcome.after.completed&&outcome.after.items>=40
    &&outcome.after.cls<=.1&&outcome.after.overflow<=1&&outcome.errors.length===0
    &&requests.length>=1&&ajaxResponses.includes(200);
 }catch(e){outcome.pass=false;outcome.error=String(e).slice(0,300)}
 await context.close();
 return outcome;
}
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 try{for(const lang of langs)for(const width of widths){
  const result=await once(browser,lang,width);
  console.log('LIVE_RESULT '+JSON.stringify(result));
 }}finally{await browser.close()}
})().catch(e=>{console.error('FATAL',String(e));process.exitCode=3});
