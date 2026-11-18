'use strict';
const {chromium}=require('playwright'),crypto=require('crypto'),fs=require('fs');
const site='https://tours.dev.mad4b.com/';
const out=process.env.MAD4B_CARD_QA_OUT;
if(!out || !out.startsWith('/out/'))throw Error('INVALID_SOURCE_BROWSER_QA_OUTPUT');
const UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
const ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
const hash = s => crypto.createHash('sha256').update(s).digest('hex').slice(0,20);
(async () => {
 const browser=await chromium.launch({args:['--no-sandbox','--disable-dev-shm-usage']});
 const results=[];
 try{
  for(const width of [390,768]){
   for(const variant of ['active_cached','bypass_native']){
    const head={Accept:ACCEPT};
    if(variant==='bypass_native'){
     head['X-MAD4B-Source-Cards-QA']='v1';
     head['X-MAD4B-Bypass-Cache']='1';
    }
    const context=await browser.newContext({
      viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,
      userAgent:UA,extraHTTPHeaders:head,
    });
    const pageErrors=[];
    const page=await context.newPage();
    page.on('pageerror',e=>pageErrors.push(String(e.message||e).slice(0,100)));
    // Privacy QA never records a test visitor's choice on the live site.
    // The real UI is exercised; only the isolated browser's optional
    // consent-receipt network side effect is intercepted.
    await page.route(url=>{
      const u=new URL(url);
      return u.pathname.includes('/mad4b-consent/v1/receipt')
        || (u.searchParams.get('rest_route')||'').includes('/mad4b-consent/v1/receipt');
    },route=>route.fulfill({status:200,contentType:'application/json',body:'{"success":true,"data":{"test":true}}'}));
    try{
     const response=await page.goto(site,{waitUntil:'domcontentloaded',timeout:60000});
     if(!response || response.status()!==200)throw Error('HTML_NOT_200:'+variant+':'+width);
     const headers=await response.allHeaders();
     if(headers['x-mad4b-mobile-css-ready']!=='1')throw Error('MOBILE_CSS_NOT_READY');
     const banner=page.locator('#mad4b-consent-banner');
     await banner.waitFor({state:'visible',timeout:12000});
     await banner.locator('[data-mad4b-choice="reject"]').click({timeout:9000});
     await banner.waitFor({state:'hidden',timeout:12000});
     const consentDismissed=await banner.isHidden();
     if(!consentDismissed)throw Error('CONSENT_UI_DID_NOT_CLOSE');
     const widget=page.locator('.elementor-element-b25dbff').first();
     await widget.waitFor({state:'attached',timeout:12000});
     await widget.scrollIntoViewIfNeeded({timeout:15000});
     // Lazy JetEngine/Swiper mounts can change both cloned slides and
     // pointer hit targets after DOMContentLoaded. Check an actual
     // initialized slider before accepting any click as a UI proof.
     let swiperReady=false;
     try{
       await page.waitForFunction(()=>{
         const root=document.querySelector('.elementor-element-b25dbff');
         const slider=root?.querySelector('.jet-listing-grid__slider.swiper');
         return Boolean(slider && (slider.swiper?.initialized ||
           slider.classList.contains('swiper-initialized')));
       },null,{timeout:12000});
       swiperReady=true;
     }catch(e){swiperReady=false;}
     await page.waitForTimeout(600);
     const info=await widget.evaluate(el=>{
       const visible=elem=>{
         const s=getComputedStyle(elem);const b=elem.getBoundingClientRect();
         return s.display!=='none' && s.visibility!=='hidden' && b.width>2 && b.height>2;
       };
       const cards=[...el.querySelectorAll('.jet-listing-grid__item')];
       const links=[...el.querySelectorAll('a[href]')].filter(visible);
       const b=el.getBoundingClientRect();
       const allLinks=[...el.querySelectorAll('a[href]')];
       const candidates=[];
       const blockers=[];
       for(let i=0;i<allLinks.length;i++){
         const a=allLinks[i],r=a.getBoundingClientRect();
         if(r.width<2||r.height<2||r.right<=0||r.bottom<=0||
            r.left>=innerWidth||r.top>=innerHeight||r.top<0 ||
            getComputedStyle(a).pointerEvents==='none')continue;
         const cx=Math.min(innerWidth-2,Math.max(2,r.left+r.width/2));
         const cy=Math.min(innerHeight-2,Math.max(2,r.top+r.height/2));
         const top=document.elementFromPoint(cx,cy);
         if(top && (a===top||a.contains(top))) candidates.push(i);
         else blockers.push(top?top.tagName.toLowerCase():'none');
       }
       return {
          hitTestCandidates:candidates,
          blockedLinkCount:blockers.length,
          blockerTags:[...new Set(blockers)].slice(0,3),
          cardCount:cards.length,linkCount:links.length,
          hrefs:links.map(x=>x.getAttribute('href')),
          widgetWidth:Math.round(b.width),widgetHeight:Math.round(b.height),
          visibleCardCount:cards.filter(visible).length,
          computedDisplay:getComputedStyle(el).display,
          volatileDcssCount:(el.outerHTML.match(/elementor-dcss-[0-9]{10,22}/g)||[]).length
       };
     });
     if(info.cardCount<6 || info.linkCount===0 || info.volatileDcssCount!==0)
       throw Error('TOP_CITIES_STRUCTURE_NOT_VERIFIED:'+variant+':'+width);
     let actionable=false,actionabilityError='',keyboardFocusable=false;
     if(info.hitTestCandidates.length){
       const link=widget.locator('a[href]').nth(info.hitTestCandidates[0]);
       try{
         // Actual physical Playwright click, not a synthetic DOM click.
         // The temporary capture handler keeps the user on the same
         // test page without opening the destination or mutating it.
         await page.evaluate(()=>{
           window.__mad4bTermCardClickCount=0;
           document.addEventListener('click',event=>{
             const anchor=event.target.closest?.('.elementor-element-b25dbff a[href]');
             if(!anchor)return;
             event.preventDefault();
             event.stopImmediatePropagation();
             window.__mad4bTermCardClickCount++;
           },true);
         });
         await link.click({timeout:9000});
         actionable=(await page.evaluate(()=>window.__mad4bTermCardClickCount))===1;
         await link.focus({timeout:2500});
         keyboardFocusable=await link.evaluate(el=>document.activeElement===el);
       }catch(e){actionabilityError=String(e.message||e).slice(0,100);}
     }else{actionabilityError='no-visible-unoccluded-anchor';}
     const row={
       contract:'mad4b.source.top-cities.browser-qa.v3',
       width,variant,http:response.status(),cssReady:headers['x-mad4b-mobile-css-ready'],
       cacheStatus:headers['x-mad4b-preset-cache']||null,
       cards:info.cardCount,links:info.linkCount,linkDigest:hash(JSON.stringify(info.hrefs)),
       widthPx:info.widgetWidth,heightPx:info.widgetHeight,
       visibleCards:info.visibleCardCount,display:info.computedDisplay,
       volatileDcss:info.volatileDcssCount,realClickDelivered:actionable,
       keyboardFocusable,swiperReady,consentDismissed,
       hitTestCandidateCount:info.hitTestCandidates.length,
       blockedLinkCount:info.blockedLinkCount,blockerTags:info.blockerTags,
       actionabilityError,
       jsErrorCount:pageErrors.length,
       errorKinds:pageErrors.map(x=>/cannot read properties of undefined/i.test(x)?'undefined_property':
         /listener indicated an asynchronous response/i.test(x)?'extension_listener':
         /undefined/i.test(x)?'undefined_runtime':'other').slice(0,3),
     };
     results.push(row);
     console.log('SOURCE_TOP_CITIES_CHROMIUM',JSON.stringify(row));
    }finally{await context.close();}
   }
  }
 }finally{await browser.close();}
 for(const width of [390,768]){
  const native=results.find(x=>x.width===width && x.variant==='bypass_native');
  const canary=results.find(x=>x.width===width && x.variant==='active_cached');
  if(!native || !canary
     || native.cards!==canary.cards || native.links!==canary.links
     || native.linkDigest!==canary.linkDigest
     || native.widthPx!==canary.widthPx
     || Math.abs(native.heightPx-canary.heightPx)>3
     || native.visibleCards!==canary.visibleCards
     || native.volatileDcss!==0 || canary.volatileDcss!==0
     || !native.swiperReady || !canary.swiperReady
     || !native.consentDismissed || !canary.consentDismissed
     || !native.realClickDelivered || !canary.realClickDelivered
     || !native.keyboardFocusable || !canary.keyboardFocusable
     || canary.jsErrorCount>native.jsErrorCount)
     throw Error('BROWSER_MOBILE_TOP_CITIES_CANARY_PARITY_HOLD_'+width);
 }
 fs.writeFileSync(out,JSON.stringify({contract:'mad4b.top-cities-canary-browser-qa.v3',
  passed:true,source_site:'tours.dev.mad4b.com',results},null,2)+'\n',{mode:0o600});
 console.log('SOURCE_TOP_CITIES_BROWSER_QA_PASSED_390_768',results.length);
})().catch(e=>{console.error('SOURCE_TOP_CITIES_BROWSER_QA_HOLD',String(e));process.exitCode=8;});
