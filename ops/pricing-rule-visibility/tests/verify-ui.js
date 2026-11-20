'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../../../wp-content/mu-plugins/assets/mad4b-pricing-rules-visibility-v1.js'),'utf8');
class E{
 constructor(tag='div'){
  this.tag=tag;this.children=[];this.dataset={};this.attributes={};this.listeners={};
  this.hidden=false;this.textContent='';this.className='';this.value='';
  this.query={};this.nodeType=1;
 }
 append(...nodes){this.children.push(...nodes);nodes.forEach(x=>x.parent=this);}
 replaceChildren(...nodes){this.children=[];this.append(...nodes);}
 insertAdjacentElement(where,node){this.append(node);node.insertedPosition=where;}
 insertBefore(node,before){const index=this.children.indexOf(before);if(index>=0)this.children.splice(index,0,node);else this.children.unshift(node);node.parent=this;}
 setAttribute(name,val){this.attributes[name]=String(val);}
 addEventListener(name,cb){this.listeners[name]=cb;}
 querySelector(key){return this.query[key]||null;}
 querySelectorAll(key){return this.query[key]||[];}
 matches(key){return this.tag==='row'&&key==='.mad4b-ws-dimension-row';}
}
function treeText(node){
 if(!node)return '';
 return (node.textContent||'')+' '+(node.children||[]).map(treeText).join(' ');
}
(async()=>{
const root=new E(),launch=new E(),dimensions=new E(),head=new E();
root.query['#mad4b-pricing-rules-status']=null;
root.query['.mad4b-ws-dimension-launcher']=launch;
root.query['.mad4b-ws-dimension-panel']=dimensions;
const row=new E('row'),editor=new E('details'),body=new E(),trigger=new E('summary');
const input=new E('input');input.value='taxonomy:tour-types';
row.query['.mad4b-ws-dimension-item input[type=checkbox]']=input;
row.query['.mad4b-ws-nested-policy']=editor;
editor.query['.mad4b-ws-nested-policy-content']=body;
editor.query['summary']=trigger;
body.query['.mad4b-pricing-rule-inline']=null;
trigger.query['.mad4b-pricing-rule-inline-badge']=null;
dimensions.query['.mad4b-ws-dimension-row']=[row];
const counter=new E('small');counter.textContent='1/2 active';
dimensions.query['.mad4b-ws-dimension-group > summary small']=[counter];
const document={readyState:'complete',head,querySelector:(q)=>q==='.jet-engine-options-page-wrap'?root:null,
 createElement:(t)=>new E(t),createTextNode:(t)=>{const e=new E('#text');e.textContent=t;return e;}};
let requests=0;
const snapshot={
 read_only:true,engine_enabled:true,booking_enabled:false,dimension_mode:'custom',
 selected_dimensions:['taxonomy:tour-types','taxonomy:package_levels'],strategy_count:0,
 native_assignments:{enabled_approved:0},published_tour_rates:0,
 policy_bindings:{global_refs:0,term_records:0,post_records:0},by_dimension:{}
};
const context={window:{MAD4BPricingRulesReadback:{ajax:'/wp-admin/admin-ajax.php',action:'read',nonce:'valid',lab:'/lab',bindings:'/bindings'}},
 document,MutationObserver:class{observe(){}},URLSearchParams,
 fetch:async()=>{requests++;return {ok:true,json:async()=>({success:true,data:snapshot})};},
 console};
vm.runInNewContext(source,context);
await new Promise(resolve=>setImmediate(resolve));
assert.equal(requests,1);
const status=launch.children.find(x=>x.id==='mad4b-pricing-rules-status');
assert.ok(status,'Status was not inserted after dimension launcher');
const contents=treeText(status);
assert.match(contents,/Pricing Engine: ON/);
assert.match(contents,/saved Lab strategies/);
assert.match(contents,/0\s+published Tour Rates/);
assert.match(contents,/not price rules/);
assert.equal(counter.textContent,'1/2 selected');
assert.match(treeText(body),/No saved Pricing Lab strategy targets this dimension/);
assert.match(treeText(trigger),/0 saved strategies/);
assert.ok(head.children.length,'Expected scoped CSS');
console.log('PASS source-backed summary displays engine, selected dimensions and zero saved pricing rules');
console.log('PASS Tour Types rule editor displays empty saved-rule state, not a fictional active rule');
console.log('PASS confusing active dimension counter is relabeled selected');
console.log('RULE_VISIBILITY_DOM_TEST_PASSED');
})().catch(e=>{console.error(e);process.exitCode=1;});
