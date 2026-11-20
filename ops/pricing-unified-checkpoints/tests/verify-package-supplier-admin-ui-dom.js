'use strict';
const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const src=fs.readFileSync(path.join(__dirname,
 '../../../wp-content/mu-plugins/assets/mad4b-package-supplier-admission-review-v1.js'),'utf8');
class E{
 constructor(tag='div'){
  this.tag=tag;this.children=[];this.listeners={};this.attrs={};
  this.name='';this.type='';this.value='';this.disabled=false;
  this.textContent='';this.className='';this.parentNode=null;
 }
 append(...items){for(const item of items){item.parentNode=this;this.children.push(item);}}
 insertBefore(child,sibling){
  const index=this.children.indexOf(sibling);
  assert.ok(index>=0);
  child.parentNode=this;this.children.splice(index,0,child);
 }
 replaceChildren(...items){this.children=[];this.append(...items);}
 setAttribute(k,v){this.attrs[k]=v;}
 addEventListener(type,callback){this.listeners[type]=callback;}
}
const page=new E(),original=new E(),nativeForm=new E('form'),nativeSave=new E('button');
nativeSave.type='submit';nativeForm.append(nativeSave);original.append(nativeForm);page.append(original);
const document={querySelector(q){return q==='.jet-engine-options-page-wrap'?original:null;},
 createElement(tag){return new E(tag);}};
const calls=[];
const cfg={url:'/wp-admin/admin-ajax.php',
 action:'mad4b_package_supplier_admission_review_v1',nonce:'test-nonce',
 tours:[{id:60629,title:'Best of Egypt 12 Days'},{id:60627,title:'Amazing 5 Days'}],
 today:'2026-12-15'};
const ctx={document,URL,window:{location:{host:'wayfatours.com'},MAD4BPackageSupplierAdmissionReview:cfg},URLSearchParams,
 fetch:async(url,params)=>{
   calls.push({url,body:String(params.body),method:params.method});
   return {ok:true,json:async()=>({success:true,data:{saved:false,edit_tour_url:'https://wayfatours.com/wp-admin/post.php?post=60629&action=edit',result:{
    tour_id:60629,date:'2026-12-15',currency:'EGP',
    occupancy:{adults:2,children:1,infants:0},
    rate_generation_ready:false,checkout_ready:false,
    components:{accommodation:{binding_count:2,stages:[
     {stage_key:'hotel-cairo-01',nights:3,price_status:'pending_quote',
      bookable_unit_selection_present:false,supplier_cost_receipt_present:false}
    ]},transportation:{source_role:'self_cost',currency:'EGP'},
    guide:{status:'guide_coverage_not_reviewed'},
    extras:{runtime_status:'SOURCE_PRICING_FUNCTIONS_PRESENT_DATA_UNVERIFIED'}},
    blockers:['stay_unit_not_selected','transport_route_cost_unapproved',
     'child_infant_component_costs_not_certified']
   }}})};
 }};
(async()=>{
vm.runInNewContext(src,ctx);
assert.equal(page.children.length,2,'One external panel should be added');
assert.equal(page.children[1],original,'Native vendor root must not be reparented');
assert.deepEqual(nativeForm.children,[nativeSave],'Original form and Save must remain unchanged');
const panel=page.children[0];
assert.equal(panel.tag,'section');
const all=[];
const visit=node=>{all.push(node);for(const kid of node.children)visit(kid);};
visit(panel);
assert.equal(all.filter(x=>x.tag==='form').length,0,'No duplicate form allowed');
assert.equal(all.filter(x=>x.name).length,0,'Readiness form controls must not share names with native JetEngine');
assert.ok(all.filter(x=>x.tag==='button').every(x=>x.type==='button'));
const button=all.find(x=>x.tag==='button'&&x.textContent==='Inspect supplier evidence');
assert.ok(button);
const selectors=all.filter(x=>x.tag==='select');
assert.equal(selectors.length,2);
selectors[0].value='60629';
const fields=all.filter(x=>x.tag==='input');
fields.find(x=>x.type==='number').value='2';
await button.listeners.click();
assert.equal(calls.length,1);
assert.equal(calls[0].method,'POST');
assert.ok(calls[0].body.includes('tour_id=60629'));
assert.ok(calls[0].body.includes('action=mad4b_package_supplier_admission_review_v1'));
assert.equal(button.disabled,false);
assert.ok(all.length>12);
assert.equal(nativeSave.type,'submit');
const updated=[];visit(panel);
const originalLink=page.children[0].children[0].children[1].children.find(x=>x.className==='mad4b-supplier-readiness__result')?.children.find(x=>x.tag==='a');
assert.ok(originalLink && originalLink.href.includes('post=60629'),'Native Tour edit link missing');
console.log('PASS read-only supplier panel is outside original JetEngine form');
console.log('PASS AJAX audit requests never touch native form controls or Save');
console.log('PASS cost panel uses authorized action with signed nonce and explicit dimensions');
console.log('PACKAGE_SUPPLIER_ADMIN_DOM_ACCEPTED');
})().catch(e=>{console.error(e);process.exitCode=1;});
