'use strict';
const assert=require('node:assert/strict');
const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const js=fs.readFileSync(path.join(__dirname,
 '../../../wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.js'),'utf8');
class Element{
 constructor(tag='div',name='',cls=''){
  this.tag=tag;this.name=name;this.className=cls;this.children=[];
  this.classes=new Set(cls.split(' ').filter(Boolean));
  this.classList={
   add:x=>this.classes.add(x),remove:x=>this.classes.delete(x),
   contains:x=>this.classes.has(x),toggle:(x,enabled)=>{
    if(enabled)this.classes.add(x);else this.classes.delete(x);
   }
  };
  this.parentNode=null;this.listeners={};this.dataset={};this.attrs={};
  this.hidden=false;this.textContent='';
 }
 append(...nodes){
  for(const node of nodes){node.parentNode?.remove(node);node.parentNode=this;this.children.push(node);}
 }
 remove(node){const i=this.children.indexOf(node);if(i>=0)this.children.splice(i,1);node.parentNode=null;}
 insertBefore(node,ref){
  node.parentNode?.remove(node);
  const i=ref===null?this.children.length:this.children.indexOf(ref);
  assert.ok(i>=0,'Must have insertion anchor');
  this.children.splice(i,0,node);node.parentNode=this;
 }
 get nextSibling(){
  if(!this.parentNode)return null;
  return this.parentNode.children[this.parentNode.children.indexOf(this)+1]||null;
 }
 setAttribute(k,v){this.attrs[k]=v;}
 getAttribute(k){return this.attrs[k]??null;}
 addEventListener(k,fn){(this.listeners[k]||=[]).push(fn);}
 click(){for(const fn of this.listeners.click||[])fn();}
 focus(){this.focused=true;}
 querySelector(sel){
  if(this===formContainer && sel==='form')return form;
  if(this===form){
   if(sel==='#settings_top')return inputGroup;
   if(sel==='button[type="submit"]')return submit;
  }
  if(this===root){
   if(sel==='.mad4b-ws-nav')return nav;
   if(sel==='.mad4b-ws-content')return content;
   if(sel==='.mad4b-ws-dimension-open')return controller;
  }
  if(this===content){
   if(sel==='.mad4b-ws-section-heading')return sectionHeading;
   if(sel==='.mad4b-ws-product-context')return this.children.find(c=>c.className==='mad4b-ws-product-context')||null;
  }
  if(this===nav){
   if(sel==='.mad4b-ws-nav-btn:not(.mad4b-ws-service-nav)')return company;
   if(sel==='.mad4b-ws-service-nav,.mad4b-guide-cost-nav')return service;
   if(sel==='[data-mad4b-nav-group="company"]')return this.children.find(c=>c.dataset.mad4bNavGroup==='company')||null;
   if(sel==='[data-mad4b-nav-group="product"]')return this.children.find(c=>c.dataset.mad4bNavGroup==='product')||null;
   if(sel==='.mad4b-ws-nav-btn[data-section="overview"]')return company;
  }
  return null;
 }
 querySelectorAll(sel){
  if(sel!=='form')return [];
  const collected=[];const walk=node=>{
   if(node.tag==='form')collected.push(node);
   for(const child of node.children||[])walk(child);
  };
  walk(this);return collected;
 }
}
const root=new Element('section','root','jet-engine-options-page-wrap mad4b-ws-visual');
const ws=new Element('section','workspace','mad4b-workspace');
const content=new Element('div','content','mad4b-ws-content');
const sectionHeading=new Element('header','sectionHeading','mad4b-ws-section-heading');
const separateSupplierForm=new Element('form','externalSupplierOwnSave');
content.append(sectionHeading,separateSupplierForm);
const formContainer=new Element('div','nativeSection','cx-section mad4b-ws-original');
const form=new Element('form','jetEngineForm');
const inputGroup=new Element('div','settings_top');
const field=new Element('input','booking_enabled');
const nonce=new Element('input','jet_engine_nonce');
const submit=new Element('button','vendorSave');
submit.type='submit';
inputGroup.append(field,nonce);
form.append(inputGroup,submit);formContainer.append(form);
const nav=new Element('nav','nav','mad4b-ws-nav');
const company=new Element('button','company','mad4b-ws-nav-btn');
company.dataset.section='overview';nav.append(company);
const service=new Element('button','stay','mad4b-ws-nav-btn mad4b-ws-service-nav');
nav.append(service);
const controller=new Element('button','dimensions','mad4b-ws-dimension-open');
controller.attrs['aria-expanded']='false';
ws.append(content);root.append(ws,formContainer);
const listeners={};
const document={
 querySelector:s=>s==='.jet-engine-options-page-wrap'?root:null,
 createElement:tag=>new Element(tag),
 createComment:label=>new Element('comment',label),
 addEventListener:(name,fn)=>(listeners[name]||=[]).push(fn)
};
let rootObserver=null;
class MutationObserver{
 constructor(fn){this.fn=fn;}
 observe(target,options){if(target===root)rootObserver=this;}
 disconnect(){this.disconnected=true;}
}
const sendMode=()=>{for(const fn of listeners['mad4b:jetengine:mode-changed']||[])fn();}
vm.runInNewContext(js,{document,window:{MAD4BWorkspaceShell:{pricing:true}},MutationObserver});
assert.equal(formContainer.parentNode,content,'Native editor must be structurally INSIDE the main workspace content');
assert.equal(root.children.filter(x=>x===formContainer).length,0,'Orphan duplicate editor remained');
assert.equal(content.children[content.children.length-1],formContainer);
assert.equal(root.classes.has('mad4b-ws-editor-composed'),true);
assert.equal(root.classes.has('mad4b-ws-flat-canvas'),true,
 'Flat canvas must activate only with the original grouped pricing editor');
assert.equal(root.querySelectorAll('form').length,2,'Existing independent supplier form must remain intact');
assert.equal(separateSupplierForm.parentNode,content);
assert.deepEqual(form.children,[inputGroup,submit]);
assert.deepEqual(inputGroup.children,[field,nonce]);
root.classes.delete('mad4b-ws-visual');root.classes.add('mad4b-ws-native');
sendMode();
assert.equal(formContainer.parentNode,root,'Raw native mode did not restore original parent');
assert.equal(form.parentNode,formContainer);
assert.equal(root.classes.has('mad4b-ws-editor-composed'),false);
assert.equal(root.classes.has('mad4b-ws-flat-canvas'),false,
 'Original raw JetEngine view must be exempt from flat design overrides');
assert.equal(root.querySelectorAll('form').length,2,'Existing independent supplier form must remain intact');
assert.equal(separateSupplierForm.parentNode,content);
root.classes.delete('mad4b-ws-native');root.classes.add('mad4b-ws-visual');
sendMode();
assert.equal(formContainer.parentNode,content,'Grouped mode did not recompose original form');
assert.equal(root.querySelectorAll('form').length,2,'Existing independent supplier form must remain intact');
assert.equal(separateSupplierForm.parentNode,content);
assert.equal(inputGroup.children[1],nonce);
assert.equal(form.children[1],submit,'JetEngine original Save changed');
assert.equal(field.parentNode,inputGroup,'Native field detached');
assert.ok(rootObserver,'Product navigation observer missing');
console.log('PASS original JetEngine form is physically embedded in ONE workspace content surface');
console.log('PASS raw native mode restores exact original form and native input references');
console.log('PASS toggle repeated with no second form, field, nonce, or Save action');
console.log('JETENGINE_SINGLE_NATIVE_EDITOR_DOM_ACCEPTED');
