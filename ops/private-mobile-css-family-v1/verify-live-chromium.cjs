'use strict';
const {chromium}=require('playwright');
const base='https://wayfatours.com';
const UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let failed=false;
try {
for (const mode of ['mobile','desktop']) {
 const mobile=mode==='mobile';
 const cx=await browser.newContext({viewport:{width:mobile?390:1440,height:844},isMobile:mobile,hasTouch:mobile,deviceScaleFactor:mobile?2:1,userAgent:mobile?UA:'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/157.0.0.0 Safari/537.36'});
 const page=await cx.newPage(),requests=[],errors=[],headers=[];
 page.on('request',r=>{if(r.url().includes('/wp-content/litespeed/css/'))requests.push(r.url().split('?')[0]);});
 page.on('pageerror',e=>errors.push(e.message.slice(0,200)));
 await page.goto(base+'/',{waitUntil:'domcontentloaded',timeout:55000});
 await page.evaluate(async()=>{
  const n=(await(await fetch('/wp-json/visitor-preferences/v1/state',{credentials:'same-origin',cache:'no-store'})).json()).nonces;
  for(const [action,country,nonce] of [['country','AE',n.country],['manual_passport','GB',n.passport]]){
   const r=await fetch('/wp-json/visitor-preferences/v1/apply',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,country,nonce})});
   if(!r.ok)throw Error(action+' failed '+r.status);
  }
 });
 for(const locale of ['en','es']){
  requests.length=0;
  const resp=await page.goto(base+(locale==='en'?'/':'/es/'),{waitUntil:'load',timeout:65000});
  await page.waitForTimeout(450);
  const info=await page.evaluate(()=>{
   const css=document.querySelector('link#mad4b-css-rest-v1');
   const s=document.querySelector('style#mad4b-critical-css-v1');
   return {critical:Boolean(s&&s.textContent.length>16000),cssAsync:Boolean(css),media:css?.media||'', cssLoaded:!!css?.sheet,
     inline:!!document.querySelector('script[data-mad4b-css-async-lib="inline-v1"]'),
     stylesheets:[...document.querySelectorAll('link[rel="stylesheet"]')].filter(l=>l.href.includes('/wp-content/litespeed/css/')).length,
     country:[...document.querySelectorAll('.mad4b-country-switcher select[name="market_country"]')].map(x=>x.value),
     passport:[...document.querySelectorAll('.travel-booking-nationality-switcher select[name="nationality"]')].map(x=>x.value)};
  });
  const h=resp.headers();
  const unique=[...new Set(requests)];
  const cookieNames=(await cx.cookies()).map(c=>c.name);
  const expected=mobile ? (info.critical&&info.cssAsync&&info.inline&&info.media==='all'&&info.cssLoaded)
     : (!info.critical&&!info.cssAsync&&!info.inline);
  const good=resp.status()===200&&expected
      &&info.country.every(x=>x==='AE')&&info.passport.every(x=>x==='GB')
      &&unique.length===1&&requests.length===1&&errors.length===0
      &&h['cache-control']?.includes('private')&&h['cache-control']?.includes('no-store')
      &&!cookieNames.some(x=>x.startsWith('wp_woocommerce_session_'));
  console.log('LIVE_CHROMIUM_PRIVATE',JSON.stringify({mode,locale,pass:good,http:resp.status(),css:info,uniqueCss:unique.map(x=>x.split('/').at(-1)),requests:requests.length,private:h['cache-control'],errors}));
  if(!good)failed=true;
 }
 await cx.close();
}
}finally {await browser.close();}
if(failed)process.exitCode=1;
})().catch(e=>{console.error('LIVE_QA_ERROR',e.stack);process.exitCode=2;});
