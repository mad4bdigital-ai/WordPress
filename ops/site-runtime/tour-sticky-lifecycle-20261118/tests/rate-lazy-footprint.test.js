/* TEST_TOUR_URL=https://... TEST_WIDTH=390 node tests/rate-lazy-footprint.test.js
 * Read-only page test of JetEngine Query Builder count-based Lazy Load geometry.
 */
const {chromium}=require('playwright');
const assert=require('assert');
const url=process.env.TEST_TOUR_URL;
if(!url||!/^https:\/\//.test(url))throw Error('A trusted TEST_TOUR_URL is required');
const width=Number(process.env.TEST_WIDTH||390);
if(!Number.isInteger(width)||width<320||width>767)throw Error('Use a supported mobile width');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
 const page=await browser.newPage({viewport:{width,height:952},isMobile:true,hasTouch:true});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000});
 assert.strictEqual(response.status(),200);
 await page.waitForSelector('#tour_rates_accordion .jet-listing-grid',{timeout:18000});
 await page.waitForTimeout(250);
 const first=await page.evaluate(()=>{
 const grid=document.querySelector('#tour_rates_accordion .jet-listing-grid');
 const widget=grid.closest('.elementor-widget-jet-listing-grid');
 const anchor=widget?.nextElementSibling;
 const visibleForm=[...document.querySelectorAll('form.fluent_form_6')].find(f=>{
  const style=getComputedStyle(f);
  return f.getClientRects().length>0&&style.visibility!=='hidden';
 });
 const root=grid.closest('.elementor-template');
 const config=JSON.parse(grid.getAttribute('data-lazy-load')||'{}');
 const count=root?.querySelector('.jet-engine-query-count[data-query="'+config?.query?.query_id+'"]');
 return {loaded:grid.classList.contains('jet-listing-grid--lazy-load-completed'),
  pending:grid.classList.contains('jet-listing-grid--lazy-load'),
  reserved:grid.classList.contains('mad4b-rate-lazy-reserved'),
  reserve:grid.style.getPropertyValue('--mad4b-rate-lazy-reserve'),
  total:Number(count?.textContent),
  height:grid.getBoundingClientRect().height,
  docTop:grid.getBoundingClientRect().top+scrollY,
  anchorTop:anchor?.getBoundingClientRect().top+scrollY,
  formTop:visibleForm?visibleForm.getBoundingClientRect().top+scrollY:null};
 });
 assert(Number.isFinite(first.anchorTop),'Missing immediate rate-listing follower');
 if(first.pending){
  assert(first.reserved,'Pending rate loader missing reservation class');
  assert.strictEqual(first.reserve,Math.round(first.total*138.5)+'px');
  assert(Math.abs(first.height-Number.parseFloat(first.reserve))<1);
 }
 await page.evaluate(y=>window.scrollTo(0,y),Math.max(0,first.docTop-400));
 await page.waitForSelector('#tour_rates_accordion .jet-listing-grid--lazy-load-completed',{timeout:16000});
 const after=await page.evaluate(()=>{
  const grid=document.querySelector('#tour_rates_accordion .jet-listing-grid');
  const widget=grid.closest('.elementor-widget-jet-listing-grid');
  const anchor=widget?.nextElementSibling;
  const visibleForm=[...document.querySelectorAll('form.fluent_form_6')].find(f=>{
   const style=getComputedStyle(f);
   return f.getClientRects().length>0&&style.visibility!=='hidden';
  });
  return {loaded:grid.classList.contains('jet-listing-grid--lazy-load-completed'),
    height:grid.getBoundingClientRect().height,
    anchorTop:anchor?.getBoundingClientRect().top+scrollY,
    formTop:visibleForm?visibleForm.getBoundingClientRect().top+scrollY:null,
    rows:grid.querySelectorAll('.jet-listing-grid__item').length};
 });
 assert(after.loaded&&after.rows>0,'The real JetEngine rates must load');
 assert(Math.abs(after.anchorTop-first.anchorTop)<3,'Content below rates moved during lazy load');
 if(first.formTop!==null&&after.formTop!==null){
  assert(Math.abs(after.formTop-first.formTop)<3,'Visible Form #6 moved during lazy rate load');
 }
 assert.deepStrictEqual(errors,[],'Page Javascript exceptions');
 console.log('RATE_LAZY_FOOTPRINT_PASS',JSON.stringify({width,first,after,
  delta:after.anchorTop-first.anchorTop,
  visibleFormCompared:first.formTop!==null&&after.formTop!==null}));
 await page.close();
 }finally{await browser.close();}
})().catch(e=>{console.error('RATE_LAZY_FOOTPRINT_FAIL',e.stack||e.message);process.exit(1)});
