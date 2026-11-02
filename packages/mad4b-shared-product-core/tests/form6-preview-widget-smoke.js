"use strict";
/** Zero network / zero WordPress data. Simulates the Form 6 widget browser binding. */
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../mu-plugins/shared-product-core/adapters/presentation-form6-price-quote-v1.php','utf8');
const anchor=source.indexOf("add_action('fluentform/after_form_render'");
assert(anchor>=0);
const start=source.indexOf('<script>',anchor)+'<script>'.length;
const end=source.indexOf('</script>',start);
assert(start>0&&end>start);
const script=source.slice(start,end);

const fields={
 tour_id:'42',rate_id:'24',datetime:'2027-01-15',
 no_of_adults:'2',no_of_children:'1',no_of_infants:'0',occupancy:'double'
};
const listeners={};
const form={
 matches:q=>q==='form',
 querySelectorAll:()=>Object.entries(fields).map(([name,value])=>({name,value})),
 addEventListener:(name,cb)=>{listeners[name]=cb;}
};
const output={
 previousElementSibling:form,
 classList:{contains:c=>c==='shared-form6-price-reference'},
 dataset:{tourId:'42',endpoint:'/wp-json/shared-product/v1/tour-form-price-preview'},
 textContent:''
};
let lastRequest=null;
let count=0;
let quoteStatus='partial_price_preview_only';
const ctx={
 document:{currentScript:{previousElementSibling:output},documentElement:{lang:'en'}},
 AbortController,Intl,Number,Array,String,JSON,Error,
 clearTimeout:()=>{},
 setTimeout:fn=>{fn();return 1;},
 fetch:async (endpoint,opts)=>{
  assert.equal(endpoint,output.dataset.endpoint);
  assert.equal(opts.method,'POST');
  assert.equal(opts.cache,'no-store');
  lastRequest=JSON.parse(opts.body);count++;
  return {ok:true,json:async()=>({
   status:quoteStatus,display_kind:quoteStatus==='partial_price_preview_only'
    ?'adults_only_reference':'nonbinding_price_preview',
   display_amount:250,currency:'USD'
  })};
 }
};
const flush=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 vm.runInNewContext(script,ctx,{filename:'form6-preview-widget.js',timeout:1000});
 await flush();
 assert.equal(count,1);
 assert.equal(lastRequest.tour_id,42);
 assert.equal(lastRequest.rate_id,24);
 assert.equal(lastRequest.children,1);
 assert.equal(lastRequest.adults,2);
 assert.equal(lastRequest.travel_date,'2027-01-15');
 assert(!('total' in lastRequest));
 assert.match(output.textContent,/Adult price reference/);
 assert.match(output.textContent,/Child\/infant charges are not included/);
 assert.match(output.textContent,/Final group price requires review/);
 fields.no_of_children='0';
 quoteStatus='price_preview_only';
 listeners.change();
 await flush();
 assert.equal(count,2);
 assert.equal(lastRequest.children,0);
 assert.match(output.textContent,/Indicative tour price/);
 assert(!/Adult price reference/.test(output.textContent));
 fields.rate_id='';
 listeners.change();
 await flush();
 assert.equal(count,2,'an absent selected rate must not call price provider');
 assert.match(output.textContent,/Select a valid Tour Rate/);
 fields.rate_id='24';
 fields.datetime='15-Jan-2027';
 listeners.change();
 await flush();
 assert.equal(lastRequest.travel_date,'2027-01-15');
 assert.equal(count,3);
 console.log('FORM6_BROWSER_SMOKE_PASS checks=14 no_net_no_order');
})().catch(e=>{console.error(e);process.exitCode=1;});
