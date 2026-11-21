'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const path=require('path');
const file=path.join(__dirname,'../../../wp-content/mu-plugins/assets/mad4b-pricing-entity-picker-v1.js');
class Element {
 constructor(tag='div'){this.tag=tag;this.children=[];this.listeners={};
  this.attrs={};this.dataset={};this.nodeType=1;this.textContent='';
  this.type='';this.value='';this.name='';this.disabled=false;this.readOnly=false;}
 append(...nodes){this.children.push(...nodes);for(const n of nodes)n.parentElement=this;}
 setAttribute(k,v){this.attrs[k]=v;}
 addEventListener(k,fn){this.listeners[k]=fn;}
 dispatchEvent(e){(this.listeners[e.type]||(()=>{}))(e);this.events.push(e.type);}
 closest(sel){if(sel==='.cx-ui-repeater-item-control')return this.host||null;return null;}
 querySelectorAll(k){return this.queries?.[k]||[];}
 querySelector(k){return this.queries?.[k]||null;}
 replaceChildren(...nodes){this.children=[];this.append(...nodes);}
 focus(){this.focused=true;}
}
const root=new Element(),top=new Element(),host=new Element(),control=new Element();
const native=new Element('input');
native.name='shared_package_pricing_rows[item-0][package_term_id]';
native.type='number';native.value='';native.events=[];native.host=host;
root.queries={'#settings_top':top};
top.queries={
 '.cx-control[data-control-name="shared_package_pricing_rows"]':control
};
control.queries={'input[name]':[native]};
const document={
 querySelector(k){return k==='.jet-engine-options-page-wrap'?root:null;},
 createElement(tag){return new Element(tag);}
};
const calls=[];
const context={
 document,window:{MAD4BPricingEntityPicker:{
  action:'mad4b_pricing_entity_picker_v1',nonce:'fixture',url:'/wp-admin/admin-ajax.php'}},
 MutationObserver:class {observe(){}},URLSearchParams,Event:class{constructor(type){this.type=type;}},
 fetch:async(url,arg)=>{
  calls.push([url,String(arg.body)]);
  return {ok:true,json:async()=>({success:true,data:{results:[
   {id:2354,name:'Gold',type:'package',status:'eligible_term_gate',canonical_id:2354}
  ]}})};
 }};
vm.runInNewContext(fs.readFileSync(file,'utf8'),context);
(async()=>{
 assert.equal(host.children.length,1,'Picker did not attach to native row');
 const details=host.children[0];
 assert.equal(details.tag,'details');
 const inner=details.children[1];
 const search=inner.children[0],go=inner.children[1],results=inner.children[3];
 search.value='Gold';
 await go.listeners.click();
 assert.equal(calls.length,1);
 assert.equal(results.children.length,1,'Gold native entity missing');
 assert.equal(native.value,'','Typing search mutated native field prematurely');
 results.children[0].listeners.click();
 assert.equal(native.value,'2354','Entity choice did not update original field');
 assert.deepEqual(native.events,['input','change'],
  'JetEngine input/change events not emitted after native assignment');
 assert.equal(search.name,'','Search control must NOT become a second JetEngine input');
 assert.equal(native.focused,true);
 console.log('PASS native numeric input receives selected lookup term, with input+change events');
 console.log('PASS read-only search leaves native value unchanged until selection');
 console.log('PASS no parallel named inputs and no duplicate JetEngine save');
 console.log('NATIVE_JETENGINE_ENTITY_PICKER_DOM_ACCEPTED');
})().catch(e=>{console.error(e);process.exitCode=1;});
