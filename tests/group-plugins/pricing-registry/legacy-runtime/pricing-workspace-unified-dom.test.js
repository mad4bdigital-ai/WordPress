/**
 * Dependency-free DOM smoke regression for the real pricing workspace adapter.
 * Does not execute any native pricing/save code or create live records.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Element {
  constructor(tag='div',cls=''){
    this.tagName=tag.toUpperCase();this.className=cls;
    this.textContent='';this.dataset={};this.attrs={};this.children=[];
    this.parentElement=null;this.hidden=false;this.handlers={};
    this.classList={
      contains:k=>this.className.split(/\s+/).includes(k),
      add:k=>{if(!this.classList.contains(k))this.className+=' '+k;},
      remove:k=>{this.className=this.className.split(/\s+/).filter(x=>x!==k).join(' ');},
      toggle:(k,on)=>on?this.classList.add(k):this.classList.remove(k)
    };
  }
  append(...items){for(const item of items){
    if(item.parentElement)item.parentElement.children=item.parentElement.children.filter(x=>x!==item);
    item.parentElement=this;this.children.push(item);
  }}
  setAttribute(k,v){this.attrs[k]=v;}
  getAttribute(k){return this.attrs[k]??null;}
  addEventListener(k,fn,capture=false){(this.handlers[k]??=[]).push({fn,capture});}
  matches(q){return q.startsWith('.mad4b-ws-nav-btn')&&this.classList.contains('mad4b-ws-nav-btn')&&(!q.includes(':not(')||!this.dataset.pricingExternal);}
  closest(q){return this.matches(q)?this:this.parentElement?.closest(q)??null;}
  querySelector(q){if(q===':scope > details')return this.children.find(x=>x.tagName==='DETAILS')??null;return null;}
  querySelectorAll(q){return q==='.mad4b-ws-nav-btn:not([data-pricing-external])'?this.children.filter(x=>x.matches(q)):[];}
  scrollIntoView(option){this.scroll=option;}
  click(){
    const e={target:this};
    for(const item of this.parentElement?.handlers.click??[])if(item.capture)item.fn(e);
    for(const item of this.handlers.click??[])item.fn(e);
  }
}
function testContext(options={}){
  const root=new Element('div','jet-engine-options-page-wrap mad4b-ws-visual');
  const nav=new Element('nav','mad4b-ws-nav');
  const content=new Element('main','mad4b-ws-content');
  const guide=new Element('p');guide.textContent='Only Save changes persists native settings.';
  const title=new Element('h3');
  const hint=new Element('p');
  const search=new Element('input');
  const stay=new Element('section','mad4b-stay-panel');
  const transport=new Element('section','mad4b-ts-panel');
  const original=new Element('button','mad4b-ws-nav-btn is-active');
  original.addEventListener('click',()=>{title.textContent='General & availability';});
  nav.append(original);
  root.append(stay);
  if(!options.late)root.append(transport);
  const raw=new Element('button');raw.textContent='All JetEngine fields';
  raw.addEventListener('click',()=>root.classList.remove('mad4b-ws-visual'));
  const visual=new Element('button');visual.textContent='Grouped JetEngine view';
  visual.addEventListener('click',()=>root.classList.add('mad4b-ws-visual'));
  const map={
    '.mad4b-ws-nav':nav,'.mad4b-ws-content':content,'.mad4b-ws-tip':guide,
    '.mad4b-ws-section-heading h3':title,
    '.mad4b-ws-section-heading p':hint,'.mad4b-ws-search':search,
    '.mad4b-stay-panel':stay,
    '#mad4b-transport-self-controls':options.late?null:transport
  };
  root.querySelector=q=>map[q]??null;
  root.querySelectorAll=q=>q==='.mad4b-ws-actions button'?[raw,visual]:[];
  let observer;
  class Observer {constructor(fn){this.fn=fn;observer=this;}observe(){}disconnect(){this.disconnected=true;}}
  const document={
    querySelector:q=>q==='.jet-engine-options-page-wrap'?root:null,
    createElement:tag=>new Element(tag),
    createTextNode:text=>{const t=new Element('#text');t.textContent=text;return t;},
    addEventListener:()=>{}
  };
  const window={
    MAD4BPricingWorkspaceUnified:options.disabled?null:{version:'1.0.0'},
    matchMedia:()=>({matches:true}),setTimeout:()=>{}
  };
  vm.runInNewContext(fs.readFileSync(
    path.resolve(__dirname,'../assets/pricing-workspace-unified.js'),'utf8'),
    {document,window,MutationObserver:Observer,console},
    {filename:'pricing-workspace-unified.js'});
  return {root,nav,content,guide,title,hint,search,stay,transport,original,
    raw,visual,get observer(){return observer;},
    addLate(){root.append(transport);map['#mad4b-transport-self-controls']=transport;observer.fn();}
  };
}
const checks=[];
function check(name,test){test();checks.push(name);}
const c=testContext();
check('one sticky nav with two service sections',()=>{
  assert.equal(c.nav.children.length,3);
  assert.equal(c.nav.children[1].dataset.pricingExternal,'accommodation');
  assert.equal(c.nav.children[2].dataset.pricingExternal,'transportation');
});
check('same DOM nodes moved, never cloned',()=>{
  assert.equal(c.stay.parentElement,c.content.children[0]);
  assert.equal(c.transport.parentElement,c.content.children[1]);
  assert.equal(c.root.children.length,0);
});
check('native fields initially preserved',()=>{
  assert.equal(c.root.dataset.mad4bPricingPane,undefined);
  assert.equal(c.content.children[0].hidden,true);
  assert.equal(c.content.children[1].hidden,true);
});
check('transport view hides stay and original inspector via scoped state',()=>{
  c.nav.children[2].click();
  assert.equal(c.root.dataset.mad4bPricingPane,'transportation');
  assert.equal(c.content.children[0].hidden,true);
  assert.equal(c.content.children[1].hidden,false);
  assert.equal(c.nav.children[2].getAttribute('aria-current'),'page');
  assert.match(c.guide.textContent,/saves separately/);
});
check('stay view switches without duplicate forms',()=>{
  c.nav.children[1].click();
  assert.equal(c.root.dataset.mad4bPricingPane,'accommodation');
  assert.equal(c.content.children[0].hidden,false);
  assert.equal(c.content.children[1].hidden,true);
  assert.equal(c.stay.parentElement.children.length,1);
});
check('return to original native group restores native guidance',()=>{
  c.original.click();
  assert.equal(c.root.dataset.mad4bPricingPane,undefined);
  assert.equal(c.guide.textContent,'Only Save changes persists native settings.');
  assert.equal(c.title.textContent,'General & availability');
});
check('native mode and grouped mode remain accessible',()=>{
  c.nav.children[2].click();
  c.raw.click();
  assert.equal(c.root.dataset.mad4bPricingPane,undefined);
  assert.equal(c.root.classList.contains('mad4b-ws-visual'),false);
  c.visual.click();
  assert.equal(c.root.classList.contains('mad4b-ws-visual'),true);
  assert.equal(c.title.textContent,'General & availability');
});
const late=testContext({late:true});
check('late service panel is attached by bounded MutationObserver',()=>{
  assert.equal(late.nav.children.length,2);
  late.addLate();
  assert.equal(late.nav.children.length,3);
  assert.equal(late.observer.disconnected,true);
});
check('no page config produces no modification',()=>{
  const none=testContext({disabled:true});
  assert.equal(none.nav.children.length,1);
  assert.equal(none.content.children.length,0);
});
const css=fs.readFileSync(path.resolve(__dirname,'../assets/pricing-workspace-unified.css'),'utf8');
check('CSS is scoped and supports wide and narrow fields',()=>{
  assert.match(css,/\.mad4b-pricing-composed/);
  assert.match(css,/data-mad4b-pricing-pane/);
  assert.match(css,/@container \(max-width:950px\)/);
  assert.match(css,/\.mad4b-tour-inspector/);
});
console.log(JSON.stringify({status:'PASS',assertions:checks.length,failures:0,
  note:'DOM integration test; actual logged-in Chrome visual acceptance separate'}));
