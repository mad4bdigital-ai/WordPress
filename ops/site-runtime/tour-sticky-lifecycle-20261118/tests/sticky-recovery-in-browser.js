const{chromium}=require('playwright');
(async()=>{
const b=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
try{
 const p=await b.newPage({viewport:{width:427,height:952},isMobile:true,hasTouch:true});
 let errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto('https://tours.dev.mad4b.com/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/?sticky_guard_injection=1',{waitUntil:'domcontentloaded',timeout:50000});
 await p.waitForTimeout(650);
 await p.evaluate(()=>scrollTo(0,3000));await p.waitForTimeout(300);
 const before=await p.evaluate(()=>{let e=document.querySelector('.elementor-element-f846cdc'),$=window.jQuery;return{active:e.classList.contains('elementor-sticky--active'),backup:!!$(e).data('css-backup-unsticky'),spacers:e.parentElement.querySelectorAll('.elementor-sticky__spacer').length}});
 await p.evaluate(()=>{let e=document.querySelector('.elementor-element-f846cdc');window.jQuery(e).removeData('css-backup-unsticky');});
 await p.evaluate(()=>scrollTo(0,0));await p.waitForTimeout(280);
 const recovered=await p.evaluate(()=>{let e=document.querySelector('.elementor-element-f846cdc'),$=window.jQuery;return{active:e.classList.contains('elementor-sticky--active'),position:getComputedStyle(e).position,spacers:e.parentElement.querySelectorAll('.elementor-sticky__spacer').length,guard:window.MAD4BStickyLifecycleGuard}});
 await p.evaluate(()=>{let e=document.querySelector('.elementor-element-f846cdc'),$=window.jQuery;$(e).sticky({to:'top',offset:0,classes:{sticky:'elementor-sticky',stickyActive:'elementor-sticky--active',stickyEffects:'elementor-sticky--effects',spacer:'elementor-sticky__spacer'}})});
 await p.evaluate(()=>scrollTo(0,3000));await p.waitForTimeout(200);
 await p.evaluate(()=>scrollTo(0,0));await p.waitForTimeout(250);
 const after=await p.evaluate(()=>{let e=document.querySelector('.elementor-element-f846cdc');return{active:e.classList.contains('elementor-sticky--active'),spacers:e.parentElement.querySelectorAll('.elementor-sticky__spacer').length,guard:window.MAD4BStickyLifecycleGuard}});
 console.log('STICKY_INJECTION',JSON.stringify({before,recovered,after,errors,pass:before.active&&before.backup&&recovered.active===false&&recovered.spacers===1&&recovered.guard.recoveries>=1&&after.guard.reinitializations>=1&&after.spacers===1&&errors.length===0}));
 await p.close();
}finally{await b.close()}
})().catch(e=>{console.log('FATAL',String(e.stack||e).slice(0,1000));process.exit(1)});
