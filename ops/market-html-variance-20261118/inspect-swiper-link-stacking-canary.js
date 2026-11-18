'use strict';
const {chromium}=require('playwright'),fs=require('fs');
const output=process.env.MAD4B_SWIPER_STACK_OUTPUT;
if(!output||!output.startsWith('/out/'))throw Error('UNTRUSTED_OUTPUT');
(async()=>{
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 const results=[];
 try{
  for(const width of [390,768]){
   const context=await browser.newContext({
     viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,
     userAgent:'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36',
     extraHTTPHeaders:{Accept:'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
       'X-MAD4B-Bypass-Cache':'1'}
   });
   const page=await context.newPage();
   try{
    const resp=await page.goto('https://tours.dev.mad4b.com/',{waitUntil:'domcontentloaded',timeout:60000});
    if(resp?.status()!==200)throw Error('SOURCE_HTTP_NOT_200');
    const widget=page.locator('.elementor-element-b25dbff').first();
    await widget.scrollIntoViewIfNeeded({timeout:16000});
    await page.waitForFunction(()=>Boolean(document.querySelector('.elementor-element-b25dbff .swiper-initialized')?.swiper?.initialized),null,{timeout:12000});
    await page.waitForTimeout(350);
    async function survey(label){
      const result=await widget.evaluate(root=>{
        const desc=el=>!el?null:{
          tag:el.tagName.toLowerCase(),
          cl:String(el.className||'').slice(0,95),
          z:getComputedStyle(el).zIndex,
          position:getComputedStyle(el).position,
          pointerEvents:getComputedStyle(el).pointerEvents
        };
        const links=[...root.querySelectorAll('a[href]')];
        let viewportLinks=0,unblocked=0,blocked=0;
        const obstacles=[];
        for(const a of links){
          const b=a.getBoundingClientRect();
          if(b.width<3||b.height<3||b.right<=0||b.bottom<=0||b.left>=innerWidth||b.top>=innerHeight)continue;
          viewportLinks++;
          const bxy=[[.5,.5],[.3,.3],[.7,.7],[.2,.8],[.8,.2]];
          let good=false;
          for(const [rx,ry] of bxy){
            const x=Math.min(innerWidth-2,Math.max(2,b.left+b.width*rx));
            const y=Math.min(innerHeight-2,Math.max(2,b.top+b.height*ry));
            const top=document.elementFromPoint(x,y);
            if(top&&(top===a||a.contains(top))){good=true;break;}
            if(obstacles.length<8)obstacles.push({
               link:desc(a),
               hit:desc(top),
               linkParent:desc(a.parentElement),
               hitParent:desc(top?.parentElement),
               centerOffset:{x:Math.round(x-b.left),y:Math.round(y-b.top)}
            });
          }
          if(good)unblocked++;else blocked++;
        }
        return {links:links.length,viewportLinks,unblocked,blocked,obstacles};
      });
      const row={width,label,...result};
      results.push(row);
      console.log('SOURCE_SWIPER_STACK_DIAG',JSON.stringify(row));
      return row;
    }
    await survey('baseline');
    const injections=[
      {label:'anchor_z',css:'.elementor-45927 .elementor-element-3b68b75 .jet-listing-dynamic-link__link {position:relative!important;z-index:30!important;pointer-events:auto!important;}'},
      {label:'widget_z',css:'.elementor-45927 .elementor-element-3b68b75 {position:relative!important;z-index:30!important;pointer-events:auto!important;}.elementor-45927 .elementor-element-3b68b75 .jet-listing-dynamic-link__link {position:relative!important;z-index:31!important;pointer-events:auto!important;}'},
      {label:'overlay_nohit',css:'.elementor-45927 .elementor-element-95d1c9c::before {pointer-events:none!important;}.elementor-45927 .elementor-element-3b68b75 {position:relative!important;z-index:30!important;}.elementor-45927 .elementor-element-3b68b75 a.jet-listing-dynamic-link__link {position:relative!important;z-index:31!important;pointer-events:auto!important;}'}
    ];
    for(const spec of injections){
      const handle=await page.addStyleTag({content:spec.css});
      await page.waitForTimeout(75);
      await survey(spec.label);
      await handle.evaluate(el=>el.remove());
    }
   }finally{await context.close();}
  }
 }finally{await browser.close();}
 fs.writeFileSync(output,JSON.stringify({contract:'mad4b.source.swiper-link-occlusion.canary.v1',only_temporary_browser_styles:true,results},null,2)+'\n',{mode:0o600});
})().catch(e=>{console.error('SOURCE_SWIPER_STACK_HOLD',String(e));process.exitCode=8;});
