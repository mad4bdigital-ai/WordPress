'use strict';
const {chromium}=require('/usr/src/app/node_modules/playwright'); const fs=require('fs');
const path=process.argv[2]||'/'; if(!/^\/[a-z0-9/_~.\-]*$/i.test(path)||path.includes('..'))process.exit(10);
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const out=[];
for(const mobile of [false,true]){
 const context=await browser.newContext({viewport:mobile?{width:390,height:844}:{width:1440,height:900},ignoreHTTPSErrors:true,
  userAgent:mobile?'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1':'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/155.0 Safari/537.36'});
 const page=await context.newPage();await page.setExtraHTTPHeaders({'X-MAD4B-BYPASS-CACHE':'1','X-MAD4B-DYNAMIC-CSS':'native-v1','X-MAD4B-JS-POLICY':'native-v1'});
 const errors=[];let navs=0;page.on('framenavigated',f=>{if(f===page.mainFrame())navs++});page.on('pageerror',e=>errors.push(e.message.slice(0,100)));
 const cdp=await context.newCDPSession(page);await cdp.send('Profiler.enable');await cdp.send('Profiler.setSamplingInterval',{interval:1000});await cdp.send('Profiler.start');
 let status=0;try{const r=await page.goto('https://tours.dev.mad4b.com'+path,{waitUntil:'domcontentloaded',timeout:37000});status=r.status();await page.waitForTimeout(7500)}catch(e){errors.push(String(e).slice(0,150))}
 const profile=(await cdp.send('Profiler.stop')).profile;
 const map=new Map(profile.nodes.map(n=>[n.id,n]));const times={};const fns={};
 const samples=profile.samples||[],deltas=profile.timeDeltas||[];
 for(let i=0;i<samples.length;i++){
  const n=map.get(samples[i]);if(!n)continue;const f=n.callFrame;
  const uri=f.url?f.url.split('?')[0].replace('https://tours.dev.mad4b.com',''):('(anonymous '+f.functionName+')');
  const dt=(deltas[i]||1000)/1000;
  times[uri]=(times[uri]||0)+dt;
  const key=(uri+' | '+f.functionName).slice(0,260);fns[key]=(fns[key]||0)+dt;
 }
 const js=await page.evaluate(()=>({jquery:typeof window.jQuery==='function'?window.jQuery.fn.jquery:'missing',dom:{listing:document.querySelectorAll('.jet-listing-grid__item').length,widgets:document.querySelectorAll('.elementor-widget').length},resourceCount:performance.getEntriesByType('resource').length})).catch(()=>null);
 const top=Object.entries(times).sort((a,b)=>b[1]-a[1]).slice(0,24).map(([url,ms])=>({url,ms:Math.round(ms)}));
 const topFns=Object.entries(fns).sort((a,b)=>b[1]-a[1]).slice(0,24).map(([name,ms])=>({name,ms:Math.round(ms)}));
 out.push({mobile,status,navs,js,errors,profileDurationMs:Math.round((profile.endTime-profile.startTime)/1000),samples:samples.length,top,topFunctions:topFns});await context.close();
}
await browser.close();fs.writeFileSync('/out/jquery-cpu-profile.json',JSON.stringify({path,observations:out},null,2));
console.log(JSON.stringify(out.map(x=>({mobile:x.mobile,status:x.status,navs:x.navs,samples:x.samples,dom:x.js?.dom,top:x.top.slice(0,12),functions:x.topFunctions.slice(0,10),errors:x.errors}))));
})().catch(e=>{console.error(e.stack);process.exit(5)});
