'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const filename=process.argv[2]||path.join(__dirname,'../assets/js/ajax-filter-state.js');
const source=fs.readFileSync(filename,'utf8');
const groupKey='jet-engine/tours_query_archive';
const events={},documents={},timers=[],requests=[];
let reqId=0;
const token={
  innerHTML:'Original',textContent:'Original',tagName:'SPAN',style:{backgroundImage:''},attributes:{'data-etg-dfsb-token':'title'},
  getAttribute(k){return this.attributes[k]??null;},
  hasAttribute(k){return Object.prototype.hasOwnProperty.call(this.attributes,k);},
  setAttribute(k,v){this.attributes[k]=v;},
  removeAttribute(k){delete this.attributes[k];},
  closest(){return null;}
};
const document={
  addEventListener(name,callback){documents[name]=callback;},
  dispatchEvent(event){(events.dispatches||(events.dispatches=[])).push(event);},
  querySelectorAll(selector){
    if(selector==='[data-etg-dfsb-token]'||selector==='[data-etg-dfsb-token],[data-etg-dfsb-slot],[data-etg-dfsb-media-slot]')return [token];
    if(selector.includes('[data-etg-dfsb-token]:not([data-etg-dfsb-group])'))return [token];
    return [];
  }
};
const location={pathname:'/es/tours-and-activities/',search:''};
const window={
  location,JetSmartFilters:null,
  setTimeout(fn,ms){const t={fn,ms,active:true,id:++reqId};timers.push(t);return t.id;},
  clearTimeout(id){const t=timers.find(x=>x.id===id);if(t)t.active=false;}
};
const cfg={
  endpoint:'/es/wp-json/dfsb/v1/ajax-presentation',
  contract:'etg.dfsb.ajax-presentation.v1',
  canonicalContract:'dfsb.ajax-presentation.v1',
  jsfVersion:'3.8.5.1',
  supportedJsfVersions:['3.8.3.1','3.8.5.1']
};
window.DFSB_AJAX=cfg;
function CustomEvent(type,options){this.type=type;this.detail=options?.detail;}
function fetch(url,opts) {
  return new Promise((resolve,reject)=>requests.push({url,opts,resolve,reject}));
}
vm.runInNewContext(source,{window,document,CustomEvent,WeakMap,AbortController,fetch,
  URLSearchParams,Promise,Error,Array,Object,JSON,String,Number}, {timeout:1800});
const subs={},group={currentQuery:{}};
window.JetSmartFilters={events:{subscribe(name,fn){subs[name]=fn;}},filterGroups:{[groupKey]:group}};
assert.equal(typeof documents['jet-smart-filters/inited'],'function');
documents['jet-smart-filters/inited']();
function fire(name){assert.equal(typeof subs[name],'function',name);subs[name]('jet-engine','tours_query_archive');}
function flushSoon(){for(const t of timers){if(t.ms<=35&&t.active){t.active=false;t.fn();}}}
async function settle(){for(let i=0;i<15;i++)await Promise.resolve();}
const result=(value)=>({contract:cfg.contract,canonical_contract:cfg.canonicalContract,
 status:'ready',authorizing:false,url_authority:false,seo_mutation:false,
 provider:'jet-engine',query_id:'tours_query_archive',presentation_state_complete:true,
 values:{tokens:{title:{value,type:'text'}},slots:{}}});
const fulfill=(r,value)=>r.resolve({ok:true,status:200,text:()=>Promise.resolve(JSON.stringify(result(value)))});
(async()=>{
  group.currentQuery={'_tax_query_tour-types':'205'};
  location.pathname='/es/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:excursiones-de-un-dia/';
  fire('ajaxFilters/start-loading');
  assert.ok(events.dispatches.some(e=>e.type==='dfsb/ajax-presentation-start'));
  flushSoon();
  assert.equal(requests.length,1,'REST must begin while native JSF is still loading');
  fulfill(requests[0],'Day Tours');
  await settle();
  assert.equal(token.textContent,'Original','prepared result must be held until Listing renders');
  fire('ajaxFilters/updated');
  await settle();
  assert.equal(token.textContent,'Day Tours','commit immediately on native completion');
  assert.equal(requests.length,1,'must not send a second REST request');
  fire('ajaxFilters/end-loading');
  group.currentQuery={'_tax_query_tour-types':'205'};
  fire('ajaxFilters/start-loading');
  flushSoon();
  assert.equal(requests.length,2,'new request should be started');
  location.pathname='/es/tours-and-activities/';
  group.currentQuery={};
  fire('ajaxFilters/start-loading');
  flushSoon();
  fulfill(requests[1],'Stale Day Tours');
  await settle();
  fire('ajaxFilters/updated');
  await settle();
  fire('ajaxFilters/end-loading');
  assert.notEqual(token.textContent,'Stale Day Tours','reset must invalidate stale in-flight response');
  assert.equal(requests.length,2,'reset must not issue a filtered REST request');
  console.log('PASS: parallel prefetch, native commit, no duplicate REST, stale reset cancellation');
})().catch(e=>{console.error(e);process.exitCode=1;});

