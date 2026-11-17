'use strict';
const {chromium}=require('playwright');
const path=require('path');
const BASE='https://wayfatours.com', PATH='/__mad4b-cart-island-browser-test';
const HTML='<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Public Shell</title></head><body>'
 +'<main id="public-content">Shared public article without a cart or order</main>'
 +'<div data-mad4b-cart-island="v1" data-cart-endpoint="https://wayfatours.com/wp-json/wc/store/v1/cart">'
 +'<button type="button" data-cart-open aria-controls="cart-panel" aria-expanded="false">Cart <span data-cart-count>0</span></button>'
 +'<section id="cart-panel" data-cart-panel hidden><div data-cart-status></div><ul data-cart-items></ul><output data-cart-total></output></section></div></body></html>';
function check(ok,msg){if(!ok)throw Error('FAIL '+msg);console.log('PASS '+msg);}
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
try{
  for(const locale of ['en','es']){
  for(const country of ['A','B']){
   const title=country+'/'+locale;
   const endpoint=BASE+(locale==='es'?'/es':'')+'/wp-json/wc/store/v1/cart';
   const html=locale==='en'?HTML:HTML.replace('lang="en"','lang="es"').replace('wayfatours.com/wp-json/','wayfatours.com/es/wp-json/');
   const context=await browser.newContext({viewport:{width:390,height:844}});
   const page=await context.newPage();
   let navigations=0,fetches=0;
   const pageErrors=[];
   page.on('framenavigated',()=>navigations++);
   page.on('pageerror',e=>pageErrors.push(e.message));
   await page.route(BASE+PATH,route=>route.fulfill({status:200,contentType:'text/html',body:html,headers:{'Cache-Control':'public,max-age=1800'}}));
   let count=country==='A'?2:7;
   let cache='private, no-store, max-age=0';
   let slow=false;
   await page.route(endpoint,async route=>{
    fetches++;
    let captured=count;
    let payload={items_count:captured,items:[{name:country==='A'?'<img src=x onerror=alert(1)>':'Other person',quantity:captured}],totals:{currency_code:'USD',currency_minor_unit:2,total_price:String(captured*10000)}};
    if(slow) await new Promise(resolve=>setTimeout(resolve,350));
    return route.fulfill({status:200,contentType:'application/json',headers:{'Cache-Control':cache},body:JSON.stringify(payload)});
   });
   await page.goto(BASE+PATH,{waitUntil:'domcontentloaded',timeout:14000});
   const initialNav=navigations;
   const initialHTML=await page.content();
   check(!initialHTML.includes('Other person')&&!initialHTML.includes('onerror='),'HTML shell has no cart content '+country);
   await page.addScriptTag({path:process.env.MAD4B_CART_JS_PATH||path.resolve(__dirname,'../../wp-content/mu-plugins/assets/mad4b-cart-island-v1.js')});
   await page.waitForTimeout(100);
   check(fetches===0,'no eager cart API for fresh browser '+country);
   await page.locator('[data-cart-open]').click();
   await page.waitForFunction(v=>document.querySelector('[data-cart-count]').textContent===String(v),count,{timeout:5000});
   check(fetches===1&&navigations===initialNav,'private island hydrate, no reload '+country);
   check((await page.locator('[data-cart-items]').innerText()).includes(country==='A'?'img':'Other person'),'separate cart contents '+country);
   check((await page.locator('img').count())===0,'cart item not HTML parsed '+country);
   count++;
   await page.evaluate(()=>document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart',{bubbles:true})));
   await page.waitForFunction(v=>document.querySelector('[data-cart-count]').textContent===String(v),count,{timeout:6000});
   check(fetches===2&&navigations===initialNav,'Woo cart change, AJAX only '+country);
   // An in-flight stale GET cannot hide a newer Woo cart change.
   slow=true;
   count++;
   await page.evaluate(()=>document.body.dispatchEvent(new CustomEvent('mad4b:cart-changed',{bubbles:true})));
   await page.waitForFunction(()=>document.querySelector('[data-cart-count]').textContent!==null,{timeout:5000});
   await page.waitForTimeout(175);
   count++;
   await page.evaluate(()=>document.body.dispatchEvent(new CustomEvent('mad4b:cart-changed',{bubbles:true})));
   await page.waitForFunction(v=>document.querySelector('[data-cart-count]').textContent===String(v),count,{timeout:9000});
   check(fetches===4&&navigations===initialNav,'in-flight cart update replayed once after stale fetch '+country);
   slow=false;
   cache='public, max-age=1800';
   await page.evaluate(()=>document.body.dispatchEvent(new CustomEvent('mad4b:cart-changed',{bubbles:true})));
   await page.waitForFunction(()=>document.querySelector('[data-cart-count]').textContent==='–',{timeout:6000});
   check(fetches===5&&navigations===initialNav,'public-cache cart response rejected '+country);
   const storage=await page.evaluate(()=>({session:sessionStorage.length,local:localStorage.length}));
   check(storage.session===0&&storage.local===0,'no local cart storage '+country);
   check(pageErrors.length===0,'no runtime errors '+title);
   await context.close();
  }
  }
}finally{await browser.close();}
console.log('PASS_CART_ISLAND_BROWSER_COMPLETE');
})().catch(e=>{console.error(e.stack||e);process.exitCode=1;});
