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
const root=new E(),launch=new E(),dimensions=new E(),head=new E(),content=new E(),sidebar=new E(),listing=new E();
const nav=new E('button');nav.dataset.section='overview';
root.query['.mad4b-ws-nav-btn.is-active']=nav;
root.query['.mad4b-ws-content']=content;
root.query['.mad4b-ws-sidebar']=sidebar;
content.query['.mad4b-ws-listing']=listing;
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
 policy_bindings:{global_refs:0,term_records:0,post_records:0},by_dimension:{},
 unified_registry:{
   rows:[{
     id:'tour_type:194',family:'tour_type',name:'Packages',effect:'+25%',
     status:'preview_eligible',source:'mad4b_dynamic_dimension_markup_v1',
     dimension:'taxonomy:tour-types'
   }],
   source_count:12,status_counts:{preview_eligible:1},
   by_dimension:{
     'taxonomy:tour-types':[{
       family:'tour_type',name:'Packages',effect:'+25%',
       status:'preview_eligible',source:'mad4b_dynamic_dimension_markup_v1'
     }]
   },warnings:[]
 }
};
const context={window:{MAD4BPricingRulesReadback:{ajax:'/wp-admin/admin-ajax.php',action:'read',nonce:'valid',lab:'/lab',bindings:'/bindings'}},
 document,MutationObserver:class{observe(){}},URLSearchParams,
 requestAnimationFrame:(fn)=>fn(),
 fetch:async()=>{requests++;return {ok:true,json:async()=>({success:true,data:snapshot})};},
 console};
vm.runInNewContext(source,context);
await new Promise(resolve=>setImmediate(resolve));
assert.equal(requests,1);
const status=launch.children.find(x=>x.id==='mad4b-pricing-rules-status');
assert.ok(status,'Status was not inserted after dimension launcher');
const contents=treeText(status);
assert.match(contents,/Pricing Engine: ON/);
assert.match(contents,/Pricing Lab drafts/);
assert.match(contents,/0\s+published Tour Rates/);
assert.match(contents,/native Tour Type\/Package Level preview rules exist/);
assert.equal(counter.textContent,'1/2 selected');
assert.match(treeText(body),/No saved Pricing Lab Strategy targets this dimension/);
assert.match(treeText(body),/Packages/);
assert.match(treeText(body),/preview eligible/);
assert.match(treeText(trigger),/1 native \/ 0 Lab drafts/);
assert.ok(head.children.length,'Expected scoped CSS');
const policyTab=content.children.find(node=>node.id==='mad4b-pricing-unified-policy-list');
assert.ok(policyTab,'Unified registry not mounted inside Pricing policies content');
assert.equal(policyTab.hidden,true,'Unified registry should hide outside Pricing policies');
nav.dataset.section='policies';
sidebar.listeners.click();
assert.equal(policyTab.hidden,false,'Pricing policies selection did not reveal unified registry');
assert.match(treeText(policyTab),/Unified policies · all source families/);
assert.match(treeText(policyTab),/Packages/);
assert.match(treeText(policyTab),/preview_eligible|preview eligible/);
nav.dataset.section='sources';
sidebar.listeners.click();
assert.equal(policyTab.hidden,true,'Unified registry not hidden in other native tabs');
console.log('PASS unified summary displays one native preview rule separately from zero Lab drafts');
console.log('PASS Tour Types editor displays canonical native preview and absence of Lab drafts');
console.log('PASS confusing active dimension counter is relabeled selected');
console.log('PASS native Pricing policies tab renders the unified registry and preserves other native sections');
console.log('RULE_VISIBILITY_DOM_TEST_PASSED');
})().catch(e=>{console.error(e);process.exitCode=1;});
