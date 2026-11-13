'use strict';
// Hermetic browser-event tests; read the REAL existing Quick Preview scripts.
// Never access WordPress, a network, a booking form, or a signing authority.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const src=path.resolve(__dirname,'../../wp-content/mu-plugins/mad4b-extra-quick-preview');
const events={};const classes=()=>{
 const state=new Set();
 return {contains:n=>state.has(n),add:n=>state.add(n),remove:n=>state.delete(n),
   toggle:(n,yes)=>{if(yes)state.add(n);else state.delete(n);return yes;}};
};
const parentMessages=[];let visible=false,observerCallback=null;
const popup={isConnected:true,querySelector:s=>s==='[data-mad4b-uael-tour="1"]'?{}:null,
  getAttribute:()=>null};
const body={classList:classes()};body.classList.add('mad4b-extra-quickview-frame');
const document={readyState:'complete',body,documentElement:{classList:classes()},
  querySelectorAll:s=>s==='.elementor-popup-modal'?[popup]:[],
  addEventListener:(name,fn)=>{events['frame-'+name]=fn;}};
const window={parent:{postMessage:(payload,origin)=>parentMessages.push({payload,origin})},
  requestAnimationFrame:fn=>{fn();},addEventListener:(name,fn)=>{events['frame-window-'+name]=fn;}};
class MutationObserver{constructor(cb){observerCallback=cb;}observe(){}}
vm.runInNewContext(fs.readFileSync(path.join(src,'quick-preview-frame.js'),'utf8'),
  {window,document,MutationObserver,getComputedStyle:()=>({display:visible?'block':'none'}),
    location:{origin:'https://tours.dev.mad4b.com'},Number,Promise,console});
assert.equal(parentMessages.length,1);
assert.equal(parentMessages[0].payload.open,false);
assert.equal(body.classList.contains('mad4b-extra-quickview-gallery-active'),false);
visible=true;observerCallback();
assert.equal(parentMessages.at(-1).payload.open,true);
assert.equal(body.classList.contains('mad4b-extra-quickview-gallery-active'),true);
assert.equal(document.documentElement.classList.contains('mad4b-extra-quickview-gallery-active'),true);
const n=parentMessages.length;observerCallback();assert.equal(parentMessages.length,n);
visible=false;observerCallback();
assert.equal(parentMessages.at(-1).payload.open,false);
assert.equal(body.classList.contains('mad4b-extra-quickview-gallery-active'),false);

const parentEvents={};const elements=[];
const sourceWindow={};
function makeElement(tag){
 const listeners={},out={tagName:tag.toUpperCase(),className:'',classList:classes(),open:false,
  contentWindow:tag==='iframe'?sourceWindow:undefined,
  dataset:{},setAttribute(){},removeAttribute(){},remove(){},append(){},
  addEventListener:(name,fn)=>{listeners[name]=fn;},
  showModal(){this.open=true;},close(){this.open=false;listeners.close?.();}};
 elements.push(out);return out;
}
const cardButton={tagName:'BUTTON',disabled:false,
 getAttribute:name=>({
  'data-mad4b-extra-preview-url':'https://tours.dev.mad4b.com/example-tour/?mad4b_extra_quickview=1',
  'data-mad4b-extra-preview':'60469',
  'data-mad4b-extra-preview-title':'Nubian Village & Felucca Sunset – 1 Day Tour from Aswan'
 }[name]||null),
 closest:selector=>selector.startsWith('.shared-extra-feature-carousel')?cardButton:null};
const parentDocument={body:{append(){}},createElement:makeElement,
 addEventListener:(name,fn)=>{parentEvents[name]=fn;},querySelectorAll:()=>[]};
const origin='https://tours.dev.mad4b.com';
const parentWindow={location:{origin,protocol:'https:'},
 addEventListener:(name,fn)=>{parentEvents['window-'+name]=fn;}};
vm.runInNewContext(fs.readFileSync(path.join(src,'quick-preview.js'),'utf8'),
 {window:parentWindow,document:parentDocument,location:parentWindow.location,
  URL,Number,Promise,console});
parentEvents.click({target:{closest:selector=>selector.startsWith('.shared-extra-feature-carousel')?cardButton:null},
 button:0,preventDefault(){},stopPropagation(){}});
const dialog=elements.find(x=>x.tagName==='DIALOG');
assert.ok(dialog?.open);
const title=elements.find(x=>x.tagName==='STRONG');
assert.equal(title?.textContent,'Nubian Village & Felucca Sunset – 1 Day Tour from Aswan');
const message=(source,where,open)=>parentEvents['window-message']({source,origin:where,
 data:{type:'mad4b-extra-preview-gallery-state',open}});
message({},origin,true);
assert.equal(dialog.classList.contains('mad4b-extra-quick-preview-gallery-active'),false,
 'Untrusted iframe cannot modify parent modal');
message(sourceWindow,'https://example.invalid',true);
assert.equal(dialog.classList.contains('mad4b-extra-quick-preview-gallery-active'),false,
 'Wrong origin cannot modify parent modal');
message(sourceWindow,origin,true);
assert.equal(dialog.classList.contains('mad4b-extra-quick-preview-gallery-active'),true);
message(sourceWindow,origin,false);
assert.equal(dialog.classList.contains('mad4b-extra-quick-preview-gallery-active'),false);
console.log('PASS: iframe gallery state, same-origin/source validation, native title, reopen without nested chrome (14 checks)');
