'use strict';
const {chromium}=require('playwright');
const selector='.elementor-location-header .header-context-controls select[name="market_country"]';
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  for(const device of [{id:'mobile',width:390,dpr:2,mobile:true},{id:'desktop',width:1440,dpr:1,mobile:false}]){
   const context=await browser.newContext({viewport:{width:device.width,height:850},deviceScaleFactor:device.dpr,isMobile:device.mobile,hasTouch:device.mobile,userAgent:device.mobile?'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36':'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/157.0.0.0 Safari/537.36'});
   const page=await context.newPage();let navigation=0,applies=0;const failures=[];
   page.on('request',r=>{if(r.isNavigationRequest())navigation++;if(r.url().includes('/visitor-preferences/v1/apply'))applies++;});
   page.on('pageerror',e=>failures.push(e.message.slice(0,130)));
   const en=await page.goto('https://wayfatours.com/',{waitUntil:'domcontentloaded',timeout:35000});
   await page.waitForTimeout(650);
   const entry=await page.evaluate(s=>({
      countries:[...document.querySelectorAll(s)].map(x=>x.value),
      contextInitialized:[...document.querySelectorAll('.elementor-location-header .header-context-controls')].map(x=>x.dataset.contextInitialized||''),
      critical:!!document.querySelector('#mad4b-critical-css-v1'),
      asyncCss:!!document.querySelector('#mad4b-css-rest-v1'),
      inline:!!document.querySelector('script[data-mad4b-css-async-lib="inline-v1"]')
   }),selector);
   const nBefore=navigation;
   await Promise.all([
     page.waitForResponse(r=>r.url().includes('/visitor-preferences/v1/apply')&&r.status()===200,{timeout:27000}),
     page.evaluate(s=>{const x=document.querySelector(s);if(!x)throw Error('MISSING_NATIVE_COUNTRY');x.value='AE';x.dispatchEvent(new Event('change',{bubbles:true}));},selector)
   ]);
   await page.waitForFunction(s=>[...document.querySelectorAll(s)].every(e=>e.value==='AE'),selector,{timeout:11000});
   const saved=await page.evaluate(s=>({path:location.pathname,values:[...document.querySelectorAll(s)].map(x=>x.value),saved:[...document.querySelectorAll(s)].map(x=>x.dataset.saved||'')}),selector);
   const navAfterChange=navigation;
   const state=await page.evaluate(async()=>{const r=await fetch('/wp-admin/admin-ajax.php?action=mad4b_visitor_preference_state',{credentials:'same-origin',cache:'no-store'});const d=await r.json();return {country:d.data?.country?.country,explicit:d.data?.country?.explicit,passport:d.data?.nationality?.country};});
   const es=await page.goto('https://wayfatours.com/es/',{waitUntil:'domcontentloaded',timeout:34000});
   await page.waitForTimeout(850);
   const lang=await page.evaluate(s=>({path:location.pathname,countries:[...document.querySelectorAll(s)].map(x=>x.value),passports:[...document.querySelectorAll('.elementor-location-header select[name="nationality"]')].map(x=>x.value)}),selector);
   const passes=entry.contextInitialized.every(x=>x==='1')&&(!device.mobile || (entry.critical&&entry.asyncCss&&entry.inline))&&
       navAfterChange===nBefore&&applies===1&&saved.values.every(v=>v==='AE')&&state.country==='AE'&&state.explicit===true&&
       lang.countries.every(v=>v==='AE')&&lang.passports.every(v=>v==='AE')&&en.status()===200&&es.status()===200&&failures.length===0;
   console.log('GUEST_REAL_SESSION_QA',JSON.stringify({device:device.id,passes,http:{en:en.status(),es:es.status()},entry,saved,lang,state,counts:{navigation,navAfterChange,nBefore,applies},errors:failures.slice(0,3)}));
   if(!passes)throw Error('REGRESSION_ON_PRODUCTION_GUEST');
   await context.close();
  }
 }finally{await browser.close()}
})().catch(e=>{console.error('GUEST_QA_FAILED',e.stack?.slice(0,850));process.exit(1)});
