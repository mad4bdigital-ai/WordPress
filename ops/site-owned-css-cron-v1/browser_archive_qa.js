'use strict';
// Observational (read-only) browser QA of this site's anonymous archive.
// The operator controls policy before each run; this script never writes WordPress.
const {chromium}=require('playwright'),fs=require('fs'),crypto=require('crypto');
const host=process.env.MAD4B_QA_HOST;
const phase=process.env.MAD4B_QA_PHASE;
const root=process.env.MAD4B_QA_OUTPUT||'/out';
if(!/^https:\/\/[a-z0-9.-]+$/.test(host||'') || !/^(baseline|candidate)$/.test(phase||''))process.exit(7);
const url=host+'/tours-and-activities/';
const agent='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';
async function run(){
 const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 const results=[];
 try{
  for(const width of [390,768]){
   const context=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:1,isMobile:width===390,hasTouch:width===390,userAgent:agent,
    extraHTTPHeaders:{Accept:'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8','X-MAD4B-Bypass-Cache':'1'}});
   const page=await context.newPage();const errors=[];
   page.on('pageerror',e=>errors.push(String(e.message||e).slice(0,200)));
   let response;
   try{response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000});await page.waitForTimeout(2800)}
   catch(e){results.push({width,error:String(e).slice(0,220)});await context.close();continue}
   await page.addStyleTag({content:'*,*::before,*::after{animation-duration:0s!important;transition-duration:0s!important;scroll-behavior:auto!important}'});
   const data=await page.evaluate(()=>{
    const classes=[...document.querySelectorAll('[class*="elementor-dcss-"]')].flatMap(n=>
     String(n.className||'').match(/elementor-dcss-(?:mad4b-[a-f0-9]{16}|[0-9]{10,22})/g)||[]);
    const cards=[...document.querySelectorAll('[class*="jet-listing-dynamic-post-"]')].map(x=>
     (String(x.className||'').match(/jet-listing-dynamic-post-([0-9]+)/)||[])[1]).filter(Boolean);
    const links=[...document.querySelectorAll('a[href*="/tours-and-activities/"]')].map(n=>n.getAttribute('href')).filter(Boolean);
    const priceTokens=(document.body.innerText||'').match(/(?:USD|EUR|EGP|GBP)\s*\d[\d,.]*|\d[\d,.]*\s*(?:USD|EUR|EGP|GBP)/g)||[];
    const sheet=[...document.querySelectorAll('link[rel="stylesheet"]')].map(x=>x.href).filter(x=>x.includes('/litespeed/css/'));
    let boxes=[...document.querySelectorAll('.jet-listing-grid__item')].slice(0,8).map(x=>{const r=x.getBoundingClientRect();return {x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),h:Math.round(r.height)}});
    const xOverflow=Math.max(0,Math.ceil(document.documentElement.scrollWidth-innerWidth));
    return {cards,links,priceTokens,stable:classes.filter(x=>x.includes('-mad4b-')).length,
      volatile:classes.filter(x=>/elementor-dcss-\d/.test(x)).length,
      cssAssets:sheet.map(x=>x.split('/').slice(-1)[0].slice(0,50)),
      boxes,scrollWidth:document.documentElement.scrollWidth,innerWidth,xOverflow,
      menuButtons:document.querySelectorAll('button[aria-expanded]').length,
      totalImages:document.images.length,brokenImages:[...document.images].filter(x=>x.complete&&x.naturalWidth===0).length};
   });
   const expanded=page.locator('button[aria-expanded]').first();
   let toggle='not_applicable';
   try {
    if(await expanded.count()){
      const before=await expanded.getAttribute('aria-expanded');
      await expanded.click({timeout:3500});
      await page.waitForTimeout(250);
      const after=await expanded.getAttribute('aria-expanded');
      toggle=(before!==after)?'changed':'unchanged';
    }
   }catch(e){toggle='failed';}
   const screenshot=root+'/archive-'+phase+'-'+width+'.png';
   await page.screenshot({path:screenshot,fullPage:false,timeout:15000});
   const sha=a=>crypto.createHash('sha256').update(a.join('|')).digest('hex');
   const row={width,http:response?.status(),...data,
      cardsDigest:sha(data.cards),pricesDigest:sha(data.priceTokens),linksDigest:sha(data.links),
      errors:errors.slice(0,8),toggle};
   delete row.cards;delete row.links;delete row.priceTokens;
   results.push(row);
   console.log('ARCHIVE_BROWSER_READBACK',phase,width,JSON.stringify(row));
   await context.close();
  }
 }finally{await browser.close()}
 const output={contract:'mad4b.css.site-owned.archive-browser.v1',phase,host,results};
 fs.writeFileSync(root+'/archive-'+phase+'-browser.json',JSON.stringify(output,null,2)+'\n');
 if(results.length!==2 || results.some(x=>x.error || x.http!==200))process.exitCode=9;
}
run().catch(e=>{console.error('ARCHIVE_QA_ERROR',e.message);process.exit(3)});
