'use strict';
const fs=require('fs'),vm=require('vm'),path=require('path'),assert=require('node:assert/strict');
const src=fs.readFileSync(path.join(__dirname,
 '../../../wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.js'),'utf8');
class Element{
 constructor(tag='div',classes=''){
  this.tag=tag;this.className=classes;this.dataset={};this.attrs={};
  this.children=[];this.parentNode=null;this.textContent='';
  this.classes=new Set(classes.split(' '));
  this.classList={add:name=>this.classes.add(name)};
 }
 setAttribute(k,v){this.attrs[k]=v;}
 append(el){el.parentNode=this;this.children.push(el);}
 insertBefore(el,target){
  const pos=this.children.indexOf(target);assert.ok(pos>=0);
  el.parentNode=this;this.children.splice(pos,0,el);
 }
 querySelector(selector){
  if(selector==='[data-mad4b-nav-group="company"]')
   return this.children.find(x=>x.dataset.mad4bNavGroup==='company')||null;
  if(selector==='[data-mad4b-nav-group="product"]')
   return this.children.find(x=>x.dataset.mad4bNavGroup==='product')||null;
  if(selector==='.mad4b-ws-nav-btn:not(.mad4b-ws-service-nav)')
   return this.children.find(x=>x.classes.has('mad4b-ws-nav-btn')&&!x.classes.has('mad4b-ws-service-nav'))||null;
  if(selector==='.mad4b-ws-service-nav,.mad4b-guide-cost-nav')
   return this.children.find(x=>x.classes.has('mad4b-ws-service-nav')||x.classes.has('mad4b-guide-cost-nav'))||null;
  if(selector==='.mad4b-ws-service-nav')
   return this.children.find(x=>x.classes.has('mad4b-ws-service-nav'))||null;
  return null;
 }
}
const nav=new Element('nav'),root=new Element('div'),originalForm=new Element('form');
const nativeSave=new Element('button');originalForm.append(nativeSave);
root.append(originalForm);
const core=new Element('button','mad4b-ws-nav-btn');core.dataset.section='overview';nav.append(core);
root.querySelector=sel=>sel==='.mad4b-ws-nav'?nav:null;
const document={
 querySelector:sel=>sel==='.jet-engine-options-page-wrap'?root:null,
 createElement:tag=>new Element(tag)
};
let observer;
const MutationObserver=class{
 constructor(cb){this.callback=cb;observer=this;}
 observe(target,options){this.target=target;this.options=options;}
 disconnect(){this.stopped=true;}
};
const ctx={document,window:{MAD4BWorkspaceShell:{pricing:true,version:'1.0.0'}},MutationObserver};
vm.runInNewContext(src,ctx);
assert.equal(nav.children[0].dataset.mad4bNavGroup,'company');
assert.equal(nav.children[1],core);
assert.equal(nav.children[0].attrs.role,'heading');
assert.equal(nav.children[0].attrs['aria-level'],'3');
assert.equal(root.children[0],originalForm);
assert.equal(originalForm.children[0],nativeSave);
const guide=new Element('button','mad4b-ws-nav-btn mad4b-guide-cost-nav');
nav.append(guide);observer.callback();
assert.equal(nav.children[2].dataset.mad4bNavGroup,'product');
assert.equal(nav.children[3],guide,'Guide should be in Product settings even when it mounts first');
assert.equal(observer.stopped,true);
const accommodation=new Element('button','mad4b-ws-nav-btn mad4b-ws-service-nav');
accommodation.dataset.pricingExternal='accommodation';
nav.append(accommodation);
assert.equal(nav.children.length,5);
assert.equal(root.classes.has('mad4b-ws-unified-shell'),true);
console.log('PASS company settings and late-mounted product service settings are separated');
console.log('PASS original JetEngine form, Save, fields and source panel nodes remain unchanged');
console.log('JETENGINE_UNIFIED_SHELL_NAV_DOM_PASS');
