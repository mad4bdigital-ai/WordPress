'use strict';
const {chromium}=require('/usr/src/app/node_modules/playwright'),fs=require('fs');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const page=await browser.newPage();
 const requests=[];
 await page.route('https://tours.dev.mad4b.com/__qa_test/**',async route=>{
  if(route.request().url().endsWith('/quote')){
   requests.push(JSON.parse(route.request().postData()));
   return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({
    status:'price_preview_only',total:2220,currency:'USD',component_adjustments_certified:false,lines:[]
   })});
  }
  await route.fulfill({status:200,contentType:'text/html',body:'<html><body class="single-tours-and-activities"><div class="elementor-24784"><form class="fluent_form_6"><input name="datetime" value="2026-11-10"><input name="no_of_adults" value="2"><input name="no_of_children" value="1"><input name="no_of_infants" value="0"><textarea name="description"></textarea></form></div></body></html>'});
 });
 await page.goto('https://tours.dev.mad4b.com/__qa_test/form',{waitUntil:'domcontentloaded'});
 await page.evaluate(()=>{
  window.SharedPresentationFluentConfigV1={
   tourId:60627,quoteEndpoint:'https://tours.dev.mad4b.com/__qa_test/quote',scope:'tour_single_booking',
   formScopeSelector:'.elementor-24784 form.fluent_form_6',extraDetails:{},extraNames:{},transportDetails:{},
   accommodationPolicy:{rates_with_stay:{}}
  };
  window.SharedPresentationSelectionV1={getSnapshot:()=>({
   selectedRateId:61140,rates:[{rateId:61140}],extras:new Set([]),revision:1,tourId:60627
  })};
 });
 await page.addScriptTag({path:'/out/fluent-rate-children-quote-live.js'});
 const get=()=>page.evaluate(()=>({
  output:document.querySelector('.shared-fluent-server-quote output')?.textContent,
  scope:document.querySelector('.shared-fluent-server-quote')?.dataset.priceScope,
  input:document.querySelector('[name="shared_tour_selection_v2"]')?.value
 }));
 await page.waitForTimeout(530);
 const child=await get();
 await page.locator('[name="no_of_children"]').fill('0');
 await page.locator('[name="no_of_infants"]').fill('1');
 await page.waitForTimeout(530);
 const infant=await get();
 await page.locator('[name="no_of_infants"]').fill('0');
 await page.waitForTimeout(530);
 const adultsOnly=await get();
 await browser.close();
 const outcome={child,infant,adultsOnly,requests};
 console.log(JSON.stringify(outcome));
 fs.writeFileSync('/out/fluent-rate-minors-quote-qa.json',JSON.stringify(outcome,null,2));
 if(!child.output.includes('Adults only')||!child.output.includes('excludes 1 child')
  ||!infant.output.includes('excludes 1 infant')
  ||adultsOnly.output.includes('Adults only')
  ||requests.length!==3
  ||requests.some(x=>x.children!==0||x.infants!==0||x.rate_id!==61140||x.source!=='rate'))process.exit(5);
})().catch(e=>{console.error(e.stack);process.exit(6)});
