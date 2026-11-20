'use strict';
const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const assert=require('node:assert/strict');
const code=fs.readFileSync(path.join(__dirname,
 '../../../wp-content/mu-plugins/assets/mad4b-package-supplier-admission-review-v1.js'),'utf8');
class Element{
 constructor(tag='div'){
  this.tag=tag;this.children=[];this.listeners={};this.attrs={};this.map={};
  this.className='';this.dataset={};this.type='';this.name='';this.value='';
  this.textContent='';this.hidden=false;this.disabled=false;this.parentNode=null;
  this.classNames=new Set();
  this.classList={contains:x=>this.classNames.has(x),toggle:(x,on)=>{
   if(on)this.classNames.add(x);else this.classNames.delete(x);
  }};
 }
 append(...items){for(const item of items){item.parentNode=this;this.children.push(item);}}
 insertBefore(child,sibling){
  const index=this.children.indexOf(sibling);
  assert.ok(index>=0,'Insertion must target a native Workspace sibling');
  child.parentNode=this;this.children.splice(index,0,child);
 }
 replaceChildren(...items){this.children=[];this.append(...items);}
 setAttribute(key,value){this.attrs[key]=value;}
 addEventListener(name,callback){this.listeners[name]=callback;}
 querySelector(sel){return this.map[sel]||null;}
 scrollIntoView(){this.scrolled=true;}
 closest(sel){
  if(sel.includes('mad4b-ws-nav-btn')&&this.className.includes('mad4b-ws-nav-btn'))return this;
  if(sel.includes('mad4b-ws-search')&&this.className.includes('mad4b-ws-search'))return this;
  return null;
 }
 dispatchEvent(e){this.listeners[e.type]?.(e);}
}
const page=new Element(),root=new Element(),workspace=new Element(),provider=new Element(),
  nativeForm=new Element('form'),originalSave=new Element('button'),
  nav=new Element('button'),search=new Element('input');
