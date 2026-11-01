'use strict';
const { chromium }=require('/usr/src/app/node_modules/playwright');
const fs=require('fs');
const path=process.argv[2]||'/';
const origin='https://tours.dev.mad4b.com';
if(!/^\/(?:[a-z0-9._~!$&'()*+,;=:@%/-]*)$/.test(path)||path.length>250||path.includes('//')||path.includes('..'))process.exit(12);
const SKIP=/(?:first-paint|navigation|carousel|listing|pattern|container|switcher|nationality|currency|wcml|woo|astra|jet-|font|icon|swiper|slider|header|sticky|jquery|age|filter|widget-mega|elementor|menu|calendar|modal|popup|price|checkout|cart|base|forms-public|form-default|accessibility)/i;
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});let samples=[];
 for(const mobile of [false,true]){
  const context=await browser.newContext({viewport:mobile?{width:390,height:844}:{width:1440,height:900},userAgent:mobile?'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1':undefined,ignoreHTTPSErrors:true});
  const page=await context.newPage();await page.setExtraHTTPHeaders({'X-MAD4B-BYPASS-CACHE':'1'});
  let response=await page.goto(origin+path+(path.includes('?')?'&':'?')+'LSCWP_CTRL=before_optm',{waitUntil:'domcontentloaded',timeout:35000});await page.waitForTimeout(2100);await page.waitForLoadState('load',{timeout:13000}).catch(()=>{});
  let result=await page.evaluate(()=>{
   function flatten(sheet){let rules=[];let ambiguous=0;let font=0;
    function walk(list){for(let r of list){if(r.type===5||r.type===7){font++;continue;}if(r.selectorText){rules.push(r.selectorText);continue;}if(r.cssRules){walk(r.cssRules);continue;}if(r.type===1){ambiguous++;}}}
    try{walk(sheet.cssRules||[]);}catch(e){ambiguous++;}return {rules,ambiguous,font};}
   let css=[];for(let s of document.styleSheets){if(!s.href||!s.ownerNode||s.ownerNode.tagName!=='LINK')continue;
    const node=s.ownerNode,id=node.id||'',uri=new URL(s.href,location.href),rel=uri.pathname;
    if(uri.host!==location.host||!rel.startsWith('/wp-content/')||!rel.endsWith('.css'))continue;
    const d=flatten(s);let matched=0,above=0,unknown=0;
    if(d.rules.length>0&&d.rules.length<=1500){for(let selector of d.rules){let selectors=selector.split(',');let hit=false,visible=false;
     for(let sel of selectors){try{let els=document.querySelectorAll(sel);if(els.length){hit=true;for(let el of Array.from(els).slice(0,25)){let b=el.getBoundingClientRect();if(b.width>0&&b.height>0&&b.bottom>=0&&b.top<innerHeight+100){visible=true;break;}}}}catch(e){unknown++;}}
     if(hit)matched++;if(visible)above++;}}
    const typeCount=d.rules.length;
    css.push({id,rel,ruleCount:typeCount,matched,above,ambiguous:d.ambiguous,fonts:d.font,unknown,criticalByName:/(?:elementor|font|icon|swiper|slider|header|nav|sticky|astra.*main|jet-blog|jet-tabs|woocommerce-grid|woocommerce-layout|price|checkout|cart|calendar|modal|popup|menu|jet-search|jet-engine|jet-elements|jet-blocks)/i.test(rel)});
   }
   return {css,route:location.pathname,documentStatus:document.readyState,viewport:{width:innerWidth,height:innerHeight},styles:document.styleSheets.length};
  });samples.push({mobile,status:response.status(),...result});await context.close();
 }
 await browser.close();
 const bykey=new Map();for(const sample of samples){for(const item of sample.css){const key=item.id+'|'+item.rel;let x=bykey.get(key)||[];x.push(item);bykey.set(key,x);}}
 const assets={};const why=[];
 for(const [key,items] of bykey){const a=items[0];if(items.length!==2)continue;
  if(!a.id.endsWith('-css')||!/^[a-z0-9_-]+-css$/.test(a.id))continue;
  if(!/^\/wp-content\/(plugins|themes|mu-plugins)\/[a-zA-Z0-9/_-]+\.css$/.test(a.rel))continue;
  if(SKIP.test(a.rel) && !/(?:fluent-forms-elementor-widget|woo-products)\.css$/.test(a.rel))continue;
  if(items.some(x=>x.ruleCount===0||x.ruleCount>1500||x.ambiguous||x.fonts||x.unknown||x.above>0))continue;
  let handle=a.id.slice(0,-4);let tier=items.some(x=>x.matched>0)?'below-fold':'idle';
  assets[handle]={relpath:a.rel,tier};why.push({handle,path:a.rel,firstFrameHits:items.map(x=>x.above),documentHits:items.map(x=>x.matched),tier});
 }
 const out={contract:'mad4b.local-css-scout.v1',path,samples,eligible:assets,candidates:why,success:samples.every(s=>s.status===200&&s.route===path)};
 fs.writeFileSync('/out/scout-result.json',JSON.stringify(out,null,2));console.log(JSON.stringify({path,valid:out.success,cssCounts:samples.map(x=>x.css.length),candidates:why}));
})().catch(e=>{console.error(e.stack);process.exit(2)});
