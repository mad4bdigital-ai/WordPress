const{chromium}=require('playwright');
const origin=process.env.TEST_ORIGIN;
if(!origin||!/^https:\/\/[^/]+$/.test(origin))throw Error('Trusted TEST_ORIGIN required');
const routes=[
 {path:'/tours-and-activities/',selector:'.mad4b-mobile-dock-host'},
 {path:'/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/',selector:'.elementor-element-d8505dd.elementor-sticky--active'},
 {path:'/destination/africa-containent/egypt/cairo/',selector:'.elementor-element-9f6293a.elementor-sticky--active'}
];
(async()=>{
const b=await chromium.launch({channel:'chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
try{
 for(const width of [390,427]){
  for(const item of routes){
   const p=await b.newPage({viewport:{width,height:952},isMobile:true,hasTouch:true});
   const errors=[];p.on('pageerror',e=>errors.push(e.message));
   try{
    const r=await p.goto(origin+item.path,{waitUntil:'domcontentloaded',timeout:50000});
    await p.waitForTimeout(550);
    const consent=p.locator('[data-mad4b-choice="reject"]');
    if(await consent.isVisible().catch(()=>false))await consent.tap({timeout:5000});
    await p.evaluate(()=>scrollTo(0,1250));await p.waitForTimeout(320);
    const snap=()=>p.evaluate(selector=>{
      const el=document.querySelector(selector),style=el?getComputedStyle(el):null,r=el?.getBoundingClientRect();
      const clones=[...document.querySelectorAll('.elementor-sticky__spacer')].filter(e=>e.matches('.elementor-element-d8505dd,.elementor-element-9f6293a'));
      return {found:!!el,visible:!!style&&style.visibility==='visible'&&r.width>100&&r.height>40,visibility:style?.visibility,pointer:style?.pointerEvents,opacity:style?.opacity,height:Math.round(r?.height||0),count:document.querySelectorAll(selector).length,clones:clones.length,scroll:scrollY,cssMarker:!!document.querySelector('#mad4b-mobile-bottom-nav-policy-v1'),menu:document.querySelector('.elementor-location-header .e-n-menu-toggle')?.getAttribute('aria-expanded')}
    },item.selector);
    const initial=await snap();
    const menu=p.locator('button.e-n-menu-toggle').first();await menu.tap({timeout:7500});await p.waitForTimeout(130);
    const opened=await snap();
    await menu.tap({timeout:7500});await p.waitForTimeout(130);
    const closed=await snap();
    const pass=r.status()===200&&initial.visible&&opened.visibility==='hidden'&&opened.pointer==='none'&&closed.visible&&opened.cssMarker&&errors.length===0;
    if(!pass)failures++;
    console.log('BOTTOM_POLICY_TEST',JSON.stringify({width,path:item.path,pass,initial,opened,closed,errors}).slice(0,7000));
   }catch(e){failures++;console.log('BOTTOM_POLICY_FAIL',width,item.path,String(e.stack||e).slice(0,750))}finally{await p.close()}
  }
 }
}finally{await b.close()}
})().catch(e=>{console.log('FATAL',String(e.stack||e).slice(0,1100));process.exit(1)});
