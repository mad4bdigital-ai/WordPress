'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,
 '../../../wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.js'),'utf8');
class Element {
 constructor(tag='div',cl=''){
  this.tag=tag;this.className=cl;this.classes=new Set(cl.split(' ').filter(Boolean));
  this.children=[];this.parentNode=null;this.listeners={};this.attrs={};this.dataset={};
  this.hidden=false;this.textContent='';this.type='';this.focused=false;this.clicked=0;
  this.classList={add:x=>this.classes.add(x),remove:x=>this.classes.delete(x),
   contains:x=>this.classes.has(x)};
 }
 append(...nodes){for(const n of nodes){n.parentNode=this;this.children.push(n);}}
 insertBefore(node,ref){
  const index=this.children.indexOf(ref);
  assert.ok(index>=0);node.parentNode=this;this.children.splice(index,0,node);
 }
 get nextSibling(){
  if(!this.parentNode)return null;
  return this.parentNode.children[this.parentNode.children.indexOf(this)+1]||null;
 }
 setAttribute(key,val){this.attrs[key]=val;}
 getAttribute(key){return this.attrs[key]??null;}
 addEventListener(type,fn){(this.listeners[type]||=[]).push(fn);}
 dispatchEvent(type){for(const fn of this.listeners[type]||[])fn({type,target:this});}
 click(){this.clicked++;this.dispatchEvent('click');}
 focus(){this.focused=true;}
 querySelector(selector){
  if(selector==='[data-mad4b-nav-group="company"]')
   return this.children.find(x=>x.dataset.mad4bNavGroup==='company')||null;
  if(selector==='[data-mad4b-nav-group="product"]')
   return this.children.find(x=>x.dataset.mad4bNavGroup==='product')||null;
  if(selector==='.mad4b-ws-nav-btn:not(.mad4b-ws-service-nav)')
   return this.children.find(x=>x.classes.has('mad4b-ws-nav-btn')&&
     !x.classes.has('mad4b-ws-service-nav'))||null;
  if(selector==='.mad4b-ws-service-nav,.mad4b-guide-cost-nav')
   return this.children.find(x=>x.classes.has('mad4b-ws-service-nav')||
     x.classes.has('mad4b-guide-cost-nav'))||null;
  if(selector==='.mad4b-ws-nav-btn[data-section="overview"]')
   return this.children.find(x=>x.dataset.section==='overview')||null;
  if(selector==='.mad4b-ws-section-heading')
   return this.children.find(x=>x.classes.has('mad4b-ws-section-heading'))||null;
  if(selector==='.mad4b-ws-product-context')
   return this.children.find(x=>x.className==='mad4b-ws-product-context')||null;
  return null;
 }
}
const nav=new Element('nav');
const overview=new Element('button','mad4b-ws-nav-btn');
overview.dataset.section='overview';nav.append(overview);
const service=new Element('button','mad4b-ws-nav-btn mad4b-ws-service-nav');
service.dataset.pricingExternal='accommodation';nav.append(service);
const content=new Element('section','mad4b-ws-content');
const heading=new Element('header','mad4b-ws-section-heading');
const originalContent=new Element('div','original-product-content');
content.append(heading,originalContent);
const nativeForm=new Element('form'),originalSave=new Element('button');
nativeForm.append(originalSave);
const dimension=new Element('button','mad4b-ws-dimension-open');
dimension.attrs['aria-expanded']='false';
dimension.addEventListener('click',()=>dimension.attrs['aria-expanded']='true');
const root=new Element('div','jet-engine-options-page-wrap mad4b-ws-visual');
root.dataset.mad4bPricingPane='accommodation';
root.append(nativeForm,content);
root.querySelector=selector=>{
 if(selector==='.mad4b-ws-nav')return nav;
 if(selector==='.mad4b-ws-content')return content;
 if(selector==='.mad4b-ws-dimension-open')return dimension;
 return null;
};
overview.addEventListener('click',()=>{
 delete root.dataset.mad4bPricingPane;
 nav.dispatchEvent('click');
});
const docListeners={};
const doc={
 querySelector:selector=>selector==='.jet-engine-options-page-wrap'?root:null,
 createElement:tag=>new Element(tag),
 addEventListener:(type,fn)=>{docListeners[type]=fn;}
};
let rootObserver;
const MutationObserver=class{
 constructor(fn){this.fn=fn;}
 observe(target,opt){if(target===root)rootObserver=this;}
 disconnect(){this.disconnected=true;}
};
const ctx={document:doc,window:{MAD4BWorkspaceShell:{pricing:true,version:'1.0.0'}},MutationObserver};
vm.runInNewContext(source,ctx);
const panel=content.querySelector('.mad4b-ws-product-context');
assert.ok(panel,'Missing product step controller');
assert.equal(panel.hidden,false,'Accommodation needs source scope context');
assert.match(panel.children[0].children[2].textContent,/Accommodation & Units/);
assert.equal(panel.parentNode,content,'Must stay outside original native form');
assert.equal(content.children[1],panel,'Scope should be after workspace heading');
const button=panel.children[1];
assert.equal(button.type,'button');
assert.equal(button.textContent,'Configure dimensions');
button.click();
assert.equal(overview.clicked,1,'Configure dimensions must navigate to company-wide settings');
assert.equal(dimension.clicked,1,'Must open the ORIGINAL company dimension controller');
assert.equal(dimension.focused,true,'Keyboard focus should follow the opened dimension controller');
assert.equal(panel.hidden,true,'Product contextual panel must hide on company-wide settings');
root.dataset.mad4bPricingPane='transportation';
rootObserver.fn();
assert.equal(panel.hidden,false);
assert.match(panel.children[0].children[2].textContent,/Transport/);
root.classes.add('mad4b-guide-cost-tab-active');
delete root.dataset.mad4bPricingPane;
rootObserver.fn();
assert.match(panel.children[0].children[2].textContent,/Guide-day coverage/);
root.classes.delete('mad4b-ws-visual');
docListeners['mad4b:jetengine:mode-changed']();
assert.equal(panel.hidden,true,'Native mode must hide grouped-only callout');
assert.deepEqual(nativeForm.children,[originalSave],'Native JetEngine form mutated');
assert.deepEqual(content.children.slice(2),[originalContent]);
console.log('PASS company-wide dimensions remain one original source of truth');
console.log('PASS Accommodation, Transportation and Guide use the same non-saving product scope controller');
console.log('PASS switching native mode hides synthetic UI, with JetEngine form and Save untouched');
console.log('JETENGINE_PRODUCT_SCOPE_DIMENSIONS_DOM_PASS');
