/* Validate archive price dock ownership, including when its native portaling
 * script is intentionally blocked in the disposable browser test only.
 * TEST_ORIGIN=https://your-site node tests/archive-preportal-price.test.js
 */
const {chromium}=require('playwright');
const origin=process.env.TEST_ORIGIN;
if(!origin||!/^https:\/\/[^/]+$/.test(origin))throw Error('Trusted TEST_ORIGIN required');
(async()=>{
 const b=await chromium.launch({channel:'chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 let fails=0;
 try{
 for(const degraded of [false,true]){
  const p=await b.newPage({viewport:{width:427,height:952},isMobile:true,hasTouch:true});
  let blocked=0,errors=[];
  if(degraded)await p.route('**/mad4b-mobile-scroll-action-viewport-v2.js*',async route=>{blocked++;await route.abort()});
  p.on('pageerror',e=>errors.push(e.message));
  try{
   const res=await p.goto(origin+'/tours-and-activities/',{waitUntil:'domcontentloaded',timeout:58000});
   await p.waitForTimeout(650);
   const evidence=await p.evaluate(()=>{
    const dock=document.querySelector('.elementor-element-650c69');
    const label=dock?.querySelector('.mad4b-archive-price-value');
    if(!dock||!label)return {missing:true};
    // Synthetic price text only in this isolated browser to verify that the
    // CSS gate, not a pending REST request, owns premature visibility.
    label.hidden=false;
    label.textContent='Start from USD 28.00 / per person';
    label.closest('.elementor-widget-container')?.style.setProperty('visibility','visible');
    label.closest('.elementor-widget')?.style.removeProperty('display');
    const d=getComputedStyle(dock),l=getComputedStyle(label),r=dock.getBoundingClientRect();
    return {mounted:dock.dataset.mad4bDockMounted==='viewport',
      host:!!document.querySelector('body > .mad4b-mobile-dock-host'),
      labelCount:document.querySelectorAll('.mad4b-archive-price-value').length,
      dockCount:document.querySelectorAll('.elementor-element-650c69').length,
      dockVisibility:d.visibility,labelVisibility:l.visibility,
      dockPosition:d.position,rect:[r.x,r.y,r.width,r.height].map(Math.round),
      hasPolicy:!!document.querySelector('#mad4b-mobile-bottom-nav-policy-v1')};
   });
   const pass=res.status()===200&&!evidence.missing&&evidence.hasPolicy&&
     evidence.labelCount===1&&evidence.dockCount===1&&errors.length===0&&
     (degraded?blocked===1&&!evidence.mounted&&evidence.dockVisibility==='visible'&&evidence.labelVisibility==='hidden':
      evidence.mounted&&evidence.host&&evidence.labelVisibility==='visible');
   if(!pass)fails++;
   console.log('ARCHIVE_PORTAL_PRICE_TEST',JSON.stringify({origin,degraded,pass,blocked,evidence,errors}));
  }catch(e){fails++;console.log('ARCHIVE_PORTAL_PRICE_FAIL',String(e.stack||e).slice(0,650))}
  finally{await p.close()}
 }
 if(fails)throw Error('ARCHIVE_PRICE_TEST_FAILURES:'+fails);
 }finally{await b.close()}
})().catch(e=>{console.error('FATAL',String(e.stack||e).slice(0,800));process.exit(1)});
