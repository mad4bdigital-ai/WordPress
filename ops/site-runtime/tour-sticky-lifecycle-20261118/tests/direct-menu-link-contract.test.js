/**
 * Verify the actual source-owned Elementor direct-link contract with a
 * disposable browser profile. It never changes WordPress or booking data.
 * TEST_TOUR_URL=https://... node tests/direct-menu-link-contract.test.js
 */
const assert=require('assert');
const {chromium}=require('playwright');
const url=process.env.TEST_TOUR_URL;
if(!url||!url.startsWith('https://'))throw Error('Trusted TEST_TOUR_URL required');
const origin=new URL(url).origin;
(async()=>{
 const browser=await chromium.launch({
  channel:'chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']
 });
 const page=await browser.newPage({viewport:{width:427,height:952},isMobile:true,hasTouch:true});
 const errors=[];page.on('pageerror',e=>errors.push(String(e.stack||e.message).slice(0,1000)));
 try{
  const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000});
  assert.strictEqual(response.status(),200);
  const consent=page.locator('[data-mad4b-choice="reject"]');
  if(await consent.isVisible().catch(()=>false))await consent.tap({timeout:7000});
  await page.waitForFunction(()=>window.MAD4BDirectMenuLinkGuard?.contract==='mad4b.n-menu.direct-link.v1',null,{timeout:12000});
  const before=await page.evaluate(()=>{
   const list=[...document.querySelectorAll('.elementor-location-header .elementor-widget-n-menu .e-n-menu-title')];
   const isDirect=x=>!!x.querySelector(':scope > a.e-n-menu-title-container[href]')&&!x.querySelector(':scope > .e-n-menu-dropdown-icon');
   const direct=list.filter(isDirect),dropdowns=list.filter(x=>!!x.querySelector(':scope > .e-n-menu-dropdown-icon'));
   return {count:list.length,direct:direct.length,dropdowns:dropdowns.length,
     marked:direct.filter(x=>x.classList.contains('link-only')).length,
     wrong:dropdowns.filter(x=>x.classList.contains('link-only')).length,
     guard:window.MAD4BDirectMenuLinkGuard?.contract};
  });
  assert(before.direct>=1 && before.dropdowns>=1);
  assert.strictEqual(before.direct,before.marked,'Direct menu links not marked');
  assert.strictEqual(before.wrong,0,'Dropdown was incorrectly marked as direct');
  // A real child-list re-render must refresh classification, without CSS hacks
  // or adding click interceptors. All mutations are browser-local.
  const lifecycle=await page.evaluate(async()=>{
   const title=[...document.querySelectorAll('.elementor-location-header .elementor-widget-n-menu .e-n-menu-title')]
      .find(x=>x.classList.contains('link-only')&&x.querySelector(':scope > a[href]'));
   if(!title)return {error:'direct title absent'};
   const button=document.createElement('button');
   button.type='button';button.className='e-n-menu-dropdown-icon';button.style.display='none';
   title.appendChild(button);
   await new Promise(resolve=>setTimeout(resolve,100));
   const removed=!title.classList.contains('link-only');
   button.remove();
   await new Promise(resolve=>setTimeout(resolve,100));
   return {removedWhenDropdownAdded:removed,recoveredWhenRemoved:title.classList.contains('link-only')};
  });
  assert(lifecycle.removedWhenDropdownAdded && lifecycle.recoveredWhenRemoved,
    'Dynamic menu re-render did not reconcile direct link class');
  const menu=page.locator('button.e-n-menu-toggle').first();
  await menu.tap({timeout:9000});
  const home=page.locator('.elementor-location-header .elementor-widget-n-menu .e-n-menu-title a[href="'+origin+'"]').first();
  await home.tap({timeout:10000});
  await page.waitForURL(u=>new URL(u).pathname==='/',{timeout:30000});
  await page.goBack({waitUntil:'domcontentloaded',timeout:55000});
  const state=await menu.getAttribute('aria-expanded');
  await menu.tap({timeout:8000});
  assert.notStrictEqual(await menu.getAttribute('aria-expanded'),state,'First menu tap failed after Back');
  assert.deepStrictEqual(errors,[],'JavaScript errors during actual direct-link journey');
  console.log('DIRECT_MENU_LINK_PASS',JSON.stringify({
   origin,before,lifecycle,backMenuResponded:true,pageerrors:errors,
   previousBfcacheUnverified:true
  }));
 }finally{await page.close();await browser.close()}
})().catch(e=>{console.error('DIRECT_MENU_LINK_FAIL',String(e.stack||e).slice(0,2700));process.exit(1)});
