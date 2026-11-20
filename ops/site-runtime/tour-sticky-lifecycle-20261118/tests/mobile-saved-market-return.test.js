/**
 * Persistent mobile-market interaction check in isolated Chrome context.
 * TEST_TOUR_URL=https://... node tests/mobile-saved-market-return.test.js
 * Updates only the browser's temporary visitor preference via the native UI.
 * No WP-admin, booking form submission, orders, or server-side configuration.
 */
'use strict';
const assert=require('assert');
const {chromium}=require('playwright');
const url=process.env.TEST_TOUR_URL;
const market=process.env.TEST_COUNTRY||'EG';
if(!url||!url.startsWith('https://'))throw Error('Trusted TEST_TOUR_URL required');
if(!/^[A-Z]{2}$/.test(market))throw Error('Invalid ISO 3166 alpha-2 country code');
const origin=new URL(url).origin;
(async()=>{
 const browser=await chromium.launch({
  channel:'chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']
 });
 const context=await browser.newContext({
  viewport:{width:427,height:952},isMobile:true,hasTouch:true,deviceScaleFactor:2
 });
 const page=await context.newPage(),errors=[],api=[],steps=[];
 page.on('pageerror',e=>errors.push(String(e.stack||e.message).slice(0,4000)));
 page.on('response',r=>{
  if(r.url().startsWith(origin+'/wp-json/visitor-preferences/v1/')){
   api.push({path:new URL(r.url()).pathname,status:r.status()});
  }
 });
 await page.addInitScript(()=>{
  addEventListener('pageshow',e=>{window.__mad4bPagePersisted=e.persisted});
 });
 async function verify(label){
  const button=page.locator('button.e-n-menu-toggle').first();
  await button.scrollIntoViewIfNeeded({timeout:10000});
  const before=await button.getAttribute('aria-expanded');
  const start=Date.now();
  await button.tap({timeout:7000});
  const after=await button.getAttribute('aria-expanded');
  const elapsed=Date.now()-start;
  assert.notStrictEqual(after,before,'Menu failed after '+label);
  const state=await page.evaluate(()=>({
    blocked:document.querySelectorAll('[aria-busy="true"]').length,
    overlays:document.querySelectorAll('.header-context-controls.is-open').length,
    bfcache:!!window.__mad4bPagePersisted
  }));
  assert.strictEqual(state.blocked,0,'Stale busy state at '+label);
  assert.strictEqual(state.overlays,0,'Stale header preference overlay at '+label);
  steps.push({label,elapsed,changed:true,...state});
  if(after==='true')await button.tap({timeout:7000});
 }
 try{
  const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000});
  assert.strictEqual(response.status(),200);
  const consent=page.locator('[data-mad4b-choice="reject"]');
  if(await consent.isVisible().catch(()=>false))await consent.tap({timeout:7500});
  const root=page.locator('.elementor-location-header .header-context-controls-mobile').first();
  await root.locator('[data-header-context-toggle]').tap({timeout:8000});
  await root.locator('select[name="market_country"]').selectOption(market,{timeout:9000});
  await page.waitForFunction(()=>{
    const el=document.querySelector('.elementor-location-header .header-context-controls-mobile');
    return !el?.hasAttribute('aria-busy') &&
      (el?.querySelector('[data-header-context-status]')?.textContent||'').trim()==='Saved';
  },null,{timeout:16000});
  assert.strictEqual(await root.locator('select[name="market_country"]').inputValue(),market);
  assert(api.some(x=>x.path.endsWith('/apply')&&x.status===200),'Native market apply did not succeed');
  if(await root.locator('[data-header-context-toggle]').getAttribute('aria-expanded')==='true'){
   await root.locator('[data-header-context-toggle]').tap({timeout:6500});
  }
  await verify('saved-market-direct');
  const homes=page.locator('header a[href="'+origin+'"]');
  assert(await homes.count()>0,'No canonical header home anchor');
  let home=null;
  for(const candidate of await homes.all()){
   if(await candidate.isVisible()){home=candidate;break;}
  }
  if(!home){
   const menu=page.locator('button.e-n-menu-toggle').first();
   await menu.tap({timeout:9000});
   for(const candidate of await homes.all()){
    if(await candidate.isVisible()){home=candidate;break;}
   }
  }
  assert(home,'No visible header navigation anchor after opening the native menu');
  await home.tap({timeout:9000});
  await page.waitForURL(x=>new URL(x).pathname==='/',{timeout:30000});
  await page.goBack({waitUntil:'domcontentloaded',timeout:50000});
  await verify('saved-market-browser-back');
  await page.reload({waitUntil:'domcontentloaded',timeout:50000});
  await verify('saved-market-reload');
  await page.waitForTimeout(2400);
  await verify('saved-market-idle');
  assert.deepStrictEqual(errors,[],'Site JavaScript errors');
  console.log('SAVED_MARKET_TOUCH_PASS',JSON.stringify({
    origin,country:market,steps,api:api.slice(-4),errors,
    actualBfcacheObserved:steps.some(x=>x.bfcache)
  }));
 }finally{await context.close();await browser.close();}
})().catch(e=>{console.error('SAVED_MARKET_TOUCH_FAIL',e.stack||e.message);process.exit(1)});
