'use strict';
const fs=require('fs');const {chromium}=require('playwright');
const code=fs.readFileSync('/assets/mad4b-responsive-media-demand-v1.js','utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  for(const target of [{device:'mobile',width:390,dpr:2,mobile:true},{device:'desktop',width:1440,dpr:1,mobile:false}]){
   const ctx=await browser.newContext({viewport:{width:target.width,height:850},deviceScaleFactor:target.dpr,isMobile:target.mobile,hasTouch:target.mobile,userAgent:target.mobile?'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36':'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/157.0.0.0 Safari/537.36'});
   const page=await ctx.newPage();let rest=[];let errors=[];
   page.on('pageerror',e=>errors.push(e.message.slice(0,120)));
   await page.route('**/wp-json/mad4b/v1/responsive-media?*',async route=>{
     const query=new URL(route.request().url()).searchParams;
     const items=(query.get('items')||'').split(',').filter(Boolean);
     const response={contract:'mad4b.responsive-media.demand.v1',items:{}};
     for(const item of items){
       const [id,width]=item.split(':');
       rest.push({id,width:Number(width)});
       response.items[item]={state:'pending'};
     }
     await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(response)});
   });
   await page.goto('https://wayfatours.com/',{waitUntil:'domcontentloaded',timeout:35000});
   await page.evaluate(()=>window.MAD4BResponsiveMedia={endpoint:location.origin+'/wp-json/mad4b/v1/responsive-media',selector:'.elementor-element-95d1c9c',rootLazyClass:'mad4b-city-geometry-lazy',cardVisibleClass:'mad4b-city-geometry-visible',step:64,minWidth:192,maxWidth:1536});
   await page.addScriptTag({content:code});
   await page.waitForTimeout(550);
   const before=rest.length;
   const cards=page.locator('.elementor-element-95d1c9c');
   const count=await cards.count();
   if(!count)throw new Error('NO_CITY_CARDS');
   await cards.first().scrollIntoViewIfNeeded({timeout:14000});
   await page.waitForTimeout(1300);
   const measure=await page.evaluate(()=>Array.from(document.querySelectorAll('.elementor-element-95d1c9c')).slice(0,6).map(x=>({width:Math.round(x.getBoundingClientRect().width),term:x.closest('.jet-listing-grid__item')?.className?.match(/jet-listing-dynamic-post-(\d+)/)?.[1],intersect:x.getBoundingClientRect().top<window.innerHeight&&x.getBoundingClientRect().bottom>0})));
   const bad=rest.filter(x=>x.width<192||x.width>1536||x.width%64!==0);
   console.log('ON_DEMAND_BROWSER_QA',JSON.stringify({device:target.device,view:target.width,dpr:target.dpr,cards:count,requests_before_visible:before,requested:rest,geometry:measure,invalid_sizes:bad.length,errors:errors.slice(0,3)}));
   if(bad.length)throw new Error('OUT_OF_BUCKET_REQUEST');
   await ctx.close();
  }
 } finally{await browser.close();}
})().catch(e=>{console.error('QA_FAILED',e.stack?.slice(0,850));process.exit(1)});
