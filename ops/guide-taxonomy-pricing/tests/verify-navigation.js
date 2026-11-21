'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.resolve(__dirname,'../../../wp-content/mu-plugins/assets/mad4b-guide-taxonomy-pricing-v1.js'),'utf8');

function render(rootPresent=true, hash=''){
 class Node {
  constructor(kind='div'){
   this.kind=kind;this.children=[];this.dataset={};this.attrs={};
   this.listeners={};this.hidden=false;this.textContent='';
   this.style={};this.classNames=new Set();this.id='';this.scrolled=false;
   this.classList={
    add:(x)=>this.classNames.add(x),
    remove:(x)=>this.classNames.delete(x),
    toggle:(x,on)=>{
      if(on===undefined)on=!this.classNames.has(x);
      if(on)this.classNames.add(x);else this.classNames.delete(x);
      return on;
    },
    contains:(x)=>this.classNames.has(x)
   };
  }
  appendChild(node){this.children.push(node);node.parent=this;return node;}
  append(...items){for(const x of items)this.appendChild(x);}
  prepend(node){this.children.unshift(node);node.parent=this;}
  setAttribute(key,value){this.attrs[key]=String(value);}
  addEventListener(name,listener){(this.listeners[name]??=[]).push(listener);}
  scrollIntoView(){this.scrolled=true;}
  querySelector(selector){
   if(selector==='#mad4b-guide-cost-result')return status;
   if(selector==='#mad4b-guide-default-form')return null;
   if(selector==='.mad4b-ws-sidebar .mad4b-ws-nav')return nav;
   if(selector==='.mad4b-ws-content')return content;
   if(selector==='.mad4b-workspace')return rootPresent?workspace:null;
   if(selector==='.mad4b-guide-approve-term')return null;
   return null;
  }
  querySelectorAll(selector){
   if(selector==='.mad4b-guide-term-row')return termRows;
   if(selector==='.mad4b-ws-nav-btn')return nav.children;
   return [];
  }
 }
 const root=new Node(),workspace=new Node(),nav=new Node(),content=new Node(),panel=new Node(),status=new Node();
 panel.id='mad4b-guide-taxonomy-cost-panel';
 const termRows=Array.from({length:5},()=>new Node());
 const native=Array.from({length:6},()=>{
  const b=new Node('button');b.classNames.add('mad4b-ws-nav-btn');nav.appendChild(b);return b;
 });
 const head=new Node(),wrap=new Node();
 const location={hash};
 const history={replaceState(_a,_b,newHash){location.hash=newHash;}};
 const document={
  readyState:'complete',head,
  getElementById(key){return key===panel.id?panel:null;},
  querySelector(key){
   if(key==='.jet-engine-options-page-wrap')return rootPresent?root:null;
   if(key==='#wpbody-content .wrap')return wrap;
   return null;
  },
  createElement:(tag)=>new Node(tag),
  createTextNode:(text)=>{const n=new Node('#text');n.textContent=text;return n;}
 };
 const window={MAD4BGuideTaxPricing:{url:'/wp-admin/admin-ajax.php',nonce:'test',action:'test'},
  addEventListener(){}};
 vm.runInNewContext(source,{document,window,history,location,URLSearchParams,
   fetch(){throw Error('unexpected network')},console});
 return {root,nav,content,panel,workspace,native,wrap,location};
}
const a=render();
assert.equal(a.nav.children.length,7,'Seventh Guide Costs nav missing');
assert.equal(a.panel.parent,a.content,'Panel was not relocated to real Pricing Controls content');
assert.equal(a.panel.hidden,true,'Panel must be hidden until activated');
const guide=a.nav.children[6];
assert.equal(guide.dataset.section,'guide-costs');
guide.listeners.click[0]();
assert.equal(a.panel.hidden,false,'Guide click did not show panel');
assert.equal(a.root.classNames.has('mad4b-guide-cost-tab-active'),true);
assert.equal(guide.classNames.has('is-active'),true);
assert.equal(a.location.hash,'#mad4b-guide-taxonomy-cost-panel');
assert.equal(a.panel.scrolled,true);
a.nav.listeners.click[0]({target:{closest:(sel)=>sel.includes(':not(')?a.native[0]:null}});
assert.equal(a.panel.hidden,true,'Native section did not restore original editor');
assert.equal(a.root.classNames.has('mad4b-guide-cost-tab-active'),false);
console.log('PASS seventh Guide costs nav mounts inside real Pricing Controls');
console.log('PASS selecting Guide costs shows editable panel');
console.log('PASS native sections restore JetEngine editor without cloning forms');

const b=render(true,'#mad4b-guide-taxonomy-cost-panel');
assert.equal(b.panel.hidden,false,'Deep link did not activate Guide tab');
console.log('PASS deep link opens the Guide costs tab');

const c=render(false);
assert.equal(c.panel.parent,c.wrap,'Missing workspace should show panel near page heading');
console.log('PASS vendor-markup fallback keeps panel discoverable');
console.log('GUIDE_PRICING_NAVIGATION_JS_ACCEPTED');
