'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const file=process.env.MAD4B_DEVICE_CACHE_PHP;
assert(file,'pass MAD4B_DEVICE_CACHE_PHP');
const php=fs.readFileSync(file,'utf8');
const match=php.match(/<<<'JS'\n([\s\S]*?)\nJS;/);
assert(match,'JS source must come from actual WordPress PHP asset');
const code=match[1];
assert(code.includes('function boundedRetry()') && code.includes('frontend.__mad4bDeviceModeCache = true;'));
function eventTarget() {
 const handlers={};
 return {handlers,addEventListener(n,fn){(handlers[n]??=[]).push(fn);},
   removeEventListener(n,fn){handlers[n]=(handlers[n]||[]).filter(x=>x!==fn)},
   emit(n,arg){for(const h of [...(handlers[n]||[])])h(arg||{})}};
}
function fixture(state='ready') {
 let reads=0,timer=0;
 const calls=new Map();
 const document=eventTarget(),window=eventTarget();
 window.innerWidth=390;
 window.setTimeout=(fn)=>{calls.set(++timer,fn);return timer};
 window.clearTimeout=(id)=>{calls.delete(id)};
 window.visualViewport=null;
 const frontend={getCurrentDeviceMode(){reads++;return window.innerWidth<600?'mobile':'tablet';},
   isEditMode(){return state==='edit'}};
 if(state==='ready'||state==='edit')window.elementorFrontend=frontend;
 vm.runInNewContext(code,{window,document,console},{timeout:800});
 return {window,document,frontend,calls,reads:()=>reads,runTimer(){let [id,fn]=calls.entries().next().value||[];if(fn){calls.delete(id);fn()}},emitDocument:(n,arg)=>document.emit(n,arg),emitWindow:(n,arg)=>window.emit(n,arg)};
}
const ready=fixture('ready');
assert.equal(ready.window.elementorFrontend.__mad4bDeviceModeCache,true);
assert.equal(ready.calls.size,0,'no timers when Elementor is already ready');
for(let i=0;i<100;i++)assert.equal(ready.window.elementorFrontend.getCurrentDeviceMode(),'mobile');
assert.equal(ready.reads(),1,'100 device mode checks use one CSS read');
ready.window.innerWidth=800;
assert.equal(ready.frontend.getCurrentDeviceMode(),'tablet');
assert.equal(ready.reads(),2,'width change invalidates memo');
ready.emitWindow('pageshow');
ready.frontend.getCurrentDeviceMode();
assert.equal(ready.reads(),3,'pageshow invalidates');
ready.emitDocument('load',{target:{tagName:'LINK',relList:{contains:x=>x==='stylesheet'}}});
ready.frontend.getCurrentDeviceMode();
assert.equal(ready.reads(),4,'late stylesheet invalidates');
console.log('PASS ready instance: no timers, memo and resize/stylesheet invalidation');

const delayed=fixture('not-yet');
assert.equal(delayed.window.elementorFrontend,undefined);
assert(delayed.calls.size<=1 && delayed.calls.size>0,'one bounded fallback timer');
delayed.window.elementorFrontend=delayed.frontend;
delayed.emitDocument('DOMContentLoaded');
assert.equal(delayed.frontend.__mad4bDeviceModeCache,true);
assert.equal(delayed.calls.size,0,'late install cancels pending retry');
for(let i=0;i<50;i++)delayed.frontend.getCurrentDeviceMode();
assert.equal(delayed.reads(),1,'late init optimized exactly once');
delayed.emitWindow('load');delayed.emitWindow('pageshow');
assert.equal(delayed.calls.size,0,'no duplicated initializers');
console.log('PASS deferred LiteSpeed instance: DOM ready hooks actual object; no duplicate timers');

const never=fixture('not-yet');
for(let i=0;i<20;i++)never.runTimer();
assert.equal(never.calls.size,0,'bounded retries terminate');
console.log('PASS missing Elementor: all retries are bounded');

const editor=fixture('edit');
assert(!editor.frontend.__mad4bDeviceModeCache,'editor untouched');
assert.equal(editor.calls.size,0,'editor never scheduled');
console.log('PASS editor not changed');
console.log('ALL_ELEMENTOR_DEVICE_MODE_LATE_INIT_TESTS_PASS');
