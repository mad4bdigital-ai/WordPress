'use strict';
const { chromium }=require('/usr/src/app/node_modules/playwright');const fs=require('fs');
const path=process.argv[2]||'/',repeats=Math.min(3,Math.max(1,Number(process.argv[3]||3)));
if(!/^\/[a-z0-9\/._~-]*$/.test(path)||path.includes('//')||path.includes('..'))process.exit(11);
const base='https://tours.dev.mad4b.com';
(async()=>{let browser=await chromium.launch({headless:true,args:['--no-sandbox']});let out=[];
for(let round=0;round<repeats;round++)for(let mobile of [false,true])for(let mode of ['native-v1','canary-v1']){
 const ctx=await browser.newContext({viewport:mobile?{width:390,height:844}:{width:1440,height:900},userAgent:mobile?'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1':undefined,ignoreHTTPSErrors:true});const p=await ctx.newPage();let failures=[],pageErrors=[];
 p.on('response',x=>{if(x.url().includes('/wp-content/')&&x.status()>=400)failures.push(x.status())});p.on('pageerror',e=>pageErrors.push(e.message.slice(0,100)));
 await p.addInitScript(()=>{window.__cssVitals={lcp:0,cls:0};try{new PerformanceObserver(x=>{for(const e of x.getEntries())window.__cssVitals.lcp=Math.round(e.startTime)}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(x=>{for(const e of x.getEntries())if(!e.hadRecentInput)window.__cssVitals.cls+=e.value}).observe({type:'layout-shift',buffered:true});}catch(e){}});
 await p.setExtraHTTPHeaders({'X-MAD4B-DYNAMIC-CSS':mode,'X-MAD4B-BYPASS-CACHE':'1'});
 let status=0,sample={};try{let r=await p.goto(base+path,{waitUntil:'domcontentloaded',timeout:35000});status=r.status();await p.waitForTimeout(3600);await p.waitForLoadState('load',{timeout:8000}).catch(()=>{});await p.waitForTimeout(400);
 sample=await p.evaluate(()=>{let n=performance.getEntriesByType('navigation')[0];function c(sel){let e=document.querySelector(sel);if(!e)return null;let s=getComputedStyle(e),r=e.getBoundingClientRect();return [Math.round(r.width),Math.round(r.height),s.display,s.position,s.fontSize,s.color];}
 let css=[...document.querySelectorAll('[data-mad4b-autonomous]')];return {ttfb:Math.round(n.responseStart-n.requestStart),fcp:Math.round(performance.getEntriesByName('first-contentful-paint')[0]?.startTime||0),lcp:window.__cssVitals?.lcp||0,cls:+(window.__cssVitals?.cls||0).toFixed(5),tags:css.length,allReady:css.every(x=>x.media==='all'&&!!x.sheet),top:[c('h1'),c('.site-header'),c('header'),c('.elementor-widget-heading'),c('.jet-listing-grid__items')],path:location.pathname};});
 await p.evaluate(()=>window.scrollTo(0,Math.floor(document.body.scrollHeight/2)));await p.waitForTimeout(200);sample.mid=await p.evaluate(()=>{let e=document.querySelector('.elementor-sticky--active,[data-settings*="sticky"]');return e?getComputedStyle(e).position:null;});
 }catch(e){sample.error=String(e).slice(0,200);}out.push({round,mobile,mode,status,failures,pageErrors:pageErrors.length,...sample});await ctx.close();}
 await browser.close();fs.writeFileSync('/out/benchmark-result.json',JSON.stringify({path,observations:out},null,2));console.log(JSON.stringify({path,n:out.length,rows:out.map(x=>({round:x.round,mobile:x.mobile,mode:x.mode,http:x.status,lcp:x.lcp,cls:x.cls,tags:x.tags,err:x.error}))}));
})().catch(e=>{console.error(e.stack);process.exit(2)});
