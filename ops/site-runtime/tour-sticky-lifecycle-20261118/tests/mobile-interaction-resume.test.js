/**
 * Mobile touch lifecycle regression on a disposable browser profile.
 * Tests direct view, forward/back, reload, foreground after idle and
 * Chromium frozen/active; never claims BFCache without a persisted pageshow.
 */
'use strict';
const {chromium}=require('playwright');
const assert=require('assert');
const tourUrl=process.env.TEST_TOUR_URL;
if(!tourUrl||!/^https:\/\//.test(tourUrl))throw Error('A trusted TEST_TOUR_URL is required');
const origin=new URL(tourUrl).origin;
const homeUrl=process.env.TEST_HOME_URL||origin+'/';
if(new URL(homeUrl).origin!==origin)throw Error('Home must be same origin');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 const context=await browser.newContext({viewport:{width:427,height:952},isMobile:true,hasTouch:true,deviceScaleFactor:1});
 const page=await context.newPage();
 const errors=[],steps=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{
  window.__mad4bTapTrace=[];
  window.addEventListener('pageshow',e=>{window.__mad4bWasBfcache=e.persisted});
  for(const name of ['pointerdown','pointerup','touchstart','touchend','click']){
   document.addEventListener(name,e=>{
    const t=e.target?.closest?.('button,a,[role="button"]')||e.target;
    if(window.__mad4bTapTrace.length<100)window.__mad4bTapTrace.push({name,tag:t?.tagName,cls:String(t?.className||'').slice(0,65),prevented:e.defaultPrevented});
   },true);
  }
 });
 async function open(url){
  const res=await page.goto(url,{waitUntil:'domcontentloaded',timeout:65000});
  assert.strictEqual(res.status(),200,url);
 }
 async function verify(label){
  await page.waitForTimeout(350);
  const consent=page.locator('[data-mad4b-choice="reject"]');
  if(await consent.isVisible().catch(()=>false))await consent.tap({timeout:4500});
  const button=page.locator('button.e-n-menu-toggle').first();
  await button.waitFor({state:'visible',timeout:10000});
  await button.scrollIntoViewIfNeeded({timeout:10000});
  const before=await button.getAttribute('aria-expanded'),rect=await button.boundingBox();
  assert(rect&&rect.width>10&&rect.height>10,'Missing menu button bounds');
  const point=[rect.x+rect.width/2,rect.y+rect.height/2];
  const hit=await page.evaluate(([x,y])=>{
   const e=document.elementFromPoint(x,y),button=document.querySelector('button.e-n-menu-toggle');
   return e===button||button?.contains(e);
  },point);
  assert(hit,'Unrelated layer blocks menu tap');
  await button.tap({timeout:4500});
  const after=await button.getAttribute('aria-expanded');
  assert.notStrictEqual(after,before,'Mobile menu did not toggle');
  const state=await page.evaluate(()=>({
   visibility:document.visibilityState,
   bfcache:!!window.__mad4bWasBfcache,
   ariaBusy:document.querySelectorAll('[aria-busy="true"]').length,
   stickyGuard:window.MAD4BStickyLifecycleGuard?.contract||null,
   events:(window.__mad4bTapTrace||[]).slice(-5).map(x=>x.name)
  }));
  if(page.url().includes('/tours-and-activities/')){
   assert.strictEqual(state.stickyGuard,'mad4b.sticky.lifecycle.v1');
   assert.strictEqual(state.ariaBusy,0,'Busy state after menu tap');
  }
  steps.push({label,hit,before,after,state});
  if(after==='true')await button.tap({timeout:4500});
 }
 try{
  await open(tourUrl);await verify('direct-tour');
  await open(homeUrl);await verify('navigate-home');
  await page.goBack({waitUntil:'domcontentloaded',timeout:65000});await verify('back-tour');
  await page.goForward({waitUntil:'domcontentloaded',timeout:65000});await verify('forward-home');
  await page.goBack({waitUntil:'domcontentloaded',timeout:65000});await verify('back-again');
  const other=await context.newPage();
  await other.goto(homeUrl,{waitUntil:'domcontentloaded',timeout:65000});
  await other.bringToFront();await page.waitForTimeout(2000);
  await page.bringToFront();await verify('foreground-after-idle');await other.close();
  await page.reload({waitUntil:'domcontentloaded',timeout:65000});await verify('reopen-reload');
  const cdp=await context.newCDPSession(page);
  for(let i=0;i<4;i++){
   await page.evaluate(y=>scrollTo(0,y),i%2?1500:0);
   await cdp.send('Page.setWebLifecycleState',{state:'frozen'});
   await new Promise(resolve=>setTimeout(resolve,1200));
   await cdp.send('Page.setWebLifecycleState',{state:'active'});
   await verify('freeze-resume-'+i);
  }
  assert.deepStrictEqual(errors,[],'Site JS exceptions');
  console.log('MOBILE_INTERACTION_LIFECYCLE_PASS',JSON.stringify({
   origin,passed:steps.length,steps:steps.map(x=>x.label),
   realBfcacheObserved:steps.some(x=>x.state.bfcache),errors
  }));
 }finally{await context.close();await browser.close()}
})().catch(e=>{console.error('MOBILE_INTERACTION_LIFECYCLE_FAIL',e.stack||e.message);process.exit(1)});
