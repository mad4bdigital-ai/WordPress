'use strict';
/**
 * Post-policy visual + harmless UI interaction monitor. It never issues a
 * Critical CSS certificate, mutates visitor preferences, submits a booking,
 * or returns policy approval. Alerts are independent observational evidence.
 */
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path');
const host=process.env.MAD4B_OBSERVE_HOST||'';
const route=process.env.MAD4B_OBSERVE_ROUTE||'';
const out=process.env.MAD4B_OBSERVE_OUT||'/out';
const site=new Set(['wayfatours.com','tours.dev.mad4b.com']).has(host)?host:null;
if(!site||!/^\/[a-zA-Z0-9_/%.-]{0,340}$/.test(route)||route.includes('//')||route.includes('..'))
 throw Error('UNAUTHORIZED_OR_PRIVATE_VISUAL_OBSERVATION_ROUTE');
const UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const accept='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
const results=[];
const widths=[390,768];
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  for(const width of widths){
   const ctx=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:width<768?2:1,
     isMobile:width<768,hasTouch:width<768,userAgent:UA,extraHTTPHeaders:{Accept:accept}});
   const page=await ctx.newPage(),errors=[],failedStyles=[];
   page.on('pageerror',e=>errors.push(String(e.message).slice(0,140)));
   page.on('requestfailed',req=>{
      if(/\.css(?:\?|$)/i.test(req.url()))failedStyles.push(req.url().split('/').pop().slice(0,90));
   });
   await page.addInitScript(()=>{
      window.__mad4bObserve={shift:[],lcp:[],fcp:[]};
      try{new PerformanceObserver(list=>window.__mad4bObserve.shift.push(...list.getEntries().filter(e=>!e.hadRecentInput).map(e=>e.value))).observe({type:'layout-shift',buffered:true});}catch{}
      try{new PerformanceObserver(list=>window.__mad4bObserve.lcp.push(...list.getEntries().map(e=>Math.round(e.startTime)))).observe({type:'largest-contentful-paint',buffered:true});}catch{}
      try{new PerformanceObserver(list=>window.__mad4bObserve.fcp.push(...list.getEntries().filter(e=>e.name==='first-contentful-paint').map(e=>Math.round(e.startTime)))).observe({type:'paint',buffered:true});}catch{}
   });
   let row={width,status:null,alerts:[],interaction:'not-present'};
   try{
     const response=await page.goto('https://'+site+route,{waitUntil:'domcontentloaded',timeout:47000});
     row.status=response?.status()||null;
     if(new URL(page.url()).origin!=='https://'+site)
       throw Error('PUBLIC_VISUAL_CROSS_ORIGIN_REDIRECT');
     await page.waitForTimeout(2600);
     const raw=await page.evaluate(()=>{
       const b=document.body;
       const h1=document.querySelector('h1');
       const visible=el=>!!el&&el.getBoundingClientRect().width>0&&el.getBoundingClientRect().height>0;
       const x=window.__mad4bObserve||{shift:[],lcp:[],fcp:[]};
       return {h1Visible:visible(h1),bodyVisible:visible(b),
         viewportOverflowPx:Math.max(0,document.documentElement.scrollWidth-window.innerWidth),
         styleSheetCount:document.styleSheets.length,
         cssLoaded:[...document.styleSheets].some(s=>s.href?.includes('/litespeed/css/')),
         cls:+x.shift.reduce((a,v)=>a+v,0).toFixed(5),
         lcpMs:x.lcp.at(-1)||null,fcpMs:x.fcp.at(-1)||null,
         currencyHeader:null};
     });
     Object.assign(row,raw);
     row.currency=response?.headers()['x-mad4b-public-ccss-currency']||null;
     row.market=response?.headers()['x-mad4b-public-ccss-market']||null;
     row.ccssReady=response?.headers()['x-mad4b-public-ccss-ready']||null;
     if(row.status!==200)row.alerts.push('public_http_not_200');
     if(!row.bodyVisible)row.alerts.push('visible_body_missing');
     if(row.viewportOverflowPx>6)row.alerts.push('horizontal_overflow');
     if(!row.cssLoaded)row.alerts.push('original_css_not_loaded');
     if(row.cls>0.1)row.alerts.push('layout_shift_exceeds_watch_threshold');
     // Harmless local UI interaction: opening a visible navigation toggle,
     // never following links or submitting search/checkout forms.
     const menu=page.locator('.elementor-menu-toggle:visible,button[aria-label*="menu" i]:visible').first();
     if(await menu.count()>0){
       try{
         const before=await menu.getAttribute('aria-expanded');
         await menu.click({timeout:3000});
         const after=await menu.getAttribute('aria-expanded');
         row.interaction=before!==after?'menu_toggled':'menu_click_no_state_change';
         await page.keyboard.press('Escape');
         if(row.interaction!=='menu_toggled')row.alerts.push('menu_toggle_no_state_change');
       }catch(e){
         row.interaction='menu_error';
         row.alerts.push('menu_click_error');
       }
     }
     const form=page.locator('input[type="search"]:visible,select:visible').first();
     if(await form.count()>0){
       try{
         await form.focus({timeout:2500});
         row.formKeyboardFocusable=await form.evaluate(el=>document.activeElement===el);
         if(!row.formKeyboardFocusable)row.alerts.push('form_focus_failed');
       }catch{row.alerts.push('form_focus_error');}
     }
   }catch(e){
     row.alerts.push('navigation_or_browser_error');
     row.error=String(e).slice(0,150);
   }
   if(errors.length)row.alerts.push('javascript_page_errors');
   if(failedStyles.length)row.alerts.push('stylesheet_requests_failed');
   row.jsErrorCount=errors.length;
   row.failedCssCount=failedStyles.length;
   results.push(row);
   console.log('PUBLIC_CSS_VISUAL_OBSERVATION',JSON.stringify(row));
   await ctx.close();
  }
 }finally{await browser.close();}
 const report={contract:'mad4b.public-css.post-policy-observation.v1',
   host:site,path:route,checkedAt:new Date().toISOString(),
   purpose:'visual_and_interaction_observation_only_never_policy_gate',
   outcomes:results,qualityAlerts:results.reduce((n,r)=>n+r.alerts.length,0)};
 fs.writeFileSync(path.join(out,'visual-observation.json'),JSON.stringify(report,null,2)+'\n',{mode:0o600});
})().catch(e=>{
 console.error('OBSERVE_PUBLIC_CSS_NOT_A_POLICY_GATE',String(e).slice(0,220));
 process.exitCode=3;
});