originalSave.type='submit';
nativeForm.append(originalSave);
root.append(nativeForm);
workspace.append(provider);
root.append(workspace);page.append(root);
root.classNames.add('mad4b-ws-visual');
nav.className='mad4b-ws-nav-btn is-active';nav.dataset.section='overview';search.className='mad4b-ws-search';
nav.click=()=>{
 nav.dataset.section='sources';
 root.dispatchEvent({type:'click',target:nav});
};
root.map={
 '.mad4b-ws-content':workspace,
 '.mad4b-ws-nav-btn[data-section="sources"]':nav,
 '.mad4b-ws-nav-btn.is-active':nav,
 '.mad4b-ws-search':search,
};
workspace.map={'.mad4b-ws-provider-panel':provider};
const docListeners={};
const document={
 querySelector:sel=>sel==='.jet-engine-options-page-wrap'?root:null,
 createElement:tag=>new Element(tag),
 addEventListener:(name,cb)=>docListeners[name]=cb
};
const calls=[];
const cfg={
 url:'/wp-admin/admin-ajax.php',
 action:'mad4b_package_supplier_admission_review_v1',nonce:'test-nonce',
 tours:[{id:60629,title:'Best of Egypt 12 Days'}, {id:60627,title:'Amazing 5 Days'}],
 today:'2026-12-15'
};
const context={
 document,URL,URLSearchParams,window:{
  location:{host:'wayfatours.com'},MAD4BPackageSupplierAdmissionReview:cfg
 },
 fetch:async(url,arg)=>{
  const body=String(arg.body);
  calls.push({url,body,method:arg.method});
  const id=Number(new URLSearchParams(body).get('tour_id'));
  return {ok:true,json:async()=>({success:true,data:{
   saved:false,
   edit_tour_url:'https://wayfatours.com/wp-admin/post.php?post='+id+'&action=edit',
   result:{
    tour_id:id,date:'2026-12-15',currency:'EGP',
    occupancy:{adults:2,children:0,infants:0},
    rate_generation_ready:false,checkout_ready:false,
    components:{
     accommodation:{binding_count:2,stages:[{
      stage_key:'cairo-hotel',nights:3,price_status:'pending_quote',
      bookable_unit_selection_present:false,supplier_cost_receipt_present:false
     }]},
     transportation:{source_role:'self_cost',currency:'EGP'},
     guide:{status:'guide_coverage_not_reviewed'},
     extras:{runtime_status:'DATA_UNVERIFIED'}
    },
    blockers:['stay_unit_not_selected','transport_route_cost_unapproved']
   }}})};
 }
};
(async()=>{
 vm.runInNewContext(code,context);
 assert.equal(root.children.length,2,'Native form and workspace must remain');
 assert.deepEqual(nativeForm.children,[originalSave],'Original Save mutated');
 assert.equal(workspace.children.length,3,'Overview, inspector, Provider should share content');
 assert.equal(workspace.children[2],provider,'Provider rules moved by inspector');
 const [overview,panel]=workspace.children;
 assert.equal(overview.className,'mad4b-supplier-readiness-overview');
 assert.equal(panel.id,'mad4b-supplier-readiness-inspector');
 assert.equal(panel.parentNode,workspace);
 assert.equal(panel.hidden,true,'Full inspector must NOT appear on Overview');
 assert.equal(overview.hidden,false,'Compact Overview status missing');
 const walk=(n,arr=[])=>{arr.push(n);for(const child of n.children)walk(child,arr);return arr;};
 const all=walk(panel);
 assert.equal(all.filter(x=>x.tag==='form').length,0,'Must not embed second form');
 assert.equal(all.filter(x=>x.name).length,0,'No field may conflict with native JetEngine');
 assert.ok(all.filter(x=>x.tag==='button').every(x=>x.type==='button'));
 const openButton=walk(overview).find(x=>x.tag==='button');
 assert.ok(openButton,'Missing Overview navigate to Price Sources');
 openButton.listeners.click();
 assert.equal(nav.dataset.section,'sources');
 assert.equal(panel.hidden,false,'Supplier Inspector must appear in Price Sources');
 assert.equal(overview.hidden,true,'Compact Overview must hide outside Overview');
 assert.equal(panel.children[0].open,true,'Navigation must open requested Inspector');
 assert.equal(panel.scrolled,true,'Navigate should scroll Inspector into view');
 const select=all.find(x=>x.tag==='select');
 const inspect=all.find(x=>x.tag==='button'&&x.textContent==='Inspect supplier evidence');
 assert.ok(select&&inspect);
 // Catalog Tour Preview provides the single current selected Tour.
 root.dataset.mad4bPreviewTourId='60629';
 root.dispatchEvent({type:'mad4b:tour-preview-selection',detail:{tourId:60629,title:'Best of Egypt 12 Days'}});
 assert.equal(select.value,'60629','Supplier Inspector did not inherit Tour Preview context');
 assert.equal(select.parentNode.hidden,true,'Independent Tour picker must hide when Preview available');
 const linked=all.find(x=>x.className==='mad4b-supplier-readiness__linked-tour');
 assert.equal(linked.hidden,false,'Shared Tour selection must be visible');
 await inspect.listeners.click();
 assert.equal(calls.length,1);
 assert.equal(calls[0].method,'POST');
 assert.ok(calls[0].body.includes('tour_id=60629'));
 assert.ok(calls[0].body.includes('nonce=test-nonce'));
 assert.equal(inspect.disabled,false);
 const out=all.find(x=>x.className==='mad4b-supplier-readiness__result');
 assert.ok(out.children.some(x=>x.tag==='a'&&x.href.includes('post=60629')));
 // Changing Preview Tour clears previous supplier receipt immediately.
 root.dataset.mad4bPreviewTourId='60627';
 root.dispatchEvent({type:'mad4b:tour-preview-selection',detail:{tourId:60627,title:'Amazing 5 Days'}});
 assert.equal(select.value,'60627');
 assert.equal(out.children.length,0,'Stale Tour cost evidence remained visible');
 await inspect.listeners.click();
 assert.ok(calls[1].body.includes('tour_id=60627'));
 // Preview -> Source CTA shares Tour selection and only changes client view.
 nav.dataset.section='overview';root.dispatchEvent({type:'click',target:nav});
 root.dispatchEvent({type:'mad4b:open-supplier-inspector',detail:{tourId:60627}});
 assert.equal(nav.dataset.section,'sources');
 assert.equal(panel.hidden,false);
 // Search and original native JetEngine mode should hide the panel.
 search.value='markup';root.dispatchEvent({type:'input',target:search});
 assert.equal(panel.hidden,true);
 search.value='';
 root.classNames.delete('mad4b-ws-visual');
 docListeners['mad4b:jetengine:mode-changed']();
 assert.equal(panel.hidden,true);
 assert.equal(overview.hidden,true);
 // When Preview is cleared, independent fallback selector is restored.
 delete root.dataset.mad4bPreviewTourId;
 root.dispatchEvent({type:'mad4b:tour-preview-selection',detail:{tourId:0}});
 assert.equal(select.parentNode.hidden,false);
 assert.equal(linked.hidden,true);
 assert.deepEqual(nativeForm.children,[originalSave]);
 console.log('PASS Overview shows only compact summary; inspector belongs to Price Sources workspace');
 console.log('PASS shared Tour Preview selection, Source CTA and stale receipt reset');
 console.log('PASS native form untouched, no second Save or named inputs');
 console.log('PASS search, native mode and empty Preview hide context correctly');
 console.log('SUPPLIER_INSPECTOR_WORKSPACE_DOM_ACCEPTED');
})().catch(error=>{console.error(error);process.exitCode=1;});
